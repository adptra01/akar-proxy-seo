<?php
/**
 * Uji unit helper murni (tanpa HTTP).
 *
 * Jalankan: php tests/unit_cases.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

akar_test_seed();

$AKAR_KEY = 'test-key-1234567890abcdef';

echo "== akar_proxy_default_file() ==\n";
t_same(akar_proxy_default_file(), 'wp-api-proxy.php', 'default dari konstanta plugin');
update_option('akar_proxy_seo_proxy_file', 'proxy-kustom.php');
t_same(akar_proxy_default_file(), 'proxy-kustom.php', 'opsi plugin menang atas konstanta');
$GLOBALS['akar_proxy_route_file'] = 'paksa.php';
t_same(akar_proxy_default_file(), 'paksa.php', 'global akar_proxy_route_file menang');
unset($GLOBALS['akar_proxy_route_file']);
delete_option('akar_proxy_seo_proxy_file');

echo "\n== akar_proxy_route_file() ==\n";
$_SERVER['SCRIPT_NAME'] = '/wp-api-proxy.php';
$_SERVER['PHP_SELF']    = '/wp-api-proxy.php';
t_same(akar_proxy_route_file('/wp-api-proxy.php/posts'), 'wp-api-proxy.php', 'nama file dari SCRIPT_NAME');

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF']    = '/index.php';
t_same(akar_proxy_route_file('/proxy-lain.php/ping'), 'proxy-lain.php', 'regex .php dari path');
t_same(akar_proxy_route_file('/beranda'), 'wp-api-proxy.php', 'fallback ke nama default');

$GLOBALS['akar_proxy_route_file'] = 'dipaksa.php';
t_same(akar_proxy_route_file('/apa/saja'), 'dipaksa.php', 'override global dihormati');
unset($GLOBALS['akar_proxy_route_file']);

echo "\n== akar_proxy_segments_from_path() ==\n";
t_same(akar_proxy_segments_from_path('/wp-api-proxy.php/posts/12', 'wp-api-proxy.php'), array('posts', '12'), 'dua segmen');
t_same(akar_proxy_segments_from_path('/wp-api-proxy.php/status/', 'wp-api-proxy.php'), array('status'), 'garis miring ekor dibuang');
t_same(akar_proxy_segments_from_path('/wp-api-proxy.php', 'wp-api-proxy.php'), array(), 'hanya nama file -> kosong');
t_same(akar_proxy_segments_from_path('/wp-api-proxy.php//posts//', 'wp-api-proxy.php'), array('posts'), 'garis miring ganda diabaikan');
t_same(akar_proxy_segments_from_path('/proxy.php/ping', 'proxy.php'), array('ping'), 'nama file kustom');
t_same(akar_proxy_segments_from_path('/tidak/ada', 'wp-api-proxy.php'), array(), 'path tanpa nama file -> kosong');
t_same(akar_proxy_segments_from_path('/wp-api-proxy.php/posts?page=1', 'wp-api-proxy.php'), array('posts?page=1'), 'query TIDAK dipotong (kontrak fungsi: path, bukan URI)');
t_same(akar_proxy_segments_from_path('/WP-API-PROXY.PHP/posts', 'wp-api-proxy.php'), array('posts'), 'nama file dicocokkan tanpa peduli huruf besar');

echo "\n== akar_proxy_segments() ==\n";
$_SERVER['SCRIPT_NAME'] = '/wp-api-proxy.php';
$_SERVER['PHP_SELF']    = '/wp-api-proxy.php';
$_GET = array();
$_SERVER['REQUEST_URI'] = '/wp-api-proxy.php/posts?page=2';
t_same(akar_proxy_segments(), array('posts'), 'mode PATH_INFO, query dipotong');

$_SERVER['REQUEST_URI'] = '/wp-api-proxy.php/seo/33';
t_same(akar_proxy_segments(), array('seo', '33'), 'mode PATH_INFO dengan id');

$_SERVER['REQUEST_URI'] = '/wp-api-proxy.php';
$_GET = array('akar_route' => 'posts', 'page' => 3);
t_same(akar_proxy_segments(), array('posts'), 'mode query ?akar_route=posts');

$_GET = array('akar_route' => 'seo', 'id' => '5');
t_same(akar_proxy_segments(), array('seo', '5'), '?akar_route=seo&id=5');

$_GET = array('akar_route' => '/terms/');
t_same(akar_proxy_segments(), array('terms'), '?akar_route dengan garis miring');

$_GET = array();
t_same(akar_proxy_segments(), array(), 'tanpa route -> kosong');

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF']    = '/index.php';
$_SERVER['REQUEST_URI'] = '/wp-api-proxy.php/ping';
t_same(akar_proxy_segments(), array('ping'), 'deteksi nama file tanpa SCRIPT_NAME proxy');

echo "\n== akar_proxy_api_key() ==\n";
t_same(akar_proxy_api_key(), $AKAR_KEY, 'key dibaca dari opsi');
update_option('akar_proxy_seo_api_key', '  ' . $AKAR_KEY . "  \n");
t_same(akar_proxy_api_key(), $AKAR_KEY, 'spasi/newline dipangkas');

if (!defined('AKAR_PROXY_SEO_KEY')) {
    define('AKAR_PROXY_SEO_KEY', 'kunci-konstanta-legacy');
}
delete_option('akar_proxy_seo_api_key');
t_same(akar_proxy_api_key(), 'kunci-konstanta-legacy', 'fallback konstanta AKAR_PROXY_SEO_KEY');

add_filter('akar_proxy_seo_api_key', function ($key) {
    return 'dari-filter';
});
t_same(akar_proxy_api_key(), 'dari-filter', 'filter akar_proxy_seo_api_key menang');
$GLOBALS['wp_filters']['akar_proxy_seo_api_key'] = array();

update_option('akar_proxy_seo_api_key', $AKAR_KEY);
t_same(akar_proxy_api_key(), $AKAR_KEY, 'opsi kembali dipakai');
delete_option('akar_proxy_seo_api_key');
t_same(akar_proxy_api_key(), 'kunci-konstanta-legacy', 'opsi kosong -> konstanta lagi');
update_option('akar_proxy_seo_api_key', $AKAR_KEY);

echo "\n== akar_proxy_given_key() ==\n";
$_GET = array();
unset($_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
t_same(akar_proxy_given_key(), '', 'tanpa header/query -> kosong');

$_GET = array('api_key' => '  q-key  ');
t_same(akar_proxy_given_key(), 'q-key', 'dari ?api_key (dipangkas)');

$_GET = array();
$_SERVER['HTTP_X_API_KEY'] = ' h-key ';
t_same(akar_proxy_given_key(), 'h-key', 'dari header X-API-Key');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer b-key';
t_same(akar_proxy_given_key(), 'h-key', 'header menang atas bearer');

unset($_SERVER['HTTP_X_API_KEY']);
t_same(akar_proxy_given_key(), 'b-key', 'dari Authorization: Bearer');

$_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc';
t_same(akar_proxy_given_key(), '', 'skema selain Bearer diabaikan');

$_GET = array('api_key' => 'q-key');
unset($_SERVER['HTTP_AUTHORIZATION']);
t_same(akar_proxy_given_key(), 'q-key', 'query menang atas header');

$_GET = array();
unset($_SERVER['HTTP_X_API_KEY']);

echo "\n== akar_proxy_require_auth() ==\n";
function auth_status()
{
    akar_proxy_json_mode(true);
    try {
        akar_proxy_require_auth();
    } catch (Akar_Proxy_Json_Response $e) {
        return array($e->payload, $e->status);
    } finally {
        akar_proxy_json_mode(false);
    }
    return array(null, 0);
}

$_GET = array('api_key' => $AKAR_KEY);
list($p, $s) = auth_status();
t_same($s, 0, 'key benar -> tidak melempar');

$_GET = array('api_key' => 'salah');
list($p, $s) = auth_status();
t_same($s, 401, 'key salah -> 401');
t_same($p['error'], 'Invalid or missing API key', 'pesan 401');

$_GET = array('api_key' => $AKAR_KEY . 'x');
list($p, $s) = auth_status();
t_same($s, 401, 'key mirip (lebih panjang) tetap 401');

delete_option('akar_proxy_seo_api_key');
add_filter('akar_proxy_seo_api_key', function () {
    return '';
});
$_GET = array('api_key' => 'apa-saja');
list($p, $s) = auth_status();
t_same($s, 500, 'key belum diatur -> 500');
t_contains('belum diatur', $p['error'], 'pesan key belum diatur');
$GLOBALS['wp_filters']['akar_proxy_seo_api_key'] = array();
update_option('akar_proxy_seo_api_key', $AKAR_KEY);
$_GET = array();

echo "\n== akar_proxy_clean_value() ==\n";
t_same(akar_proxy_clean_value('int01', '1'), '1', 'int01 "1"');
t_same(akar_proxy_clean_value('int01', '0'), '0', 'int01 "0"');
t_same(akar_proxy_clean_value('int01', 1), '1', 'int01 angka 1');
t_same(akar_proxy_clean_value('int01', '7'), '', 'int01 "7" -> kosong');
t_same(akar_proxy_clean_value('int01', 'ya'), '', 'int01 teks -> kosong');
t_same(akar_proxy_clean_value('int01', ''), '', 'int01 kosong -> kosong');

t_same(akar_proxy_clean_value('url', 'https://contoh.test/a?b=1'), 'https://contoh.test/a?b=1', 'url valid dipertahankan');
t_same(akar_proxy_clean_value('url', 'javascript:alert(1)'), '', 'url skema berbahaya ditolak');
t_same(akar_proxy_clean_value('url', 'bukan url'), '', 'url tanpa skema ditolak');

t_same(akar_proxy_clean_value('text', "  <b>Halo</b>\n  dunia "), 'Halo dunia', 'text: tag dibuang, spasi dirapikan');
t_same(akar_proxy_clean_value('textarea', "Baris satu\nBaris dua"), "Baris satu\nBaris dua", 'textarea: baris baru dipertahankan');
t_same(akar_proxy_clean_value('textarea', "  <i>A</i>  \r\n B  "), "A  \n B", 'textarea: \\r dibuang, tag dibuang, trim pinggir');
t_same(akar_proxy_clean_value('lain', '  x  '), 'x', 'tipe tak dikenal diperlakukan text');

echo "\n== akar_proxy_yoast_map() ==\n";
$map = akar_proxy_yoast_map();
t_same(count($map), 12, '12 field Yoast');
$expected = array('title', 'description', 'focus_keyword', 'canonical', 'noindex', 'nofollow', 'og_title', 'og_description', 'og_image', 'twitter_title', 'twitter_description', 'twitter_image');
t_same(array_keys($map), $expected, 'urutan & nama field ramah');
$types = array();
$bad   = array();
foreach ($map as $friendly => $def) {
    t_ok(is_array($def) && count($def) === 2, 'definisi ' . $friendly . ' = [meta, tipe]');
    if (strpos($def[0], '_yoast_wpseo_') !== 0) {
        $bad[] = $friendly;
    }
    $types[$def[1]] = true;
}
t_same($bad, array(), 'semua meta key berawalan _yoast_wpseo_');
t_same(array_keys($types), array('text', 'textarea', 'url', 'int01'), 'hanya 4 tipe yang dipakai');
t_same($map['title'][1], 'text', 'title bertipe text');
t_same($map['canonical'][1], 'url', 'canonical bertipe url');
t_same($map['noindex'][1], 'int01', 'noindex bertipe int01');

echo "\n== akar_proxy_safe_image_url() ==\n";
t_same(akar_proxy_safe_image_url('https://example.test/a.png'), 'https://example.test/a.png', 'https valid lolos apa adanya');
t_same(akar_proxy_safe_image_url('  http://example.test/a.png  '), 'http://example.test/a.png', 'http valid + spasi dipangkas');

$cases = array(
    ''                          => 'akar_image_url',
    '   '                       => 'akar_image_url',
    'bukan url'                 => 'akar_image_url',
    'ftp://example.test/a.png'  => 'akar_image_url_scheme',
    'file:///etc/passwd'        => 'akar_image_url',
    'http://127.0.0.1/a.png'    => 'akar_image_url_blocked',
    'http://localhost/a.png'    => 'akar_image_url_blocked',
    'http://10.0.0.5/a.png'     => 'akar_image_url_blocked',
    'http://192.168.1.2/a.png'  => 'akar_image_url_blocked',
    'http://169.254.169.254/latest/meta-data' => 'akar_image_url_blocked',
    'http://example.test:22/a.png' => 'akar_image_url_blocked',
);
foreach ($cases as $url => $code) {
    $res = akar_proxy_safe_image_url($url);
    t_ok(is_wp_error($res), 'URL ditolak: ' . ($url === '' ? '(kosong)' : $url));
    if (is_wp_error($res)) {
        t_same($res->get_error_code(), $code, '  kode error ' . $code);
    }
}

echo "\n== akar_proxy_sideload_image() ==\n";
$att = akar_proxy_sideload_image('https://example.test/cover.png', 1);
t_ok(is_int($att) && $att > 0, 'gambar valid -> attachment id');
t_ok(isset($GLOBALS['wp_attachments'][$att]), 'attachment tercatat');
t_contains('akar-cover-', $GLOBALS['wp_attachments'][$att], 'nama file berawalan akar-cover-');
t_ok(strpos($GLOBALS['wp_attachments'][$att], '.png') !== false, 'ekstensi .png dari getimagesize');

$att = akar_proxy_sideload_image('http://127.0.0.1/x.png', 1);
t_ok(is_wp_error($att), 'URL privat ditolak sebelum download');
if (is_wp_error($att)) {
    t_same($att->get_error_code(), 'akar_image_url_blocked', 'kode error SSRF');
}

add_filter('akar_proxy_seo_max_image_bytes', function () {
    return 1;
});
$att = akar_proxy_sideload_image('https://example.test/big.png', 1);
t_ok(is_wp_error($att), 'gambar lebih besar dari batas ditolak');
if (is_wp_error($att)) {
    t_same($att->get_error_code(), 'akar_image_too_large', 'kode error batas ukuran');
    t_contains('1 B', $att->get_error_message(), 'pesan menyebut batas ukuran');
}
$GLOBALS['wp_filters']['akar_proxy_seo_max_image_bytes'] = array();

add_filter('akar_proxy_seo_max_image_bytes', function () {
    return 0;
});
$att = akar_proxy_sideload_image('https://example.test/besar.png', 1);
t_ok(is_int($att) && $att > 0, 'batas 0 = tanpa batas');
$GLOBALS['wp_filters']['akar_proxy_seo_max_image_bytes'] = array();

echo "\n== akar_proxy_body() ==\n";
$GLOBALS['akar_proxy_body_override'] = array('title' => 'Dari Override');
t_same(akar_proxy_body(), array('title' => 'Dari Override'), 'override dipakai apa adanya');
unset($GLOBALS['akar_proxy_body_override']);

$_POST = array('title' => 'Dari POST');
t_same(akar_proxy_body(), array('title' => 'Dari POST'), 'fallback ke $_POST');
$_POST = array();

echo "\n== akar_proxy_seo_payload() ==\n";
$payload = akar_proxy_seo_payload(1);
t_same(array_keys($payload), array('data'), 'payload hanya berisi data');
t_same(count($payload['data']['yoast']), 13, '12 field + readability_score');
t_same($payload['data']['yoast']['title'], 'Judul SEO Satu', 'title dari meta');
t_same($payload['data']['yoast']['readability_score'], '90', 'readability_score dari meta');
t_same($payload['data']['yoast']['nofollow'], '', 'field tanpa meta -> string kosong');
t_same(akar_proxy_seo_payload(21)['data']['yoast']['title'], '', 'post tanpa meta -> kosong');

echo "\n== akar_proxy_dispatch() default ==\n";
$_GET = array('api_key' => $AKAR_KEY);
list($p, $s) = dispatch_route_unit(array());
t_same($s, 200, 'segmen kosong -> status 200');
t_same($p['status'], 'ok', 'segmen kosong berarti /status');
t_same($p['endpoint'], 'legacy', 'endpoint default legacy di luar REST');

$_GET = array('api_key' => $AKAR_KEY);
list($p, $s) = dispatch_route_unit(array('ping'));
t_same($s, 200, '/ping 200 tanpa key');
t_same($p['ok'], true, 'ping ok');

$_GET = array();
list($p, $s) = dispatch_route_unit(array('ping'));
t_same($s, 200, 'ping tetap publik tanpa key');

$_GET = array();
list($p, $s) = dispatch_route_unit(array('status'));
t_same($s, 401, 'status tanpa key 401');

$_GET = array('api_key' => $AKAR_KEY);
list($p, $s) = dispatch_route_unit(array('featured_image'), 'POST', array('post_id' => 1, 'image_url' => 'https://example.test/x.png'));
t_same($s, 200, 'alias featured_image (garis bawah) dilayani');

list($p, $s) = dispatch_route_unit(array('seo', '1'), 'GET');
t_same($s, 200, 'seo GET 200');
t_same($p['data']['yoast']['title'], 'Judul SEO Satu', 'seo GET membaca meta');

list($p, $s) = dispatch_route_unit(array('seo'), 'GET');
t_same($s, 404, 'seo tanpa id 404');

list($p, $s) = dispatch_route_unit(array('posts'), 'DELETE');
t_same($s, 405, 'DELETE /posts 405');

$_GET = array();

/**
 * Salinan dispatch_route() dari suite REST (file ini berdiri sendiri).
 */
function dispatch_route_unit(array $segments, $method = 'GET', $body = null)
{
    akar_proxy_json_mode(true);
    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    if (is_array($body)) {
        $GLOBALS['akar_proxy_body_override'] = $body;
    }
    try {
        akar_proxy_dispatch($segments);
    } catch (Akar_Proxy_Json_Response $e) {
        return array($e->payload, $e->status);
    } finally {
        akar_proxy_json_mode(false);
        unset($GLOBALS['akar_proxy_body_override']);
    }
    return array(null, 0);
}

t_summary('unit_cases');
