<?php
/**
 * Uji end-to-end jalur legacy lewat HTTP sungguhan (`php -S`).
 *
 * Yang dibuktikan:
 *   1. fallback plugin melayani /wp-api-proxy.php/... walau file tidak ada,
 *   2. file legacy hasil generate bisa berdiri sendiri (tanpa dobel-serving),
 *   3. mode query ?akar_route= sama di kedua jalur,
 *   4. setelah file dihapus, fallback kembali mengambil alih.
 *
 * Jalankan: php tests/legacy_cases.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib.php';

$AKAR_KEY  = 'test-key-1234567890abcdef';
$akarProxy = ABSPATH . 'wp-api-proxy.php';

/* -------------------------------------------------------------------------- */
/* Helper                                                                      */
/* -------------------------------------------------------------------------- */

/**
 * Cari port TCP bebas.
 *
 * @return int
 */
function akar_test_free_port()
{
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!$sock) {
        return 8099;
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);

    $parts = explode(':', (string) $name);
    $port  = (int) end($parts);

    return $port > 0 ? $port : 8099;
}

/**
 * Panggil satu request HTTP/1.0 (tanpa chunked, tanpa keep-alive).
 *
 * @param int         $port
 * @param string      $method
 * @param string      $target
 * @param array|null  $body
 * @param array       $headers
 * @return array{0:int,1:string,2:string} [status, body, head]
 */
function akar_http($port, $method, $target, $body = null, array $headers = array())
{
    $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5);
    if (!$fp) {
        return array(0, '', 'koneksi gagal: ' . $errstr);
    }

    $payload = '';
    $lines   = array(
        $method . ' ' . $target . ' HTTP/1.0',
        'Host: 127.0.0.1:' . $port,
        'Connection: close',
    );

    if ($body !== null) {
        $payload = is_array($body) ? json_encode($body) : (string) $body;
        $lines[] = 'Content-Type: application/json';
        $lines[] = 'Content-Length: ' . strlen($payload);
    }

    foreach ($headers as $header) {
        $lines[] = $header;
    }

    fwrite($fp, implode("\r\n", $lines) . "\r\n\r\n");
    if ($payload !== '') {
        fwrite($fp, $payload);
    }

    stream_set_timeout($fp, 20);
    $raw = stream_get_contents($fp);
    fclose($fp);

    $raw   = is_string($raw) ? $raw : '';
    $split = preg_split("/\r?\n\r?\n/", $raw, 2);
    $head  = isset($split[0]) ? $split[0] : '';
    $rest  = isset($split[1]) ? $split[1] : '';

    $status = 0;
    if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $m)) {
        $status = (int) $m[1];
    }

    return array($status, $rest, $head);
}

/**
 * @param int    $port
 * @param string $method
 * @param string $target
 * @param array|null $body
 * @return array{0:int,1:mixed,2:string}
 */
function akar_http_json($port, $method, $target, $body = null)
{
    list($status, $raw) = akar_http($port, $method, $target, $body);

    return array($status, json_decode($raw, true), $raw);
}

/**
 * Tunggu sampai server menerima koneksi.
 *
 * @param int $port
 * @return bool
 */
function akar_wait_port($port)
{
    for ($i = 0; $i < 120; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
        if ($fp) {
            fclose($fp);
            return true;
        }
        usleep(50000);
    }

    return false;
}

/* -------------------------------------------------------------------------- */
/* Bersihkan sisa run sebelumnya                                               */
/* -------------------------------------------------------------------------- */

if (file_exists($akarProxy)) {
    @unlink($akarProxy);
}

if (file_exists($akarProxy)) {
    echo "Tidak bisa menghapus {$akarProxy}; jalankan di folder yang bisa ditulisi.\n";
    exit(1);
}

$akarPort = akar_test_free_port();
$akarLog  = sys_get_temp_dir() . '/akar-legacy-server-' . getmypid() . '.log';
@unlink($akarLog);

$akarCmd = escapeshellarg(PHP_BINARY)
    . ' -S 127.0.0.1:' . $akarPort
    . ' -t ' . escapeshellarg(__DIR__ . '/site')
    . ' ' . escapeshellarg(__DIR__ . '/router.php');

$akarProc = proc_open(
    $akarCmd,
    array(
        0 => array('pipe', 'r'),
        1 => array('file', $akarLog, 'a'),
        2 => array('file', $akarLog, 'a'),
    ),
    $akarPipes,
    __DIR__
);

if (!is_resource($akarProc)) {
    echo "Gagal menjalankan php -S.\n";
    exit(1);
}

register_shutdown_function(function () use ($akarProc, $akarProxy, $akarLog) {
    if (is_resource($akarProc)) {
        @proc_terminate($akarProc);
        @proc_close($akarProc);
    }
    if (file_exists($akarProxy)) {
        @unlink($akarProxy);
    }
    @unlink($akarLog);
});

$akarBase = 'http://127.0.0.1:' . $akarPort;

if (!akar_wait_port($akarPort)) {
    echo "Server uji tidak siap di port {$akarPort}. Log:\n";
    echo @file_get_contents($akarLog);
    exit(1);
}

echo "Server uji: {$akarBase}\n";

/* -------------------------------------------------------------------------- */
/* 1. Fallback plugin (file proxy BELUM ada)                                   */
/* -------------------------------------------------------------------------- */

echo "\n== Fallback plugin (file proxy tidak ada) ==\n";
t_ok(!file_exists($akarProxy), 'file proxy memang belum ada');

list($s, $j, $raw) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/ping');
t_same($s, 200, 'GET /wp-api-proxy.php/ping 200');
t_ok(is_array($j), 'respons ping JSON utuh (tidak ada warning yang bocor)', substr($raw, 0, 200));
t_same(isset($j['ok']) ? $j['ok'] : null, true, 'ping ok');
t_same(isset($j['service']) ? $j['service'] : null, 'akar-proxy-seo', 'ping menyebut service');

list($s, $j, $raw) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/status?api_key=' . $AKAR_KEY);
t_same($s, 200, 'GET /status dengan key 200');
t_ok(is_array($j), 'respons status JSON utuh', substr($raw, 0, 200));
t_same(isset($j['endpoint']) ? $j['endpoint'] : null, 'plugin-fallback', 'endpoint = plugin-fallback');
t_same(isset($j['plugin_version']) ? $j['plugin_version'] : null, defined('AKAR_PROXY_SEO_VERSION') ? AKAR_PROXY_SEO_VERSION : null, 'versi plugin dilaporkan');
t_same(isset($j['key_configured']) ? $j['key_configured'] : null, true, 'key_configured true');

list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/status');
t_same($s, 401, 'GET /status tanpa key 401');
t_same(isset($j['error']) ? $j['error'] : null, 'Invalid or missing API key', 'pesan 401');

$h = akar_http($akarPort, 'GET', '/wp-api-proxy.php/status', null, array('X-API-Key: ' . $AKAR_KEY));
t_same($h[0], 200, 'auth lewat header X-API-Key');

$h = akar_http($akarPort, 'GET', '/wp-api-proxy.php/status', null, array('Authorization: Bearer ' . $AKAR_KEY));
t_same($h[0], 200, 'auth lewat Authorization: Bearer');

list($s, $j, $raw) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/posts?api_key=' . $AKAR_KEY . '&per_page=5');
t_same($s, 200, 'GET /posts 200');
t_ok(is_array($j), 'respons posts JSON utuh', substr($raw, 0, 200));
t_same(isset($j['total']) ? $j['total'] : null, 13, 'total posts 13');
t_same(isset($j['posts']) && is_array($j['posts']) ? count($j['posts']) : null, 5, 'per_page=5 dihormati');
t_same(isset($j['page']) ? $j['page'] : null, 1, 'page default 1');

list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/terms?api_key=' . $AKAR_KEY . '&taxonomy=category');
t_same($s, 200, 'GET /terms category 200');
t_same(isset($j['data']) && is_array($j['data']) ? count($j['data']) : null, 3, '3 kategori dikembalikan');

list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/seo/1?api_key=' . $AKAR_KEY);
t_same($s, 200, 'GET /seo/1 200');
t_same(isset($j['data']['yoast']['title']) ? $j['data']['yoast']['title'] : null, 'Judul SEO Satu', 'meta Yoast terbaca lewat HTTP');

list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/tidak-ada?api_key=' . $AKAR_KEY);
t_same($s, 404, 'route tak dikenal 404');
t_same(isset($j['error']) ? $j['error'] : null, 'Route tidak ditemukan', 'pesan route tak dikenal');

list($s, $j) = akar_http_json($akarPort, 'POST', '/wp-api-proxy.php/posts?api_key=' . $AKAR_KEY, array(
    'title'   => 'Artikel Lewat Fallback',
    'content' => '<p>Isi artikel</p>',
    'status'  => 'publish',
));
t_same($s, 201, 'POST /posts 201');
t_same(isset($j['post']['title']) ? $j['post']['title'] : null, 'Artikel Lewat Fallback', 'judul artikel tersimpan');

list($s, $j) = akar_http_json($akarPort, 'POST', '/wp-api-proxy.php/posts', array('title' => 'Tanpa Key'));
t_same($s, 401, 'POST /posts tanpa key 401');

echo "\n== Fallback: mode query ?akar_route= ==\n";
list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php?akar_route=ping');
t_same($s, 200, '?akar_route=ping 200');
t_same(isset($j['ok']) ? $j['ok'] : null, true, 'ping lewat query');

list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php?akar_route=posts&api_key=' . $AKAR_KEY . '&per_page=3');
t_same($s, 200, '?akar_route=posts 200');
t_same(isset($j['total']) ? $j['total'] : null, 13, 'total posts lewat query');
t_same(isset($j['posts']) && is_array($j['posts']) ? count($j['posts']) : null, 3, 'per_page lewat query');

/* -------------------------------------------------------------------------- */
/* 2. File proxy hasil generate (legacy standalone)                            */
/* -------------------------------------------------------------------------- */

echo "\n== File proxy hasil generate ==\n";
list($written, $message) = akar_pse_write_proxy_file();
t_same($written, true, 'akar_pse_write_proxy_file() berhasil', $message);
t_ok(file_exists($akarProxy), 'file proxy ada di root WordPress');
t_ok(filesize($akarProxy) > 10000, 'ukuran file wajar (' . filesize($akarProxy) . ' byte)');

$src = (string) file_get_contents($akarProxy);
t_ok(strpos($src, '<?php') === 0, 'diawali <?php');
t_contains('function akar_proxy_dispatch', $src, 'berisi dispatcher');
t_ok(strpos($src, '@@AKAR_PROXY_SEO_HANDLERS@@') === false, 'penanda sudah terganti');
t_same(substr_count($src, 'class Akar_Proxy_Json_Response'), 1, 'kelas response tepat sekali');
t_contains('wp-load.php', $src, 'berisi bootstrap wp-load');
t_ok(strpos($src, $AKAR_KEY) === false, 'API key tidak ikut tertulis di file proxy');
t_ok(strpos($src, 'test-key-') === false, 'tidak ada sisa key uji di file proxy');

list($s, $j, $raw) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/ping');
t_same($s, 200, 'file: GET /ping 200');
t_ok(is_array($j), 'file: respons JSON utuh', substr($raw, 0, 200));
t_same(isset($j['ok']) ? $j['ok'] : null, true, 'file: ping ok');

list($s, $j, $raw) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/status?api_key=' . $AKAR_KEY);
t_same($s, 200, 'file: GET /status 200');
t_ok(is_array($j), 'file: respons status JSON utuh (tidak dobel-serving)', substr($raw, 0, 200));
t_same(isset($j['endpoint']) ? $j['endpoint'] : null, 'legacy', 'file: endpoint = legacy');

list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/posts?api_key=' . $AKAR_KEY . '&per_page=2');
t_same($s, 200, 'file: GET /posts 200');
t_same(isset($j['total']) ? $j['total'] : null, 13, 'file: total posts 13');
t_same(isset($j['posts']) && is_array($j['posts']) ? count($j['posts']) : null, 2, 'file: per_page=2');

list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php?akar_route=status&api_key=' . $AKAR_KEY);
t_same($s, 200, 'file: ?akar_route=status 200');
t_same(isset($j['endpoint']) ? $j['endpoint'] : null, 'legacy', 'file: query mode tetap endpoint legacy');

list($s, $j) = akar_http_json($akarPort, 'POST', '/wp-api-proxy.php/posts?api_key=' . $AKAR_KEY, array(
    'title'  => 'Artikel Lewat File Legacy',
    'status' => 'draft',
));
t_same($s, 201, 'file: POST /posts 201');
t_same(isset($j['post']['title']) ? $j['post']['title'] : null, 'Artikel Lewat File Legacy', 'file: judul tersimpan');
t_same(isset($j['post']['status']) ? $j['post']['status'] : null, 'draft', 'file: status draft dihormati');

list($s, $j) = akar_http_json($akarPort, 'POST', '/wp-api-proxy.php/featured-image?api_key=' . $AKAR_KEY, array(
    'post_id'   => 1,
    'image_url' => 'https://example.test/legacy-cover.png',
));
t_same($s, 200, 'file: POST /featured-image 200');
t_same(isset($j['updated']) ? $j['updated'] : null, true, 'file: featured image terpasang');

list($s, $j) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/status');
t_same($s, 401, 'file: GET /status tanpa key 401');

/* -------------------------------------------------------------------------- */
/* 3. Hapus file -> fallback kembali melayani                                  */
/* -------------------------------------------------------------------------- */

echo "\n== Hapus file proxy: fallback kembali ==\n";
list($deleted, $deleteMessage) = akar_pse_delete_proxy_file();
t_same($deleted, true, 'akar_pse_delete_proxy_file() berhasil', $deleteMessage);
t_ok(!file_exists($akarProxy), 'file proxy sudah tidak ada');

list($s, $j, $raw) = akar_http_json($akarPort, 'GET', '/wp-api-proxy.php/status?api_key=' . $AKAR_KEY);
t_same($s, 200, 'tanpa file: GET /status 200');
t_ok(is_array($j), 'tanpa file: respons JSON utuh', substr($raw, 0, 200));
t_same(isset($j['endpoint']) ? $j['endpoint'] : null, 'plugin-fallback', 'tanpa file: endpoint plugin-fallback');
t_same(isset($j['key_configured']) ? $j['key_configured'] : null, true, 'tanpa file: key tetap terbaca');

/* -------------------------------------------------------------------------- */
/* 4. Server tidak menulis warning ke log                                      */
/* -------------------------------------------------------------------------- */

echo "\n== Log server ==\n";
$log = is_file($akarLog) ? (string) file_get_contents($akarLog) : '';
t_ok(strpos($log, 'PHP Fatal error') === false, 'tidak ada fatal error di log server');
t_ok(strpos($log, 'PHP Warning') === false, 'tidak ada warning di log server', substr($log, -400));
t_ok(strpos($log, 'PHP Notice') === false, 'tidak ada notice di log server', substr($log, -400));

t_summary('legacy_cases');
