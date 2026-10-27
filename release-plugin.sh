#!/usr/bin/env bash
#
# Rilis plugin Akar Proxy SEO.
#
#   ./release-plugin.sh                 # bangun zip + manifest (belum dipublikasikan)
#   ./release-plugin.sh --publish       # + salin ke docs/ (sumber GitHub Pages)
#
# Yang dilakukan:
#   1. Cek versi konsisten (header plugin = konstanta = readme Stable tag).
#   2. Bangun akar-proxy-seo.zip HANYA dari folder akar-proxy-seo/.
#   3. Cetak md5 + sha256 + daftar isi zip.
#   4. Bangun manifest JSON dari readme.txt (lihat build-manifest.py).
#   5. Dengan --publish: salin zip + manifest ke docs/ supaya ikut
#      ter-deploy GitHub Pages (sumber auto-update).
#
# Butuh: bash, zip, unzip, python3.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$HERE/akar-proxy-seo"
ZIP="$HERE/akar-proxy-seo.zip"
MANIFEST="$HERE/akar-proxy-seo.json"
PUBLISH_DIR="$HERE/docs"
PUBLISH=0

for arg in "$@"; do
  case "$arg" in
    --publish) PUBLISH=1 ;;
    -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "argumen tidak dikenal: $arg" >&2; exit 2 ;;
  esac
done

for bin in zip unzip python3 md5sum sha256sum; do
  command -v "$bin" >/dev/null 2>&1 || { echo "butuh '$bin' di PATH" >&2; exit 2; }
done

[ -f "$SRC/akar-proxy-seo.php" ] || { echo "sumber tidak ada: $SRC/akar-proxy-seo.php" >&2; exit 2; }

VERSION="$(sed -n "s/^ \* Version: //p" "$SRC/akar-proxy-seo.php" | head -1 | tr -d '[:space:]')"
CONST="$(sed -n "s/^define('AKAR_PROXY_SEO_VERSION', '\(.*\)');$/\1/p" "$SRC/akar-proxy-seo.php" | head -1)"
STABLE="$(sed -n 's/^Stable tag: //p' "$SRC/readme.txt" | head -1 | tr -d '[:space:]')"
HANDLERS="$(sed -n "s/.*'plugin_version'.*: *'\([0-9][0-9.]*\)'.*/\1/p" "$SRC/includes/handlers.php" | head -1)"

echo "versi header   : ${VERSION:-KOSONG}"
echo "versi konstanta: ${CONST:-KOSONG}"
echo "versi readme   : ${STABLE:-KOSONG}"
echo "fallback handler: ${HANDLERS:-KOSONG}"

[ -n "$VERSION" ] || { echo "GAGAL: header Version kosong" >&2; exit 1; }
[ "$VERSION" = "$CONST" ] || { echo "GAGAL: header vs konstanta beda" >&2; exit 1; }
[ "$VERSION" = "$STABLE" ] || { echo "GAGAL: header vs readme Stable tag beda" >&2; exit 1; }
if [ -n "$HANDLERS" ] && [ "$HANDLERS" != "$VERSION" ]; then
  echo "GAGAL: fallback plugin_version di handlers.php ($HANDLERS) != $VERSION" >&2
  exit 1
fi

rm -f "$ZIP" "$MANIFEST"
# -X: buang atribut tambahan (uid/gid) supaya zip tidak bergantung mesin.
# Pola exclude menjaga sampah editor tidak ikut ke situs pengguna.
(
  cd "$HERE"
  zip -r -X -q "$ZIP" "akar-proxy-seo" \
    -x "*/.*" "*/.DS_Store" "*/__MACOSX/*" "*.swp" "*.bak" "*.orig" "*/node_modules/*"
)

echo
echo "== isi zip =="
unzip -l "$ZIP"

echo
echo "== ukuran =="
stat -c '%s byte' "$ZIP"

echo
echo "== checksum =="
md5sum "$ZIP" | awk '{print "md5    : " $1}'
sha256sum "$ZIP" | awk '{print "sha256 : " $1}'

# Zip harus punya satu root folder akar-proxy-seo/ dan berisi 3 berkas inti.
ENTRIES="$(unzip -Z1 "$ZIP")"
for wajib in "akar-proxy-seo/akar-proxy-seo.php" "akar-proxy-seo/readme.txt" "akar-proxy-seo/includes/handlers.php" "akar-proxy-seo/includes/legacy-proxy.php"; do
  echo "$ENTRIES" | grep -qx "$wajib" || { echo "GAGAL: $wajib tidak ada di zip" >&2; exit 1; }
done
if echo "$ENTRIES" | grep -qv '^akar-proxy-seo/'; then
  echo "GAGAL: ada entri di luar folder akar-proxy-seo/" >&2
  exit 1
fi

echo
python3 "$HERE/build-manifest.py" \
  --plugin-dir "$SRC" \
  --zip "$ZIP" \
  --out "$MANIFEST"

if [ "$PUBLISH" -eq 1 ]; then
  mkdir -p "$PUBLISH_DIR"
  touch "$PUBLISH_DIR/.nojekyll"
  cp -f "$ZIP" "$PUBLISH_DIR/akar-proxy-seo.zip"
  cp -f "$MANIFEST" "$PUBLISH_DIR/akar-proxy-seo.json"
  echo
  echo "== dipublikasikan ke docs/ =="
  ls -l "$PUBLISH_DIR/akar-proxy-seo.json" "$PUBLISH_DIR/akar-proxy-seo.zip"
  echo "Langkah berikutnya:"
  echo "  git add docs && git commit -m \"rilis v$VERSION\" && git push"
  echo "GitHub Pages men-deploy otomatis; manifest live di:"
  echo "  https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.json"
fi

echo
echo "Selesai: $ZIP (v$VERSION)"
