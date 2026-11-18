#!/usr/bin/env python3
"""apk_audit.py — فهرست‌گیری محتوای APK برای مقایسهٔ نسخه اصلی و نسخهٔ فعلی.

بدون هیچ تغییر on-disk: فقط zip را می‌خواند و dex را در حافظه تجزیه می‌کند.
خروجی: JSON روی stdout (یا --out file).
"""
import zipfile, struct, json, sys, re, hashlib, os

def read_uleb128(b, o):
    r = 0; s = 0
    while True:
        x = b[o]; o += 1
        r |= (x & 0x7f) << s
        if not (x & 0x80):
            return r, o
        s += 7

def parse_dex(data):
    if data[:4] != b'dex\n':
        return None
    (string_ids_size, string_ids_off, type_ids_size, type_ids_off,
     proto_ids_size, proto_ids_off, field_ids_size, field_ids_off,
     method_ids_size, method_ids_off, class_defs_size, class_defs_off) = struct.unpack_from('<IIIIIIIIIIII', data, 0x38)

    def get_string(idx):
        off = struct.unpack_from('<I', data, string_ids_off + 4 * idx)[0]
        _, p = read_uleb128(data, off)          # utf16 length
        _, p = read_uleb128(data, p)            # utf8 length
        e = data.index(b'\x00', p)
        return data[p:e].decode('utf-8', 'replace')

    strings = [get_string(i) for i in range(string_ids_size)]
    types = [strings[struct.unpack_from('<I', data, type_ids_off + 4 * i)[0]] for i in range(type_ids_size)]
    classes = []
    for i in range(class_defs_size):
        cidx = struct.unpack_from('<I', data, class_defs_off + 32 * i)[0]
        d = types[cidx]
        classes.append(d[1:-1].replace('/', '.') if d.startswith('L') else d)
    return {'strings': strings, 'types': types, 'classes': classes,
            'n_methods': method_ids_size, 'n_fields': field_ids_size, 'n_strings': string_ids_size}

URL_RE = re.compile(r'(https?://[A-Za-z0-9._~:/?#\[\]@!$&\'()*+,;=%-]{4,})')
TG_RE = re.compile(r'\b\d{8,12}:[A-Za-z0-9_-]{30,40}\b')

def audit(path):
    z = zipfile.ZipFile(path)
    names = z.namelist()
    dex_files = sorted(n for n in names if n.endswith('.dex'))
    out = {'path': path, 'size': os.path.getsize(path),
           'sha256': hashlib.sha256(open(path, 'rb').read()).hexdigest(),
           'entries': len(names), 'dex_files': {}, 'assets': [n for n in names if n.startswith('assets/')][:60],
           'urls': set(), 'telegram_tokens': set(), 'classes': set(),
           'logo_json': None, 'manifest_size': None, 'arsc_size': None}
    for d in dex_files:
        info = parse_dex(z.read(d))
        if not info:
            continue
        out['dex_files'][d] = {'classes': len(info['classes']), 'methods': info['n_methods'],
                               'fields': info['n_fields'], 'strings': info['n_strings']}
        out['classes'] |= set(info['classes'])
        for s in info['strings']:
            for m in URL_RE.findall(s):
                out['urls'].add(m)
            for m in TG_RE.findall(s):
                out['telegram_tokens'].add(m)
    if 'assets/logo.json' in names:
        try:
            out['logo_json'] = json.loads(z.read('assets/logo.json'))
        except Exception:
            out['logo_json'] = z.read('assets/logo.json')[:400].decode('utf-8', 'replace')
    if 'AndroidManifest.xml' in names:
        out['manifest_size'] = z.getinfo('AndroidManifest.xml').file_size
    if 'resources.arsc' in names:
        out['arsc_size'] = z.getinfo('resources.arsc').file_size
    out['urls'] = sorted(out['urls'])
    out['telegram_tokens'] = sorted(out['telegram_tokens'])
    out['classes'] = sorted(out['classes'])
    return out

if __name__ == '__main__':
    files = sys.argv[1:] or ["BABARPHANTOM (1)_sign_sign.apk", "dist/BABARPHANTOM_FA.apk"]
    res = [audit(f) for f in files]
    if '--out' in files:
        files.remove('--out')
    print(json.dumps(res, ensure_ascii=False, indent=1))
