<?php
/**
 * Bootstrap uji offline: stub WordPress + plugin Akar Proxy SEO.
 *
 * ABSPATH diarahkan ke tests/site/ supaya jalur file legacy (dipakai admin page)
 * menunjuk folder yang benar-benar ada.
 */

// wp-load.php sudah mendefinisikannya saat request masuk lewat file proxy.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/site/');
}

require_once __DIR__ . '/wp-stubs.php';

$akarPluginDir = getenv('AKAR_PLUGIN_DIR');
if (!$akarPluginDir || !is_dir($akarPluginDir)) {
    $akarPluginDir = dirname(__DIR__) . '/akar-proxy-seo';
}

require_once $akarPluginDir . '/akar-proxy-seo.php';
