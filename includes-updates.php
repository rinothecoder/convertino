<?php
if (!defined('ABSPATH')) { exit; }

/** Release checks use no GitHub credentials or analytics records. */
final class Convertino_Updates {
    const MANIFEST_URL = '';
    const UPDATE_URI = 'https://github.com/rinothecoder/convertino/';
    const MAX_PACKAGE_BYTES = 20971520;

    public static function boot() {
        add_filter('update_plugins_github.com', [__CLASS__, 'update'], 10, 4);
        add_filter('plugins_api', [__CLASS__, 'information'], 10, 3);
        add_filter('upgrader_pre_download', [__CLASS__, 'download'], 10, 4);
        add_action('admin_post_convertino_check_updates', [__CLASS__, 'check']);
    }

    public static function manifest_url() {
        return (string) apply_filters('convertino_update_manifest_url', self::MANIFEST_URL);
    }

    private static function cache_key() {
        return 'convertino_release_' . substr(hash('sha256', self::manifest_url()), 0, 20);
    }

    private static function https_url($url) {
        if (!is_string($url) || strlen($url) > 2048) { return false; }
        $parts = wp_parse_url($url);
        return $parts && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] === 443);
    }

    /** Downloads stay on the publisher's origin, or its exact GitHub repository. */
    public static function package_allowed($url) {
        $manifest = self::manifest_url();
        if (!self::https_url($manifest) || !self::https_url($url)) { return false; }
        $source = wp_parse_url($manifest); $package = wp_parse_url($url);
        if (strtolower($source['host']) !== strtolower($package['host'])) { return false; }
        if (strtolower($source['host']) === 'github.com') {
            $path = explode('/', trim($source['path'] ?? '', '/'));
            if (count($path) < 2) { return false; }
            $prefix = '/' . $path[0] . '/' . $path[1] . '/releases/download/';
            if (!str_starts_with($package['path'] ?? '', $prefix)) { return false; }
        }
        return str_ends_with($package['path'] ?? '', '.zip');
    }

    public static function validate($data) {
        if (!is_array($data) || ($data['slug'] ?? '') !== 'convertino'
            || !is_string($data['version'] ?? null)
            || !preg_match('/^\d+\.\d+\.\d+$/D', $data['version'])
            || !self::package_allowed($data['download_url'] ?? '')
            || !is_string($data['sha256'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'])) {
            return new WP_Error('convertino_release_invalid', 'The publisher returned invalid release information.');
        }
        foreach (['requires', 'requires_php', 'tested'] as $field) {
            if (!is_string($data[$field] ?? null) || !preg_match('/^\d+\.\d+(?:\.\d+)?$/D', $data[$field])) {
                return new WP_Error('convertino_release_invalid', 'The release has invalid compatibility information.');
            }
        }
        $data['homepage'] = self::https_url($data['homepage'] ?? '') ? $data['homepage'] : self::UPDATE_URI;
        $data['changelog'] = wp_kses_post(substr((string)($data['changelog'] ?? ''), 0, 16000));
        return array_intersect_key($data, array_flip(['slug','version','download_url','sha256','requires','requires_php','tested','homepage','changelog']));
    }

    public static function release($force = false) {
        $url = self::manifest_url();
        if (!$url) { return new WP_Error('convertino_release_unconfigured', 'The release download location has not been configured yet.'); }
        $cached = $force ? false : get_site_transient(self::cache_key());
        if (is_array($cached)) {
            return !empty($cached['release']) ? $cached['release'] : new WP_Error('convertino_release_unavailable', $cached['error']);
        }
        $release = new WP_Error('convertino_release_unavailable', 'Could not reach the release publisher. Tracking continues normally.');
        if (self::https_url($url)) {
            $response = wp_safe_remote_get($url, [
                'timeout'=>10, 'redirection'=>3, 'limit_response_size'=>65536,
                'user-agent'=>'Convertino Updater/' . Convertino_Core::VERSION,
                'headers'=>['Accept'=>'application/json'], 'cookies'=>[],
            ]);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                $release = self::validate(json_decode(wp_remote_retrieve_body($response), true));
            }
        }
        set_site_transient(self::cache_key(), [
            'checked'=>time(), 'release'=>is_wp_error($release) ? null : $release,
            'error'=>is_wp_error($release) ? $release->get_error_message() : '',
        ], is_wp_error($release) ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
        return $release;
    }

    public static function update($update, $plugin_data, $plugin_file, $locales) {
        if ($plugin_file !== plugin_basename(CONVERTINO_FILE)) { return $update; }
        $release = self::release();
        if (is_wp_error($release)) { return false; }
        return [
            'id'=>self::UPDATE_URI, 'slug'=>'convertino', 'version'=>$release['version'],
            'url'=>$release['homepage'], 'package'=>$release['download_url'],
            'requires'=>$release['requires'], 'requires_php'=>$release['requires_php'], 'tested'=>$release['tested'],
            // Pin the checksum to the release WordPress offered, even if a newer release appears later.
            'convertino_sha256'=>$release['sha256'],
        ];
    }

    public static function information($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'convertino') { return $result; }
        $release = self::release();
        if (is_wp_error($release)) { return $release; }
        return (object)[
            'name'=>'Convertino', 'slug'=>'convertino', 'version'=>$release['version'], 'author'=>'Rino',
            'homepage'=>$release['homepage'], 'requires'=>$release['requires'], 'requires_php'=>$release['requires_php'],
            'tested'=>$release['tested'], 'download_link'=>$release['download_url'],
            'sections'=>['description'=>'One page, its traffic sources, selected buttons and conversions.', 'changelog'=>$release['changelog']],
        ];
    }

    public static function download($reply, $package, $upgrader, $hook_extra) {
        if (($hook_extra['plugin'] ?? '') !== plugin_basename(CONVERTINO_FILE)
            || ($hook_extra['action'] ?? '') !== 'update' || ($hook_extra['type'] ?? '') !== 'plugin') { return $reply; }
        // Do not accept a preceding filter's unchecked file for Convertino.
        if (is_wp_error($reply)) { return $reply; }
        $updates = get_site_transient('update_plugins');
        $offered = $updates->response[plugin_basename(CONVERTINO_FILE)] ?? null;
        if (!$offered || ($offered->package ?? '') !== $package || !self::package_allowed($package)
            || !preg_match('/^[a-f0-9]{64}$/D', (string)($offered->convertino_sha256 ?? ''))) {
            return new WP_Error('convertino_package_unverified', 'Convertino could not verify this update. Check for updates again.');
        }
        if (!version_compare($offered->new_version ?? '0', Convertino_Core::VERSION, '>')) {
            return new WP_Error('convertino_package_old', 'This release is not newer than the installed version.');
        }
        if (!function_exists('wp_tempnam')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $file = wp_tempnam('convertino-update.zip');
        if (!$file) { return new WP_Error('convertino_package_temp', 'Could not create a temporary update file.'); }
        $response = wp_safe_remote_get($package, [
            'timeout'=>60, 'redirection'=>3, 'stream'=>true, 'filename'=>$file,
            'limit_response_size'=>self::MAX_PACKAGE_BYTES,
            'user-agent'=>'Convertino Updater/' . Convertino_Core::VERSION, 'cookies'=>[],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200
            || !hash_equals($offered->convertino_sha256, (string)hash_file('sha256', $file))) {
            wp_delete_file($file);
            return new WP_Error('convertino_package_integrity', 'The update download could not be verified. Your installed plugin has not been changed.');
        }
        return $file;
    }

    public static function check() {
        if (!current_user_can('update_plugins')) { wp_die('You cannot check plugin updates.'); }
        check_admin_referer('convertino_check_updates');
        self::release(true);
        wp_clean_plugins_cache();
        wp_update_plugins();
        wp_safe_redirect(admin_url('admin.php?page=convertino#convertino-updates')); exit;
    }

    public static function screen() {
        $cached = get_site_transient(self::cache_key());
        $release = is_array($cached) ? ($cached['release'] ?? null) : null;
        $message = !self::manifest_url() ? 'Release downloads are not configured yet.'
            : ($release ? (version_compare($release['version'], Convertino_Core::VERSION, '>') ? 'Version ' . $release['version'] . ' is available.' : 'You have the latest published version.')
                : ($cached['error'] ?? 'Ready to check for published releases.'));
        ?>
        <section id="convertino-updates" class="convertino-section"><h2>Plugin updates</h2>
            <p>Installed version: <strong><?php echo esc_html(Convertino_Core::VERSION); ?></strong></p>
            <p role="status"><?php echo esc_html($message); ?></p>
            <p class="convertino-muted">Published releases appear in WordPress’s Plugins screen. Updates preserve your page setup and analytics data. Automatic updates follow your WordPress choice. Development commits are not installed.</p>
            <?php if (current_user_can('update_plugins') && self::manifest_url()): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="convertino_check_updates"><?php wp_nonce_field('convertino_check_updates'); ?><button class="button">Check for updates</button></form>
                <?php if ($release && version_compare($release['version'], Convertino_Core::VERSION, '>')): ?><p><a class="button button-primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">Open Plugins to update</a></p><?php endif; ?>
            <?php endif; ?>
            <p class="convertino-muted">Release checks send no analytics records or GitHub credentials. The download host can see your server’s IP address.</p>
        </section>
        <?php
    }
}
