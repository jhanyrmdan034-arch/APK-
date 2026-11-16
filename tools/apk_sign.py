#!/usr/bin/env python3
"""
apk_sign.py -- sign an APK with a dedicated RSA key, without Java.

Implements JAR signing (APK Signature Scheme v1).  v1 is honoured by every
Android release and is the only scheme that can be produced without the
Android SDK build tools.

Usage:
    python3 apk_sign.py <in.apk> <out.apk> [--key key.pem]

If no key is given, a fresh 2048-bit RSA key is generated and stored
next to the output as <out.apk>.key.pem
"""

import base64
import hashlib
import os
import subprocess
import sys
import zipfile


def generate_key(path: str):
    """generate a fresh RSA-2048 private key in PEM format"""
    subprocess.run(
        ["openssl", "genrsa", "-out", path, "2048"],
        check=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    return path


def load_cert_and_key(key_path: str):
    """return (certificate_object, private_key_object)"""
    import datetime

    from cryptography import x509
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.x509.oid import NameOID

    with open(key_path, "rb") as fh:
        key = serialization.load_pem_private_key(fh.read(), password=None)

    subject = issuer = x509.Name(
        [
            x509.NameAttribute(NameOID.COMMON_NAME, "BABARPHANTOM Persian"),
            x509.NameAttribute(NameOID.ORGANIZATION_NAME, "Custom Build"),
        ]
    )
    now = datetime.datetime.utcnow()
    cert = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(issuer)
        .public_key(key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now)
        .not_valid_after(now + datetime.timedelta(days=3650))
        .sign(key, hashes.SHA256())
    )
    return cert, key


def sign_apk(in_apk: str, out_apk: str, key_path: str, alias: str = "babarsigner"):
    from cryptography.hazmat.primitives import hashes, serialization
    from cryptography.hazmat.primitives.serialization import pkcs7

    cert, key = load_cert_and_key(key_path)
    cert_der = cert.public_bytes(serialization.Encoding.DER)

    zin = zipfile.ZipFile(in_apk, "r")

    entries = [
        info
        for info in zin.infolist()
        if not info.filename.startswith("META-INF/")
    ]

    # ---- MANIFEST.MF ---------------------------------------------------
    manifest_lines = [
        "Manifest-Version: 1.0",
        "Created-By: 1.0 (Android apk signer)",
        "",
    ]
    digests = {}
    for info in entries:
        data = zin.read(info.filename)
        digest = base64.b64encode(hashlib.sha256(data).digest()).decode("ascii")
        digests[info.filename] = digest
        manifest_lines.append(f"Name: {info.filename}")
        manifest_lines.append(f"SHA-256-Digest: {digest}")
        manifest_lines.append("")

    manifest_text = "\r\n".join(manifest_lines) + "\r\n"
    manifest_bytes = manifest_text.encode("utf-8")
    manifest_digest = base64.b64encode(
        hashlib.sha256(manifest_bytes).digest()
    ).decode("ascii")

    # ---- CERT.SF -------------------------------------------------------
    sf_lines = [
        "Signature-Version: 1.0",
        f"SHA-256-Digest-Manifest: {manifest_digest}",
        "Created-By: 1.0 (Android apk signer)",
        "",
    ]
    for info in entries:
        section = f"Name: {info.filename}\r\nSHA-256-Digest: {digests[info.filename]}\r\n"
        section_digest = base64.b64encode(
            hashlib.sha256(section.encode("utf-8")).digest()
        ).decode("ascii")
        sf_lines.append(f"Name: {info.filename}")
        sf_lines.append(f"SHA-256-Digest: {section_digest}")
        sf_lines.append("")

    sf_text = "\r\n".join(sf_lines) + "\r\n"
    sf_bytes = sf_text.encode("utf-8")

    # ---- PKCS#7 signature ----------------------------------------------
    signature = (
        pkcs7.PKCS7SignatureBuilder()
        .set_data(sf_bytes)
        .add_signer(cert, key, hashes.SHA256())
        .sign(serialization.Encoding.DER, [pkcs7.PKCS7Options.Binary])
    )

    # ---- write the signed APK ------------------------------------------
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


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if len(args) < 2:
        print(__doc__)
        sys.exit(1)

    in_apk, out_apk = args[0], args[1]
    key_path = None
    if "--key" in sys.argv:
        key_path = sys.argv[sys.argv.index("--key") + 1]

    if key_path is None:
        key_path = out_apk + ".key.pem"
    if not os.path.exists(key_path):
        generate_key(key_path)
        print(f"[sign] generated new key: {key_path}")

    sign_apk(in_apk, out_apk, key_path)
    print(f"[sign] signed -> {out_apk}")


if __name__ == "__main__":
    main()
