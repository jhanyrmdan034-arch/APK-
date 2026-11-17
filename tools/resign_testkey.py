#!/usr/bin/env python3
"""
Resign an APK with the AOSP testkey (same as the original BABARPHANTOM APK)
and produce a v1+v2+v3 signed output, so the fingerprint matches the
original and Android 7+ accepts the v2 block.

Usage:
    python3 tools/resign_testkey.py <in.apk> <out.apk> --key /tmp/aosp/testkey.pem --cert /tmp/aosp/testkey.x509.pem
"""
import argparse
import base64
import hashlib
import os
import sys
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

# v1 signing helpers (copied from apk_sign.py but with external cert)
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.serialization import pkcs7

def sign_v1(in_apk: str, out_apk: str, key_pem: str, cert_pem: str, alias: str = "ANDROID"):
    with open(key_pem, "rb") as fh:
        key = serialization.load_pem_private_key(fh.read(), password=None)
    with open(cert_pem, "rb") as fh:
        cert = x509.load_pem_x509_certificate(fh.read())

    zin = zipfile.ZipFile(in_apk, "r")

    entries = [info for info in zin.infolist() if not info.filename.startswith("META-INF/")]

    manifest_lines = ["Manifest-Version: 1.0", "Created-By: 1.0 (Android apk signer)", ""]
    digests = {}
    for info in entries:
        data = zin.read(info.filename)
        digest = base64.b64encode(hashlib.sha256(data).digest()).decode()
        digests[info.filename] = digest
        manifest_lines.append(f"Name: {info.filename}")
        manifest_lines.append(f"SHA-256-Digest: {digest}")
        manifest_lines.append("")
    manifest_text = "\r\n".join(manifest_lines) + "\r\n"
    manifest_bytes = manifest_text.encode("utf-8")
    manifest_digest = base64.b64encode(hashlib.sha256(manifest_bytes).digest()).decode()

    sf_lines = ["Signature-Version: 1.0", f"SHA-256-Digest-Manifest: {manifest_digest}", "Created-By: 1.0 (Android apk signer)", ""]
    for info in entries:
        section = f"Name: {info.filename}\r\nSHA-256-Digest: {digests[info.filename]}\r\n"
        section_digest = base64.b64encode(hashlib.sha256(section.encode("utf-8")).digest()).decode()
        sf_lines.append(f"Name: {info.filename}")
        sf_lines.append(f"SHA-256-Digest: {section_digest}")
        sf_lines.append("")
    sf_text = "\r\n".join(sf_lines) + "\r\n"
    sf_bytes = sf_text.encode("utf-8")

    signature = (
        pkcs7.PKCS7SignatureBuilder()
        .set_data(sf_bytes)
        .add_signer(cert, key, hashes.SHA256())
        .sign(serialization.Encoding.DER, [pkcs7.PKCS7Options.Binary])
    )

    zout = zipfile.ZipFile(out_apk, "w", zipfile.ZIP_DEFLATED)
    zout.writestr("META-INF/MANIFEST.MF", manifest_bytes)
    zout.writestr(f"META-INF/{alias}.SF", sf_bytes)
    zout.writestr(f"META-INF/{alias}.RSA", signature)
    for info in zin.infolist():
        if info.filename.startswith("META-INF/"):
            continue
        data = zin.read(info.filename)
        ni = zipfile.ZipInfo(info.filename, date_time=info.date_time)
        ni.compress_type = info.compress_type
        ni.external_attr = info.external_attr
        zout.writestr(ni, data)
    zout.close()
    zin.close()
    print(f"[v1 ] signed {out_apk} with {cert_pem} (alias={alias})")


def sign_v2v3_preserve_v1(in_apk: str, out_apk: str, key_pem: str, cert_pem: str):
    """
    Add v2/v3 Signing Block while preserving existing v1 META-INF files.
    This is a patched version of sign_apk.signer.sign_apk that does NOT drop v1.
    """
    from sign_apk.keys import KeyMaterial
    from sign_apk import rezip, zipdata, v2sign, v3sign
    from sign_apk.signer import assemble_apk_signing_block, APK_SIGNATURE_SCHEME_V2_BLOCK_ID, APK_SIGNATURE_SCHEME_V3_BLOCK_ID
    from sign_apk.v2sign import build_v2_signature_scheme_block
    from sign_apk.v3sign import build_v3_signature_scheme_block

    # Load key material
    with open(key_pem, "rb") as f:
        private_key = serialization.load_pem_private_key(f.read(), password=None)
    with open(cert_pem, "rb") as f:
        certificate = x509.load_pem_x509_certificate(f.read())
    km = KeyMaterial(private_key=private_key, certificate=certificate)

    with open(in_apk, "rb") as f:
        data = f.read()

    sections = zipdata.find_eocd(data)

    # Keep v1 files -> empty drop set
    drop_names = set()
    data = rezip.rebuild_zip(data, sections.central_dir_offset, drop_names)

    sections = zipdata.find_eocd(data)
    before_central_dir = data[: sections.central_dir_offset]
    central_dir = data[sections.central_dir_offset : sections.central_dir_offset + sections.central_dir_size]

    signing_block_start_offset = len(before_central_dir)
    eocd_for_digest = zipdata.set_eocd_cd_offset(sections.eocd, signing_block_start_offset)
    content_sections = [before_central_dir, central_dir, eocd_for_digest]

    id_value_pairs = []

    v2_block_value, v2_digest, algo_id = build_v2_signature_scheme_block(
        km.private_key, km.certificate, content_sections, v3_signing_enabled=True
    )
    id_value_pairs.append((APK_SIGNATURE_SCHEME_V2_BLOCK_ID, v2_block_value))

    v3_block_value, v3_digest, algo_id = build_v3_signature_scheme_block(
        km.private_key, km.certificate, content_sections
    )
    id_value_pairs.append((APK_SIGNATURE_SCHEME_V3_BLOCK_ID, v3_block_value))

    signing_block = assemble_apk_signing_block(id_value_pairs)
    new_central_dir_offset = signing_block_start_offset + len(signing_block)
    final_eocd = zipdata.set_eocd_cd_offset(sections.eocd, new_central_dir_offset)

    with open(out_apk, "wb") as f:
        f.write(before_central_dir)
        f.write(signing_block)
        f.write(central_dir)
        f.write(final_eocd)

    print(f"[v2+v3] added Signing Block to {out_apk} ({len(signing_block)} bytes)")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("input", help="input APK (Persian, patched)")
    ap.add_argument("output", help="output APK")
    ap.add_argument("--key", required=True, help="PEM private key (testkey)")
    ap.add_argument("--cert", required=True, help="PEM certificate (testkey)")
    ap.add_argument("--alias", default="ANDROID", help="META-INF alias (default ANDROID to match original)")
    args = ap.parse_args()

    tmp = args.output + ".v1.tmp"
    sign_v1(args.input, tmp, args.key, args.cert, alias=args.alias)
    sign_v2v3_preserve_v1(tmp, args.output, args.key, args.cert)
    os.remove(tmp)
    print(f"[done] {args.output}")


if __name__ == "__main__":
    main()
