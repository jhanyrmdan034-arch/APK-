#!/usr/bin/env python3
"""
build_mapping.py -- regenerate tools/translation_fa.json from the *actual*
strings that live in the DEX files of the APK.

The previous mapping was built from grep output and had lost the first
character of many keys (and the emoji prefix of others).  This script
takes the canonical Persian translations and re-attaches them to the
exact string literals that are present in the DEX, using a tolerant
match:

  1. exact match
  2. the DEX string equals the key plus one leading character
  3. the DEX string ends with the key (emoji / prefix) and the match is
     unambiguous

Usage:
    python3 build_mapping.py [apk_explore_dir]
"""

import json
import os
import struct
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from dexsec import DexFile, decode_mutf8  # noqa: E402
from dexpatch import _read_string_data  # noqa: E402

MAPPING_PATH = os.path.join(HERE, "translation_fa.json")


def dex_strings(path):
    """all string literals of a dex file"""
    data = open(path, "rb").read()
    dex = DexFile(data)
    map_off = dex.header.get("map_off", 0)
    if not map_off:
        return list(dex.strings)
    count = struct.unpack_from("<I", data, map_off)[0]
    sd = None
    for i in range(count):
        p = map_off + 4 + 12 * i
        t, u, s, o = struct.unpack_from("<HHII", data, p)
        if t == 0x2002:
            sd = (s, o)
            break
    if not sd:
        return list(dex.strings)
    out = []
    cursor = sd[1]
    for _ in range(sd[0]):
        _, raw, length = _read_string_data(data, cursor)
        out.append(decode_mutf8(raw))
        cursor += length
    return out


def main():
    explore = sys.argv[1] if len(sys.argv) > 1 else "apk_explore"
    mapping = json.load(open(MAPPING_PATH, encoding="utf-8"))

    for dex_name in sorted(mapping):
        path = os.path.join(explore, dex_name)
        if not os.path.exists(path):
            print(f"!! {path} not found")
            continue
        texts = dex_strings(path)
        textset = set(texts)
        repl = mapping[dex_name]

        fixed = {}
        unresolved = []
        for key, value in repl.items():
            if key in textset:
                fixed[key] = value
                continue
            cands = [t for t in texts if t.endswith(key)]
            if len(cands) == 1:
                fixed[cands[0]] = value
            elif len(cands) > 1:
                unresolved.append((key, "ambiguous", cands))
            else:
                # the key lost its first character -> try to repair it
                cands = [t for t in texts if len(t) == len(key) + 1 and t[1:] == key]
                if len(cands) == 1:
                    fixed[cands[0]] = value
                else:
                    unresolved.append((key, "missing", cands))

        mapping[dex_name] = fixed
        print(f"[{dex_name}] {len(repl)} -> {len(fixed)} resolved")
        for key, kind, cands in unresolved:
            print(f"    [{kind}] {key!r}")

    with open(MAPPING_PATH, "w", encoding="utf-8") as fh:
        json.dump(mapping, fh, ensure_ascii=True, indent=2)
    print(f"written {MAPPING_PATH}")


if __name__ == "__main__":
    main()
