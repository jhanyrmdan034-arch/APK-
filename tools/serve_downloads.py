#!/usr/bin/env python3
"""Simple download server with HTTP Range support (needed for reliable APK/ZIP downloads)."""
import os, sys, mimetypes, re
from http.server import HTTPServer, SimpleHTTPRequestHandler
from functools import partial

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "downloads")
ROOT = os.path.abspath(ROOT)

MIME = {
    ".apk": "application/vnd.android.package-archive",
    ".zip": "application/zip",
    ".md": "text/markdown; charset=utf-8",
    ".html": "text/html; charset=utf-8",
}


class Handler(SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header("Accept-Ranges", "bytes")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Access-Control-Allow-Origin", "*")
        super().end_headers()

    def guess_type(self, path):
        ext = os.path.splitext(str(path))[1].lower()
        return MIME.get(ext, mimetypes.guess_type(str(path))[0] or "application/octet-stream")

    def do_GET(self):
        rng = self.headers.get("Range")
        if not rng:
            return self._serve(False)
        m = re.match(r"bytes=(\d*)-(\d*)", rng.strip())
        rel = self.path.split("?")[0].lstrip("/") or "index.html"
        path = os.path.abspath(os.path.join(ROOT, rel))
        if not path.startswith(ROOT) or not os.path.isfile(path) or not m:
            return self._serve(False)
        size = os.path.getsize(path)
        start_s, end_s = m.group(1), m.group(2)
        if start_s == "":
            start = max(0, size - int(end_s or 0))
            end = size - 1
        else:
            start = int(start_s)
            end = int(end_s) if end_s else size - 1
        end = min(end, size - 1)
        if start > end:
            self.send_response(416)
            self.send_header("Content-Range", f"bytes */{size}")
            self.end_headers()
            return
        length = end - start + 1
        self.send_response(206)
        self.send_header("Content-Type", self.guess_type(path))
        self.send_header("Content-Range", f"bytes {start}-{end}/{size}")
        self.send_header("Content-Length", str(length))
        if self.path.lower().endswith((".apk", ".zip")):
            self.send_header("Content-Disposition", f'attachment; filename="{os.path.basename(path)}"')
        self.end_headers()
        with open(path, "rb") as f:
            f.seek(start)
            left = length
            while left > 0:
                chunk = f.read(min(262144, left))
                if not chunk:
                    break
                try:
                    self.wfile.write(chunk)
                except (BrokenPipeError, ConnectionResetError):
                    return
                left -= len(chunk)

    def _serve(self, _):
        rel = self.path.split("?")[0].lstrip("/") or "index.html"
        path = os.path.abspath(os.path.join(ROOT, rel))
        if path.startswith(ROOT) and os.path.isfile(path) and rel.lower().endswith((".apk", ".zip", ".md")):
            size = os.path.getsize(path)
            self.send_response(200)
            self.send_header("Content-Type", self.guess_type(path))
            self.send_header("Content-Length", str(size))
            self.send_header("Content-Disposition", f'attachment; filename="{os.path.basename(path)}"')
            self.end_headers()
            with open(path, "rb") as f:
                while True:
                    chunk = f.read(262144)
                    if not chunk:
                        break
                    try:
                        self.wfile.write(chunk)
                    except (BrokenPipeError, ConnectionResetError):
                        return
            return
        return SimpleHTTPRequestHandler.do_GET(self)

    def log_message(self, fmt, *args):
        sys.stderr.write("%s - %s\n" % (self.address_string(), fmt % args))


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 8000
    os.chdir(ROOT)
    srv = HTTPServer(("0.0.0.0", port), partial(Handler, directory=ROOT))
    print(f"serving {ROOT} on 0.0.0.0:{port}", flush=True)
    srv.serve_forever()
