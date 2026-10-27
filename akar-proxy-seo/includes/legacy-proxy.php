<?php
/**
 * Akar Solution - WP API Proxy (legacy standalone)
 *
 * File ini adalah "cangkang" untuk file `wp-api-proxy.php` yang ditulis plugin
 * Akar Proxy SEO ke root WordPress. Saat ditulis, penanda di bawah diganti
 * dengan isi `includes/handlers.php`, sehingga file hasilnya berdiri sendiri:
 * bisa dipanggil langsung lewat URL tanpa melewati bootstrap WordPress.
 *
 * JANGAN dipanggil langsung dari browser -- file ini bukan proxy yang lengkap.
 * Gunakan tombol "Pasang / Perbaiki" di WP Admin > Akar Proxy SEO.
 *
 * Routes (cara lama, tetap didukung):
 *   GET  /wp-api-proxy.php/status
 *   GET  /wp-api-proxy.php/posts?page=1&per_page=10
 *   POST /wp-api-proxy.php/posts
 *   GET  /wp-api-proxy.php/terms?taxonomy=category
 *   GET  /wp-api-proxy.php/seo/{post_id}
 *   POST /wp-api-proxy.php/seo/{post_id}
 *   POST /wp-api-proxy.php/featured-image
 *
 * Auth: ?api_key=... | X-API-Key: ... | Authorization: Bearer ...
 */

/* @@AKAR_PROXY_SEO_HANDLERS@@ */

/* -------------------------------------------------------------------------- */
/* Bootstrap: cari wp-load.php bila file dipanggil langsung                    */
/* -------------------------------------------------------------------------- */

if (!defined('ABSPATH')) {
    $dir    = __DIR__;
    $loaded = false;

    for ($i = 0; $i < 6; $i++) {
        if (file_exists($dir . '/wp-load.php')) {
            require_once $dir . '/wp-load.php';
            $loaded = true;
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    if (!$loaded) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'wp-load.php not found', 'status' => 500));
        exit;
    }
}

/* Penjaga: file cangkang ini tidak boleh dipakai tanpa digabung handler. */
if (!function_exists('akar_proxy_dispatch')) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array(
        'error'  => 'File proxy belum lengkap. Pasang ulang lewat WP Admin > Akar Proxy SEO.',
        'status' => 500,
    ));
    exit;
}

if (!defined('AKAR_PROXY_SEO_NO_DISPATCH')) {
    $GLOBALS['akar_proxy_endpoint'] = 'legacy';
    $GLOBALS['akar_proxy_route_file'] = basename(__FILE__);
    akar_proxy_dispatch();
}
