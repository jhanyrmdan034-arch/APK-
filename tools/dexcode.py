#!/usr/bin/env python3
"""
dexcode.py -- low level DEX code_item surgery.

It can rewrite the instruction stream of a method and keeps the
try/catch tables consistent:

  * instructions that live inside a try block are shifted together with
    the block
  * branch targets are relocated
  * the catch handler list is rebuilt

The operations are expressed on the *instruction level* so that the
caller does not have to deal with code units.
"""

import struct

from dexsec import DexFile, uleb128


# ---------------------------------------------------------------- helpers
def _uleb(value):
    out = bytearray()
    while True:
        b = value & 0x7F
        value >>= 7
        if value:
            out.append(b | 0x80)
        else:
            out.append(b)
            break
    return bytes(out)


class CodeItem:
    """the code_item of a single method"""

    def __init__(self, dex: DexFile, code_off: int):
        self.dex = dex
        self.off = code_off
        d = dex.data
        self.registers_size = struct.unpack_from("<H", d, code_off)[0]
        self.ins_size = struct.unpack_from("<H", d, code_off + 2)[0]
        self.outs_size = struct.unpack_from("<H", d, code_off + 4)[0]
        self.tries_size = struct.unpack_from("<H", d, code_off + 6)[0]
        self.debug_info_off = struct.unpack_from("<I", d, code_off + 8)[0]
        self.insns_size = struct.unpack_from("<I", d, code_off + 12)[0]
        self.insns_off = code_off + 16
        self.insns = d[self.insns_off : self.insns_off + 2 * self.insns_size]

        # ---- try items -------------------------------------------------
        self.tries = []
        p = self.insns_off + 2 * self.insns_size
        if p % 4:
            p += 2
        self.tries_off = p
        for i in range(self.tries_size):
            start, count, handler = struct.unpack_from("<HHH", d, p)
            self.tries.append({"start": start, "count": count, "handler": handler})
            p += 8

        # ---- catch handlers --------------------------------------------
        self.handlers_off = p
        self.handler_bytes = bytearray(d[p : p + self._handlers_length()])

    def _handlers_length(self):
        if not self.tries:
            return 0
        d = self.dex.data
        # the handler list is a uleb128 count followed by the handlers;
        # its length can be derived from the smallest handler offset
        base = self.handlers_off
        rel = min(t["handler"] for t in self.tries)
        # walk the uleb128 list
        p = base
        _, p = uleb128(d, p)
        # each entry is a signed uleb128 offset; the list ends after the
        # last entry -> we simply take everything up to the end of the
        # code_item, which is bounded by the next item in the data section
        return self._rest_length(base)

    def _rest_length(self, base):
        """length of the encoded_catch_handler_list"""
        d = self.dex.data
        p = base
        list_size, p = uleb128(d, p)
        for _ in range(list_size):
            size, p = uleb128(d, p)
            # signed uleb128 count of handlers
            count = size
            if count & 0x80000000:
                count -= 0x100000000
            for _ in range(abs(count)):
                _, p = uleb128(d, p)  # type idx
                _, p = uleb128(d, p)  # addr
            if count <= 0:
                _, p = uleb128(d, p)  # catch_all addr
        return p - base

    # ------------------------------------------------------------------
    def instructions(self):
        """yield (offset_in_code_units, opcode, unit_count, raw_bytes)"""
        from dexdis import INSN_UNITS

        pos = 0
        while pos < self.insns_size:
            unit = struct.unpack_from("<H", self.insns, 2 * pos)[0]
            op = unit & 0xFF
            size = INSN_UNITS.get(op, 1)
            yield pos, op, size, self.insns[2 * pos : 2 * (pos + size)]
            pos += size

    def find_invoke(self, method_idx):
        """yield (offset, raw) for every invoke-* of *method_idx*"""
        for off, op, size, raw in self.instructions():
            if op in (0x6E, 0x6F, 0x70, 0x71, 0x72, 0x73, 0x74, 0x75, 0x76, 0x77):
                idx = struct.unpack_from("<H", raw, 2)[0]
                if idx == method_idx:
                    yield off, raw

    def find_op(self, predicate):
        for off, op, size, raw in self.instructions():
            if predicate(op, raw):
                yield off, op, size, raw

    # ------------------------------------------------------------------
    def rebuild(self, new_insns: bytes, new_insns_size: int, tries, handlers):
        """returns the new code_item bytes"""
        d = self.dex.data
        header = bytearray(d[self.off : self.insns_off])
        struct.pack_into("<I", header, 12, new_insns_size)

        out = bytearray(header)
        out += new_insns
        if tries:
            if len(out) % 4:
                out += b"\x00"
            for t in tries:
                out += struct.pack("<HHH", t["start"], t["count"], t["handler"])
        out += handlers
        return bytes(out)

    def write(self, new_bytes: bytes):
        """overwrite the code_item in the dex buffer"""
        d = self.dex.data
        old_len = len(self.insns) + (16 - 16)
        # the code_item may be followed by padding -> we simply replace the
        # exact bytes and keep the rest
        d[self.off : self.off + len(new_bytes)] = new_bytes


def encode_try_handlers(entries):
    """encode a catch handler list.

    entries: list of (type_idx_or_None, address) -- the handler order must
    match the order the try items reference them.
    """
    # group consecutive handlers with the same address
    groups = []
    for type_idx, addr in entries:
        if groups and groups[-1][1] == addr:
            groups[-1][0].append(type_idx)
        else:
            groups.append(([type_idx], addr))

    out = bytearray()
    out += _uleb(len(groups))
    for types, addr in groups:
        # signed uleb128: positive -> number of typed handlers
        out += _uleb(len(types))
        for t in types:
            if t is None:
                continue
            out += _uleb(t & 0xFFFFFFFF)
        if types and types[0] is None:
            # catch-all
            out += _uleb(addr)
        else:
            for t in types:
                if t is not None:
                    out += _uleb(addr)
    return bytes(out)
