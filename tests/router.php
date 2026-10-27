<?php
/**
 * Router untuk `php -S`, meniru perilaku nginx/Apache WordPress:
 *   - file .php yang ada dieksekusi (termasuk PATH_INFO setelah .php),
 *   - file .php yang tidak ada (mis. wp-api-proxy.php belum dipasang)
 *     diteruskan ke index.php seperti rewrite WordPress.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$docroot = __DIR__ . '/site';

if (preg_match('#^/([^/]+\.php)(/.*)?$#', (string) $path, $m)) {
    $file = $docroot . '/' . $m[1];

    if (is_file($file)) {
        $_SERVER['SCRIPT_NAME']     = '/' . $m[1];
        $_SERVER['SCRIPT_FILENAME'] = $file;
        $_SERVER['PATH_INFO']       = isset($m[2]) ? $m[2] : '';
        require $file;
        return true;
    }

    $_SERVER['SCRIPT_NAME']     = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $docroot . '/index.php';
    require $docroot . '/index.php';
    return true;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $docroot . '/index.php';
require $docroot . '/index.php';
return true;
