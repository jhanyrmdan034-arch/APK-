#!/usr/bin/env python3
"""
dexdis.py -- a minimal DEX bytecode disassembler.

It is only good enough to read the instruction stream of a method and to
locate invoke-* instructions that call a given method -- which is exactly
what the patcher needs to rewrite the control flow.

Usage:
    python3 dexdis.py <file.dex> <ClassName> <methodName>
"""

import struct
import sys

from dexsec import DexFile, uleb128

# opcode -> (name, format)
# format characters:
#   10n  20t  20bc  22c  21c  21s  21h  21c-t  30t  31i  31t  31c
#   32x  35c  3rc  45cc 4rcc 51l
OPCODES = {}


def _build_opcodes():
    def op(code, name, fmt):
        OPCODES[code] = (name, fmt)

    # format 10x
    for i, n in enumerate([
        "nop", "move", "move", "move", "move", "move", "move", "move",
        "move", "move", "move", "move", "move", "move", "move", "move",
        "move", "move", "move", "move", "move", "move", "move", "move",
    ]):
        pass
    simple = {
        0x00: "nop", 0x01: "move", 0x02: "move/from16", 0x03: "move/16",
        0x04: "move-wide", 0x05: "move-wide/from16", 0x06: "move-wide/16",
        0x07: "move-object", 0x08: "move-object/from16", 0x09: "move-object/16",
        0x0A: "move-result", 0x0B: "move-result-wide", 0x0C: "move-result-object",
        0x0D: "move-exception", 0x0E: "return-void", 0x0F: "return",
        0x10: "return-wide", 0x11: "return-object",
        0x12: "const/4", 0x13: "const/16", 0x14: "const", 0x15: "const/high16",
        0x16: "const-wide/16", 0x17: "const-wide/32", 0x18: "const-wide",
        0x19: "const-wide/high16",
        0x1A: "const-string", 0x1B: "const-string/jumbo",
        0x1C: "const-class",
        0x1D: "monitor-enter", 0x1E: "monitor-exit",
        0x1F: "check-cast", 0x20: "instance-of",
        0x21: "array-length", 0x22: "new-instance", 0x23: "new-array",
        0x24: "filled-new-array", 0x25: "filled-new-array/range",
        0x26: "fill-array-data",
        0x27: "throw",
        0x28: "goto", 0x29: "goto/16", 0x2A: "goto/32",
        0x2B: "packed-switch", 0x2C: "sparse-switch",
        0x2D: "cmpl-float", 0x2E: "cmpg-float", 0x2F: "cmpl-double",
        0x30: "cmpg-double", 0x31: "cmp-long",
        0x32: "if-eq", 0x33: "if-ne", 0x34: "if-lt", 0x35: "if-ge",
        0x36: "if-gt", 0x37: "if-le",
        0x38: "if-eqz", 0x39: "if-nez", 0x3A: "if-ltz", 0x3B: "if-gez",
        0x3C: "if-gtz", 0x3D: "if-lez",
        0x44: "aget", 0x45: "aget-wide", 0x46: "aget-object", 0x47: "aget-boolean",
        0x48: "aget-byte", 0x49: "aget-char", 0x4A: "aget-short",
        0x4B: "aput", 0x4C: "aput-wide", 0x4D: "aput-object", 0x4E: "aput-boolean",
        0x4F: "aput-byte", 0x50: "aput-char", 0x51: "aput-short",
        0x52: "iget", 0x53: "iget-wide", 0x54: "iget-object", 0x55: "iget-boolean",
        0x56: "iget-byte", 0x57: "iget-char", 0x58: "iget-short",
        0x59: "iput", 0x5A: "iput-wide", 0x5B: "iput-object", 0x5C: "iput-boolean",
        0x5D: "iput-byte", 0x5E: "iput-char", 0x5F: "iput-short",
        0x60: "sget", 0x61: "sget-wide", 0x62: "sget-object", 0x63: "sget-boolean",
        0x64: "sget-byte", 0x65: "sget-char", 0x66: "sget-short",
        0x67: "sput", 0x68: "sput-wide", 0x69: "sput-object", 0x6A: "sput-boolean",
        0x6B: "sput-byte", 0x6C: "sput-char", 0x6D: "sput-short",
        0x6E: "invoke-virtual", 0x6F: "invoke-super", 0x70: "invoke-direct",
        0x71: "invoke-static", 0x72: "invoke-interface",
        0x73: "invoke-virtual/range", 0x74: "invoke-super/range",
        0x75: "invoke-direct/range", 0x76: "invoke-static/range",
        0x77: "invoke-interface/range",
        0x78: "neg-int", 0x79: "not-int", 0x7A: "neg-long", 0x7B: "not-long",
        0x7C: "neg-float", 0x7D: "neg-double", 0x7E: "neg-int/lit8",
        0x7F: "not-int/lit8", 0x80: "not-long/lit8",
        0x81: "int-to-long", 0x82: "int-to-float", 0x83: "int-to-double",
        0x84: "long-to-int", 0x85: "long-to-float", 0x86: "long-to-double",
        0x87: "float-to-int", 0x88: "float-to-long", 0x89: "float-to-double",
        0x8A: "double-to-int", 0x8B: "double-to-long", 0x8C: "double-to-float",
        0x8D: "int-to-byte", 0x8E: "int-to-char", 0x8F: "int-to-short",
        0x90: "add-int", 0x91: "sub-int", 0x92: "mul-int", 0x93: "div-int",
        0x94: "rem-int", 0x95: "and-int", 0x96: "or-int", 0x97: "xor-int",
        0x98: "shl-int", 0x99: "shr-int", 0x9A: "ushr-int",
        0x9B: "add-long", 0x9C: "sub-long", 0x9D: "mul-long", 0x9E: "div-long",
        0x9F: "rem-long", 0xA0: "and-long", 0xA1: "or-long", 0xA2: "xor-long",
        0xA3: "shl-long", 0xA4: "shr-long", 0xA5: "ushr-long",
        0xA6: "add-float", 0xA7: "sub-float", 0xA8: "mul-float", 0xA9: "div-float",
        0xAA: "rem-float", 0xAB: "add-double", 0xAC: "sub-double",
        0xAD: "mul-double", 0xAE: "div-double", 0xAF: "rem-double",
        0xB0: "add-int/2addr", 0xB1: "sub-int/2addr", 0xB2: "mul-int/2addr",
        0xB3: "div-int/2addr", 0xB4: "rem-int/2addr", 0xB5: "and-int/2addr",
        0xB6: "or-int/2addr", 0xB7: "xor-int/2addr", 0xB8: "shl-int/2addr",
        0xB9: "shr-int/2addr", 0xBA: "ushr-int/2addr",
        0xBB: "add-long/2addr", 0xBC: "sub-long/2addr", 0xBD: "mul-long/2addr",
        0xBE: "div-long/2addr", 0xBF: "rem-long/2addr", 0xC0: "and-long/2addr",
        0xC1: "or-long/2addr", 0xC2: "xor-long/2addr", 0xC3: "shl-long/2addr",
        0xC4: "shr-long/2addr", 0xC5: "ushr-long/2addr",
        0xC6: "add-float/2addr", 0xC7: "sub-float/2addr", 0xC8: "mul-float/2addr",
        0xC9: "div-float/2addr", 0xCA: "rem-float/2addr",
        0xCB: "add-double/2addr", 0xCC: "sub-double/2addr", 0xCD: "mul-double/2addr",
        0xCE: "div-double/2addr", 0xCF: "rem-double/2addr",
        0xD0: "add-int/lit16", 0xD1: "rsub-int", 0xD2: "mul-int/lit16",
        0xD3: "div-int/lit16", 0xD4: "rem-int/lit16", 0xD5: "and-int/lit16",
        0xD6: "or-int/lit16", 0xD7: "xor-int/lit16", 0xD8: "add-int/lit8",
        0xD9: "rsub-int/lit8", 0xDA: "mul-int/lit8", 0xDB: "div-int/lit8",
        0xDC: "rem-int/lit8", 0xDD: "and-int/lit8", 0xDE: "or-int/lit8",
        0xDF: "xor-int/lit8", 0xE0: "shl-int/lit8", 0xE1: "shr-int/lit8",
        0xE2: "ushr-int/lit8",
        0xFA: "invoke-polymorphic", 0xFB: "invoke-polymorphic/range",
        0xFC: "invoke-custom", 0xFD: "invoke-custom/range",
        0xFE: "const-method-handle", 0xFF: "const-method-type",
    }
    OPCODES.update(simple)


_build_opcodes()

# instruction length in code units for each opcode
INSN_UNITS = {}


def _build_units():
    one = set(range(0x00, 0x0E)) | {0x0E, 0x0F, 0x10, 0x11, 0x12, 0x1D, 0x1E, 0x21, 0x27, 0x7E, 0x7F, 0x80, 0x81, 0x82, 0x83, 0x84, 0x85, 0x86, 0x87, 0x88, 0x89, 0x8A, 0x8B, 0x8C, 0x8D, 0x8E, 0x8F, 0x90, 0x91, 0x92, 0x93, 0x94, 0x95, 0x96, 0x97, 0x98, 0x99, 0x9A, 0x9B, 0x9C, 0x9D, 0x9E, 0x9F, 0xA0, 0xA1, 0xA2, 0xA3, 0xA4, 0xA5, 0xA6, 0xA7, 0xA8, 0xA9, 0xAA, 0xAB, 0xAC, 0xAD, 0xAE, 0xAF, 0xB0, 0xB1, 0xB2, 0xB3, 0xB4, 0xB5, 0xB6, 0xB7, 0xB8, 0xB9, 0xBA, 0xBB, 0xBC, 0xBD, 0xBE, 0xBF, 0xC0, 0xC1, 0xC2, 0xC3, 0xC4, 0xC5, 0xC6, 0xC7, 0xC8, 0xC9, 0xCA, 0xCB, 0xCC, 0xCD, 0xCE, 0xCF}
    for o in one:
        INSN_UNITS[o] = 1
    for o in list(range(0x13, 0x20)) + list(range(0x22, 0x28)):
        INSN_UNITS[o] = 2
    INSN_UNITS[0x14] = 3
    INSN_UNITS[0x17] = 3
    INSN_UNITS[0x18] = 5
    INSN_UNITS[0x1B] = 3
    for o in (0x28,):
        INSN_UNITS[o] = 1
    INSN_UNITS[0x29] = 2
    INSN_UNITS[0x2A] = 3
    INSN_UNITS[0x2B] = 3
    INSN_UNITS[0x2C] = 3
    for o in range(0x2D, 0x32):
        INSN_UNITS[o] = 2
    for o in range(0x32, 0x3E):
        INSN_UNITS[o] = 2
    for o in range(0x44, 0x6E):
        INSN_UNITS[o] = 2
    for o in range(0x6E, 0x73):
        INSN_UNITS[o] = 3
    for o in range(0x73, 0x78):
        INSN_UNITS[o] = 3
    for o in range(0x90, 0xD0):
        INSN_UNITS[o] = 2
    for o in range(0xD0, 0xD8):
        INSN_UNITS[o] = 2
    for o in range(0xD8, 0xE3):
        INSN_UNITS[o] = 2
    INSN_UNITS[0xFA] = 4
    INSN_UNITS[0xFB] = 4
    INSN_UNITS[0xFC] = 3
    INSN_UNITS[0xFD] = 3
    INSN_UNITS[0xFE] = 2
    INSN_UNITS[0xFF] = 2


_build_units()


def method_ref_name(dex, method_idx):
    cls, proto, name_idx = dex.methods[method_idx]
    return dex.strings[dex.types[cls]], dex.strings[name_idx]


def disassemble(dex, code_off, insns_size):
    """yield (offset_in_code_units, opcode, name, raw_bytes)"""
    data = dex.data
    base = code_off + 16
    pos = 0
    total = insns_size
    while pos < total:
        off = pos
        unit = struct.unpack_from("<H", data, base + 2 * pos)[0]
        op = unit & 0xFF
        name = OPCODES.get(op, f"op_{op:02x}")
        size = INSN_UNITS.get(op, 1)
        raw = data[base + 2 * pos : base + 2 * min(pos + size, total)]
        yield off, op, name, raw
        pos += size


def find_method(dex, class_name, method_name):
    for mi, (c, proto, name_idx) in enumerate(dex.methods):
        if dex.strings[dex.types[c]] == class_name and dex.strings[name_idx] == method_name:
            return mi
    return None


def find_code_off(dex, method_idx):
    """walk every class_data to find the code offset of *method_idx*"""
    data = dex.data
    for cdef in dex.class_defs:
        off = cdef["class_data_off"]
        if not off:
            continue
        p = off
        sf, p = uleb128(data, p)
        ifs, p = uleb128(data, p)
        dm, p = uleb128(data, p)
        vm, p = uleb128(data, p)
        for _ in range(sf):
            _, p = uleb128(data, p)
            _, p = uleb128(data, p)
        for _ in range(ifs):
            _, p = uleb128(data, p)
            _, p = uleb128(data, p)
        mi = 0
        for cnt in (dm, vm):
            mi = 0
            for _ in range(cnt):
                diff, p = uleb128(data, p)
                mi = mi + diff
                _, p = uleb128(data, p)
                code_off, p = uleb128(data, p)
                if mi == method_idx:
                    return code_off
    return None


def main():
    path, class_name, method_name = sys.argv[1], sys.argv[2], sys.argv[3]
    dex = DexFile(open(path, "rb").read())
    mi = find_method(dex, class_name, method_name)
    if mi is None:
        print("method not found")
        sys.exit(1)
    code_off = find_code_off(dex, mi)
    insns_size = struct.unpack_from("<I", dex.data, code_off + 12)[0]
    print(f"# {class_name}->{method_name} mi={mi} code_off={code_off} insns={insns_size}")
    for off, op, name, raw in disassemble(dex, code_off, insns_size):
        extra = ""
        if name.startswith("invoke"):
            # 35c / 3rc
            if op in (0x6E, 0x6F, 0x70, 0x71, 0x72):
                midx = struct.unpack_from("<H", raw, 2)[0]
                cn, mn = method_ref_name(dex, midx)
                extra = f"  // {cn}->{mn}"
            elif op in (0x73, 0x74, 0x75, 0x76, 0x77):
                midx = struct.unpack_from("<H", raw, 2)[0]
                cn, mn = method_ref_name(dex, midx)
                extra = f"  // {cn}->{mn}"
            elif op in (0xFA, 0xFB):
                extra = "  // polymorphic"
        elif name.startswith("const-string"):
            idx = struct.unpack_from("<H", raw, 2)[0]
            extra = f'  // "{dex.strings[idx][:60]}"'
        elif name.startswith("sget") or name.startswith("sput"):
            idx = struct.unpack_from("<H", raw, 2)[0]
            extra = f"  // {dex.strings[dex.fields[idx][2]]}"
        elif name.startswith("iget") or name.startswith("iput"):
            idx = struct.unpack_from("<H", raw, 2)[0]
            extra = f"  // {dex.strings[dex.fields[idx][2]]}"
        print(f"  {off:5}  {name:24} {raw.hex(' ')}{extra}")


if __name__ == "__main__":
    main()
