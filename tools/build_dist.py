#!/usr/bin/env python3
"""بازسازی پکیج‌های توزیع BABARPHANTOM (HOST_READY / Central_v2 / PATCH)."""
import pathlib, zipfile, shutil, sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
CENTRAL = ROOT / "central"
DIST = ROOT / "dist"

EXCLUDE_NAMES = {"config.php"}
EXCLUDE_DIRS = {"__pycache__"}

PATCH_FILES = [
    ("central/public_html/admin/delivery_builder.php", "public_html/admin/delivery_builder.php"),
    ("central/public_html/admin/apk.php", "public_html/admin/apk.php"),
    ("central/public_html/admin/apk_upload.php", "public_html/admin/apk_upload.php"),
    ("central/public_html/assets/apk-upload.js", "public_html/assets/apk-upload.js"),
    ("central/private/security/helpers.php", "private/security/helpers.php"),
]


def iter_files(base: pathlib.Path, skip_zip_apk=True):
    for p in sorted(base.rglob("*")):
        if p.is_dir():
            continue
        if any(part in EXCLUDE_DIRS for part in p.parts):
            continue
        if p.name in EXCLUDE_NAMES:
            continue
        if skip_zip_apk and p.suffix.lower() in {".apk", ".zip"}:
            continue
        yield p


def build_host_ready():
    out = DIST / "BABARPHANTOM_HOST_READY.zip"
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for p in iter_files(CENTRAL / "public_html"):
            z.write(p, str(p.relative_to(CENTRAL)))
        for p in iter_files(CENTRAL / "private"):
            z.write(p, str(p.relative_to(CENTRAL)))
        z.writestr("README_UPLOAD.txt",
                   "این زیپ را در Home هاست (/home/USERNAME) آپلود و Extract کن — ساختار public_html و private خودکار ساخته می‌شود.\n"
                   "سپس https://دامنه/install.php را باز کن.\n")
        readme = CENTRAL / "README.md"
        if readme.exists():
            z.write(readme, "README.md")
    return out


def build_central(zip_name_inside="BABARPHANTOM_Central_v2"):
    out = DIST / "BABARPHANTOM_Central_v2.zip"
    dirs = [
        "public_html/assets/", "public_html/assets/js/", "public_html/templates/",
        "private/apk/", "private/uploads/", "private/uploads/incoming/", "private/backups/",
        "private/logs/", "private/telegram_tokens/", "private/templates/",
        "private/config/", "private/database/", "private/security/", "private/telegram/",
        "private/scripts/", "public_html/api/", "public_html/admin/",
    ]
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for d in dirs:
            zi = zipfile.ZipInfo(f"{zip_name_inside}/{d}")
            zi.external_attr = 0o755 << 16
            z.writestr(zi, "")
        for p in iter_files(CENTRAL):
            z.write(p, f"{zip_name_inside}/" + str(p.relative_to(CENTRAL)))
    return out


def build_patch():
    out = DIST / "PATCH_APK_UPLOAD_ONLY.zip"
    with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
        for src, dst in PATCH_FILES:
            path = ROOT / src
            if not path.exists():
                print("!! missing", src, file=sys.stderr)
                continue
            z.write(path, dst)
        z.writestr("README.txt",
                   "PATCH APK UPLOAD — 2026-11-18\n\n"
                   "در /home/USERNAME آپلود و Extract کن (Overwrite):\n"
                   "  public_html/admin/delivery_builder.php\n"
                   "  public_html/admin/apk.php\n"
                   "  public_html/admin/apk_upload.php   <-- جدید: آپلود تکه‌تکه\n"
                   "  public_html/assets/apk-upload.js   <-- جدید\n"
                   "  private/security/helpers.php\n\n"
                   "سپس: chmod 0775 private/apk private/logs private/uploads\n"
                   "و private/uploads/incoming را بساز (0775) اگر نبود.\n")
    return out


if __name__ == "__main__":
    DIST.mkdir(exist_ok=True)
    for builder in (build_host_ready, build_central, build_patch):
        p = builder()
        with zipfile.ZipFile(p) as z:
            print(f"{p.name}: {len(z.namelist())} entries, {p.stat().st_size} bytes")
        shutil.copy(p, ROOT / p.name)
