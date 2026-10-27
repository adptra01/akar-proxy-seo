<?php
/**
 * wp-load.php tiruan untuk server uji.
 *
 * Dipakai dua jalur:
 *   - site/index.php (plugin fallback, file proxy tidak ada)
 *   - site/wp-api-proxy.php hasil generate (legacy standalone)
 */

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../bootstrap.php';

// Situs tiruan sudah "terpasang": isi opsi (API key) + 13 post contoh.
if (function_exists('akar_test_seed')) {
    akar_test_seed();
}

// WordPress asli menembakkan init setelah plugin dimuat.
do_action('init');
