#!/usr/bin/env python3
"""
arsc_patch.py -- edit strings inside an Android resources.arsc (binary).

Strategy
--------
The global *value* string pool is the only thing that is modified.  New
strings are **appended** to the pool, so every existing string offset stays
valid and no other chunk has to be relocated.  Afterwards every
ResTable_entry that referenced the old string is repointed at the index of
the new one.

Layout of resources.arsc (verified against this APK):

    0x0000  ResTable_header          type=0x0002 hdrSize=12 size pkgCount
    0x000C  ResStringPool (global)   type=0x0001 hdrSize=28  <- value strings
    ...     ResTable_package         type=0x0200 hdrSize=288
             - typeStrings pool      type=0x0001
             - keyStrings pool       type=0x0001
             - ResTable_typeSpec     type=0x0202
             - ResTable_type         type=0x0201  (holds the value indices)

Usage:
    python3 arsc_patch.py <in.arsc> <out.arsc> <mapping.json>

mapping.json: { "old text": "new text", ... }
"""

import json
import struct
import sys

RES_STRING_POOL_TYPE = 0x0001
RES_TABLE_TYPE = 0x0002
RES_TABLE_PACKAGE_TYPE = 0x0200
RES_TABLE_TYPE_TYPE = 0x0201
UTF8_FLAG = 1 << 8

COMPLEX_ENTRY_FLAG = 0x0001
TYPE_STRING = 0x03


def u16(b, o):
    return struct.unpack_from("<H", b, o)[0]


def u32(b, o):
    return struct.unpack_from("<I", b, o)[0]


class Pool:
    """ResStringPool chunk"""

    def __init__(self, data: bytes, off: int):
        self.off = off
        self.data = data
        self.type = u16(data, off)
        self.header_size = u16(data, off + 2)
        self.chunk_size = u32(data, off + 4)
        self.string_count = u32(data, off + 8)
        self.style_count = u32(data, off + 12)
        self.flags = u32(data, off + 16)
        self.strings_start = u32(data, off + 20)
        self.styles_start = u32(data, off + 24)
        self.is_utf8 = bool(self.flags & UTF8_FLAG)
        base = off + self.header_size
        self.offsets = [u32(data, base + 4 * i) for i in range(self.string_count)]
        self.style_offsets = [
            u32(data, base + 4 * (self.string_count + i)) for i in range(self.style_count)
        ]

    def read(self, index):
        p = self.off + self.strings_start + self.offsets[index]
        d = self.data
        if self.is_utf8:
            n1 = d[p]
            p += 1
            if n1 & 0x80:
                n1 = ((n1 & 0x7F) << 8) | d[p]
                p += 1
            n2 = d[p]
            p += 1
            if n2 & 0x80:
                n2 = ((n2 & 0x7F) << 8) | d[p]
                p += 1
            return d[p : p + n2].decode("utf-8", errors="replace")
        n = u16(d, p)
        p += 2
        if n & 0x8000:
            n = ((n & 0x7FFF) << 16) | u16(d, p)
            p += 2
        return d[p : p + n * 2].decode("utf-16-le", errors="replace")

    def encode(self, text):
        if self.is_utf8:
            b = text.encode("utf-8")
            n = len(text)
            out = bytearray()
            out.append(n & 0x7F if n < 0x80 else (((n >> 8) & 0x7F) | 0x80))
            if n >= 0x80:
                out.append(n & 0x7F)
            out.append(len(b) & 0x7F if len(b) < 0x80 else (((len(b) >> 8) & 0x7F) | 0x80))
            if len(b) >= 0x80:
                out.append(len(b) & 0x7F)
            out += b
            out.append(0)
            return bytes(out)
        b = text.encode("utf-16-le")
        n = len(text)
        out = bytearray()
        if n >= 0x8000:
            out += struct.pack("<H", 0x8000 | (n >> 16))
        out += struct.pack("<H", n & 0xFFFF)
        out += b
        out += b"\x00\x00"
        return bytes(out)


class Table:
    """parsed resources.arsc"""

    def __init__(self, data: bytes):
        self.data = bytearray(data)
        assert u16(self.data, 0) == RES_TABLE_TYPE, "not a resource table"
        self.pool = Pool(self.data, u16(self.data, 2))
        assert self.pool.type == RES_STRING_POOL_TYPE
        self.pkg_off = self.pool.off + self.pool.chunk_size
        assert u16(self.data, self.pkg_off) == RES_TABLE_PACKAGE_TYPE
        self.pkg_base = self.pkg_off + 268
        self.type_strings = u32(self.data, self.pkg_base)
        self.key_strings = u32(self.data, self.pkg_base + 8)
        self.ks_pool = Pool(self.data, self.pkg_off + self.key_strings)
        self.type_chunks = self._scan_type_chunks()

    def _scan_type_chunks(self):
        chunks = []
        p = self.pkg_off + self.key_strings + self.ks_pool.chunk_size
        end = len(self.data)
        while p + 8 <= end:
            ctype = u16(self.data, p)
            csize = u32(self.data, p + 4)
            if csize == 0 or csize > end - p:
                break
            if ctype == RES_TABLE_TYPE_TYPE:
                chunks.append(p)
            p += csize
        return chunks

    def _entries(self, p):
        entry_count = u32(self.data, p + 12)
        entries_start = u32(self.data, p + 16)
        e = p + entries_start
        for _ in range(entry_count):
            if e + 12 > len(self.data):
                return
            size = u16(self.data, e)
            flags = u16(self.data, e + 2)
            key = u32(self.data, e + 4)
            yield e, size, flags, key
            if size == 0:
                return
            e += size

    def find_value_users(self):
        """value string index -> list of (type_chunk, entry_offset, key)"""
        users = {}
        for p in self.type_chunks:
            for e, size, flags, key in self._entries(p):
                if flags & COMPLEX_ENTRY_FLAG:
                    continue
                value_off = e + 8
                if self.data[value_off + 3] == TYPE_STRING:
                    v = u16(self.data, value_off + 4)
                    users.setdefault(v, []).append((p, e, key))
        return users

    def append_strings(self, texts):
        """append new strings to the global value pool -> list of indices"""
        pool = self.pool
        if pool.style_count:
            raise NotImplementedError("styled string pools are not supported")

        old_data_area_len = pool.chunk_size - pool.strings_start
        blob = bytearray()
        new_offsets = []
        for t in texts:
            enc = pool.encode(t)
            new_offsets.append(old_data_area_len + len(blob))
            blob += enc
        while len(blob) % 4:
            blob.append(0)

        old_chunk_size = pool.chunk_size
        old_off_arr_size = 4 * pool.string_count
        new_off_arr_size = 4 * (pool.string_count + len(texts))
        extra = len(blob) + (new_off_arr_size - old_off_arr_size)
        new_chunk_size = (old_chunk_size + extra + 3) & ~3
        pad = new_chunk_size - (old_chunk_size + extra)

        hdr = bytearray(self.data[pool.off : pool.off + pool.header_size])
        struct.pack_into("<I", hdr, 4, new_chunk_size)
        struct.pack_into("<I", hdr, 8, pool.string_count + len(texts))
        new_strings_start = pool.strings_start + (new_off_arr_size - old_off_arr_size)
        struct.pack_into("<I", hdr, 20, new_strings_start)

        off_arr = bytearray()
        for o in pool.offsets:
            off_arr += struct.pack("<I", o)
        for o in new_offsets:
            off_arr += struct.pack("<I", o)

        old_data_area = self.data[pool.off + pool.strings_start : pool.off + old_chunk_size]
        new_pool = (
            bytes(hdr)
            + bytes(off_arr)
            + bytes(old_data_area)
            + bytes(blob)
            + b"\x00" * pad
        )
        shift = len(new_pool) - old_chunk_size

        out = bytearray(self.data[: pool.off])
        out += new_pool
        out += self.data[pool.off + old_chunk_size :]
        struct.pack_into("<I", out, 4, u32(out, 4) + shift)

        self.data = out
        self.pool = Pool(self.data, pool.off)
        self.pkg_off = pool.off + len(new_pool)
        self.pkg_base = self.pkg_off + 268
        self.type_strings = u32(self.data, self.pkg_base)
        self.key_strings = u32(self.data, self.pkg_base + 8)
        self.ks_pool = Pool(self.data, self.pkg_off + self.key_strings)
        self.type_chunks = self._scan_type_chunks()

        return [pool.string_count + i for i in range(len(texts))]

    def repoint(self, type_chunk, entry_off, new_index):
        struct.pack_into("<H", self.data, entry_off + 8 + 4, new_index)


def patch_arsc(data: bytes, replacements: dict):
    """returns (new_bytes, applied_list)"""
    table = Table(data)

    texts = []
    for i in range(table.pool.string_count):
        try:
            texts.append(table.pool.read(i))
        except Exception:
            texts.append(None)

    todo = []
    for i, t in enumerate(texts):
        if t is not None and t in replacements:
            todo.append((i, replacements[t]))
    if not todo:
        return data, []

    new_indices = table.append_strings([t for _, t in todo])
    users = table.find_value_users()
    for (old_index, _), new_index in zip(todo, new_indices):
        for p, e, key in users.get(old_index, []):
            table.repoint(p, e, new_index)

    return bytes(table.data), todo


def main():
    if len(sys.argv) != 4:
        print(__doc__)
        sys.exit(1)
    src, dst, mapping_file = sys.argv[1], sys.argv[2], sys.argv[3]
    with open(mapping_file, encoding="utf-8") as fh:
        repl = json.load(fh)
    data = open(src, "rb").read()
    out, applied = patch_arsc(data, repl)
    open(dst, "wb").write(out)
    print(f"[arsc] patched {len(applied)} strings")
    for k, v in applied:
        print(f"   {k!r} -> {v!r}")


if __name__ == "__main__":
    main()
