<?php
if (!defined('ABSPATH')) { exit; }

final class Convertino_Core {
    const COOKIE = 'convertino_session';
    const VERSION = '0.1.2';
    public static function table($suffix) { global $wpdb; return $wpdb->prefix . 'convertino_' . $suffix; }
    public static function settings() {
        return wp_parse_args(get_option('convertino_settings', []), ['page_id'=>0, 'enabled'=>false, 'buttons'=>[], 'form_ids'=>[], 'product_ids'=>[]]);
    }
    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'scripts']);
        add_action('convertino_cleanup', [__CLASS__, 'cleanup']);
        add_action('fluentform/submission_inserted', [__CLASS__, 'form'], 10, 3);
        add_action('fluent_cart/checkout/prepare_other_data', [__CLASS__, 'attach_order']);
        add_action('fluent_cart/order_paid_done', [__CLASS__, 'paid']);
        add_action('fluent_cart/order_refunded', [__CLASS__, 'refund']);
    }
    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sessions = self::table('sessions'); $events = self::table('events'); $orders = self::table('orders');
        dbDelta("CREATE TABLE $sessions (
            id char(32) NOT NULL,
            page_id bigint(20) unsigned NOT NULL,
            source varchar(190) NOT NULL,
            medium varchar(100) NOT NULL DEFAULT '',
            campaign varchar(190) NOT NULL DEFAULT '',
            referrer_host varchar(190) NOT NULL DEFAULT '',
            started_at datetime NOT NULL,
            last_seen datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY page_started (page_id,started_at),
            KEY page_source (page_id,source),
            KEY last_seen (last_seen)
        ) $charset;");
        dbDelta("CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id char(32) NOT NULL,
            event_key char(64) NOT NULL,
            type varchar(20) NOT NULL,
            target varchar(190) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_key (event_key),
            KEY session_type (session_id,type),
            KEY created_at (created_at)
        ) $charset;");
        dbDelta("CREATE TABLE $orders (
            order_id bigint(20) unsigned NOT NULL,
            session_id char(32) NOT NULL,
            amount bigint(20) NOT NULL DEFAULT 0,
            refunded bigint(20) NOT NULL DEFAULT 0,
            currency char(3) NOT NULL,
            paid_at datetime NOT NULL,
            PRIMARY KEY  (order_id),
            KEY session_id (session_id),
            KEY paid_at (paid_at)
        ) $charset;");
        if (!wp_next_scheduled('convertino_cleanup')) { wp_schedule_event(time()+3600, 'daily', 'convertino_cleanup'); }
        update_option('convertino_db_version', 1, false);
    }
    public static function sign($value) { return hash_hmac('sha256', $value, wp_salt('auth')); }
    public static function token($id) { return $id . '.' . self::sign($id); }
    public static function cookie_id() {
        $parts = explode('.', (string)($_COOKIE[self::COOKIE] ?? ''));
        return count($parts) === 2 && preg_match('/^[a-f0-9]{32}$/D', $parts[0]) && hash_equals(self::sign($parts[0]), $parts[1]) ? $parts[0] : '';
    }
    public static function set_cookie($id = '') {
        $value = $id ? self::token($id) : '';
        setcookie(self::COOKIE, $value, ['expires'=>$id ? time()+1800 : time()-3600, 'path'=>'/', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax']);
        $_COOKIE[self::COOKIE] = $value;
    }
    public static function blocked() {
        // REST cookie authentication can clear current_user without a REST nonce.
        $uid = get_current_user_id() ?: wp_validate_auth_cookie('', 'logged_in');
        if ($uid && user_can($uid, 'manage_options')) { return true; }
        if (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') { return true; }
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        return !$ua || preg_match('/bot|crawler|spider|headless|lighthouse|preview|wget|curl/i', $ua);
    }
    public static function current_session($active = true) {
        global $wpdb;
        if (self::blocked()) { return null; }
        $id = self::cookie_id();
        if (!$id) { return null; }
        $sql = 'SELECT * FROM ' . self::table('sessions') . ' WHERE id=%s';
        if ($active) { $sql .= ' AND last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)'; }
        return $wpdb->get_row($wpdb->prepare($sql, $id));
    }
    public static function source($utm, $referrer, $medium = '') {
        $utm = self::text($utm, 190); $medium = self::text($medium, 100);
        $host = strtolower((string)wp_parse_url($referrer, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);
        $home = preg_replace('/^www\./', '', strtolower((string)wp_parse_url(home_url(), PHP_URL_HOST)));
        $raw = $utm ?: ($host === $home ? '' : $host);
        $value = strtolower($raw);
        $map = ['chatgpt'=>['chatgpt','chatgpt.com','chat.openai.com'], 'google'=>['google','google.com'], 'perplexity'=>['perplexity','perplexity.ai'], 'claude'=>['claude','claude.ai'], 'gemini'=>['gemini','gemini.google.com'], 'copilot'=>['copilot','copilot.microsoft.com'], 'bing'=>['bing','bing.com'], 'facebook'=>['facebook','facebook.com','fb'], 'instagram'=>['instagram','instagram.com'], 'linkedin'=>['linkedin','linkedin.com'], 'youtube'=>['youtube','youtube.com'], 'email'=>['email','newsletter']];
        $names = ['chatgpt'=>'ChatGPT', 'google'=>'Google', 'perplexity'=>'Perplexity', 'claude'=>'Claude', 'gemini'=>'Gemini', 'copilot'=>'Copilot', 'bing'=>'Bing', 'facebook'=>'Facebook', 'instagram'=>'Instagram', 'linkedin'=>'LinkedIn', 'youtube'=>'YouTube', 'email'=>'Email'];
        foreach ($map as $key=>$values) {
            foreach ($values as $domain) {
                if ($value === $domain || (str_contains($domain, '.') && str_ends_with($value, '.' . $domain))) { return $names[$key]; }
            }
        }
        if (!$utm && preg_match('/^(?:[^.]+\.)?google\.(?:com|[a-z]{2}|com\.[a-z]{2}|co\.[a-z]{2})$/D', $host)) { return 'Google'; }
        if (!$raw && strtolower($medium) === 'email') { return 'Email'; }
        return $raw ?: 'Direct / unknown';
    }
    public static function text($value, $length) { return mb_substr(sanitize_text_field(is_scalar($value) ? (string)$value : ''), 0, $length); }
    public static function scripts() {
        $s = self::settings();
        if (!$s['enabled'] || !$s['page_id'] || self::blocked() || is_preview()) { return; }
        // Load on other pages too so consent revocation clears attribution.
        wp_enqueue_script('convertino-tracker', plugins_url('assets/tracker.js', CONVERTINO_FILE), [], self::VERSION, true);
        $id = is_page((int)$s['page_id']) ? (int)$s['page_id'] : 0;
        wp_add_inline_script('convertino-tracker', 'window.ConvertinoConfig=' . wp_json_encode(['endpoint'=>rest_url('convertino/v1/'), 'page'=>$id, 'proof'=>$id ? self::sign('page:'.$id) : '', 'buttons'=>$id ? $s['buttons'] : []]) . ';', 'before');
    }
    public static function routes() {
        foreach (['start','event','revoke'] as $path) {
            register_rest_route('convertino/v1', '/'.$path, ['methods'=>'POST', 'callback'=>[__CLASS__, 'request_'.$path], 'permission_callback'=>[__CLASS__, 'permission']]);
        }
    }
    public static function permission($request) {
        $origin = rtrim((string)$request->get_header('origin'), '/');
        $expected = wp_parse_url(home_url());
        $site_origin = $expected['scheme'].'://'.$expected['host'].(isset($expected['port']) ? ':'.$expected['port'] : '');
        if ($origin !== $site_origin || !in_array(strtolower((string)$request->get_header('sec-fetch-site')), ['', 'same-origin'], true)) {
            return new WP_Error('convertino_origin', 'Same-origin requests required.', ['status'=>403]);
        }
        return true;
    }
    private static function ready($request) {
        $s = self::settings();
        if (!$s['enabled'] || !$s['page_id'] || self::blocked() || $request->get_param('consent') !== true) {
            return new WP_Error('convertino_disabled', 'Tracking unavailable or consent missing.', ['status'=>403]);
        }
        // Short-lived keyed counters; raw addresses are never stored.
        $key = 'convertino_rate_' . substr(self::sign((string)($_SERVER['REMOTE_ADDR'] ?? '')), 0, 32);
        $bucket = get_transient($key) ?: ['time'=>time(), 'count'=>0];
        if (time()-$bucket['time'] >= 60) { $bucket = ['time'=>time(), 'count'=>0]; }
        if ($bucket['count'] >= 120) { return new WP_Error('convertino_rate', 'Too many requests.', ['status'=>429]); }
        $bucket['count']++; set_transient($key, $bucket, 60);
        return true;
    }
    private static function response($data) { $r = new WP_REST_Response($data); $r->header('Cache-Control', 'no-store, private'); return $r; }
    public static function request_start($request) {
        global $wpdb;
        $ready = self::ready($request); if (is_wp_error($ready)) { return $ready; }
        $s = self::settings(); $page = absint($request->get_param('page'));
        if (!$page || $page !== (int)$s['page_id'] || !hash_equals(self::sign('page:'.$page), (string)$request->get_param('proof')) || get_post_status($page) !== 'publish') {
            return new WP_Error('convertino_page', 'Invalid tracked page.', ['status'=>403]);
        }
        $event = (string)$request->get_param('id');
        if (!preg_match('/^[a-f0-9-]{20,64}$/D', $event)) { return new WP_Error('convertino_event', 'Invalid event ID.', ['status'=>400]); }
        // A replay of the same page-view request recovers the first session,
        // including when its initial Set-Cookie response was lost.
        $view_key = 'view:'.$page.':'.$event;
        $session = self::current_session();
        $existing = $wpdb->get_row($wpdb->prepare('SELECT s.* FROM '.self::table('sessions').' s INNER JOIN '.self::table('events').' e ON e.session_id=s.id WHERE e.event_key=%s AND s.page_id=%d AND s.last_seen >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE)',hash('sha256',$view_key),$page));
        if ($existing) { $session = $existing; }
        $created = false;
        if (!$session || (int)$session->page_id !== $page) {
            $id = bin2hex(random_bytes(16));
            $referrer = self::text($request->get_param('referrer'), 1000);
            $medium = self::text($request->get_param('medium'), 100);
            $ok = $wpdb->insert(self::table('sessions'), ['id'=>$id, 'page_id'=>$page, 'source'=>self::source($request->get_param('source'), $referrer, $medium), 'medium'=>$medium, 'campaign'=>self::text($request->get_param('campaign'),190), 'referrer_host'=>self::text(wp_parse_url($referrer,PHP_URL_HOST),190), 'started_at'=>gmdate('Y-m-d H:i:s'), 'last_seen'=>gmdate('Y-m-d H:i:s')]);
            if (!$ok) { return new WP_Error('convertino_storage', 'Could not record visit.', ['status'=>503]); }
            $created = true;
        } else { $id = $session->id; }
        $ok = self::record($id, 'view', '', $view_key);
        if ($ok === false) { return new WP_Error('convertino_storage', 'Could not record page view.', ['status'=>503]); }
        if ($ok === 0 && $created) {
            // Concurrent retries can race before either response sets a cookie.
            // Keep the session that won the unique page-view insert.
            $winner = $wpdb->get_var($wpdb->prepare('SELECT session_id FROM '.self::table('events').' WHERE event_key=%s',hash('sha256',$view_key)));
            if ($winner && $winner !== $id) {
                $wpdb->delete(self::table('sessions'),['id'=>$id]); $id = $winner;
            }
        }
        self::set_cookie($id);
        return self::response(['ok'=>true]);
    }
    public static function request_event($request) {
        $ready = self::ready($request); if (is_wp_error($ready)) { return $ready; }
        $session = self::current_session();
        if (!$session) { return new WP_Error('convertino_session', 'Visit expired.', ['status'=>409]); }
        $type = $request->get_param('type'); $target = self::text($request->get_param('target'), 190);
        $id = (string)$request->get_param('id');
        if (!in_array($type, ['click','video_play','video_end'],true) || !preg_match('/^[a-f0-9-]{20,64}$/D',$id)) { return new WP_Error('convertino_event', 'Invalid event.', ['status'=>400]); }
        if ($type === 'click') {
            $allowed = array_column(self::settings()['buttons'], 'key');
            if (!in_array($target, $allowed, true)) { return new WP_Error('convertino_button', 'Button is not selected.', ['status'=>400]); }
        } elseif (!preg_match('/^fluent_player_[a-zA-Z0-9_-]{1,120}$/D', $target)) { return new WP_Error('convertino_video', 'Invalid video.', ['status'=>400]); }
        $key = $type === 'click' ? 'click:'.$session->id.':'.$id : $type.':'.$session->id.':'.$target;
        $ok = self::record($session->id, $type, $target, $key);
        if ($ok === false) { return new WP_Error('convertino_storage', 'Could not record event.', ['status'=>503]); }
        self::set_cookie($session->id);
        return self::response(['ok'=>true]);
    }
    public static function request_revoke($request) { self::set_cookie(); return self::response(['ok'=>true]); }
    public static function record($session_id, $type, $target, $key) {
        global $wpdb;
        $events = self::table('events'); $sessions = self::table('sessions');
        $result = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $events (session_id,event_key,type,target,created_at) VALUES (%s,%s,%s,%s,%s)", $session_id, hash('sha256',$key), $type, $target, gmdate('Y-m-d H:i:s')));
        $wpdb->update($sessions, ['last_seen'=>gmdate('Y-m-d H:i:s')], ['id'=>$session_id]);
        return $result;
    }
    public static function form($submission_id, $form_data, $form) {
        $s = self::settings();
        if (!$s['enabled'] || !$s['form_ids']) { return; }
        $form_id = (int)($form->id ?? 0);
        if (!in_array($form_id, $s['form_ids'], true) || !$submission_id) { return; }
        $session = self::current_session();
        if ($session) { self::record($session->id, 'form', (string)$form_id, 'fluentform:'.(int)$submission_id); }
    }
    public static function attach_order($data) {
        $s = self::settings(); if (!$s['enabled'] || !$s['product_ids']) { return; }
        $order = $data['order'] ?? null;
        if (!is_object($order) || !is_callable([$order, 'updateMeta'])) { return; }
        $session = self::current_session();
        if ($session) { $order->updateMeta('_convertino_attribution', ['session'=>$session->id,'products'=>$s['product_ids']]); }
    }
    public static function paid($data) {
        global $wpdb;
        $order = $data['order'] ?? null;
        if (!is_object($order) || !is_callable([$order,'getMeta']) || !is_callable([$order,'getTotalPaidAmount']) || empty($order->id) || ($order->mode ?? '') !== 'live' || !empty($order->parent_id)) { return; }
        $attribution = $order->getMeta('_convertino_attribution');
        $id = is_array($attribution) ? ($attribution['session'] ?? '') : '';
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) { return; }
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.self::table('sessions').' WHERE id=%s', $id))) { return; }
        $products = array_map('intval', (array)($attribution['products'] ?? [])); if (!$products) { return; }
        $items = $order->order_items;
        // V1 counts whole-order revenue only when every line belongs to the
        // selected products. Mixed baskets and renewals are deliberately excluded.
        $count = 0;
        foreach ($items ?: [] as $item) { $count++; if (!in_array((int)$item->post_id,$products,true)) { return; } }
        if (!$count || !preg_match('/^[A-Z]{3}$/D',(string)$order->currency)) { return; }
        $table = self::table('orders');
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (order_id,session_id,amount,refunded,currency,paid_at) VALUES (%d,%s,%d,%d,%s,%s)", (int)$order->id,$id,max(0,(int)$order->getTotalPaidAmount()),is_callable([$order,'getTotalRefundAmount']) ? max(0,(int)$order->getTotalRefundAmount()) : 0,$order->currency,gmdate('Y-m-d H:i:s')));
    }
    public static function refund($data) {
        global $wpdb; $order = $data['order'] ?? null;
        if (is_object($order) && !empty($order->id) && is_callable([$order,'getTotalRefundAmount'])) {
            $wpdb->update(self::table('orders'), ['refunded'=>max(0,(int)$order->getTotalRefundAmount())], ['order_id'=>(int)$order->id]);
        }
    }
    public static function report($page) {
        global $wpdb;
        $s = self::table('sessions'); $e = self::table('events'); $o = self::table('orders');
        // Aggregate each child table first: event/order joins cannot multiply totals.
        $sql = "SELECT s.source, COUNT(*) visits, SUM(COALESCE(e.views,0)) views,
            SUM(COALESCE(e.clicks,0)) clicks, SUM(COALESCE(e.forms,0)) forms,
            SUM(COALESCE(e.clicks,0)>0) click_visits, SUM(COALESCE(e.forms,0)>0) form_visits,
            SUM(COALESCE(e.plays,0)>0) video_visits, SUM(COALESCE(e.ends,0)>0) completed_visits,
            SUM(COALESCE(o.orders,0)) orders, SUM(COALESCE(o.orders,0)>0) purchase_visits
            FROM $s s LEFT JOIN (SELECT session_id, SUM(type='view') views, SUM(type='click') clicks,
                SUM(type='form') forms, SUM(type='video_play') plays, SUM(type='video_end') ends FROM $e GROUP BY session_id) e ON e.session_id=s.id
            LEFT JOIN (SELECT session_id, COUNT(*) orders FROM $o GROUP BY session_id) o ON o.session_id=s.id
            WHERE s.page_id=%d GROUP BY s.source ORDER BY visits DESC";
        $sources = $wpdb->get_results($wpdb->prepare($sql,$page),ARRAY_A);
        $buttons = $wpdb->get_results($wpdb->prepare("SELECT e.target, COUNT(*) clicks, COUNT(DISTINCT e.session_id) visits FROM $e e INNER JOIN $s s ON s.id=e.session_id WHERE s.page_id=%d AND e.type='click' GROUP BY e.target ORDER BY clicks DESC",$page),ARRAY_A);
        $revenue = $wpdb->get_results($wpdb->prepare("SELECT s.source,o.currency,SUM(GREATEST(0,o.amount-o.refunded)) amount FROM $o o INNER JOIN $s s ON s.id=o.session_id WHERE s.page_id=%d GROUP BY s.source,o.currency",$page),ARRAY_A);
        return compact('sources','buttons','revenue');
    }
    public static function cleanup() {
        global $wpdb;
        $s = self::table('sessions'); $e = self::table('events'); $o = self::table('orders');
        // One retention boundary keeps visit denominators and conversions together.
        $wpdb->query("DELETE e FROM $e e INNER JOIN $s s ON e.session_id=s.id WHERE s.started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)");
        $wpdb->query("DELETE o FROM $o o INNER JOIN $s s ON o.session_id=s.id WHERE s.started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)");
        $wpdb->query("DELETE FROM $s WHERE started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 180 DAY)");
    }
}
