=== Akar Proxy SEO ===
Contributors: akarsolution
Tags: rest-api, seo, yoast, api-proxy, n8n
Requires at least: 5.6
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later

REST API untuk otomasi konten n8n di WordPress mana pun: posts, terms, Yoast SEO (baca + tulis), featured image. Dilengkapi proxy legacy wp-api-proxy.php sebagai jalur kompatibilitas.

== Description ==

Plugin ini menyediakan **dua jalur akses** ke endpoint yang sama:

1. **REST API** (`/wp-json/akar-proxy-seo/v1/...`) — jalur utama, tidak perlu file tambahan, tetap jalan di hosting dengan root read-only.
2. **Proxy legacy** (`wp-api-proxy.php`) — jalur kompatibilitas untuk n8n/skrip lama. Plugin akan menulis file ini ke root WordPress bila folder root dapat ditulisi; bila tidak, request akan tetap dilayani melalui fallback plugin.

**Endpoint REST:**

| Method | Route | Keterangan |
|---|---|---|
| GET | `/ping` | Health check publik, balasan `{ok:true,service}`. |
| GET | `/status` | Diagnosa (butuh key): versi WP/PHP/plugin, waktu server. |
| GET | `/posts?page=1&per_page=10` | Daftar artikel semua status (maks 50/halaman). |
| POST | `/posts` | Buat artikel: `{title*, content, excerpt, slug, status, categories:[id], tags:[nama], featured_image_url}`. |
| GET | `/terms?taxonomy=category` | Daftar term `{data:[{id,name,slug,count}]}`. |
| GET | `/seo/{post_id}` | Baca meta Yoast. |
| POST | `/seo/{post_id}` | Tulis meta Yoast: `{"yoast":{...}}`, flat, raw map, atau raw list `[{key,value}]`. |
| POST | `/featured-image` | `{post_id, image_url}` — URL wajib publik (SSRF ditolak), maks 8 MB. |

**Autentikasi:** `X-API-Key: <key>` (disarankan), `Authorization: Bearer <key>`, atau `?api_key=<key>`.

**Fitur keamanan:**
- SSRF guard: hanya URL http/https publik yang boleh diunduh (localhost, IP privat, metadata cloud ditolak).
- Gambar dibatasi 8 MB, divalidasi sebagai image valid sebelum disimpan.
- API key disimpan di tabel options WordPress, tidak pernah ditulis ke file proxy.

== Installation ==

1. Upload zip via **Plugins -> Add New -> Upload Plugin**, lalu **Activate**.
2. Menu "Akar Proxy SEO" muncul di sidebar admin — di sana ada:
   - API Key aktif (copy + Generate key baru)
   - Status file proxy legacy (hijau = terpasang, merah = belum ada)
   - Dokumentasi lengkap endpoint (route, format body, contoh cURL/n8n)
3. Key disimpan di tabel options; saat aktivasi pertama diambil dari konstanta `AKAR_PROXY_SEO_KEY` (legacy) bila ada.

**Untuk n8n:**
Semua pengaturan dibaca dari Environment Variable n8n, jadi satu workflow bisa dipakai untuk banyak situs tanpa diedit:

| Variabel | Isi |
|---|---|
| `AKAR_SITES` | JSON registry: `{"situs-anda":{"base_url":"https://situs-anda.com","api_key":"..."}}` |
| `AKAR_SITE_ID` | Situs yang dipakai run ini (default entri pertama di `AKAR_SITES`) |
| `AKAR_WP_BASE_URL` | Override base URL situs terpilih (tanpa `/wp-json/...`) |
| `AKAR_WP_API_KEY` | Override API key situs terpilih |
| `AKAR_MODE` | `safe` (default) atau `production` |
| `AKAR_CONFIRM` | `true` = izinkan publish (default `false`) |
| `AKAR_DRY_RUN` | `true` = tidak ada request tulis sama sekali (default `true`) |

Default aman: run pertama hanya pratinjau. Urutan menaikkan: `AKAR_DRY_RUN=false` → artikel masuk sebagai **draft**; publish hanya bila `AKAR_MODE=production` **dan** `AKAR_CONFIRM=true`.
Kirim key lewat header `X-API-Key` (bukan query string) supaya tidak muncul di log URL.

== Changelog ==

= 1.5.0 =
* **FIX kritis** — route `GET/POST /seo/{id}` tidak lagi menghasilkan HTTP 500. Penyebabnya `validate_callback => 'is_numeric'`, padahal WordPress memanggil callback itu dengan tiga argumen (`$value, $request, $param`) sehingga PHP 8 melempar `ArgumentCountError`. Diganti `akar_pse_validate_post_id()` yang menerima ketiga argumen dan menolak id <= 0.
* **Auto-update** — plugin memeriksa manifest `https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.json` (cache 1 jam, backoff 15 menit saat gagal) dan menampilkan notifikasi update seperti plugin WordPress.org. Manifest dan zip rilis dipublikasikan dari repo GitHub `adptra01/akar-proxy-seo`, jadi tidak bergantung pada hosting situs mana pun. Background auto-update aktif khusus untuk plugin ini; matikan lewat `define('AKAR_PROXY_SEO_DISABLE_AUTO_UPDATE', true);` atau filter `akar_proxy_seo_auto_update`.
* **Admin page** — baris "Pembaruan otomatis" menampilkan versi manifest, hasil percobaan terakhir, dan tautan ke Dashboard → Updates.
* Tidak ada perubahan kontrak endpoint; API key dan URL tetap sama.

= 1.4.0 =
* **REST API utama** — semua endpoint kini tersedia di `/wp-json/akar-proxy-seo/v1/...` (ping, status, posts, terms, seo, featured-image).
* **Legacy proxy opsional** — `wp-api-proxy.php` tetap dibuat otomatis saat aktivasi bila folder root writable; fallback plugin melayani request bila file tidak ada.
* **SSRF guard** — endpoint featured-image menolak URL localhost, IP privat, dan metadata cloud.
* **Featured image** — POST /posts menerima `featured_image_url`; endpoint terpisah POST /featured-image untuk update cover.
* **Admin page** — halaman pengaturan lengkap dengan info endpoint, manajemen key, dan tombol pasang/perbaiki file proxy.
* **Multi-site ready** — n8n bisa mengelola banyak situs WordPress dengan registry base+key per site.

= 1.3.1 =
* FIX FATAL: featured image route memanggil fungsi tidak eksis `image_type_to_ext()` -> diganti `image_type_to_extension()` (inti PHP) + fallback `.jpg`.

= 1.3.0 =
* Route baru POST `/featured-image` `{post_id, image_url}`: unduh gambar dari URL, validasi mime image, simpan ke Media Library, set sebagai featured image.
* POST `/posts` kini menerima field opsional `featured_image_url` (cover langsung saat create).
* `og_image` / `twitter_image` otomatis terisi Yoast dari featured image.

= 1.2.0 =
* Plugin kini MENULIS `wp-api-proxy.php` LENGKAP ke root WordPress saat aktivasi.
* Tombol "Pasang / Perbaiki wp-api-proxy.php" di halaman admin.
* File proxy membaca API key dari database secara langsung — rotasi key TIDAK lagi perlu menulis ulang file.
* Fallback hook init: POST & GET `/seo/{id}` dan `/status` tetap dilayani plugin meski file proxy tidak terbaca.
* Route baru GET `/terms?taxonomy=category` untuk resolve ID kategori dinamis.

= 1.1.0 =
* Menu admin "Akar Proxy SEO": kelola API Key + dokumentasi endpoint.
* API Key kini tersimpan di wp options dan bisa diregenerate dari UI.

= 1.0.0 =
* Endpoint POST `/seo/{id}` dengan 4 varian format body.
* Sanitasi per tipe field (URL, keyword, teks).
* Response simetris dengan GET `/seo/{id}`.

== Upgrade Notice ==

= 1.4.0 =
Versi ini menambahkan REST API utama di samping proxy legacy. Klien lama (n8n) yang memakai URL `wp-api-proxy.php` tetap jalan; klien baru disarankan memakai REST endpoint `/wp-json/akar-proxy-seo/v1/...`. API key tetap sama, tidak perlu dirotasi saat upgrade.
