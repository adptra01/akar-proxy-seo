<?php
/**
 * Uji REST API /wp-json/akar-proxy-seo/v1/... tanpa WordPress asli.
 *
 * Jalankan: php tests/rest_cases.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

akar_test_seed();
do_action('rest_api_init');

$AKAR_KEY   = 'test-key-1234567890abcdef';
$AKAR_NS    = 'akar-proxy-seo/v1';
$AKAR_ROUTES = isset($GLOBALS['wp_rest_routes'][$AKAR_NS]) ? $GLOBALS['wp_rest_routes'][$AKAR_NS] : array();

/**
 * Atur sumber key: header | query | bearer | none.
 */
function rest_auth($mode, $key = '')
{
    unset($_GET['api_key'], $_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_AUTHORIZATION']);
    if ($mode === 'header') {
        $_SERVER['HTTP_X_API_KEY'] = $key;
    } elseif ($mode === 'query') {
        $_GET['api_key'] = $key;
    } elseif ($mode === 'bearer') {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $key;
    }
}

/**
 * Panggil callback route terdaftar.
 */
function rest_call($route, $method = 'GET', array $params = array(), $json = null)
{
    global $AKAR_ROUTES;

    if (!isset($AKAR_ROUTES[$route])) {
        return new WP_REST_Response(array('error' => 'route belum terdaftar: ' . $route), 0);
    }

    $_SERVER['REQUEST_METHOD'] = strtoupper($method);
    $callback = $AKAR_ROUTES[$route]['callback'];
    return $callback(new WP_REST_Request($method, $params, $json));
}

function rest_data($response)
{
    return $response instanceof WP_REST_Response ? $response->get_data() : null;
}

function rest_status($response)
{
    return $response instanceof WP_REST_Response ? $response->get_status() : -1;
}

/**
 * Panggil dispatcher bersama langsung (jalur yang dipakai fallback/legacy).
 *
 * @return array [payload, status]
 */
function dispatch_route(array $segments, $method = 'GET', $body = null)
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

echo "== Registrasi route ==\n";
t_same(count($AKAR_ROUTES), 6, '6 route terdaftar di namespace ' . $AKAR_NS);
foreach (array('/ping', '/status', '/terms', '/posts', '/featured-image', '/seo/(?P<id>\d+)') as $route) {
    t_ok(isset($AKAR_ROUTES[$route]), 'route ' . $route . ' terdaftar');
}
t_same($AKAR_ROUTES['/ping']['methods'], 'GET', '/ping hanya GET');
t_same($AKAR_ROUTES['/posts']['methods'], 'GET, POST', '/posts GET+POST');
t_same($AKAR_ROUTES['/featured-image']['methods'], 'POST', '/featured-image hanya POST');
t_ok($AKAR_ROUTES['/seo/(?P<id>\d+)']['args']['id']['required'] === true, '/seo id wajib');
$akarIdValidator = $AKAR_ROUTES['/seo/(?P<id>\d+)']['args']['id']['validate_callback'];
t_ok(is_string($akarIdValidator) && $akarIdValidator !== 'is_numeric', 'validator /seo bukan is_numeric telanjang');
t_ok(call_user_func($akarIdValidator, '12') === true, 'validate_callback menerima angka');
// Regresi: WordPress memanggil validator dengan TIGA argumen ($value, $request,
// $param). 'is_numeric' telanjang melempar ArgumentCountError di PHP 8 sehingga
// /seo/{id} SELALU 500. Uji ini mengunci signature yang benar.
t_ok(call_user_func($akarIdValidator, '12', null, 'id') === true, 'validate_callback menerima 3 argumen (kontrak WP)');
t_ok(call_user_func($akarIdValidator, 'abc', null, 'id') === false, 'validate_callback menolak non-angka');
t_ok(call_user_func($akarIdValidator, '0', null, 'id') === false, 'validate_callback menolak id 0');
t_ok($AKAR_ROUTES['/ping']['permission_callback'] === '__return_true', 'permission_callback publik (auth di handler)');

echo "\n== /ping ==\n";
rest_auth('none');
$r = rest_call('/ping');
t_same(rest_status($r), 200, '/ping tanpa key tetap 200');
t_same(rest_data($r)['ok'], true, 'ping ok=true');
t_same(rest_data($r)['service'], 'akar-proxy-seo', 'ping service benar');
t_ok(!isset(rest_data($r)['wordpress']), 'ping tidak membocorkan versi WordPress');

echo "\n== /status ==\n";
rest_auth('header', $AKAR_KEY);
$r = rest_call('/status');
$d = rest_data($r);
t_same(rest_status($r), 200, '/status dengan key header 200');
t_same($d['status'], 'ok', 'status ok');
t_same($d['endpoint'], 'rest', 'endpoint=rest');
t_same($d['plugin_version'], defined('AKAR_PROXY_SEO_VERSION') ? AKAR_PROXY_SEO_VERSION : null, 'plugin_version sesuai konstanta plugin');
t_same($d['key_configured'], true, 'key_configured true');
t_same($d['php'], PHP_VERSION, 'versi PHP dilaporkan');

rest_auth('query', $AKAR_KEY);
t_same(rest_status(rest_call('/status')), 200, 'auth lewat ?api_key diterima');

rest_auth('bearer', $AKAR_KEY);
t_same(rest_status(rest_call('/status')), 200, 'auth lewat Bearer diterima');

rest_auth('none');
$r = rest_call('/status');
t_same(rest_status($r), 401, '/status tanpa key 401');
t_same(rest_data($r)['error'], 'Invalid or missing API key', 'pesan 401 jelas');

rest_auth('header', 'kunci-yang-salah');
t_same(rest_status(rest_call('/status')), 401, 'key salah 401');

echo "\n== /terms ==\n";
rest_auth('header', $AKAR_KEY);
$_GET = array('taxonomy' => 'category');
$r = rest_call('/terms');
$d = rest_data($r);
t_same(rest_status($r), 200, '/terms 200');
t_same(count($d['data']), 3, '3 kategori');
t_same($d['data'][1]['name'], 'Digitalisasi', 'nama kategori kedua');
t_ok(array_keys($d['data'][0]) === array('id', 'name', 'slug', 'count'), 'bentuk item {id,name,slug,count}');

$_GET = array('taxonomy' => 'post_tag');
$r = rest_call('/terms');
t_same(count(rest_data($r)['data']), 1, '1 tag');
t_same(rest_data($r)['data'][0]['slug'], 'n8n', 'tag n8n');

$_GET = array('taxonomy' => 'tidak_ada');
$r = rest_call('/terms');
t_same(rest_status($r), 404, 'taxonomy tak dikenal 404');

$_GET = array();
t_same(rest_status(rest_call('/terms', 'POST', array(), array())), 404, 'POST /terms tidak dilayani (404)');

echo "\n== /posts GET ==\n";
rest_auth('header', $AKAR_KEY);
$_GET = array();
$r = rest_call('/posts');
$d = rest_data($r);
t_same(rest_status($r), 200, 'GET /posts 200');
t_same($d['total'], 13, 'total 13 post');
t_same(count($d['posts']), 10, 'default 10 per halaman');
t_same($d['page'], 1, 'page 1');

$first = $d['posts'][0];
t_ok(isset($first['id'], $first['title'], $first['slug'], $first['status'], $first['date'], $first['modified'], $first['link'], $first['categories']), 'bentuk item post lengkap');
t_same($first['id'], 22, 'post terbaru = 22');
t_same($first['status'], 'draft', 'draft ikut terdaftar');
t_ok(strpos($first['link'], '?p=22') !== false, 'link memakai permalink');

$_GET = array('page' => 2, 'per_page' => 10);
$d = rest_data(rest_call('/posts'));
t_same(count($d['posts']), 3, 'halaman 2 berisi 3 post');
$ids = array();
foreach ($d['posts'] as $p) {
    $ids[] = $p['id'];
}
t_ok(in_array(1, $ids, true), 'post 1 ada di halaman 2');
sort($ids);
t_same($ids, array(1, 11, 12), 'isi halaman 2 tepat');

$_GET = array('per_page' => 100);
$d = rest_data(rest_call('/posts'));
t_same($d['per_page'], 50, 'per_page dibatasi 50');

$_GET = array('page' => 99);
$d = rest_data(rest_call('/posts'));
t_same(count($d['posts']), 0, 'halaman jauh kosong');
t_same($d['total'], 13, 'total tetap 13 di halaman kosong');

echo "\n== /posts POST ==\n";
rest_auth('header', $AKAR_KEY);
$_GET = array();
$payload = array(
    'title'      => 'Artikel Uji n8n',
    'content'    => '<p>Halo "dunia" & selamat</p>',
    'excerpt'    => 'Ringkas',
    'status'     => 'publish',
    'categories' => array(3),
    'tags'       => array('n8n'),
);
$r = rest_call('/posts', 'POST', array(), $payload);
$d = rest_data($r);
t_same(rest_status($r), 201, 'POST /posts 201');
t_ok($d['post']['id'] > 0, 'post id baru');
t_same($d['post']['title'], 'Artikel Uji n8n', 'judul tersimpan');
t_same($d['post']['status'], 'publish', 'status tersimpan');
t_same($d['post']['slug'], 'artikel-uji-n8n', 'slug dibuat dari judul');
t_same($d['post']['content'], $payload['content'], 'konten utuh (slash tidak bocor)');
t_same($d['post']['categories'], array(3), 'kategori tersimpan');
t_same($d['post']['tags'], array(7), 'tag nama dipetakan ke id');
t_same($d['post']['author']['display_name'], 'Admin Akar', 'author display_name');
t_same($d['post']['featured_image'], null, 'featured_image null bila tidak dikirim');

$r = rest_call('/posts', 'POST', array(), array('title' => 'Status Asing', 'status' => 'aneh'));
t_same(rest_data($r)['post']['status'], 'draft', 'status asing -> draft');

$r = rest_call('/posts', 'POST', array(), array('content' => 'tanpa judul'));
t_same(rest_status($r), 400, 'tanpa judul 400');
t_contains('title', rest_data($r)['error'], 'pesan menyebut title');

$r = rest_call('/posts', 'POST', array(), array(
    'title'              => 'Dengan Cover',
    'featured_image_url' => 'https://example.com/cover.png',
));
$d = rest_data($r);
t_ok(is_int($d['post']['featured_image']) && $d['post']['featured_image'] > 0, 'featured image dari URL valid');
t_ok(!isset($d['post']['featured_image_error']), 'tidak ada error cover');

$r = rest_call('/posts', 'POST', array(), array(
    'title'              => 'Cover Ditolak',
    'featured_image_url' => 'http://127.0.0.1/cover.png',
));
$d = rest_data($r);
t_same(rest_status($r), 201, 'post tetap dibuat walau cover ditolak');
t_same($d['post']['featured_image'], null, 'featured_image null saat SSRF');
t_contains('privat', $d['post']['featured_image_error'], 'alasan SSRF dilaporkan');

echo "\n== /seo ==\n";
rest_auth('header', $AKAR_KEY);
$r = rest_call('/seo/(?P<id>\d+)', 'GET', array('id' => 1));
$d = rest_data($r);
t_same(rest_status($r), 200, 'GET /seo/1 200');
t_same($d['data']['yoast']['title'], 'Judul SEO Satu', 'yoast title terbaca');
t_same($d['data']['yoast']['description'], 'Deskripsi SEO Satu', 'yoast description terbaca');
t_same($d['data']['yoast']['readability_score'], '90', 'readability score terbaca');

$r = rest_call('/seo/(?P<id>\d+)', 'GET', array('id' => 9999));
t_same(rest_status($r), 404, 'post tidak ada 404');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array('yoast' => array('title' => 'Judul Baru')));
$d = rest_data($r);
t_same(rest_status($r), 200, 'POST /seo 200');
t_same($d['updated'], true, 'updated true');
t_same($d['data']['yoast']['title'], 'Judul Baru', 'bentuk {yoast:{...}} tersimpan');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array('focus_keyword' => 'otomasi n8n'));
t_same(rest_data($r)['data']['yoast']['focus_keyword'], 'otomasi n8n', 'bentuk flat map tersimpan');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array('_yoast_wpseo_opengraph-title' => 'OG Title'));
t_same(rest_data($r)['data']['yoast']['og_title'], 'OG Title', 'bentuk raw _yoast_wpseo_* tersimpan');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array(array('key' => 'twitter_title', 'value' => 'Twitter T')));
t_same(rest_data($r)['data']['yoast']['twitter_title'], 'Twitter T', 'bentuk list {key,value} nama ramah tersimpan');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array(array('key' => '_yoast_wpseo_twitter-title', 'value' => 'Twitter T2')));
t_same(rest_data($r)['data']['yoast']['twitter_title'], 'Twitter T2', 'bentuk list {key,value} meta mentah tersimpan');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array());
t_same(rest_status($r), 400, 'body kosong 400');
t_contains('kosong', rest_data($r)['error'], 'pesan body kosong');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array('tidak_dikenal' => 'x'));
t_same(rest_status($r), 400, 'field tak dikenal 400');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array('noindex' => '1'));
t_same(rest_data($r)['data']['yoast']['noindex'], '1', 'noindex 1 tersimpan');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array('noindex' => '7'));
t_same(rest_data($r)['data']['yoast']['noindex'], '', 'noindex selain 0/1 -> kosong');

$r = rest_call('/seo/(?P<id>\d+)', 'POST', array('id' => 1), array('canonical' => 'bukan url'));
t_same(rest_data($r)['data']['yoast']['canonical'], '', 'canonical tidak valid -> kosong');

echo "\n== /featured-image ==\n";
rest_auth('header', $AKAR_KEY);
$r = rest_call('/featured-image', 'POST', array(), array('post_id' => 1, 'image_url' => 'https://example.com/foto.png'));
$d = rest_data($r);
t_same(rest_status($r), 200, 'featured-image 200');
t_ok($d['data']['attachment_id'] > 0, 'attachment id dibuat');
t_contains('/wp-content/uploads/', $d['data']['source_url'], 'source_url attachment');
t_same($d['updated'], true, 'updated true');
t_same($d['id'], 1, 'id post dilaporkan');

$r = rest_call('/featured-image', 'POST', array(), array('post_id' => 1, 'image_url' => ''));
t_same(rest_status($r), 400, 'image_url kosong 400');
t_contains('wajib', rest_data($r)['error'], 'pesan image_url wajib');

$r = rest_call('/featured-image', 'POST', array(), array('post_id' => 9999, 'image_url' => 'https://example.com/foto.png'));
t_same(rest_status($r), 400, 'post_id tidak ada 400');

$r = rest_call('/featured-image', 'POST', array(), array('post_id' => 1, 'image_url' => 'http://169.254.169.254/latest/meta-data'));
t_same(rest_status($r), 400, 'metadata cloud ditolak');
t_contains('privat', rest_data($r)['error'], 'alasan metadata cloud');

$r = rest_call('/featured-image', 'POST', array(), array('post_id' => 1, 'image_url' => 'ftp://example.com/foto.png'));
t_same(rest_status($r), 400, 'skema ftp ditolak');
t_contains('http', rest_data($r)['error'], 'alasan skema');

echo "\n== Method & auth tepi ==\n";
rest_auth('header', $AKAR_KEY);
t_same(rest_status(rest_call('/posts', 'PUT', array(), array())), 405, 'PUT /posts 405');
t_same(rest_status(rest_call('/featured-image', 'GET', array())), 404, 'GET /featured-image 404');

list($dispatchPayload, $dispatchStatus) = dispatch_route(array('tidak-ada'));
t_same($dispatchStatus, 404, 'dispatcher: route tak dikenal 404');
t_contains('Route tidak ditemukan', $dispatchPayload['error'], 'pesan route tidak ditemukan');

list($dispatchPayload, $dispatchStatus) = dispatch_route(array('seo', '9999'));
t_same($dispatchStatus, 404, 'dispatcher: seo post tidak ada 404');

list($dispatchPayload, $dispatchStatus) = dispatch_route(array('posts'), 'POST', array('title' => 'Lewat Dispatcher'));
t_same($dispatchStatus, 201, 'dispatcher: POST /posts 201');
t_same($dispatchPayload['post']['title'], 'Lewat Dispatcher', 'dispatcher: judul tersimpan');

rest_auth('none');
t_same(rest_status(rest_call('/terms', 'GET', array())), 401, '/terms tanpa key 401');

delete_option('akar_proxy_seo_api_key');
rest_auth('header', 'apa-saja');
$r = rest_call('/status');
t_same(rest_status($r), 500, 'key belum diatur 500');
t_contains('belum diatur', rest_data($r)['error'], 'pesan key belum diatur');
update_option('akar_proxy_seo_api_key', $AKAR_KEY);

echo "\n== Mode JSON selalu direset ==\n";
rest_auth('header', $AKAR_KEY);
rest_call('/status');
t_same(akar_proxy_json_mode(), false, 'mode REST false setelah sukses');
rest_call('/seo/(?P<id>\d+)', 'GET', array('id' => 9999));
t_same(akar_proxy_json_mode(), false, 'mode REST false setelah error');

echo "\n== Halaman admin ==\n";
ob_start();
akar_pse_admin_page();
$html = ob_get_clean();
t_contains('Akar Proxy SEO', $html, 'halaman admin ter-render');
t_contains('akar-proxy-seo/v1', $html, 'halaman admin menyebut REST base');
t_contains($AKAR_KEY, $html, 'halaman admin menampilkan key aktif');

echo "\n== Auto-update (manifest) ==\n";
akar_test_http_reset();
delete_transient('akar_pse_manifest');
delete_transient('akar_pse_manifest_fail');
delete_transient('akar_pse_manifest_status');

$manifest_url = add_query_arg('cb', (string) (int) floor(time() / HOUR_IN_SECONDS), AKAR_PROXY_SEO_MANIFEST_URL);

// 0. URL distribusi adalah kontrak: situs yang sudah terpasang memakai konstanta
//    ini, jadi mengubahnya memutus auto-update tanpa error apa pun.
t_same(AKAR_PROXY_SEO_MANIFEST_URL, 'https://adptra01.github.io/akar-proxy-seo/akar-proxy-seo.json', 'manifest menunjuk GitHub Pages');
t_same(AKAR_PROXY_SEO_HOMEPAGE, 'https://adptra01.github.io/akar-proxy-seo/', 'homepage menunjuk halaman repo');

// 1. Jaringan mati: tidak boleh melempar error, tidak boleh mengaku berhasil.
$got = akar_pse_fetch_manifest(true);
t_same($got, false, 'manifest gagal -> false (tidak melempar)');
$st = akar_pse_manifest_status();
t_same(isset($st['ok']) ? $st['ok'] : null, false, 'status manifest mencatat kegagalan');
t_ok(get_transient('akar_pse_manifest_fail') !== false, 'kegagalan memasang backoff 15 menit');
$reqs = $GLOBALS['wp_http_requests'];
t_same(count($reqs), 1, 'satu permintaan HTTP per percobaan');
t_same($reqs[0]['url'], $manifest_url, 'URL manifest memakai cache-buster per jam');
t_same(isset($reqs[0]['args']['timeout']) ? $reqs[0]['args']['timeout'] : null, 5, 'timeout 5 detik agar tidak memblokir admin');

// 2. Selama backoff, manifest tidak diambil lagi.
$GLOBALS['wp_http_requests'] = array();
akar_pse_fetch_manifest(false);
t_same(count($GLOBALS['wp_http_requests']), 0, 'backoff mencegah permintaan berulang');

// 3. Manifest versi lebih baru -> masuk daftar update.
akar_test_http_reset();
delete_transient('akar_pse_manifest_fail');
akar_test_http_fake($manifest_url, 200, wp_json_encode(array(
    'version'      => '9.9.9',
    'download_url' => 'https://rilis.contoh/akar-proxy-seo.zip',
    'requires'     => '5.6',
    'tested'       => '6.8',
    'requires_php' => '7.4',
    'changelog'    => '<h4>9.9.9</h4>',
)));
$manifest = akar_pse_fetch_manifest(true);
t_same($manifest['version'], '9.9.9', 'manifest terbaca');
t_same(akar_pse_manifest_status()['ok'], true, 'status manifest mencatat keberhasilan');

$transient = (object) array('response' => array(), 'no_update' => array());
$transient = apply_filters('pre_set_site_transient_update_plugins', $transient);
$base      = plugin_basename(AKAR_PROXY_SEO_FILE);
t_ok(isset($transient->response[$base]), 'versi lebih baru masuk response');
t_same($transient->response[$base]->new_version, '9.9.9', 'new_version dari manifest');
t_same($transient->response[$base]->package, 'https://rilis.contoh/akar-proxy-seo.zip', 'package = download_url');
t_same($transient->response[$base]->slug, 'akar-proxy-seo', 'slug benar');
t_ok(!isset($transient->no_update[$base]), 'tidak dobel di no_update');

// 4. Manifest sama dengan versi terpasang -> no_update, bukan response.
akar_test_http_reset();
delete_transient('akar_pse_manifest');
akar_test_http_fake($manifest_url, 200, wp_json_encode(array(
    'version'      => AKAR_PROXY_SEO_VERSION,
    'download_url' => 'https://rilis.contoh/akar-proxy-seo.zip',
)));
$transient = (object) array('response' => array(), 'no_update' => array());
$transient = apply_filters('pre_set_site_transient_update_plugins', $transient);
t_ok(!isset($transient->response[$base]), 'versi sama tidak memicu update');
t_ok(isset($transient->no_update[$base]), 'versi sama dicatat di no_update');

// 5. JSON rusak / tidak lengkap -> dianggap gagal, cache lama tidak ditimpa.
akar_test_http_reset();
delete_transient('akar_pse_manifest');
akar_test_http_fake($manifest_url, 200, 'bukan-json');
t_same(akar_pse_fetch_manifest(true), false, 'JSON rusak -> false');
t_contains('tidak lengkap', (string) akar_pse_manifest_status()['error'], 'sebab kegagalan dicatat');

// 6. plugins_api hanya menjawab slug plugin ini.
akar_test_http_reset();
delete_transient('akar_pse_manifest');
delete_transient('akar_pse_manifest_fail');
akar_test_http_fake($manifest_url, 200, wp_json_encode(array(
    'version'      => '9.9.9',
    'download_url' => 'https://rilis.contoh/akar-proxy-seo.zip',
    'homepage'     => 'https://contoh.test',
    'description'  => 'Deskripsi',
    'changelog'    => '<h4>9.9.9</h4>',
)));
$info = apply_filters('plugins_api', false, 'plugin_information', (object) array('slug' => 'akar-proxy-seo'));
t_ok(is_object($info), 'plugins_api menjawab slug sendiri');
t_same($info->version, '9.9.9', 'info versi dari manifest');
t_same($info->download_link, 'https://rilis.contoh/akar-proxy-seo.zip', 'info download_link benar');
$other = apply_filters('plugins_api', 'NILAI-ASLI', 'plugin_information', (object) array('slug' => 'plugin-lain'));
t_same($other, 'NILAI-ASLI', 'plugins_api slug lain tidak diubah');
t_same(apply_filters('plugins_api', 'NILAI-ASLI', 'query_plugins', new stdClass()), 'NILAI-ASLI', 'aksi lain tidak diubah');

echo "\n== Auto-update (auto_update_plugin) ==\n";
$item = (object) array('plugin' => $base);
t_same(apply_filters('auto_update_plugin', null, $item), true, 'plugin ini diizinkan auto-update');
t_same(apply_filters('auto_update_plugin', null, (object) array('plugin' => 'plugin-lain/plugin-lain.php')), null, 'plugin lain tidak diubah');
t_same(apply_filters('auto_update_plugin', false, $item), true, 'plugin ini tetap auto-update walau nilai awal false');
t_same(apply_filters('auto_update_plugin', true, (object) array('plugin' => 'plugin-lain/plugin-lain.php')), true, 'plugin lain mempertahankan nilai awal');
t_same(apply_filters('auto_update_plugin', null, (object) array()), null, 'item tanpa properti plugin tidak diubah');

echo "\n== Sumber proxy legacy ==\n";
$src = akar_pse_proxy_source();
t_ok(is_string($src) && $src !== '', 'akar_pse_proxy_source() menghasilkan isi');
t_ok(strpos($src, "<?php\n") === 0, 'diawali <?php');
t_contains('function akar_proxy_dispatch', $src, 'berisi dispatcher');
t_same(substr_count($src, 'class Akar_Proxy_Json_Response'), 1, 'kelas response tepat sekali');
t_ok(strpos($src, '@@AKAR_PROXY_SEO_HANDLERS@@') === false, 'penanda sudah terganti');
t_contains("if (!defined('ABSPATH'))", $src, 'berisi bootstrap wp-load');
t_same(akar_pse_proxy_filename(), 'wp-api-proxy.php', 'nama file proxy default');

t_summary('rest_cases');
