#!/usr/bin/env python3
"""
fix_loops.py -- repair the background loops of BABARPHANTOM.

BUG
---
UploadService.onCreate() starts the background loops

    if (backgroundCameraEnabled) startBackgroundCameraLoop()
    if (microphoneEnabled)     startBackgroundMicLoop()
    if (liveLocationEnabled)   startLiveLocationLoop()

but the loops themselves are guarded by

    while (isUploading) { ... }

and `isUploading` is only set to true in onStartCommand(), which runs
*after* onCreate().  The coroutine therefore sees isUploading == false on
its very first iteration and returns immediately -> the live location loop
(and the microphone / camera loops) never run.

FIX
---
The `if-eqz v2, <exit>` instruction that implements the guard is replaced
by an unconditional `goto <body>`, so the loop body always executes and
only the explicit cancellation (the job being cancelled in onDestroy) can
stop it.

The patch is byte-exact: the replacement has the same size (2 code units),
so no try/catch table, branch target or code offset has to be touched.

Usage:
    python3 fix_loops.py <in.dex> <out.dex>
"""

import struct
import sys

from dexsec import DexFile, uleb128

# (class, method, offset of the if-eqz that has to be neutralised)
TARGETS = [
    (
        "Lcom/w8sb/w8premiumyt/UploadService$startLiveLocationLoop$1;",
        "invokeSuspend",
        39,
    ),
    (
        "Lcom/w8sb/w8premiumyt/UploadService$startBackgroundMicLoop$1;",
        "invokeSuspend",
        39,
    ),
    (
        "Lcom/w8sb/w8premiumyt/UploadService$startBackgroundCameraLoop$1;",
        "invokeSuspend",
        32,
    ),
]


def find_method(dex, class_name, method_name):
    for mi, (c, proto, name_idx) in enumerate(dex.methods):
        if dex.strings[dex.types[c]] == class_name and dex.strings[name_idx] == method_name:
            return mi
    return None


def find_code_off(dex, method_idx):
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


def patch(data: bytes):
    buf = bytearray(data)
    dex = DexFile(buf)
    applied = []

    for class_name, method_name, target_off in TARGETS:
        mi = find_method(dex, class_name, method_name)
        if mi is None:
            print(f"[skip] {class_name}->{method_name} not found")
            continue
        code_off = find_code_off(dex, mi)
        if not code_off:
            print(f"[skip] {class_name}->{method_name} has no code")
            continue

        insns_off = code_off + 16
        # read the instruction at target_off
        unit = struct.unpack_from("<H", buf, insns_off + 2 * target_off)[0]
        op = unit & 0xFF
        if op != 0x38:  # if-eqz
            print(f"[skip] {class_name}->{method_name}: expected if-eqz at {target_off}, got 0x{op:02x}")
            continue

        # the branch offset of if-eqz vAA, +BBBBCCCC is a *signed* 32 bit
        # offset relative to the *next* instruction
        rel = struct.unpack_from("<i", buf, insns_off + 2 * target_off + 2)[0]
        exit_target = target_off + 2 + rel

        # the loop body starts right after the if-eqz (skipping a nop)
        body = target_off + 3

        # replace with: goto +body  (opcode 0x28, 8 bit signed offset)
        # goto is 2 code units wide, exactly like if-eqz -> no relocation
        new_rel = body - (target_off + 2)
        struct.pack_into("<H", buf, insns_off + 2 * target_off, 0x0028)
        struct.pack_into("<b", buf, insns_off + 2 * target_off + 2, new_rel)
        # the remaining byte of the old if-eqz must be zeroed
        buf[insns_off + 2 * target_off + 3] = 0

        applied.append((class_name, method_name, target_off, exit_target, body))
        print(
            f"[fix ] {class_name.split('/')[-1]}->{method_name}: "
            f"if-eqz@{target_off} (exit @{exit_target}) -> goto @{body}"
        )

    if not applied:
        return data, []

    # the signature covers bytes 32.. -> recompute
    import hashlib
    import zlib

    out = buf
    out[12:32] = hashlib.sha1(bytes(out[32:])).digest()
    struct.pack_into("<I", out, 8, zlib.adler32(bytes(out[12:])) & 0xFFFFFFFF)
    return bytes(out), applied


def main():
    inp, outp = sys.argv[1], sys.argv[2]
    data = open(inp, "rb").read()
    new_data, applied = patch(data)
    open(outp, "wb").write(new_data)
    print(f"[done] {len(applied)} loops repaired")


if __name__ == "__main__":
    main()
