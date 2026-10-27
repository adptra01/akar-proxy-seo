# Akar Proxy SEO

Plugin WordPress yang membuka REST API kecil (ber-key) untuk otomasi konten dari
n8n atau skrip lain: artikel, kategori/tag, meta Yoast SEO, dan featured image.

- **Versi:** 1.5.0
- **Butuh:** WordPress >= 5.6, PHP >= 7.4
- **Lisensi:** GPLv2 or later
- **Distribusi:** `https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.zip`
- **Manifest auto-update:** `https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.json`

Plugin ini **tidak** membutuhkan akses tulis ke root WordPress. Jalur utama
adalah REST API; file `wp-api-proxy.php` hanya dibuat bila root dapat ditulisi
dan berfungsi sebagai jalur kompatibilitas untuk klien lama.

## Pasang

1. Unduh `akar-proxy-seo.zip` (rilis terbaru ada di halaman repo ini, atau dari
   URL distribusi di atas).
2. **Plugins -> Add New -> Upload Plugin** -> pilih zip -> **Install Now** ->
   **Activate**.
3. Buka menu **Akar Proxy SEO** di sidebar admin. API key dibuat otomatis saat
   aktivasi; salin dari halaman itu (bisa juga di-generate ulang di sana).

Setelah versi 1.5.0 terpasang, pembaruan berikutnya muncul seperti plugin
WordPress.org: notifikasi di **Dashboard -> Updates**, plus auto-update latar
belakang khusus plugin ini.

## Endpoint

Namespace: `/wp-json/akar-proxy-seo/v1`

| Method | Route | Keterangan |
|---|---|---|
| GET | `/ping` | Health check publik (tanpa key), balasan `{ok:true, service}`. |
| GET | `/status` | Diagnosa: versi WP/PHP/plugin, waktu server. |
| GET | `/posts?page=1&per_page=10` | Daftar artikel semua status (maks 50/halaman). |
| POST | `/posts` | Buat artikel: `{title*, content, excerpt, slug, status, categories:[id], tags:[nama], featured_image_url}`. |
| GET | `/terms?taxonomy=category` | Daftar term: `{data:[{id,name,slug,count}]}`. |
| GET | `/seo/{post_id}` | Baca meta Yoast. |
| POST | `/seo/{post_id}` | Tulis meta Yoast: `{"yoast":{...}}`, flat, raw map, atau raw list `[{key,value}]`. |
| POST | `/featured-image` | `{post_id, image_url}` — unduh gambar, simpan ke Media Library, jadikan featured image. |

### Autentikasi

Semua endpoint kecuali `/ping` membutuhkan API key, lewat salah satu cara:

```
X-API-Key: <key>              # disarankan
Authorization: Bearer <key>
?api_key=<key>                # hindari: key bisa muncul di log URL
```

Key disimpan di tabel `wp_options` (`akar_proxy_seo_api_key`). Bisa diisi dari
konstanta `AKAR_PROXY_SEO_KEY` di `wp-config.php` (dipakai saat aktivasi) atau
diubah lewat filter `akar_proxy_seo_api_key`.

### Contoh

```bash
curl -X POST "https://situs-anda.com/wp-json/akar-proxy-seo/v1/posts" \
  -H "X-API-Key: $AKAR_WP_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"title":"Judul","content":"<p>Isi</p>","status":"draft","tags":["otomasi"]}'
```

## Keamanan

- **SSRF guard** pada `featured_image_url` / `/featured-image`: hanya http/https
  publik; localhost, IP privat, dan alamat metadata cloud ditolak.
- Unduhan gambar dibatasi 8 MB (filter `akar_proxy_seo_max_image_bytes`, isi `0`
  untuk tanpa batas) dan wajib berupa image valid sebelum disimpan.
- API key tidak pernah ditulis ke file proxy; file legacy membacanya dari
  database, jadi rotasi key tidak perlu menulis ulang file.
- `/ping` sengaja publik tetapi tidak membocorkan versi WP/PHP.

## Auto-update

Plugin memeriksa manifest JSON di
`https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.json`:

- cache 1 jam; saat gagal, dicoba lagi setelah 15 menit (tidak memblokir admin),
- `download_url` di manifest menunjuk zip rilis di host yang sama,
- changelog di layar **View details** dibangun dari `readme.txt`,
- auto-update latar belakang dipaksa aktif **hanya** untuk plugin ini.

Mematikan auto-update:

```php
define('AKAR_PROXY_SEO_DISABLE_AUTO_UPDATE', true);
// atau
add_filter('akar_proxy_seo_auto_update', '__return_false');
```

## Proxy legacy (opsional)

Saat aktivasi, plugin mencoba menulis `wp-api-proxy.php` ke root WordPress. Bila
root read-only, file tidak dibuat dan request ke `/wp-api-proxy.php/...` tetap
dilayani lewat hook `init` plugin — kontrak URL lama tidak berubah.

## Struktur repo

```
akar-proxy-seo/            sumber plugin (isi zip, root folder ini yang dipasang WP)
  akar-proxy-seo.php       header, route, admin page, auto-update
  includes/handlers.php    auth, dispatcher, SSRF, handler SEO/posts/terms
  includes/legacy-proxy.php cangkang wp-api-proxy.php standalone
  readme.txt               dokumentasi + changelog (satu sumber kebenaran)
tests/                     harness uji offline (stub WordPress, tanpa DB)
docs/                      artefak publik: manifest + zip (GitHub Pages)
build-manifest.py          pembangun manifest dari readme.txt
release-plugin.sh          validasi versi, bangun zip, (--publish) ke docs/
```

## Pengembangan

Tidak perlu WordPress atau database: `tests/wp-stubs.php` meniru fungsi inti WP.

```bash
php -l akar-proxy-seo/akar-proxy-seo.php
php tests/unit_cases.php     # helper murni
php tests/rest_cases.php     # route + auth + validasi + SSRF
php tests/legacy_cases.php   # proxy legacy lewat php -S (butuh port lokal)
```

Total 350 assertion. Semua suite keluar dengan kode 1 bila ada yang gagal, jadi
aman dipakai di CI.

### Rilis baru

1. Naikkan versi di **tiga** tempat: header `Version:`, konstanta
   `AKAR_PROXY_SEO_VERSION`, dan `Stable tag:` di `readme.txt`.
2. Tambahkan blok `= x.y.z =` di `== Changelog ==` (dipakai manifest) dan
   `== Upgrade Notice ==` bila perlu.
3. Jalankan:

   ```bash
   ./release-plugin.sh --publish
   ```

   Skrip menolak rilis bila keempat nilai versi tidak sama, membangun zip hanya
   dari folder `akar-proxy-seo/`, mencetak md5/sha256, lalu menyalin artefak ke
   `docs/`.
4. Uji zip hasil rilis (bukan folder sumber):

   ```bash
   rm -rf /tmp/rel && mkdir -p /tmp/rel && unzip -q docs/akar-proxy-seo.zip -d /tmp/rel
   AKAR_PLUGIN_DIR=/tmp/rel/akar-proxy-seo php tests/rest_cases.php
   ```

5. Commit + push `docs/`; GitHub Pages men-deploy otomatis. Verifikasi
   `.../akar-proxy-seo.json` memuat versi baru dan `sha256`-nya sama dengan zip.

## Lisensi

GPLv2 or later — lihat [LICENSE](LICENSE).
