#!/usr/bin/env python3
"""Bangun manifest auto-update dari sumber plugin (readme.txt + header).

Dipakai oleh release-plugin.sh. Manifest inilah yang dibaca blok auto-update
di akar-proxy-seo.php (butuh minimal `version` + `download_url`) dan dipakai
WordPress untuk menampilkan changelog di layar Plugins -> View details.

Satu sumber kebenaran: readme.txt. Changelog di manifest diubah dari bagian
`== Changelog ==` supaya rilis baru tidak perlu ditulis dua kali.
"""

import argparse
import hashlib
import json
import os
import re
import sys


def die(msg):
    sys.stderr.write("build-manifest: %s\n" % msg)
    sys.exit(1)


def read(path):
    if not os.path.isfile(path):
        die("berkas tidak ditemukan: %s" % path)
    with open(path, encoding="utf-8") as fh:
        return fh.read()


def header_field(text, field):
    """Ambil nilai field header plugin/readme ('Version: 1.5.0')."""
    m = re.search(r"^[ \t/*#]*%s:\s*(.+?)\s*$" % re.escape(field), text, re.M)
    return m.group(1) if m else ""


def section(text, name):
    """Ambil isi satu bagian readme ('== Changelog ==' ... bagian berikutnya)."""
    m = re.search(r"^==\s*%s\s*==\s*$(.*?)(?=^==\s|\Z)" % re.escape(name), text, re.M | re.S)
    return m.group(1) if m else ""


def inline_html(text):
    """Markdown mini readme -> HTML. Escape dulu, baru tandai."""
    text = text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    text = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", text)
    text = re.sub(r"`(.+?)`", r"<code>\1</code>", text)
    return text.strip()


def changelog_html(readme):
    body = section(readme, "Changelog")
    if not body.strip():
        return ""

    blocks = re.split(r"^=\s*(.+?)\s*=\s*$", body, flags=re.M)
    # blocks[0] = teks sebelum blok pertama, lalu pasangan (versi, isi).
    out = []
    for i in range(1, len(blocks) - 1, 2):
        version = blocks[i].strip()
        items = [
            line[2:].strip()
            for line in blocks[i + 1].splitlines()
            if line.startswith("* ")
        ]
        if not items:
            continue
        out.append("<h4>%s</h4><ul>%s</ul>" % (
            inline_html(version),
            "".join("<li>%s</li>" % inline_html(item) for item in items),
        ))

    return "".join(out)


def digest(path, algo):
    h = hashlib.new(algo)
    with open(path, "rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 256), b""):
            h.update(chunk)
    return h.hexdigest()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--plugin-dir", required=True, help="folder sumber akar-proxy-seo/")
    ap.add_argument("--zip", required=True, help="zip rilis yang baru dibangun")
    ap.add_argument("--out", required=True, help="tujuan manifest JSON")
    ap.add_argument("--homepage", default="https://adptra01.github.io/akar-proxy-seo/")
    ap.add_argument("--download-url", default="https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.zip")
    ap.add_argument("--tested", default="7.1")
    ap.add_argument("--date", default="")
    args = ap.parse_args()

    main_php = os.path.join(args.plugin_dir, "akar-proxy-seo.php")
    readme = os.path.join(args.plugin_dir, "readme.txt")
    php = read(main_php)
    txt = read(readme)

    version = header_field(php, "Version")
    if not version:
        die("header 'Version:' tidak ada di %s" % main_php)

    const = re.search(r"define\(\s*'AKAR_PROXY_SEO_VERSION'\s*,\s*'([^']+)'", php)
    if not const:
        die("konstanta AKAR_PROXY_SEO_VERSION tidak ada")
    if const.group(1) != version:
        die("Versi tidak konsisten: header %s vs konstanta %s" % (version, const.group(1)))

    stable = header_field(txt, "Stable tag")
    if stable != version:
        die("Versi tidak konsisten: header %s vs readme Stable tag %s" % (version, stable))

    if not os.path.isfile(args.zip):
        die("zip tidak ditemukan: %s" % args.zip)

    description = header_field(php, "Description") or header_field(txt, "Description")

    manifest = {
        "name": header_field(php, "Plugin Name") or "Akar Proxy SEO",
        "slug": "akar-proxy-seo",
        "version": version,
        "homepage": args.homepage,
        "download_url": args.download_url,
        "requires": header_field(txt, "Requires at least") or "5.6",
        "tested": args.tested,
        "requires_php": header_field(txt, "Requires PHP") or "7.4",
        "last_updated": args.date or __import__("datetime").date.today().isoformat(),
        "author": "Akar Solution",
        "description": description,
        "changelog": changelog_html(txt),
        # Tambahan informatif: dipakai untuk memverifikasi zip yang diunduh.
        "sha256": digest(args.zip, "sha256"),
        "md5": digest(args.zip, "md5"),
        "size": os.path.getsize(args.zip),
    }

    os.makedirs(os.path.dirname(os.path.abspath(args.out)), exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(manifest, fh, indent=2, ensure_ascii=False)
        fh.write("\n")

    print("manifest : %s" % args.out)
    print("version  : %s" % manifest["version"])
    print("size     : %d byte" % manifest["size"])
    print("md5      : %s" % manifest["md5"])
    print("sha256   : %s" % manifest["sha256"])


if __name__ == "__main__":
    main()
