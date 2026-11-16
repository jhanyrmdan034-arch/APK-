#!/usr/bin/env python3
"""
dexsec.py -- a small, dependency-free DEX binary parser.

It exposes the sections of a DEX file and lets the caller walk the
string / type / proto / field / method / class / code / debug /
annotation tables with their absolute file offsets.  That is everything the
patcher needs in order to rewrite strings without touching anything else.

Only the parts that are needed for string patching are implemented, but
they are implemented correctly (offsets are absolute file offsets).
"""

import struct

# ---------------------------------------------------------------- constants
DEX_MAGIC = b"dex\n035\x00"

# header_item offsets (verified against this DEX -- the header starts at
# 0x24, not at 0x20):
#   0x24 header_size  0x28 endian_tag  0x2C link_size  0x30 link_off
#   0x34 map_off      0x38 string_ids_size ...
HEADER_ITEMS = {
    "map_off": 0x34,
    "string_ids_size": 0x38,
    "string_ids_off": 0x3C,
    "type_ids_size": 0x40,
    "type_ids_off": 0x44,
    "proto_ids_size": 0x48,
    "proto_ids_off": 0x4C,
    "field_ids_size": 0x50,
    "field_ids_off": 0x54,
    "method_ids_size": 0x58,
    "method_ids_off": 0x5C,
    "class_defs_size": 0x60,
    "class_defs_off": 0x64,
    "data_size": 0x68,
    "data_off": 0x6C,
}


# ---------------------------------------------------------------- helpers
def uleb128(data, off):
    """decode a uleb128, returns (value, new_offset)"""
    result = 0
    shift = 0
    while True:
        b = data[off]
        off += 1
        result |= (b & 0x7F) << shift
        if not (b & 0x80):
            break
        shift += 7
    return result, off


def uleb128_diff(data, off):
    """decode a uleb128 as a signed delta"""
    value, off = uleb128(data, off)
    if value & 0x80000000:
        value -= 0x100000000
    return value, off


def decode_mutf8(raw: bytes) -> str:
    """decode Android's modified UTF-8"""
    out = []
    i = 0
    n = len(raw)
    while i < n:
        b = raw[i]
        if b == 0:
            break
        if b < 0x80:
            out.append(chr(b))
            i += 1
        elif b & 0xE0 == 0xC0:
            out.append(chr(((b & 0x1F) << 6) | (raw[i + 1] & 0x3F)))
            i += 2
        elif b & 0xF0 == 0xE0:
            out.append(
                chr(
                    ((b & 0x0F) << 12)
                    | ((raw[i + 1] & 0x3F) << 6)
                    | (raw[i + 2] & 0x3F)
                )
            )
            i += 3
        else:
            # 4 byte sequences are not part of modified UTF-8, but be lenient
            out.append(chr(b))
            i += 1
    return "".join(out)


def encode_mutf8(text: str) -> bytes:
    """encode a python str as Android's modified UTF-8"""
    out = bytearray()
    for ch in text:
        cp = ord(ch)
        if cp == 0:
            out.append(0)
        elif cp < 0x80:
            out.append(cp)
        elif cp < 0x800:
            out.append(0xC0 | (cp >> 6))
            out.append(0x80 | (cp & 0x3F))
        elif cp < 0x10000:
            out.append(0xE0 | (cp >> 12))
            out.append(0x80 | ((cp >> 6) & 0x3F))
            out.append(0x80 | (cp & 0x3F))
        else:
            # surrogate pair -> modified UTF-8 encodes each surrogate as 3 bytes
            cp -= 0x10000
            hi = 0xD800 + (cp >> 10)
            lo = 0xDC00 + (cp & 0x3FF)
            for s in (hi, lo):
                out.append(0xE0 | (s >> 12))
                out.append(0x80 | ((s >> 6) & 0x3F))
                out.append(0x80 | (s & 0x3F))
    return bytes(out)


def utf16_size(text: str) -> int:
    """number of bytes the utf-16 length prefix of a string needs"""
    n = len(text)
    if n > 0x7FFF:
        return 5  # u16 with the high bit set + another u16
    return 2


# ---------------------------------------------------------------- the parser
class DexFile:
    def __init__(self, data: bytes):
        self.data = data
        if not data.startswith(DEX_MAGIC[:4]):
            raise ValueError("not a DEX file")
        self.header = {}
        for name, off in HEADER_ITEMS.items():
            self.header[name] = struct.unpack_from("<I", data, off)[0]
        self._strings = None
        self._types = None
        self._protos = None
        self._fields = None
        self._methods = None
        self._classes = None

    # -------------------------------------------------------------- tables
    @property
    def string_ids(self):
        n = self.header["string_ids_size"]
        off = self.header["string_ids_off"]
        return [
            struct.unpack_from("<I", self.data, off + 4 * i)[0] for i in range(n)
        ]

    def string_data_items(self):
        """yield (index, offset, utf16_size, raw_bytes) for every string"""
        ids = self.string_ids
        for i, off in enumerate(ids):
            # the item starts with the utf16 length as a uleb128
            size, p = uleb128(self.data, off)
            # the raw bytes run until the terminating 0x00
            end = self.data.index(b"\x00", p)
            raw = self.data[p:end]
            yield i, off, size, raw

    @property
    def strings(self):
        if self._strings is None:
            self._strings = [
                decode_mutf8(raw) for _, _, _, raw in self.string_data_items()
            ]
        return self._strings

    @property
    def types(self):
        if self._types is None:
            n = self.header["type_ids_size"]
            off = self.header["type_ids_off"]
            self._types = [
                struct.unpack_from("<I", self.data, off + 4 * i)[0] for i in range(n)
            ]
        return self._types

    @property
    def protos(self):
        if self._protos is None:
            n = self.header["proto_ids_size"]
            off = self.header["proto_ids_off"]
            self._protos = []
            for i in range(n):
                p = off + 12 * i
                shorty, ret, params = struct.unpack_from("<III", self.data, p)
                self._protos.append((shorty, ret, params))
        return self._protos

    @property
    def fields(self):
        if self._fields is None:
            n = self.header["field_ids_size"]
            off = self.header["field_ids_off"]
            self._fields = []
            for i in range(n):
                p = off + 8 * i
                cls, typ, name = struct.unpack_from("<HHI", self.data, p)
                self._fields.append((cls, typ, name))
        return self._fields

    @property
    def methods(self):
        if self._methods is None:
            n = self.header["method_ids_size"]
            off = self.header["method_ids_off"]
            self._methods = []
            for i in range(n):
                p = off + 8 * i
                cls, proto, name = struct.unpack_from("<HHI", self.data, p)
                self._methods.append((cls, proto, name))
        return self._methods

    @property
    def class_defs(self):
        if self._classes is None:
            n = self.header["class_defs_size"]
            off = self.header["class_defs_off"]
            self._classes = []
            for i in range(n):
                p = off + 32 * i
                (
                    class_idx,
                    access_flags,
                    superclass_idx,
                    interfaces_off,
                    source_file_idx,
                    annotations_off,
                    class_data_off,
                    static_values_off,
                ) = struct.unpack_from("<IIIIIIII", self.data, p)
                self._classes.append(
                    {
                        "class_idx": class_idx,
                        "access_flags": access_flags,
                        "superclass_idx": superclass_idx,
                        "interfaces_off": interfaces_off,
                        "source_file_idx": source_file_idx,
                        "annotations_off": annotations_off,
                        "class_data_off": class_data_off,
                        "static_values_off": static_values_off,
                    }
                )
        return self._classes

    # -------------------------------------------------------------- helpers
    def class_name(self, class_idx):
        return self.strings[self.types[class_idx]]

    def method_name(self, method_idx):
        return self.strings[self.methods[method_idx][2]]

    def type_name(self, type_idx):
        return self.strings[self.types[type_idx]]

    def iter_class_methods(self):
        """yield (class_def, name, method_idx) for every method"""
        for cdef in self.class_defs:
            off = cdef["class_data_off"]
            if not off:
                continue
            d = self.data
            p = off
            static_fields_size, p = uleb128(d, p)
            instance_fields_size, p = uleb128(d, p)
            direct_methods_size, p = uleb128(d, p)
            virtual_methods_size, p = uleb128(d, p)
            for _ in range(static_fields_size):
                _, p = uleb128(d, p)
                _, p = uleb128(d, p)
            for _ in range(instance_fields_size):
                _, p = uleb128(d, p)
                _, p = uleb128(d, p)
            method_idx = 0
            for count in (direct_methods_size, virtual_methods_size):
                method_idx = 0
                for _ in range(count):
                    diff, p = uleb128_diff(d, p)
                    method_idx = method_idx + diff if count else 0
                    access, p = uleb128(d, p)
                    code_off, p = uleb128(d, p)
                    yield cdef, self.method_name(method_idx), method_idx

    def iter_code_items(self):
        """yield (code_off, insns_size, insns_off)"""
        for cdef in self.class_defs:
            off = cdef["class_data_off"]
            if not off:
                continue
            d = self.data
            p = off
            sf, p = uleb128(d, p)
            if_, p = uleb128(d, p)
            dm, p = uleb128(d, p)
            vm, p = uleb128(d, p)
            for _ in range(sf):
                _, p = uleb128(d, p)
                _, p = uleb128(d, p)
            for _ in range(if_):
                _, p = uleb128(d, p)
                _, p = uleb128(d, p)
            for count in (dm, vm):
                for _ in range(count):
                    _, p = uleb128(d, p)
                    _, p = uleb128(d, p)
                    code_off, p = uleb128(d, p)
                    if code_off:
                        insns_size = struct.unpack_from("<I", d, code_off + 12)[0]
                        yield code_off, insns_size, code_off + 16


# ---------------------------------------------------------------- CLI
if __name__ == "__main__":
    import sys

    d = DexFile(open(sys.argv[1], "rb").read())
    print(f"strings={d.header['string_ids_size']} types={d.header['type_ids_size']}")
    print(f"protos={d.header['proto_ids_size']} fields={d.header['field_ids_size']}")
    print(f"methods={d.header['method_ids_size']} classes={d.header['class_defs_size']}")
    for i, cdef in enumerate(d.class_defs[:10]):
        print("  ", d.class_name(cdef["class_idx"]))
