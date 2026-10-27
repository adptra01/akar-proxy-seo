<?php
/**
 * Plugin Name: Akar Proxy SEO
 * Description: REST API untuk otomasi konten n8n di WordPress mana pun (posts, terms, Yoast SEO, featured image) plus proxy legacy wp-api-proxy.php sebagai jalur kompatibilitas. Tidak wajib menulis file ke root WordPress.
 * Version: 1.5.0
 * Update URI: https://github.com/adptra01/akar-proxy-seo
 * Author: Akar Solution
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Text Domain: akar-proxy-seo
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AKAR_PROXY_SEO_VERSION', '1.5.0');
define('AKAR_PROXY_SEO_FILE', __FILE__);
define('AKAR_PROXY_SEO_SLUG', 'akar-proxy-seo');
define('AKAR_PROXY_SEO_MANIFEST_URL', 'https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.json');
define('AKAR_PROXY_SEO_HOMEPAGE', 'https://github.com/adptra01/akar-proxy-seo');
define('AKAR_PROXY_SEO_OPTKEY', 'akar_proxy_seo_api_key');
define('AKAR_PROXY_SEO_PROXYFILE', 'wp-api-proxy.php');
define('AKAR_PROXY_SEO_FILEOPT', 'akar_proxy_seo_proxy_file');
define('AKAR_PROXY_SEO_WRITTEN', 'akar_proxy_seo_written_version');

require_once __DIR__ . '/includes/handlers.php';

/* ============================================================
 * Sumber file proxy legacy
 * ============================================================ */

/**
 * Gabungkan handler bersama dengan cangkang standalone menjadi satu file utuh.
 *
 * @return string Kosong bila sumber tidak terbaca / penanda hilang.
 */
function akar_pse_proxy_source()
{
    $dir      = __DIR__ . '/includes';
    $handlers = @file_get_contents($dir . '/handlers.php');
    $shell    = @file_get_contents($dir . '/legacy-proxy.php');

    if (!is_string($handlers) || !is_string($shell) || $handlers === '' || $shell === '') {
        return '';
    }

    $handlers = preg_replace('/^<\?php\s*/', '', $handlers, 1, $count);
    if ($count !== 1) {
        return '';
    }

    $shell = preg_replace('/^<\?php\s*/', '', $shell, 1, $count);
    if ($count !== 1) {
        return '';
    }

    if (strpos($shell, '@@AKAR_PROXY_SEO_HANDLERS@@') === false) {
        return '';
    }

    // Penanda dibungkus komentar di cangkang; ganti seluruh komentarnya, bukan
    // hanya teks penanda, supaya docblock handler tidak tersarang di dalam
    // komentar (`/* /** ... */`). Callback dipakai agar `$` di dalam handler
    // tidak dibaca preg_replace sebagai backreference.
    $handlers = ltrim($handlers);

    $src = preg_replace_callback(
        '#/\*\s*@@AKAR_PROXY_SEO_HANDLERS@@\s*\*/#',
        function () use ($handlers) {
            return $handlers;
        },
        $shell,
        1,
        $replaced
    );

    if ($replaced !== 1 || !is_string($src)) {
        return '';
    }

    $src = "<?php\n" . $src;

    if (strpos($src, '@@AKAR_PROXY_SEO_HANDLERS@@') !== false) {
        return '';
    }

    return $src;
}

/**
 * Nama file proxy yang dipakai situs ini.
 *
 * @return string
 */
function akar_pse_proxy_filename()
{
    $file = trim((string) get_option(AKAR_PROXY_SEO_FILEOPT, ''));
    if ($file !== '') {
        $file = sanitize_file_name($file);
    }

    if ($file === '' || strtolower(substr($file, -4)) !== '.php') {
        $file = AKAR_PROXY_SEO_PROXYFILE;
    }

    return $file;
}

/**
 * @return string
 */
function akar_pse_proxy_path()
{
    return ABSPATH . akar_pse_proxy_filename();
}

/**
 * Tulis (atau perbaiki) file proxy legacy.
 *
 * @return array{0: bool, 1: string}
 */
function akar_pse_write_proxy_file()
{
    $src = akar_pse_proxy_source();
    if ($src === '') {
        return array(false, 'Sumber proxy tidak lengkap (includes/handlers.php atau includes/legacy-proxy.php tidak terbaca).');
    }

    $target = akar_pse_proxy_path();
    $bytes  = @file_put_contents($target, $src);
    if ($bytes === false) {
        return array(false, sprintf('Gagal menulis %s. Folder root WordPress tidak bisa ditulisi -- REST API tetap berjalan, file legacy tidak wajib.', $target));
    }

    @chmod($target, 0644);
    update_option(AKAR_PROXY_SEO_WRITTEN, AKAR_PROXY_SEO_VERSION, false);

    return array(true, $target . sprintf(' (%s KB)', number_format($bytes / 1024, 1)));
}

/**
 * @return array{0: bool, 1: string}
 */
function akar_pse_delete_proxy_file()
{
    $target = akar_pse_proxy_path();
    if (!file_exists($target)) {
        return array(false, 'File proxy memang belum ada.');
    }
    if (!@unlink($target)) {
        return array(false, 'Gagal menghapus ' . $target . ' -- hapus manual lewat file manager hosting.');
    }
    delete_option(AKAR_PROXY_SEO_WRITTEN);

    return array(true, 'File proxy dihapus: ' . $target);
}

/* ============================================================
 * API key
 * ============================================================ */

/**
 * @return string
 */
function akar_pse_get_key()
{
    $key = trim((string) get_option(AKAR_PROXY_SEO_OPTKEY, ''));
    if ($key === '' && defined('AKAR_PROXY_SEO_KEY')) {
        $key = trim((string) constant('AKAR_PROXY_SEO_KEY'));
        update_option(AKAR_PROXY_SEO_OPTKEY, $key, false);
    }
    return $key;
}

/**
 * @param string $key
 * @return bool
 */
function akar_pse_store_key($key)
{
    $key = trim((string) $key);
    if ($key === '') {
        return false;
    }
    update_option(AKAR_PROXY_SEO_OPTKEY, $key, false);
    return true;
}

/* ============================================================
 * REST API (/wp-json/akar-proxy-seo/v1/...)
 * ============================================================ */

/**
 * Validasi parameter `id` pada route /seo/{id}.
 *
 * WordPress memanggil validate_callback dengan TIGA argumen
 * ($value, WP_REST_Request, $param). Memakai 'is_numeric' langsung membuat
 * PHP 8 melempar ArgumentCountError sehingga route SELALU 500. Signature di
 * bawah sengaja longgar agar cocok dengan ketiga argumen itu.
 *
 * @param mixed $value
 * @param mixed $request
 * @param mixed $param
 * @return bool
 */
function akar_pse_validate_post_id($value, $request = null, $param = null)
{
    return is_numeric($value) && (int) $value > 0;
}

add_action('rest_api_init', 'akar_pse_register_rest');
function akar_pse_register_rest()
{
    $ns = 'akar-proxy-seo/v1';

    $routes = array(
        '/ping' => array(
            'methods'  => 'GET',
            'callback' => akar_pse_rest_handler(array('ping')),
        ),
        '/status' => array(
            'methods'  => 'GET',
            'callback' => akar_pse_rest_handler(array('status')),
        ),
        '/terms' => array(
            'methods'  => 'GET',
            'callback' => akar_pse_rest_handler(array('terms')),
        ),
        '/posts' => array(
            'methods'  => 'GET, POST',
            'callback' => akar_pse_rest_handler(array('posts')),
        ),
        '/featured-image' => array(
            'methods'  => 'POST',
            'callback' => akar_pse_rest_handler(array('featured-image')),
        ),
        '/seo/(?P<id>\d+)' => array(
            'methods'  => 'GET, POST',
            'callback' => akar_pse_rest_handler(array('seo'), 'id'),
            'args'     => array(
                'id' => array(
                    'required'          => true,
                    'validate_callback' => 'akar_pse_validate_post_id',
                ),
            ),
        ),
    );

    foreach ($routes as $path => $args) {
        $args['permission_callback'] = '__return_true'; // auth ditegakkan di handler (lihat akar_proxy_require_auth)
        register_rest_route($ns, $path, $args);
    }
}

/**
 * Bungkus dispatcher bersama menjadi callback REST.
 *
 * @param array  $segments
 * @param string $id_param
 * @return callable
 */
function akar_pse_rest_handler(array $segments, $id_param = '')
{
    return function ($request) use ($segments, $id_param) {
        $seg = $segments;

        if ($id_param !== '') {
            $id = $request->get_param($id_param);
            if ($id !== null && $id !== '') {
                $seg[] = (string) $id;
            }
        }

        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = $request->get_params();
            if (is_array($body)) {
                unset($body[$id_param]);
            }
        }

        $GLOBALS['akar_proxy_endpoint']      = 'rest';
        $GLOBALS['akar_proxy_body_override'] = is_array($body) ? $body : array();
        akar_proxy_json_mode(true);

        try {
            akar_proxy_dispatch($seg);
            return new WP_REST_Response(array('error' => 'Route tidak ditemukan', 'status' => 404), 404);
        } catch (Akar_Proxy_Json_Response $e) {
            return new WP_REST_Response($e->payload, $e->status);
        } finally {
            akar_proxy_json_mode(false);
            unset($GLOBALS['akar_proxy_body_override'], $GLOBALS['akar_proxy_endpoint']);
        }
    };
}

/* ============================================================
 * Fallback legacy: layani /wp-api-proxy.php/... walau file tidak bisa ditulis
 * ============================================================ */

add_action('init', 'akar_pse_maybe_serve_legacy', 1);
function akar_pse_maybe_serve_legacy()
{
    if (is_admin()) {
        return;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return;
    }
    if (empty($_SERVER['REQUEST_URI'])) {
        return;
    }

    $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return;
    }

    $file = akar_pse_proxy_filename();
    $has_path  = stripos($path, $file) !== false;
    $has_query = isset($_GET['akar_route']);

    if (!$has_path && !$has_query) {
        return;
    }

    // Bila request masuk lewat file proxy itu sendiri, biarkan file yang
    // menangani (ia sudah memuat wp-load.php lalu memanggil dispatcher).
    $script = isset($_SERVER['SCRIPT_FILENAME']) ? basename((string) $_SERVER['SCRIPT_FILENAME']) : '';
    if ($script !== '' && strcasecmp($script, $file) === 0) {
        return;
    }

    // Selain itu plugin yang melayani, termasuk ketika file ada di root tetapi
    // server tidak meneruskan PATH_INFO setelah `.php`.
    $GLOBALS['akar_proxy_endpoint']   = 'plugin-fallback';
    $GLOBALS['akar_proxy_route_file'] = $file;
    akar_proxy_json_mode(false);

    $segments = $has_path ? akar_proxy_segments_from_path($path, $file) : array();

    // Samakan dengan file proxy asli: path tanpa segmen (mis.
    // /wp-api-proxy.php?akar_route=posts) tetap dilayani lewat ?akar_route=.
    if (empty($segments) && !empty($_GET['akar_route'])) {
        $segments = akar_proxy_segments();
    }

    akar_proxy_dispatch($segments);
}

/* ============================================================
 * Aktivasi & upgrade file legacy
 * ============================================================ */

register_activation_hook(__FILE__, 'akar_pse_activate');
function akar_pse_activate()
{
    if (get_option(AKAR_PROXY_SEO_OPTKEY, '') === '') {
        if (defined('AKAR_PROXY_SEO_KEY')) {
            add_option(AKAR_PROXY_SEO_OPTKEY, trim((string) constant('AKAR_PROXY_SEO_KEY')), '', false);
        } else {
            add_option(AKAR_PROXY_SEO_OPTKEY, wp_generate_password(48, false), '', false);
        }
    }

    list($ok, $msg) = akar_pse_write_proxy_file();
    set_transient('akar_pse_notice', array($ok ? 'success' : 'warning', $ok ? 'File proxy dipasang: ' . $msg : $msg), 60);
}

add_action('admin_init', 'akar_pse_maybe_refresh_proxy_file');
function akar_pse_maybe_refresh_proxy_file()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $written = (string) get_option(AKAR_PROXY_SEO_WRITTEN, '');
    if ($written === AKAR_PROXY_SEO_VERSION) {
        return;
    }

    // Hanya segarkan file yang memang sudah ada; jangan membuat file baru diam-diam.
    if (file_exists(akar_pse_proxy_path())) {
        akar_pse_write_proxy_file();
    }
}

/* ============================================================
 * Auto-update (manifest JSON, tanpa WordPress.org)
 *
 * Cara kerja:
 *   1. Manifest `https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.json`
 *      memuat version + download_url + changelog.
 *   2. Manifest di-cache 1 jam (transient) supaya halaman admin tidak
 *      memanggil server terus-menerus. Bila gagal, dicoba lagi setelah
 *      15 menit agar situs tanpa koneksi keluar tidak menunggu timeout
 *      di setiap muat halaman.
 *   3. Versi manifest > AKAR_PROXY_SEO_VERSION -> WordPress menampilkan
 *      notifikasi update seperti plugin biasa. Background auto-update
 *      diaktifkan khusus untuk plugin ini (bisa dimatikan lewat konstanta
 *      AKAR_PROXY_SEO_DISABLE_AUTO_UPDATE atau filter
 *      akar_proxy_seo_auto_update).
 * ============================================================ */

/**
 * Simpan/baca hasil percobaan terakhir mengambil manifest.
 *
 * @param array|null $status
 * @return array
 */
function akar_pse_manifest_status($status = null)
{
    if ($status === null) {
        $stored = get_transient('akar_pse_manifest_status');
        return is_array($stored) ? $stored : array();
    }

    set_transient('akar_pse_manifest_status', $status, 6 * HOUR_IN_SECONDS);
    return $status;
}

/**
 * Ambil manifest rilis. Tidak pernah melempar error dan tidak pernah
 * memblokir render halaman lebih dari `timeout` detik.
 *
 * @param bool $force Lewati cache (dipakai layar Dashboard -> Updates).
 * @return array|false
 */
function akar_pse_fetch_manifest($force = false)
{
    $cached = get_transient('akar_pse_manifest');

    if (!$force && is_array($cached)) {
        return $cached;
    }

    if (!$force && get_transient('akar_pse_manifest_fail')) {
        return is_array($cached) ? $cached : false;
    }

    // Cache-buster per jam: menghindari salinan lama di PoP Cloudflare lain.
    $url = add_query_arg(
        'cb',
        (string) (int) floor(time() / HOUR_IN_SECONDS),
        AKAR_PROXY_SEO_MANIFEST_URL
    );

    $response = wp_remote_get($url, array(
        'timeout' => 5,
        'headers' => array(
            'Accept'        => 'application/json',
            'Cache-Control' => 'no-cache',
        ),
    ));

    if (is_wp_error($response)) {
        akar_pse_manifest_status(array(
            'checked_at' => gmdate('c'),
            'ok'         => false,
            'http_code'  => null,
            'error'      => $response->get_error_message(),
        ));
        set_transient('akar_pse_manifest_fail', 1, 15 * MINUTE_IN_SECONDS);
        return is_array($cached) ? $cached : false;
    }

    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        akar_pse_manifest_status(array(
            'checked_at' => gmdate('c'),
            'ok'         => false,
            'http_code'  => $code,
            'error'      => 'HTTP ' . $code,
        ));
        set_transient('akar_pse_manifest_fail', 1, 15 * MINUTE_IN_SECONDS);
        return is_array($cached) ? $cached : false;
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data) || empty($data['version']) || empty($data['download_url'])) {
        akar_pse_manifest_status(array(
            'checked_at' => gmdate('c'),
            'ok'         => false,
            'http_code'  => $code,
            'error'      => 'Manifest bukan JSON valid atau tidak lengkap',
        ));
        set_transient('akar_pse_manifest_fail', 1, 15 * MINUTE_IN_SECONDS);
        return is_array($cached) ? $cached : false;
    }

    akar_pse_manifest_status(array(
        'checked_at' => gmdate('c'),
        'ok'         => true,
        'http_code'  => $code,
        'error'      => '',
    ));
    set_transient('akar_pse_manifest', $data, HOUR_IN_SECONDS);
    delete_transient('akar_pse_manifest_fail');

    return $data;
}

/** Segarkan manifest saat layar Dashboard -> Updates dibuka. */
add_action('load-update-core.php', 'akar_pse_flush_manifest_cache');
function akar_pse_flush_manifest_cache()
{
    delete_transient('akar_pse_manifest');
    delete_transient('akar_pse_manifest_fail');
}

add_filter('pre_set_site_transient_update_plugins', 'akar_pse_inject_update');
function akar_pse_inject_update($transient)
{
    if (!is_object($transient)) {
        return $transient;
    }

    $manifest = akar_pse_fetch_manifest();
    if (!$manifest) {
        return $transient;
    }

    $basename = plugin_basename(AKAR_PROXY_SEO_FILE);
    $item     = (object) array(
        'slug'         => AKAR_PROXY_SEO_SLUG,
        'plugin'       => $basename,
        'new_version'  => (string) $manifest['version'],
        'url'          => isset($manifest['homepage']) ? (string) $manifest['homepage'] : AKAR_PROXY_SEO_HOMEPAGE,
        'package'      => (string) $manifest['download_url'],
        'requires'     => isset($manifest['requires']) ? (string) $manifest['requires'] : '',
        'tested'       => isset($manifest['tested']) ? (string) $manifest['tested'] : '',
        'requires_php' => isset($manifest['requires_php']) ? (string) $manifest['requires_php'] : '',
        'icons'        => array(),
        'banners'      => array(),
    );

    if (version_compare((string) $manifest['version'], AKAR_PROXY_SEO_VERSION, '>')) {
        $transient->response[$basename] = $item;
    } else {
        $transient->no_update[$basename] = $item;
    }

    return $transient;
}

add_filter('plugins_api', 'akar_pse_plugin_information', 20, 3);
function akar_pse_plugin_information($result, $action, $args)
{
    if ($action !== 'plugin_information') {
        return $result;
    }

    $slug = '';
    if (is_object($args) && isset($args->slug)) {
        $slug = (string) $args->slug;
    } elseif (is_array($args) && isset($args['slug'])) {
        $slug = (string) $args['slug'];
    }

    if ($slug !== AKAR_PROXY_SEO_SLUG) {
        return $result;
    }

    $manifest = akar_pse_fetch_manifest();
    if (!$manifest) {
        return $result;
    }

    return (object) array(
        'name'          => 'Akar Proxy SEO',
        'slug'          => AKAR_PROXY_SEO_SLUG,
        'version'       => (string) $manifest['version'],
        'author'        => '<a href="https://github.com/adptra01/akar-proxy-seo">Akar Solution</a>',
        'homepage'      => isset($manifest['homepage']) ? (string) $manifest['homepage'] : AKAR_PROXY_SEO_HOMEPAGE,
        'requires'      => isset($manifest['requires']) ? (string) $manifest['requires'] : '',
        'tested'        => isset($manifest['tested']) ? (string) $manifest['tested'] : '',
        'requires_php'  => isset($manifest['requires_php']) ? (string) $manifest['requires_php'] : '',
        'download_link' => (string) $manifest['download_url'],
        'sections'      => array(
            'description' => isset($manifest['description']) ? (string) $manifest['description'] : '',
            'changelog'   => isset($manifest['changelog']) ? (string) $manifest['changelog'] : '',
        ),
    );
}

/**
 * Izinkan WordPress memperbarui plugin ini sendiri di latar belakang.
 *
 * Hanya berlaku untuk plugin ini, dan bisa dimatikan tanpa mengubah kode:
 *   define('AKAR_PROXY_SEO_DISABLE_AUTO_UPDATE', true);   // wp-config.php
 *   add_filter('akar_proxy_seo_auto_update', '__return_false');
 *
 * @param bool|null $update
 * @param object    $item
 * @return bool|null
 */
add_filter('auto_update_plugin', 'akar_pse_auto_update', 10, 2);
function akar_pse_auto_update($update, $item)
{
    if (!is_object($item) || !isset($item->plugin)) {
        return $update;
    }

    if (plugin_basename(AKAR_PROXY_SEO_FILE) !== (string) $item->plugin) {
        return $update;
    }

    if (defined('AKAR_PROXY_SEO_DISABLE_AUTO_UPDATE') && AKAR_PROXY_SEO_DISABLE_AUTO_UPDATE) {
        return false;
    }

    $enabled = true;
    if (function_exists('apply_filters')) {
        $enabled = (bool) apply_filters('akar_proxy_seo_auto_update', true);
    }

    return $enabled ? true : $update;
}

/** Setelah update berhasil, buang cache manifest agar versi baru terlihat. */
add_action('upgrader_process_complete', 'akar_pse_after_upgrade', 10, 2);
function akar_pse_after_upgrade($upgrader, $options)
{
    if (!isset($options['action'], $options['type'])) {
        return;
    }

    if ($options['action'] === 'update' && $options['type'] === 'plugin') {
        delete_transient('akar_pse_manifest');
        delete_transient('akar_pse_manifest_fail');
    }
}

/* ============================================================
 * Halaman admin
 * ============================================================ */

add_action('admin_menu', 'akar_pse_admin_menu');
function akar_pse_admin_menu()
{
    add_menu_page(
        'Akar Proxy SEO',
        'Akar Proxy SEO',
        'manage_options',
        'akar-proxy-seo',
        'akar_pse_admin_page',
        'dashicons-shield-alt',
        80
    );
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'akar_pse_action_links');
function akar_pse_action_links($links)
{
    $url = admin_url('admin.php?page=akar-proxy-seo');
    array_unshift($links, '<a href="' . esc_url($url) . '">Pengaturan</a>');
    return $links;
}

/**
 * @param string $action
 * @param string $nonce
 * @return void
 */
function akar_pse_require_admin($action, $nonce)
{
    if (!current_user_can('manage_options')) {
        wp_die('Tidak punya izin.');
    }
    check_admin_referer($action, $nonce);
}

add_action('admin_post_akar_pse_write_proxy', 'akar_pse_handle_write_proxy');
function akar_pse_handle_write_proxy()
{
    akar_pse_require_admin('akar_pse_write_proxy', '_wpnonce');

    list($ok, $msg) = akar_pse_write_proxy_file();
    set_transient('akar_pse_notice', array($ok ? 'success' : 'error', $ok ? 'File proxy dipasang: ' . $msg : $msg), 60);
    wp_safe_redirect(admin_url('admin.php?page=akar-proxy-seo'));
    exit;
}

add_action('admin_post_akar_pse_delete_proxy', 'akar_pse_handle_delete_proxy');
function akar_pse_handle_delete_proxy()
{
    akar_pse_require_admin('akar_pse_delete_proxy', '_wpnonce');

    list($ok, $msg) = akar_pse_delete_proxy_file();
    set_transient('akar_pse_notice', array($ok ? 'success' : 'error', $msg), 60);
    wp_safe_redirect(admin_url('admin.php?page=akar-proxy-seo'));
    exit;
}

add_action('admin_post_akar_pse_save_file', 'akar_pse_handle_save_file');
function akar_pse_handle_save_file()
{
    akar_pse_require_admin('akar_pse_save_file', '_wpnonce');

    $file = isset($_POST['proxy_file']) ? sanitize_file_name(wp_unslash((string) $_POST['proxy_file'])) : '';
    if ($file === '' || strtolower(substr($file, -4)) !== '.php') {
        set_transient('akar_pse_notice', array('error', 'Nama file harus berakhiran .php'), 60);
        wp_safe_redirect(admin_url('admin.php?page=akar-proxy-seo'));
        exit;
    }

    $old = akar_pse_proxy_filename();
    update_option(AKAR_PROXY_SEO_FILEOPT, $file, false);

    if ($old !== $file && file_exists(ABSPATH . $old)) {
        @unlink(ABSPATH . $old);
    }

    list($ok, $msg) = akar_pse_write_proxy_file();
    set_transient('akar_pse_notice', array($ok ? 'success' : 'warning', 'Nama file disimpan: ' . $file . '. ' . $msg), 60);
    wp_safe_redirect(admin_url('admin.php?page=akar-proxy-seo'));
    exit;
}

add_action('admin_post_akar_pse_regen_key', 'akar_pse_handle_regen_key');
function akar_pse_handle_regen_key()
{
    akar_pse_require_admin('akar_pse_regen_key', '_wpnonce');

    akar_pse_store_key(wp_generate_password(48, false));
    set_transient('akar_pse_notice', array('success', 'API key baru dibuat. Perbarui key di semua klien (n8n, skrip) sebelum menjalankan otomasi berikutnya.'), 60);
    wp_safe_redirect(admin_url('admin.php?page=akar-proxy-seo'));
    exit;
}

add_action('admin_post_akar_pse_set_key', 'akar_pse_handle_set_key');
function akar_pse_handle_set_key()
{
    akar_pse_require_admin('akar_pse_set_key', '_wpnonce');

    $key = isset($_POST['api_key']) ? trim((string) wp_unslash($_POST['api_key'])) : '';
    if (strlen($key) < 16) {
        set_transient('akar_pse_notice', array('error', 'API key minimal 16 karakter.'), 60);
    } elseif (preg_match('/\s/', $key)) {
        set_transient('akar_pse_notice', array('error', 'API key tidak boleh mengandung spasi.'), 60);
    } elseif (akar_pse_store_key($key)) {
        set_transient('akar_pse_notice', array('success', 'API key disimpan.'), 60);
    }

    wp_safe_redirect(admin_url('admin.php?page=akar-proxy-seo'));
    exit;
}

function akar_pse_admin_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('Tidak punya izin.');
    }

    $notice = get_transient('akar_pse_notice');
    if ($notice) {
        delete_transient('akar_pse_notice');
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($notice[0]),
            esc_html($notice[1])
        );
    }

    $key          = akar_pse_get_key();
    $file         = akar_pse_proxy_filename();
    $proxy_path   = ABSPATH . $file;
    $proxy_exists = file_exists($proxy_path);
    $writable     = wp_is_writable(ABSPATH);
    $rest_base    = rest_url('akar-proxy-seo/v1');
    $legacy_base  = home_url('/' . $file);
    $site_host    = wp_parse_url(home_url('/'), PHP_URL_HOST);
    $manifest     = get_transient('akar_pse_manifest');
    $manifest     = is_array($manifest) ? $manifest : array();
    $manifest_st  = akar_pse_manifest_status();
    $latest       = isset($manifest['version']) ? (string) $manifest['version'] : '';
    $has_update   = $latest !== '' && version_compare($latest, AKAR_PROXY_SEO_VERSION, '>');

    ?>
    <div class="wrap">
        <h1>Akar Proxy SEO <span style="color:#888;font-size:13px">v<?php echo esc_html(AKAR_PROXY_SEO_VERSION); ?></span></h1>

        <h2 class="title">Ringkasan</h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr>
                    <td width="200"><strong>REST API (utama)</strong></td>
                    <td>
                        <code><?php echo esc_html($rest_base); ?></code>
                        <br><small style="color:#666">Tidak butuh file tambahan dan tetap jalan di hosting yang root-nya read-only.</small>
                    </td>
                </tr>
                <tr>
                    <td><strong>Proxy legacy</strong></td>
                    <td>
                        <code><?php echo esc_html($legacy_base); ?></code>
                        <br><small style="color:#666">Kompatibilitas untuk n8n/skrip lama. Opsional.</small>
                    </td>
                </tr>
                <tr>
                    <td><strong>API key</strong></td>
                    <td>
                        <?php if ($key === '') : ?>
                            <span style="color:#d63638"><strong>Belum diatur</strong></span> -- endpoint selain <code>/ping</code> akan menolak semua request.
                        <?php else : ?>
                            <span style="color:#00a32a">Terpasang</span> (<?php echo (int) strlen($key); ?> karakter)
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Pembaruan otomatis</strong></td>
                    <td>
                        <code><?php echo esc_html(AKAR_PROXY_SEO_MANIFEST_URL); ?></code>
                        <br>
                        <?php if ($has_update) : ?>
                            <span style="color:#d63638"><strong>Versi baru tersedia: <?php echo esc_html($latest); ?></strong></span>
                            -- buka <a href="<?php echo esc_url(admin_url('update-core.php')); ?>">Dashboard &rarr; Updates</a> lalu klik <em>Check Again</em>.
                        <?php elseif ($latest !== '') : ?>
                            <span style="color:#00a32a">Sudah versi terbaru</span> (manifest: <?php echo esc_html($latest); ?>, terpasang: <?php echo esc_html(AKAR_PROXY_SEO_VERSION); ?>).
                        <?php else : ?>
                            <span style="color:#996800">Belum diperiksa.</span>
                            Buka <a href="<?php echo esc_url(admin_url('update-core.php')); ?>">Dashboard &rarr; Updates</a> untuk memeriksa.
                        <?php endif; ?>
                        <?php if (!empty($manifest_st['checked_at'])) : ?>
                            <br><small style="color:#666">
                                Percobaan terakhir: <?php echo esc_html((string) $manifest_st['checked_at']); ?>
                                <?php echo !empty($manifest_st['ok']) ? '(berhasil)' : '(gagal: ' . esc_html((string) (isset($manifest_st['error']) ? $manifest_st['error'] : 'tidak diketahui')) . ')'; ?>
                            </small>
                        <?php endif; ?>
                        <br><small style="color:#666">Pemeriksaan memakai cache 1 jam dan tidak pernah memblokir halaman ini.</small>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 class="title">API Key</h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr>
                    <td width="200"><strong>Key aktif</strong></td>
                    <td>
                        <code id="akar-key" style="word-break:break-all"><?php echo esc_html($key !== '' ? $key : '(belum diatur)'); ?></code>
                        <?php if ($key !== '') : ?>
                            <button type="button" class="button" style="margin-left:8px"
                                    onclick="navigator.clipboard.writeText(document.getElementById('akar-key').textContent)">Copy</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><strong>Set manual</strong></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="akar_pse_set_key">
                            <?php wp_nonce_field('akar_pse_set_key'); ?>
                            <input type="text" name="api_key" class="regular-text" autocomplete="off" spellcheck="false"
                                   placeholder="Tempel key dari n8n / password manager">
                            <?php submit_button('Simpan Key', 'secondary', 'submit', false); ?>
                            <p class="description">Dipakai saat menyamakan key dengan environment klien. Minimal 16 karakter, tanpa spasi.</p>
                        </form>
                    </td>
                </tr>
                <tr>
                    <td><strong>Rotasi</strong></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline"
                              onsubmit="return confirm('Buat API key baru? Semua klien yang masih memakai key lama akan ditolak (401) sampai key-nya diperbarui.');">
                            <input type="hidden" name="action" value="akar_pse_regen_key">
                            <?php wp_nonce_field('akar_pse_regen_key'); ?>
                            <?php submit_button('Generate Key Baru', 'secondary', 'submit', false); ?>
                        </form>
                        <br><small style="color:#666">Rotasi tidak perlu menulis ulang file proxy -- key dibaca dari database setiap request.</small>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 class="title">Proxy Legacy (opsional)</h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
                <tr>
                    <td width="200"><strong>File</strong></td>
                    <td><code><?php echo esc_html($proxy_path); ?></code></td>
                </tr>
                <tr>
                    <td><strong>Status</strong></td>
                    <td>
                        <?php if ($proxy_exists) : ?>
                            <span style="color:#00a32a">Terpasang.</span>
                        <?php else : ?>
                            <span style="color:#996800">Belum ada.</span>
                            <small style="color:#666">REST API tetap berjalan; file hanya dibutuhkan klien lama.</small>
                        <?php endif; ?>
                        <br><small style="color:#666">Folder root <?php echo $writable ? '<strong style="color:#00a32a">bisa ditulisi</strong>' : '<strong style="color:#d63638">tidak bisa ditulisi</strong> -- pesan 404 pada URL legacy akan dilayani fallback plugin (endpoint tetap sama).'; ?></small>
                    </td>
                </tr>
                <tr>
                    <td><strong>Nama file</strong></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="akar_pse_save_file">
                            <?php wp_nonce_field('akar_pse_save_file'); ?>
                            <input type="text" name="proxy_file" class="regular-text" value="<?php echo esc_attr($file); ?>" spellcheck="false">
                            <?php submit_button('Simpan & Pasang', 'secondary', 'submit', false); ?>
                            <p class="description">Ubah bila nama <code>wp-api-proxy.php</code> bentrok dengan file lain. Harus berakhiran <code>.php</code>.</p>
                        </form>
                    </td>
                </tr>
                <tr>
                    <td><strong>Aksi</strong></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <input type="hidden" name="action" value="akar_pse_write_proxy">
                            <?php wp_nonce_field('akar_pse_write_proxy'); ?>
                            <?php submit_button($proxy_exists ? 'Perbaiki / Tulis Ulang' : 'Pasang File Proxy', 'primary', 'submit', false); ?>
                        </form>
                        <?php if ($proxy_exists) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:6px"
                                  onsubmit="return confirm('Hapus file proxy legacy? Klien yang memakai URL legacy akan gagal sampai file dipasang lagi (fallback plugin tetap melayani).');">
                                <input type="hidden" name="action" value="akar_pse_delete_proxy">
                                <?php wp_nonce_field('akar_pse_delete_proxy'); ?>
                                <?php submit_button('Hapus File', 'delete', 'submit', false); ?>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2 class="title">Dokumentasi Endpoint</h2>
        <p>
            Auth: <code>X-API-Key: &lt;key&gt;</code> (disarankan), <code>Authorization: Bearer &lt;key&gt;</code>, atau <code>?api_key=&lt;key&gt;</code>.
            Base REST: <code><?php echo esc_html($rest_base); ?></code>
        </p>
        <table class="widefat striped" style="max-width:1000px">
            <thead>
                <tr><th width="70">Method</th><th width="290">REST</th><th width="250">Legacy</th><th>Keterangan</th></tr>
            </thead>
            <tbody>
                <tr><td>GET</td><td><code>/ping</code></td><td><code>/ping</code></td><td>Health check publik, balasan minim <code>{ok:true,service}</code>.</td></tr>
                <tr><td>GET</td><td><code>/status</code></td><td><code>/status</code></td><td>Diagnosa (butuh key): versi WordPress/PHP/plugin, waktu server.</td></tr>
                <tr><td>GET</td><td><code>/posts?page=1&amp;per_page=10</code></td><td><code>/posts?...</code></td><td>Daftar artikel semua status (maks 50/halaman).</td></tr>
                <tr><td>POST</td><td><code>/posts</code></td><td><code>/posts</code></td><td>Buat artikel: <code>{title*, content, excerpt, slug, status, categories:[id], tags:[nama], featured_image_url}</code>.</td></tr>
                <tr><td>GET</td><td><code>/terms?taxonomy=category</code></td><td><code>/terms?...</code></td><td>Daftar term <code>{data:[{id,name,slug,count}]}</code>.</td></tr>
                <tr><td>GET</td><td><code>/seo/{post_id}</code></td><td><code>/seo/{post_id}</code></td><td>Baca meta Yoast.</td></tr>
                <tr><td>POST</td><td><code>/seo/{post_id}</code></td><td><code>/seo/{post_id}</code></td><td>Tulis meta Yoast: <code>{"yoast":{...}}</code>, flat, raw map, atau raw list <code>[{key,value}]</code>.</td></tr>
                <tr><td>POST</td><td><code>/featured-image</code></td><td><code>/featured-image</code></td><td><code>{post_id, image_url}</code> -- URL wajib publik (SSRF ditolak), maks 8 MB.</td></tr>
            </tbody>
        </table>
        <p class="description">
            Hosting yang membuang PATH_INFO setelah <code>.php</code> bisa memakai mode query pada jalur legacy:
            <code><?php echo esc_html($legacy_base); ?>?akar_route=posts&amp;page=1</code> atau
            <code>?akar_route=seo&amp;id=123</code>.
        </p>

        <h2 class="title">Tes Cepat</h2>
        <p>
            <button type="button" class="button" id="akar-test-ping">Tes REST /ping</button>
            <button type="button" class="button" id="akar-test-status">Tes REST /status</button>
            <span id="akar-test-out" style="margin-left:10px;font-family:monospace;font-size:12px;color:#555"></span>
        </p>
        <script>
        (function () {
            var key = <?php echo wp_json_encode($key, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var base = <?php echo wp_json_encode($rest_base, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            var out = document.getElementById('akar-test-out');

            function call(path) {
                out.textContent = 'memuat...';
                fetch(base + path, { headers: key ? { 'X-API-Key': key } : {} })
                    .then(function (r) { return r.text(); })
                    .then(function (t) { out.textContent = t; })
                    .catch(function (e) { out.textContent = 'gagal: ' + e.message; });
            }

            document.getElementById('akar-test-ping').addEventListener('click', function () { call('/ping'); });
            document.getElementById('akar-test-status').addEventListener('click', function () { call('/status'); });
        })();
        </script>

        <h2 class="title">Contoh Klien (n8n / cURL)</h2>
        <p>Simpan base + key per situs, lalu pilih berdasarkan <code>site_id</code> di workflow (tanpa gateway terpusat):</p>
        <pre style="background:#f6f7f7;padding:12px;max-width:1000px;overflow:auto">AKAR_SITE_<?php echo esc_html(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $site_host))); ?>_BASE=<?php echo esc_html(rtrim($rest_base, '/')); ?>

AKAR_SITE_<?php echo esc_html(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $site_host))); ?>_KEY=&lt;api-key&gt;</pre>
        <pre style="background:#f6f7f7;padding:12px;max-width:1000px;overflow:auto">curl -s "<?php echo esc_html(rtrim($rest_base, '/')); ?>/status" \
  -H "X-API-Key: &lt;api-key&gt;"</pre>
        <pre style="background:#f6f7f7;padding:12px;max-width:1000px;overflow:auto">curl -s -X POST "<?php echo esc_html(rtrim($rest_base, '/')); ?>/posts" \
  -H "X-API-Key: &lt;api-key&gt;" \
  -H "Content-Type: application/json" \
  -d '{"title":"Judul","content":"&lt;p&gt;Isi&lt;/p&gt;","status":"draft","categories":[1]}'</pre>

        <h2 class="title">Checklist Rotasi Key</h2>
        <ol style="max-width:820px">
            <li>Klik <em>Generate Key Baru</em> (atau tempel key baru lewat <em>Set manual</em>).</li>
            <li>Perbarui key di klien: environment n8n, secret manager, atau skrip otomasi.</li>
            <li>Tes <code>GET /status</code> dari klien -- harus balas <code>"status":"ok"</code>.</li>
            <li>Jalankan satu workflow uji (draft) sebelum menjadwalkan produksi.</li>
        </ol>
        <p class="description">
            Key disimpan di tabel options WordPress dan dibaca setiap request; tidak ada key yang ditulis ke file proxy.
        </p>
    </div>
    <?php
}
