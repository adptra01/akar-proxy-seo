<?php
/**
 * Stub WordPress secukupnya untuk menguji plugin Akar Proxy SEO tanpa WordPress.
 *
 * Aturan penting: file ini TIDAK boleh mendefinisikan fungsi `akar_proxy_*`
 * (itu milik includes/handlers.php) dan TIDAK boleh mendefinisikan kelas
 * Akar_Proxy_Json_Response (itu juga milik handlers.php).
 */

/* -------------------------------------------------------------------------- */
/* State global                                                               */
/* -------------------------------------------------------------------------- */

$GLOBALS['wp_options']       = array();
$GLOBALS['wp_transients']    = array();
$GLOBALS['wp_posts']         = array();
$GLOBALS['wp_post_meta']     = array();
$GLOBALS['wp_terms']         = array();
$GLOBALS['wp_object_terms']  = array();
$GLOBALS['wp_thumbnails']    = array();
$GLOBALS['wp_attachments']   = array();
$GLOBALS['wp_users']         = array(1 => array('display_name' => 'Admin Akar', 'user_login' => 'admin'));
$GLOBALS['wp_actions']       = array();
$GLOBALS['wp_filters']       = array();
$GLOBALS['wp_rest_routes']   = array();
$GLOBALS['wp_redirects']     = array();
$GLOBALS['wp_http_fakes']    = array();
$GLOBALS['wp_http_requests'] = array();
$GLOBALS['wp_is_admin']      = false;
$GLOBALS['wp_can_manage']    = true;
$GLOBALS['wp_next_post_id']  = 100;

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

/* -------------------------------------------------------------------------- */
/* Kelas inti                                                                 */
/* -------------------------------------------------------------------------- */

class WP_Error
{
    public $errors = array();

    public function __construct($code = '', $message = '', $data = '')
    {
        if ($code !== '') {
            $this->errors[$code][] = array('message' => $message, 'data' => $data);
        }
    }

    public function get_error_code()
    {
        if (empty($this->errors)) {
            return '';
        }
        $keys = array_keys($this->errors);
        return (string) $keys[0];
    }

    public function get_error_message($code = '')
    {
        $code = $code !== '' ? $code : $this->get_error_code();
        return isset($this->errors[$code][0]['message']) ? $this->errors[$code][0]['message'] : '';
    }

    public function get_error_data($code = '')
    {
        $code = $code !== '' ? $code : $this->get_error_code();
        return isset($this->errors[$code][0]['data']) ? $this->errors[$code][0]['data'] : null;
    }

    public function get_error_codes()
    {
        return array_keys($this->errors);
    }
}

class WP_REST_Response
{
    public $data;
    public $status;

    public function __construct($data = null, $status = 200)
    {
        $this->data   = $data;
        $this->status = (int) $status;
    }

    public function get_data()
    {
        return $this->data;
    }

    public function get_status()
    {
        return $this->status;
    }
}

class WP_REST_Request
{
    public $method;
    public $params;
    public $json;

    public function __construct($method = 'GET', array $params = array(), $json = null)
    {
        $this->method = strtoupper((string) $method);
        $this->params = $params;
        $this->json   = $json;
    }

    public function get_param($key)
    {
        return isset($this->params[$key]) ? $this->params[$key] : null;
    }

    public function get_params()
    {
        return $this->params;
    }

    public function get_json_params()
    {
        return $this->json;
    }
}

class WP_Query
{
    public $posts       = array();
    public $found_posts = 0;
    public $post_count  = 0;

    public function __construct($args = array())
    {
        $args = is_array($args) ? $args : array();

        $post_type   = isset($args['post_type']) ? (string) $args['post_type'] : 'post';
        $post_status = isset($args['post_status']) ? $args['post_status'] : 'publish';
        $statuses    = is_array($post_status) ? $post_status : array($post_status);

        $matched = array();
        foreach ($GLOBALS['wp_posts'] as $post) {
            if ($post->post_type !== $post_type) {
                continue;
            }
            if (!in_array($post->post_status, $statuses, true)) {
                continue;
            }
            $matched[] = $post;
        }

        $order = isset($args['order']) ? strtoupper((string) $args['order']) : 'DESC';
        usort($matched, function ($a, $b) use ($order) {
            $cmp = strcmp($a->post_date, $b->post_date);
            if ($cmp === 0) {
                $cmp = $a->ID - $b->ID;
            }
            return $order === 'ASC' ? $cmp : -$cmp;
        });

        $this->found_posts = count($matched);

        $per_page = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 10;
        if ($per_page <= 0) {
            $per_page = 10;
        }
        $paged  = isset($args['paged']) ? max(1, (int) $args['paged']) : 1;
        $offset = ($paged - 1) * $per_page;

        $this->posts      = array_slice($matched, $offset, $per_page);
        $this->post_count = count($this->posts);
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

/* -------------------------------------------------------------------------- */
/* Opsi / transient                                                           */
/* -------------------------------------------------------------------------- */

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['wp_options']) ? $GLOBALS['wp_options'][$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    $GLOBALS['wp_options'][$name] = $value;
    return true;
}

function add_option($name, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (!array_key_exists($name, $GLOBALS['wp_options'])) {
        $GLOBALS['wp_options'][$name] = $value;
    }
    return true;
}

function delete_option($name)
{
    unset($GLOBALS['wp_options'][$name]);
    return true;
}

function get_transient($name)
{
    return isset($GLOBALS['wp_transients'][$name]) ? $GLOBALS['wp_transients'][$name] : false;
}

function set_transient($name, $value, $expiration = 0)
{
    $GLOBALS['wp_transients'][$name] = $value;
    return true;
}

function delete_transient($name)
{
    unset($GLOBALS['wp_transients'][$name]);
    return true;
}

/* -------------------------------------------------------------------------- */
/* Hook                                                                       */
/* -------------------------------------------------------------------------- */

function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['wp_actions'][$tag][$priority][] = $callback;
    return true;
}

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['wp_filters'][$tag][$priority][] = $callback;
    return true;
}

function apply_filters($tag, $value)
{
    $args = func_get_args();
    if (empty($GLOBALS['wp_filters'][$tag])) {
        return $value;
    }
    ksort($GLOBALS['wp_filters'][$tag]);
    foreach ($GLOBALS['wp_filters'][$tag] as $callbacks) {
        foreach ($callbacks as $callback) {
            $args[1] = $value;
            $value   = call_user_func_array($callback, array_slice($args, 1));
        }
    }
    return $value;
}

function do_action($tag)
{
    $args = func_get_args();
    if (empty($GLOBALS['wp_actions'][$tag])) {
        return;
    }
    ksort($GLOBALS['wp_actions'][$tag]);
    foreach ($GLOBALS['wp_actions'][$tag] as $callbacks) {
        foreach ($callbacks as $callback) {
            call_user_func_array($callback, array_slice($args, 1));
        }
    }
}

function register_activation_hook($file, $callback)
{
    $GLOBALS['wp_activation_hooks'][$file] = $callback;
}

function register_rest_route($namespace, $route, $args = array())
{
    $GLOBALS['wp_rest_routes'][$namespace][$route] = $args;
}

function __return_true()
{
    return true;
}

function __return_false()
{
    return false;
}

/* -------------------------------------------------------------------------- */
/* Escaping / sanitasi                                                        */
/* -------------------------------------------------------------------------- */

function wp_strip_all_tags($string, $remove_breaks = false)
{
    $string = strip_tags((string) $string);
    if ($remove_breaks) {
        $string = preg_replace('/[\r\n\t ]+/', ' ', $string);
    }
    return $string;
}

function sanitize_text_field($str)
{
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $str)));
}

function sanitize_textarea_field($str)
{
    return trim(str_replace("\r", '', strip_tags((string) $str)));
}

function sanitize_key($key)
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function sanitize_title($title, $fallback_title = '', $context = 'save')
{
    $slug = strtolower(trim((string) $title));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');
    return $slug;
}

function sanitize_file_name($name)
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $name);
    return trim((string) $name, '-');
}

function esc_url_raw($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }
    if (!in_array(strtolower($parts['scheme']), array('http', 'https', 'ftp'), true)) {
        return '';
    }
    return $url;
}

function esc_url($url)
{
    return htmlspecialchars(esc_url_raw($url), ENT_QUOTES, 'UTF-8');
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function wp_json_encode($data, $flags = 0, $depth = 512)
{
    return json_encode($data, $flags, $depth);
}

function wp_slash($value)
{
    if (is_array($value)) {
        return array_map('wp_slash', $value);
    }
    return is_string($value) ? addslashes($value) : $value;
}

function wp_unslash($value)
{
    if (is_array($value)) {
        return array_map('wp_unslash', $value);
    }
    return is_string($value) ? stripslashes($value) : $value;
}

function wp_parse_url($url, $component = -1)
{
    if ($component === -1) {
        return parse_url((string) $url);
    }
    return parse_url((string) $url, (int) $component);
}

function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
{
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    if ($special_chars) {
        $chars .= '!@#$%^&*()';
    }
    $max  = strlen($chars) - 1;
    $pass = '';
    for ($i = 0; $i < (int) $length; $i++) {
        $pass .= $chars[random_int(0, $max)];
    }
    return $pass;
}

function wp_rand($min = 0, $max = 0)
{
    $min = (int) $min;
    $max = (int) $max;
    if ($max <= 0) {
        $max = 4294967295;
    }
    return random_int($min, max($min, $max));
}

function size_format($bytes, $decimals = 0)
{
    $bytes = (float) $bytes;
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, $decimals) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, $decimals) . ' KB';
    }
    return number_format($bytes, $decimals) . ' B';
}

if (!function_exists('image_type_to_extension')) {
    function image_type_to_extension($imagetype, $dot = true)
    {
        $map = array(
            1  => 'gif',
            2  => 'jpeg',
            3  => 'png',
            6  => 'bmp',
            17 => 'ico',
            18 => 'webp',
        );
        $ext = isset($map[(int) $imagetype]) ? $map[(int) $imagetype] : '';
        if ($ext === '') {
            return false;
        }
        return $dot ? '.' . $ext : $ext;
    }
}

/* -------------------------------------------------------------------------- */
/* URL aplikasi                                                               */
/* -------------------------------------------------------------------------- */

function home_url($path = '')
{
    return 'http://example.test' . ($path !== '' ? '/' . ltrim((string) $path, '/') : '');
}

function site_url($path = '')
{
    return home_url($path);
}

function admin_url($path = '')
{
    return 'http://example.test/wp-admin/' . ltrim((string) $path, '/');
}

function rest_url($path = '')
{
    return 'http://example.test/wp-json/' . ltrim((string) $path, '/');
}

function get_bloginfo($show = '', $filter = 'raw')
{
    if ($show === 'version') {
        return '6.6.2';
    }
    if ($show === 'name') {
        return 'Site Uji';
    }
    return '';
}

function wp_is_writable($path)
{
    return true;
}

function current_user_can($capability)
{
    return !empty($GLOBALS['wp_can_manage']);
}

function is_admin()
{
    return !empty($GLOBALS['wp_is_admin']);
}

function wp_die($message = '')
{
    throw new RuntimeException('wp_die: ' . (is_string($message) ? $message : ''));
}

function check_admin_referer($action = -1, $query_arg = '_wpnonce')
{
    return true;
}

function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true)
{
    return '';
}

function submit_button($text = null, $type = 'primary', $name = 'submit', $wrap = true, $attributes = array())
{
    return '';
}

function wp_safe_redirect($location, $status = 302)
{
    $GLOBALS['wp_redirects'][] = array($location, $status);
    return true;
}

function plugin_basename($file)
{
    return basename((string) $file);
}

/* -------------------------------------------------------------------------- */
/* HTTP keluar (dipakai blok auto-update)                                     */
/*                                                                            */
/* Default: TIDAK ada jaringan. Test offline tidak boleh bergantung pada       */
/* internet, jadi permintaan apa pun mengembalikan WP_Error sampai test        */
/* mendaftarkan respons palsu lewat akar_test_http_fake().                     */
/* -------------------------------------------------------------------------- */

/**
 * Daftarkan respons palsu untuk satu URL persis.
 *
 * @param string $url
 * @param int    $code HTTP status (0 = WP_Error jaringan).
 * @param string $body
 * @param string $message Pesan error bila $code = 0.
 * @return void
 */
function akar_test_http_fake($url, $code, $body = '', $message = 'Stub: permintaan HTTP keluar dinonaktifkan')
{
    if (!isset($GLOBALS['wp_http_fakes']) || !is_array($GLOBALS['wp_http_fakes'])) {
        $GLOBALS['wp_http_fakes'] = array();
    }

    $GLOBALS['wp_http_fakes'][$url] = array(
        'code'    => (int) $code,
        'body'    => (string) $body,
        'message' => (string) $message,
    );
}

/** Kosongkan daftar respons palsu + riwayat permintaan. */
function akar_test_http_reset()
{
    $GLOBALS['wp_http_fakes']    = array();
    $GLOBALS['wp_http_requests'] = array();
}

/**
 * Stub wp_remote_get(): hanya menjawab URL yang didaftarkan test.
 *
 * @param string $url
 * @param array  $args
 * @return array|WP_Error
 */
function wp_remote_get($url, $args = array())
{
    if (!isset($GLOBALS['wp_http_requests']) || !is_array($GLOBALS['wp_http_requests'])) {
        $GLOBALS['wp_http_requests'] = array();
    }
    $GLOBALS['wp_http_requests'][] = array('url' => (string) $url, 'args' => (array) $args);

    $fake = isset($GLOBALS['wp_http_fakes'][$url]) ? $GLOBALS['wp_http_fakes'][$url] : null;
    if ($fake === null) {
        return new WP_Error('http_request_failed', 'Stub: permintaan HTTP keluar dinonaktifkan');
    }

    if ((int) $fake['code'] === 0) {
        return new WP_Error('http_request_failed', $fake['message']);
    }

    return array(
        'headers'  => array(),
        'body'     => $fake['body'],
        'response' => array(
            'code'    => (int) $fake['code'],
            'message' => 'OK',
        ),
        'cookies'  => array(),
    );
}

/**
 * @param array|WP_Error $response
 * @return int
 */
function wp_remote_retrieve_response_code($response)
{
    if (is_wp_error($response) || !is_array($response)) {
        return 0;
    }

    return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
}

/**
 * @param array|WP_Error $response
 * @return string
 */
function wp_remote_retrieve_body($response)
{
    if (is_wp_error($response) || !is_array($response)) {
        return '';
    }

    return isset($response['body']) ? (string) $response['body'] : '';
}

/**
 * @param array|WP_Error $response
 * @return array
 */
function wp_remote_retrieve_headers($response)
{
    if (is_wp_error($response) || !is_array($response)) {
        return array();
    }

    return isset($response['headers']) ? (array) $response['headers'] : array();
}

/**
 * Padanan add_query_arg() WordPress untuk bentuk yang dipakai plugin.
 *
 * @param string|array $key
 * @param mixed        $value
 * @param string|null  $url
 * @return string
 */
function add_query_arg($key, $value = null, $url = null)
{
    if (is_array($key)) {
        $args = $key;
        $url  = $value;
    } else {
        $args = array($key => $value);
        $url  = $url;
    }

    $url  = (string) $url;
    $bits = explode('#', $url, 2);
    $base = $bits[0];
    $frag = isset($bits[1]) ? '#' . $bits[1] : '';

    foreach ($args as $name => $val) {
        $name = urlencode((string) $name);
        $val  = urlencode((string) $val);
        if (preg_match('/[?&]' . preg_quote($name, '/') . '=[^&]*/', $base)) {
            $base = preg_replace('/([?&])' . preg_quote($name, '/') . '=[^&]*/', '$1' . $name . '=' . $val, $base, 1);
        } else {
            $base .= (strpos($base, '?') === false ? '?' : '&') . $name . '=' . $val;
        }
    }

    return $base . $frag;
}

/* -------------------------------------------------------------------------- */
/* Post / meta / term                                                         */
/* -------------------------------------------------------------------------- */

function get_post($post = null)
{
    if (is_object($post)) {
        return isset($GLOBALS['wp_posts'][$post->ID]) ? $GLOBALS['wp_posts'][$post->ID] : null;
    }
    $id = (int) $post;
    return isset($GLOBALS['wp_posts'][$id]) ? $GLOBALS['wp_posts'][$id] : null;
}

function get_the_title($post = 0)
{
    $p = is_object($post) ? $post : get_post($post);
    return $p ? $p->post_title : '';
}

function get_permalink($post = 0)
{
    $p = is_object($post) ? $post : get_post($post);
    return $p ? home_url('/?p=' . $p->ID) : false;
}

function get_the_author_meta($field = '', $user_id = false)
{
    $id = $user_id ? (int) $user_id : 1;
    if (!isset($GLOBALS['wp_users'][$id])) {
        return '';
    }
    return isset($GLOBALS['wp_users'][$id][$field]) ? $GLOBALS['wp_users'][$id][$field] : '';
}

function wp_insert_post($postarr, $wp_error = false, $fire_after_hooks = true)
{
    $postarr = is_array($postarr) ? $postarr : array();

    $id = (int) $GLOBALS['wp_next_post_id'];
    $GLOBALS['wp_next_post_id'] = $id + 1;

    $now = date('Y-m-d H:i:s');

    $title = isset($postarr['post_title']) ? wp_unslash((string) $postarr['post_title']) : '';
    $name  = isset($postarr['post_name']) ? sanitize_title((string) $postarr['post_name']) : '';
    if ($name === '') {
        $name = sanitize_title($title);
    }

    $post = (object) array(
        'ID'            => $id,
        'post_title'    => $title,
        'post_content'  => isset($postarr['post_content']) ? wp_unslash((string) $postarr['post_content']) : '',
        'post_excerpt'  => isset($postarr['post_excerpt']) ? wp_unslash((string) $postarr['post_excerpt']) : '',
        'post_status'   => isset($postarr['post_status']) ? (string) $postarr['post_status'] : 'draft',
        'post_type'     => isset($postarr['post_type']) ? (string) $postarr['post_type'] : 'post',
        'post_name'     => $name,
        'post_date'     => $now,
        'post_modified' => $now,
        'post_author'   => 1,
    );

    $GLOBALS['wp_posts'][$id] = $post;

    return $id;
}

function get_post_meta($post_id, $key = '', $single = false)
{
    $post_id = (int) $post_id;
    if (!isset($GLOBALS['wp_post_meta'][$post_id])) {
        return $single ? '' : array();
    }
    if ($key === '') {
        return $GLOBALS['wp_post_meta'][$post_id];
    }
    if (!array_key_exists($key, $GLOBALS['wp_post_meta'][$post_id])) {
        return $single ? '' : array();
    }
    $value = $GLOBALS['wp_post_meta'][$post_id][$key];
    return $single ? $value : array($value);
}

function update_post_meta($post_id, $meta_key, $meta_value, $prev_value = '')
{
    $GLOBALS['wp_post_meta'][(int) $post_id][(string) $meta_key] = $meta_value;
    return true;
}

function delete_post_meta($post_id, $meta_key, $meta_value = '')
{
    unset($GLOBALS['wp_post_meta'][(int) $post_id][(string) $meta_key]);
    return true;
}

function taxonomy_exists($taxonomy)
{
    $taxonomy = (string) $taxonomy;
    if (in_array($taxonomy, array('category', 'post_tag'), true)) {
        return true;
    }
    return isset($GLOBALS['wp_terms'][$taxonomy]);
}

function get_terms($args = array(), $deprecated = '')
{
    if (is_string($args)) {
        $args = array('taxonomy' => $args);
    }
    if (!is_array($args)) {
        $args = array();
    }

    $taxonomy = isset($args['taxonomy']) ? $args['taxonomy'] : 'category';
    if (!taxonomy_exists($taxonomy)) {
        return new WP_Error('invalid_taxonomy', 'Invalid taxonomy.');
    }

    $terms = array();
    if (isset($GLOBALS['wp_terms'][$taxonomy])) {
        foreach ($GLOBALS['wp_terms'][$taxonomy] as $term) {
            $terms[] = $term;
        }
    }

    return $terms;
}

function wp_set_object_terms($object_id, $terms, $taxonomy, $append = false)
{
    $taxonomy = (string) $taxonomy;
    if (!$append) {
        $GLOBALS['wp_object_terms'][(int) $object_id][$taxonomy] = array();
    }
    if (!isset($GLOBALS['wp_object_terms'][(int) $object_id][$taxonomy])) {
        $GLOBALS['wp_object_terms'][(int) $object_id][$taxonomy] = array();
    }
    foreach ((array) $terms as $term) {
        $GLOBALS['wp_object_terms'][(int) $object_id][$taxonomy][] = $term;
    }
    return array();
}

function wp_get_post_categories($post_id, $args = array())
{
    $ids = isset($GLOBALS['wp_object_terms'][(int) $post_id]['category'])
        ? $GLOBALS['wp_object_terms'][(int) $post_id]['category']
        : array();
    return array_map('intval', $ids);
}

function wp_get_post_tags($post_id, $args = array())
{
    $raw   = isset($GLOBALS['wp_object_terms'][(int) $post_id]['post_tag'])
        ? $GLOBALS['wp_object_terms'][(int) $post_id]['post_tag']
        : array();
    $ids   = array();
    $known = isset($GLOBALS['wp_terms']['post_tag']) ? $GLOBALS['wp_terms']['post_tag'] : array();

    foreach ($raw as $value) {
        if (is_numeric($value)) {
            $ids[] = (int) $value;
            continue;
        }
        foreach ($known as $term) {
            if ($term->name === $value || $term->slug === $value) {
                $ids[] = (int) $term->term_id;
                break;
            }
        }
    }

    $fields = isset($args['fields']) ? $args['fields'] : 'all';
    if ($fields === 'ids') {
        return $ids;
    }

    $out = array();
    foreach ($ids as $id) {
        if (isset($known[$id])) {
            $out[] = $known[$id];
        }
    }
    return $out;
}

/* -------------------------------------------------------------------------- */
/* Featured image                                                             */
/* -------------------------------------------------------------------------- */

function set_post_thumbnail($post_id, $thumbnail_id)
{
    $GLOBALS['wp_thumbnails'][(int) $post_id] = (int) $thumbnail_id;
    return true;
}

function get_post_thumbnail_id($post_id)
{
    return isset($GLOBALS['wp_thumbnails'][(int) $post_id]) ? $GLOBALS['wp_thumbnails'][(int) $post_id] : 0;
}

function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail')
{
    $id = (int) $attachment_id;
    return isset($GLOBALS['wp_attachments'][$id]) ? $GLOBALS['wp_attachments'][$id] : false;
}

/**
 * Tiruan wp_http_validate_url: tolak skema aneh, localhost, IP privat, metadata cloud.
 */
function wp_http_validate_url($url)
{
    $url   = (string) $url;
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }
    $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
    if (!in_array($scheme, array('http', 'https'), true)) {
        return false;
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, array(80, 443, 8080), true)) {
        return false;
    }

    $host = strtolower(trim($parts['host'], '[]'));
    if ($host === '' || $host === 'localhost' || substr($host, -6) === '.local') {
        return false;
    }
    if ($host === '169.254.169.254' || $host === 'metadata.google.internal') {
        return false;
    }

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }

    return $url;
}

/**
 * Tiruan download_url: menulis PNG 1x1 asli supaya getimagesize() valid.
 */
function download_url($url, $timeout = 300)
{
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
    $tmp = tempnam(sys_get_temp_dir(), 'akar-dl-');
    if ($tmp === false) {
        return new WP_Error('http_no_file', 'Tidak bisa membuat file sementara');
    }
    file_put_contents($tmp, $png);
    return $tmp;
}

function media_handle_sideload($file_array, $post_id, $desc = null, $overrides = array())
{
    $id = 900 + count($GLOBALS['wp_attachments']);
    $name = isset($file_array['name']) ? (string) $file_array['name'] : 'attachment.png';
    $GLOBALS['wp_attachments'][$id] = 'http://example.test/wp-content/uploads/' . $name;
    return $id;
}

/* -------------------------------------------------------------------------- */
/* Seed data                                                                  */
/* -------------------------------------------------------------------------- */

function akar_test_seed()
{
    $GLOBALS['wp_options']      = array();
    $GLOBALS['wp_transients']   = array();
    $GLOBALS['wp_posts']        = array();
    $GLOBALS['wp_post_meta']    = array();
    $GLOBALS['wp_terms']        = array();
    $GLOBALS['wp_object_terms'] = array();
    $GLOBALS['wp_thumbnails']   = array();
    $GLOBALS['wp_attachments']  = array();
    $GLOBALS['wp_rest_routes']  = array();
    $GLOBALS['wp_next_post_id'] = 100;

    update_option('akar_proxy_seo_api_key', 'test-key-1234567890abcdef');

    $GLOBALS['wp_terms']['category'] = array(
        1 => (object) array('term_id' => 1, 'name' => 'Uncategorized', 'slug' => 'uncategorized', 'count' => 0, 'taxonomy' => 'category'),
        3 => (object) array('term_id' => 3, 'name' => 'Digitalisasi', 'slug' => 'digitalisasi', 'count' => 5, 'taxonomy' => 'category'),
        4 => (object) array('term_id' => 4, 'name' => 'Business Process', 'slug' => 'business-process', 'count' => 2, 'taxonomy' => 'category'),
    );

    $GLOBALS['wp_terms']['post_tag'] = array(
        7 => (object) array('term_id' => 7, 'name' => 'n8n', 'slug' => 'n8n', 'count' => 3, 'taxonomy' => 'post_tag'),
    );

    $make = function ($id, $title, $date, $status = 'publish') {
        $GLOBALS['wp_posts'][$id] = (object) array(
            'ID'            => $id,
            'post_title'    => $title,
            'post_content'  => '<p>Isi ' . $title . '</p>',
            'post_excerpt'  => 'Ringkasan ' . $title,
            'post_status'   => $status,
            'post_type'     => 'post',
            'post_name'     => sanitize_title($title),
            'post_date'     => $date,
            'post_modified' => $date,
            'post_author'   => 1,
        );
    };

    $make(1, 'Post Satu', '2026-01-01 10:00:00');
    for ($i = 11; $i <= 22; $i++) {
        $day = $i - 10;
        $make($i, 'Post ' . $i, sprintf('2026-02-%02d 08:00:00', $day), $i === 22 ? 'draft' : 'publish');
    }

    $GLOBALS['wp_object_terms'][1]['category']   = array(3);
    $GLOBALS['wp_object_terms'][1]['post_tag']   = array('n8n');
    $GLOBALS['wp_object_terms'][12]['category']  = array(3, 4);

    $GLOBALS['wp_post_meta'][1]['_yoast_wpseo_title']     = 'Judul SEO Satu';
    $GLOBALS['wp_post_meta'][1]['_yoast_wpseo_metadesc']  = 'Deskripsi SEO Satu';
    $GLOBALS['wp_post_meta'][1]['_yoast_wpseo_focuskw']   = 'akar';
    $GLOBALS['wp_post_meta'][1]['_yoast_wpseo_content_score'] = '90';
    $GLOBALS['wp_post_meta'][12]['_yoast_wpseo_title']    = 'Judul SEO Dua Belas';
}
