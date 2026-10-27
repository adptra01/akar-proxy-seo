<?php
/**
 * Front controller tiruan WordPress.
 *
 * Saat file proxy belum ada, request /wp-api-proxy.php/... diteruskan ke sini
 * (meniru rewrite WordPress), lalu plugin melayani lewat hook init.
 */

require __DIR__ . '/wp-load.php';

if (!defined('AKAR_PROXY_SEO_NO_DISPATCH')) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Tidak ada yang menangani request ini', 'status' => 404));
}
