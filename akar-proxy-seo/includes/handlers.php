<?php
/**
 * Akar Proxy SEO -- shared handler layer.
 *
 * File ini dipakai oleh dua jalur yang berbeda:
 *   1. plugin (REST `/wp-json/akar-proxy-seo/v1/...` + fallback proxy legacy),
 *   2. file legacy `wp-api-proxy.php` (isinya digabung saat file ditulis).
 *
 * Karena itu handler TIDAK boleh menulis header atau memanggil exit langsung.
 * Semua respons lewat akar_proxy_json()/akar_proxy_fail(); saat mode REST aktif
 * respons dilempar sebagai Akar_Proxy_Json_Response lalu ditangkap pemanggil.
 *
 * Jalur legacy tetap memakai perilaku lama: header + echo + exit, sehingga file
 * hasil gabungan bisa dipanggil langsung lewat URL tanpa bootstrap WordPress.
 */

if (!function_exists('akar_proxy_dispatch')) {
    /**
     * Dilempar saat handler dipanggil dalam mode REST.
     */
    class Akar_Proxy_Json_Response extends Exception
    {
        /** @var array */
        public $payload;

        /** @var int */
        public $status;

        /**
         * @param array $payload
         * @param int   $status
         */
        public function __construct($payload, $status = 200)
        {
            $this->payload = is_array($payload) ? $payload : array('data' => $payload);
            $this->status  = (int) $status;
            parent::__construct('akar-proxy-json-response', (int) $status);
        }
    }

    /**
     * Aktifkan/matikan mode REST. Tanpa argumen hanya membaca nilai sekarang.
     *
     * @param bool|null $set
     * @return bool
     */
    function akar_proxy_json_mode($set = null)
    {
        static $rest = false;
        if ($set !== null) {
            $rest = (bool) $set;
        }
        return $rest;
    }

    /**
     * Kirim respons JSON (legacy) atau lempar respons (REST).
     *
     * @param mixed $data
     * @param int   $code
     * @return void
     */
    function akar_proxy_json($data, $code = 200)
    {
        if (akar_proxy_json_mode()) {
            throw new Akar_Proxy_Json_Response($data, $code);
        }

        if (!headers_sent()) {
            http_response_code((int) $code);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Robots-Tag: noindex');
            header('Cache-Control: no-store, max-age=0');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param string $msg
     * @param int    $code
     * @return void
     */
    function akar_proxy_fail($msg, $code = 400)
    {
        akar_proxy_json(array('error' => (string) $msg, 'status' => (int) $code), (int) $code);
    }

    /* -------------------------------------------------------------------------- */
    /* Routing / auth                                                             */
    /* -------------------------------------------------------------------------- */

    /**
     * Nama file proxy default. Bisa diubah lewat opsi plugin atau konstanta.
     *
     * @return string
     */
    function akar_proxy_default_file()
    {
        if (!empty($GLOBALS['akar_proxy_route_file'])) {
            return (string) $GLOBALS['akar_proxy_route_file'];
        }

        // Nama file kustom disimpan plugin di tabel options.
        if (function_exists('get_option')) {
            $file = trim((string) get_option('akar_proxy_seo_proxy_file', ''));
            if ($file !== '') {
                return $file;
            }
        }

        if (defined('AKAR_PROXY_SEO_PROXYFILE') && AKAR_PROXY_SEO_PROXYFILE !== '') {
            return (string) AKAR_PROXY_SEO_PROXYFILE;
        }

        return 'wp-api-proxy.php';
    }

    /**
     * Tebak nama file yang dipanggil dari path request.
     *
     * @param string $path
     * @return string
     */
    function akar_proxy_route_file($path)
    {
        if (!empty($GLOBALS['akar_proxy_route_file'])) {
            return (string) $GLOBALS['akar_proxy_route_file'];
        }

        foreach (array('SCRIPT_NAME', 'PHP_SELF') as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $script_path = parse_url((string) $_SERVER[$key], PHP_URL_PATH);
            if (!is_string($script_path)) {
                continue;
            }
            $base = basename($script_path);
            if ($base !== '' && $base !== 'index.php' && stripos($path, $base) !== false) {
                return $base;
            }
        }

        if (preg_match('#/([A-Za-z0-9._-]+\.php)(?:/|$)#', $path, $m)) {
            return $m[1];
        }

        return akar_proxy_default_file();
    }

    /**
     * Potong path menjadi segmen setelah nama file proxy.
     *
     * @param string $path
     * @param string $file
     * @return array
     */
    function akar_proxy_segments_from_path($path, $file = '')
    {
        $file = $file !== '' ? $file : akar_proxy_default_file();
        $pos  = stripos($path, $file);
        if ($pos === false) {
            return array();
        }

        $rest = trim(substr($path, $pos + strlen($file)), '/');
        if ($rest === '') {
            return array();
        }

        $seg = explode('/', $rest);
        $out = array();
        foreach ($seg as $part) {
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Segmen route dari request saat ini.
     *
     * Mendukung dua bentuk supaya tetap jalan di hosting yang membuang PATH_INFO:
     *   /wp-api-proxy.php/posts?page=2
     *   /wp-api-proxy.php?akar_route=posts&page=2
     *   /wp-api-proxy.php?akar_route=seo&id=123
     *
     * @return array
     */
    function akar_proxy_segments()
    {
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            $path = '';
        }

        $seg = akar_proxy_segments_from_path($path, akar_proxy_route_file($path));

        if (empty($seg) && !empty($_GET['akar_route'])) {
            $route = trim((string) $_GET['akar_route'], '/');
            if ($route !== '') {
                $seg = explode('/', $route);
                if ($seg[0] === 'seo' && empty($seg[1]) && isset($_GET['id'])) {
                    $seg[1] = (string) $_GET['id'];
                }
            }
        }

        $clean = array();
        foreach ($seg as $part) {
            if ($part !== '') {
                $clean[] = $part;
            }
        }
        return $clean;
    }

    /**
     * API key aktif (opsi database, konstanta legacy, atau filter).
     *
     * @return string
     */
    function akar_proxy_api_key()
    {
        $key = trim((string) get_option('akar_proxy_seo_api_key', ''));
        if ($key === '' && defined('AKAR_PROXY_SEO_KEY')) {
            $key = trim((string) constant('AKAR_PROXY_SEO_KEY'));
        }
        if (function_exists('apply_filters')) {
            $key = (string) apply_filters('akar_proxy_seo_api_key', $key);
        }
        return trim($key);
    }

    /**
     * Key yang dikirim klien: ?api_key=, X-API-Key, atau Authorization: Bearer.
     *
     * @return string
     */
    function akar_proxy_given_key()
    {
        if (isset($_GET['api_key'])) {
            return trim((string) $_GET['api_key']);
        }

        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim((string) $_SERVER['HTTP_X_API_KEY']);
        }

        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $header) {
            if (!empty($_SERVER[$header]) && preg_match('/Bearer\s+(\S+)/i', (string) $_SERVER[$header], $m)) {
                return trim($m[1]);
            }
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp((string) $name, 'X-API-Key') === 0) {
                        return trim((string) $value);
                    }
                }
            }
        }

        return '';
    }

    /**
     * Tolak request tanpa key yang benar.
     *
     * @return void
     */
    function akar_proxy_require_auth()
    {
        $key = akar_proxy_api_key();
        if ($key === '') {
            akar_proxy_fail('API key belum diatur di plugin Akar Proxy SEO', 500);
        }

        $given = akar_proxy_given_key();
        if ($given === '' || !hash_equals($key, $given)) {
            akar_proxy_fail('Invalid or missing API key', 401);
        }
    }

    /* -------------------------------------------------------------------------- */
    /* Body / Yoast                                                               */
    /* -------------------------------------------------------------------------- */

    /**
     * Body request. Saat REST, plugin mengisi override dari WP_REST_Request.
     *
     * @return array
     */
    function akar_proxy_body()
    {
        if (isset($GLOBALS['akar_proxy_body_override']) && is_array($GLOBALS['akar_proxy_body_override'])) {
            return $GLOBALS['akar_proxy_body_override'];
        }

        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $parsed = json_decode($raw, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        if (!empty($_POST)) {
            return $_POST;
        }

        return array();
    }

    /**
     * @return array
     */
    function akar_proxy_yoast_map()
    {
        return array(
            'title'               => array('_yoast_wpseo_title', 'text'),
            'description'         => array('_yoast_wpseo_metadesc', 'textarea'),
            'focus_keyword'       => array('_yoast_wpseo_focuskw', 'text'),
            'canonical'           => array('_yoast_wpseo_canonical', 'url'),
            'noindex'             => array('_yoast_wpseo_meta-robots-noindex', 'int01'),
            'nofollow'            => array('_yoast_wpseo_meta-robots-nofollow', 'int01'),
            'og_title'            => array('_yoast_wpseo_opengraph-title', 'text'),
            'og_description'      => array('_yoast_wpseo_opengraph-description', 'textarea'),
            'og_image'            => array('_yoast_wpseo_opengraph-image', 'url'),
            'twitter_title'       => array('_yoast_wpseo_twitter-title', 'text'),
            'twitter_description' => array('_yoast_wpseo_twitter-description', 'textarea'),
            'twitter_image'       => array('_yoast_wpseo_twitter-image', 'url'),
        );
    }

    /**
     * @param string $type
     * @param mixed  $value
     * @return string
     */
    function akar_proxy_clean_value($type, $value)
    {
        switch ($type) {
            case 'int01':
                return in_array((string) $value, array('0', '1'), true) ? (string) (int) $value : '';
            case 'url':
                return esc_url_raw((string) $value);
            case 'textarea':
                return sanitize_textarea_field((string) $value);
            default:
                return sanitize_text_field((string) $value);
        }
    }

    /**
     * @param int $post_id
     * @return array
     */
    function akar_proxy_seo_payload($post_id)
    {
        $yoast = array();
        foreach (akar_proxy_yoast_map() as $friendly => $def) {
            $v = get_post_meta($post_id, $def[0], true);
            $yoast[$friendly] = ($v === '') ? '' : $v;
        }
        $score = get_post_meta($post_id, '_yoast_wpseo_content_score', true);
        $yoast['readability_score'] = ($score === '') ? '' : (string) $score;

        return array('data' => array('yoast' => $yoast));
    }

    /* -------------------------------------------------------------------------- */
    /* Data endpoints                                                             */
    /* -------------------------------------------------------------------------- */

    /**
     * @return array
     */
    function akar_proxy_ping_payload()
    {
        return array('ok' => true, 'service' => 'akar-proxy-seo');
    }

    /**
     * @return array
     */
    function akar_proxy_status_payload()
    {
        $endpoint = !empty($GLOBALS['akar_proxy_endpoint']) ? (string) $GLOBALS['akar_proxy_endpoint'] : 'legacy';

        return array(
            'status'         => 'ok',
            'service'        => 'akar-proxy-seo',
            'endpoint'       => $endpoint,
            'wordpress'      => get_bloginfo('version'),
            'php'            => PHP_VERSION,
            'plugin_version' => defined('AKAR_PROXY_SEO_VERSION') ? AKAR_PROXY_SEO_VERSION : '1.5.0',
            'key_configured' => akar_proxy_api_key() !== '',
            'time'           => date('c'),
        );
    }

    /**
     * @param string $taxonomy
     * @return array
     */
    function akar_proxy_terms_payload($taxonomy = 'category')
    {
        $taxonomy = sanitize_key($taxonomy !== '' ? $taxonomy : 'category');
        if (!taxonomy_exists($taxonomy)) {
            akar_proxy_fail('Taxonomy tidak ditemukan: ' . $taxonomy, 404);
        }

        $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
        if (is_wp_error($terms)) {
            akar_proxy_fail($terms->get_error_message(), 500);
        }

        $out = array();
        foreach ($terms as $t) {
            $out[] = array(
                'id'    => (int) $t->term_id,
                'name'  => $t->name,
                'slug'  => $t->slug,
                'count' => (int) $t->count,
            );
        }

        return array('data' => $out);
    }

    /**
     * @param int $page
     * @param int $per
     * @return array
     */
    function akar_proxy_get_posts($page = 1, $per = 10)
    {
        $page = max(1, (int) $page);
        $per  = min(50, max(1, (int) $per));

        $q = new WP_Query(array(
            'post_type'      => 'post',
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            'orderby'        => 'date',
            'order'          => 'DESC',
            'paged'          => $page,
            'posts_per_page' => $per,
        ));

        $items = array();
        foreach ($q->posts as $p) {
            $items[] = array(
                'id'         => (int) $p->ID,
                'title'      => get_the_title($p),
                'slug'       => $p->post_name,
                'status'     => $p->post_status,
                'date'       => $p->post_date,
                'modified'   => $p->post_modified,
                'link'       => get_permalink($p),
                'categories' => wp_get_post_categories($p->ID),
            );
        }

        return array(
            'posts'    => $items,
            'total'    => (int) $q->found_posts,
            'page'     => $page,
            'per_page' => $per,
        );
    }

    /**
     * @param array $body
     * @return array
     */
    function akar_proxy_create_post($body)
    {
        $title = isset($body['title']) ? sanitize_text_field((string) $body['title']) : '';
        if ($title === '') {
            akar_proxy_fail('Missing required field: title.', 400);
        }

        $content = isset($body['content']) ? (string) $body['content'] : '';
        $excerpt = isset($body['excerpt']) ? sanitize_textarea_field((string) $body['excerpt']) : '';
        $status  = isset($body['status']) ? sanitize_key((string) $body['status']) : 'draft';
        if (!in_array($status, array('draft', 'publish', 'pending', 'private'), true)) {
            $status = 'draft';
        }
        $slug = isset($body['slug']) ? sanitize_title((string) $body['slug']) : '';

        $post_id = wp_insert_post(array(
            'post_title'   => wp_slash($title),
            'post_content' => wp_slash($content),
            'post_excerpt' => wp_slash($excerpt),
            'post_status'  => $status,
            'post_type'    => 'post',
            'post_name'    => $slug,
        ), true);

        if (is_wp_error($post_id)) {
            akar_proxy_fail($post_id->get_error_message(), 500);
        }
        $post_id = (int) $post_id;

        if (!empty($body['categories']) && is_array($body['categories'])) {
            wp_set_object_terms($post_id, array_map('intval', $body['categories']), 'category', false);
        }
        if (!empty($body['tags']) && is_array($body['tags'])) {
            wp_set_object_terms($post_id, array_map('strval', $body['tags']), 'post_tag', false);
        }

        $featured = null;
        $featured_error = '';
        if (!empty($body['featured_image_url'])) {
            $att = akar_proxy_sideload_image((string) $body['featured_image_url'], $post_id);
            if (is_wp_error($att)) {
                $featured_error = $att->get_error_message();
            } else {
                set_post_thumbnail($post_id, $att);
                $featured = (int) $att;
            }
        }

        $p = get_post($post_id);
        $payload = array(
            'post' => array(
                'id'             => (int) $p->ID,
                'title'          => get_the_title($p),
                'slug'           => $p->post_name,
                'type'           => $p->post_type,
                'status'         => $p->post_status,
                'date'           => $p->post_date,
                'modified'       => $p->post_modified,
                'excerpt'        => $p->post_excerpt,
                'content'        => $p->post_content,
                'link'           => get_permalink($p),
                'featured_image' => $featured,
                'author'         => array(
                    'id'           => (int) $p->post_author,
                    'display_name' => get_the_author_meta('display_name', $p->post_author),
                    'login'        => get_the_author_meta('user_login', $p->post_author),
                ),
                'categories'     => wp_get_post_categories($post_id),
                'tags'           => wp_get_post_tags($post_id, array('fields' => 'ids')),
            ),
        );

        if ($featured_error !== '') {
            $payload['post']['featured_image_error'] = $featured_error;
        }

        akar_proxy_json($payload, 201);
        return $payload;
    }

    /* -------------------------------------------------------------------------- */
    /* Featured image                                                             */
    /* -------------------------------------------------------------------------- */

    /**
     * Validasi URL gambar sebelum download: hanya http/https publik.
     *
     * Menolak localhost, IP privat, dan alamat metadata cloud supaya endpoint
     * tidak bisa dipakai membaca jaringan internal (SSRF).
     *
     * @param string $url
     * @return string|WP_Error
     */
    function akar_proxy_safe_image_url($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return new WP_Error('akar_image_url', 'image_url wajib diisi');
        }

        $url = esc_url_raw($url);
        if ($url === '') {
            return new WP_Error('akar_image_url', 'image_url tidak valid');
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, array('http', 'https'), true)) {
            return new WP_Error('akar_image_url_scheme', 'image_url harus memakai http atau https');
        }

        if (function_exists('wp_http_validate_url') && !wp_http_validate_url($url)) {
            return new WP_Error('akar_image_url_blocked', 'image_url ditolak: host privat/lokal tidak diizinkan');
        }

        return $url;
    }

    /**
     * @param string $url
     * @param int    $post_id
     * @return int|WP_Error
     */
    function akar_proxy_sideload_image($url, $post_id = 0)
    {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $safe = akar_proxy_safe_image_url($url);
        if (is_wp_error($safe)) {
            return $safe;
        }

        $tmp = download_url($safe, 45);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $max_bytes = (int) apply_filters('akar_proxy_seo_max_image_bytes', 8 * 1024 * 1024);
        if ($max_bytes > 0 && @filesize($tmp) > $max_bytes) {
            @unlink($tmp);
            return new WP_Error('akar_image_too_large', 'Ukuran gambar melebihi batas ' . size_format($max_bytes));
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            @unlink($tmp);
            return new WP_Error('invalid_image', 'File dari URL bukan gambar yang valid');
        }

        $mime = isset($info['mime']) ? $info['mime'] : 'image/jpeg';
        $ext  = image_type_to_extension(isset($info[2]) ? (int) $info[2] : 0, true);
        if (!is_string($ext) || $ext === '') {
            $ext = '.jpg';
        }
        $name = 'akar-cover-' . time() . '-' . wp_rand(100, 999) . $ext;

        $id = media_handle_sideload(array('name' => $name, 'tmp_name' => $tmp), (int) $post_id, null, array('post_mime_type' => $mime));
        if (is_wp_error($id)) {
            @unlink($tmp);
            return $id;
        }

        return (int) $id;
    }

    /**
     * @param array $body
     * @return array
     */
    function akar_proxy_featured_image($body)
    {
        $post_id = isset($body['post_id']) ? (int) $body['post_id'] : 0;
        $url     = isset($body['image_url']) ? (string) $body['image_url'] : '';

        if (!$post_id || !get_post($post_id)) {
            akar_proxy_fail('post_id tidak valid atau post tidak ditemukan', 400);
        }

        $att = akar_proxy_sideload_image($url, $post_id);
        if (is_wp_error($att)) {
            akar_proxy_fail($att->get_error_message(), 400);
        }

        set_post_thumbnail($post_id, $att);

        $payload = array(
            'data' => array(
                'attachment_id' => $att,
                'source_url'    => wp_get_attachment_image_url($att, 'large'),
            ),
            'updated' => true,
            'id'      => $post_id,
        );

        akar_proxy_json($payload);
        return $payload;
    }

    /* -------------------------------------------------------------------------- */
    /* SEO                                                                        */
    /* -------------------------------------------------------------------------- */

    /**
     * Tulis meta Yoast. Menerima 4 format body (lihat readme).
     *
     * @param int   $post_id
     * @param array $body
     * @return array
     */
    function akar_proxy_set_seo($post_id, $body)
    {
        if (empty($body)) {
            akar_proxy_fail('Request body kosong atau bukan JSON valid', 400);
        }

        $map = akar_proxy_yoast_map();
        $meta_types = array();
        foreach ($map as $friendly => $def) {
            $meta_types[$def[0]] = $def[1];
        }

        $source = (isset($body['yoast']) && is_array($body['yoast'])) ? $body['yoast'] : $body;
        $updates = array();

        $values  = array_values($source);
        $is_list = (isset($values[0]) && is_array($values[0]));

        if ($is_list) {
            foreach ($source as $row) {
                if (!is_array($row) || !isset($row['key'])) {
                    continue;
                }
                $k = (string) $row['key'];
                $v = isset($row['value']) ? $row['value'] : '';
                if (isset($map[$k])) {
                    $updates[$map[$k][0]] = akar_proxy_clean_value($map[$k][1], $v);
                } elseif (isset($meta_types[$k])) {
                    $updates[$k] = akar_proxy_clean_value($meta_types[$k], $v);
                } elseif (strpos($k, '_yoast_wpseo_') === 0) {
                    $updates[$k] = sanitize_text_field((string) $v);
                }
            }
        } else {
            foreach ($source as $field => $value) {
                $field = (string) $field;
                if (isset($map[$field])) {
                    $updates[$map[$field][0]] = akar_proxy_clean_value($map[$field][1], $value);
                } elseif (strpos($field, '_yoast_wpseo_') === 0) {
                    $updates[$field] = sanitize_text_field((string) $value);
                }
            }
        }

        if (empty($updates)) {
            akar_proxy_fail('Tidak ada field Yoast yang dikenali di body', 400);
        }

        foreach ($updates as $meta_key => $meta_value) {
            update_post_meta($post_id, $meta_key, $meta_value);
        }

        $payload = array_merge(
            array('updated' => true, 'id' => (int) $post_id),
            akar_proxy_seo_payload($post_id)
        );

        akar_proxy_json($payload);
        return $payload;
    }

    /* -------------------------------------------------------------------------- */
    /* Dispatcher                                                                 */
    /* -------------------------------------------------------------------------- */

    /**
     * @param array|null $segments
     * @return void
     */
    function akar_proxy_dispatch($segments = null)
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        $seg    = is_array($segments) ? array_values($segments) : akar_proxy_segments();

        if (empty($seg)) {
            $seg = array('status');
        }

        // /ping -- publik, sengaja minim (tanpa versi WP/PHP).
        if ($seg[0] === 'ping') {
            akar_proxy_json(akar_proxy_ping_payload());
        }

        // /status -- butuh key, berisi detail versi untuk diagnosa.
        if ($seg[0] === 'status') {
            akar_proxy_require_auth();
            akar_proxy_json(akar_proxy_status_payload());
        }

        if ($seg[0] === 'terms' && $method === 'GET') {
            akar_proxy_require_auth();
            $tax = isset($_GET['taxonomy']) ? (string) $_GET['taxonomy'] : 'category';
            akar_proxy_json(akar_proxy_terms_payload($tax));
        }

        if ($seg[0] === 'posts') {
            akar_proxy_require_auth();
            if ($method === 'POST') {
                akar_proxy_create_post(akar_proxy_body());
            }
            if ($method === 'GET') {
                $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
                $per  = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
                akar_proxy_json(akar_proxy_get_posts($page, $per));
            }
            akar_proxy_fail('Method not allowed', 405);
        }

        if (($seg[0] === 'featured-image' || $seg[0] === 'featured_image') && $method === 'POST') {
            akar_proxy_require_auth();
            akar_proxy_featured_image(akar_proxy_body());
        }

        if ($seg[0] === 'seo' && isset($seg[1]) && ctype_digit((string) $seg[1])) {
            akar_proxy_require_auth();
            $post_id = (int) $seg[1];
            if (!get_post($post_id)) {
                akar_proxy_fail('Post tidak ditemukan: ' . $post_id, 404);
            }
            if ($method === 'GET') {
                akar_proxy_json(akar_proxy_seo_payload($post_id));
            }
            if ($method === 'POST') {
                akar_proxy_set_seo($post_id, akar_proxy_body());
            }
            akar_proxy_fail('Method not allowed', 405);
        }

        akar_proxy_fail('Route tidak ditemukan', 404);
    }
}
