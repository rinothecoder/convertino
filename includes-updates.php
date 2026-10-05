<?php
if (!defined('ABSPATH')) { exit; }

/** Direct GitHub releases, using the site's connection as Adminino does. */
final class Convertino_Updates {
    const REPO = 'rinothecoder/convertino';
    const UPDATE_URI = 'https://github.com/rinothecoder/convertino/';
    const API = 'https://api.github.com/repos/rinothecoder/convertino';
    const MAX_PACKAGE_BYTES = 20971520;

    public static function boot() {
        add_filter('update_plugins_github.com', [__CLASS__, 'update'], 10, 4);
        add_filter('plugins_api', [__CLASS__, 'information'], 10, 3);
        add_filter('upgrader_pre_download', [__CLASS__, 'download'], 10, 4);
        add_filter('upgrader_source_selection', [__CLASS__, 'source'], 10, 4);
        add_action('admin_post_convertino_check_updates', [__CLASS__, 'check']);
    }
    private static function token() {
        // A dedicated connection can override Adminino's existing site connection.
        if (defined('CONVERTINO_GITHUB_TOKEN')) { return trim((string) CONVERTINO_GITHUB_TOKEN); }
        return defined('MK_GITHUB_TOKEN') ? trim((string) MK_GITHUB_TOKEN) : '';
    }
    private static function cache_key() {
        return 'convertino_github_' . substr(hash('sha256', self::token()), 0, 20);
    }
    private static function args() {
        return ['timeout'=>15, 'redirection'=>0, 'limit_response_size'=>262144,
            'user-agent'=>'Convertino Updater/' . Convertino_Core::VERSION,
            'headers'=>['Accept'=>'application/vnd.github+json', 'Authorization'=>'Bearer ' . self::token()], 'cookies'=>[]];
    }
    private static function error($code, $message) { return new WP_Error('convertino_' . $code, $message); }
    private static function json($path) {
        $response = wp_safe_remote_get(self::API . $path, self::args());
        if (is_wp_error($response)) { return self::error('github_unavailable', 'Could not reach GitHub. Tracking continues normally.'); }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401 || $code === 404) {
            return self::error('github_access', 'The site’s GitHub connection cannot read Convertino. Give its token Contents: read access to rinothecoder/convertino.');
        }
        if ($code !== 200) { return self::error('github_unavailable', 'GitHub could not complete the check. Try again later.'); }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($data) ? $data : self::error('github_invalid', 'GitHub returned invalid release information.');
    }
    public static function validate($data, $commit) {
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])
            || !is_string($data['tag_name'] ?? null) || !preg_match('/^v?(\d+\.\d+\.\d+)$/D', $data['tag_name'], $version)
            || !is_string($commit) || !preg_match('/^[a-f0-9]{40}$/D', $commit)) {
            return self::error('github_invalid', 'Publish a stable Convertino release with a tag such as v0.1.3.');
        }
        return ['version'=>$version[1], 'commit'=>$commit, 'download_url'=>self::API . '/zipball/' . $commit,
            'homepage'=>self::UPDATE_URI . 'releases/tag/' . rawurlencode($data['tag_name']),
            'changelog'=>nl2br(esc_html(substr((string)($data['body'] ?? ''), 0, 16000)))];
    }
    public static function release($force = false) {
        if (!self::token()) {
            return self::error('github_token', 'No GitHub connection is configured on this site. Add a read-only Convertino token in wp-config.php, or use Adminino’s MK_GITHUB_TOKEN connection.');
        }
        $cached = $force ? false : get_site_transient(self::cache_key());
        if (is_array($cached)) { return $cached['release'] ?? self::error($cached['code'], $cached['error']); }
        $release = self::json('/releases/latest');
        if (is_wp_error($release) && $release->get_error_code() === 'convertino_github_access') {
            // GitHub uses 404 for both a hidden repo and a repo without releases.
            $repo = self::json('');
            if (!is_wp_error($repo)) { $release = self::error('github_no_release', 'GitHub is connected. No published release yet.'); }
        }
        if (!is_wp_error($release)) {
            $tag = $release['tag_name'] ?? '';
            if (!is_string($tag) || !preg_match('/^v?\d+\.\d+\.\d+$/D', $tag) || !empty($release['draft']) || !empty($release['prerelease'])) {
                $release = self::error('github_invalid', 'Publish a stable Convertino release with a tag such as v0.1.3.');
            } else {
                // Pin the offered release to its commit, not a movable branch or tag.
                $commit = self::json('/commits/' . rawurlencode($tag));
                $release = is_wp_error($commit) ? $commit : self::validate($release, $commit['sha'] ?? '');
            }
        }
        set_site_transient(self::cache_key(), ['release'=>is_wp_error($release) ? null : $release,
            'code'=>is_wp_error($release) ? substr($release->get_error_code(), strlen('convertino_')) : '',
            'error'=>is_wp_error($release) ? $release->get_error_message() : ''],
            is_wp_error($release) ? 15 * MINUTE_IN_SECONDS : 6 * HOUR_IN_SECONDS);
        return $release;
    }
    public static function update($update, $plugin_data, $plugin_file, $locales) {
        if ($plugin_file !== plugin_basename(CONVERTINO_FILE)) { return $update; }
        $release = self::release();
        if (is_wp_error($release)) { return false; }
        return ['id'=>self::UPDATE_URI, 'slug'=>'convertino', 'version'=>$release['version'],
            'url'=>$release['homepage'], 'package'=>$release['download_url'],
            'requires'=>'6.5', 'requires_php'=>'8.0', 'convertino_commit'=>$release['commit']];
    }
    public static function information($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'convertino') { return $result; }
        $release = self::release();
        if (is_wp_error($release)) { return $release; }
        return (object)['name'=>'Convertino', 'slug'=>'convertino', 'version'=>$release['version'], 'author'=>'Rino',
            'homepage'=>$release['homepage'], 'requires'=>'6.5', 'requires_php'=>'8.0', 'download_link'=>$release['download_url'],
            'sections'=>['description'=>'One page, its traffic sources, selected buttons and conversions.', 'changelog'=>$release['changelog']]];
    }
    private static function own_update($extra) {
        return ($extra['plugin'] ?? '') === plugin_basename(CONVERTINO_FILE)
            && ($extra['action'] ?? '') === 'update' && ($extra['type'] ?? '') === 'plugin';
    }
    private static function offered() {
        $updates = get_site_transient('update_plugins');
        return $updates->response[plugin_basename(CONVERTINO_FILE)] ?? null;
    }
    /** Only this repository's pinned archive may receive the site's credential. */
    public static function package_allowed($url, $commit) {
        return is_string($commit) && preg_match('/^[a-f0-9]{40}$/D', $commit) && $url === self::API . '/zipball/' . $commit;
    }
    public static function download_url_allowed($url, $commit) {
        if (!is_string($url) || strlen($url) > 4096 || !preg_match('/^[a-f0-9]{40}$/D', (string)$commit)) { return false; }
        $parts = wp_parse_url($url);
        return $parts && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === 'codeload.github.com'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] === 443)
            && in_array($parts['path'] ?? '', ['/' . self::REPO . '/legacy.zip/' . $commit, '/' . self::REPO . '/zip/' . $commit], true);
    }
    public static function download($reply, $package, $upgrader, $extra) {
        if (!self::own_update($extra) || is_wp_error($reply)) { return $reply; }
        $offered = self::offered();
        if (!$offered || ($offered->package ?? '') !== $package || !self::token()
            || !self::package_allowed($package, $offered->convertino_commit ?? '')) {
            return self::error('package_unverified', 'Check for Convertino updates again before installing.');
        }
        if (!version_compare($offered->new_version ?? '0', Convertino_Core::VERSION, '>')) {
            return self::error('package_old', 'This release is not newer than the installed version.');
        }
        // Resolve a fresh signed archive URL. Never forward Authorization to a redirect.
        $response = wp_safe_remote_get($package, self::args());
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 302) {
            return self::error('package_unavailable', 'GitHub could not provide the update download. Check the site’s repository access.');
        }
        $url = wp_remote_retrieve_header($response, 'location');
        if (!self::download_url_allowed($url, $offered->convertino_commit)) {
            return self::error('package_redirect', 'GitHub returned an unexpected update download location.');
        }
        if (!function_exists('wp_tempnam')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $file = wp_tempnam('convertino-update.zip');
        if (!$file) { return self::error('package_temp', 'Could not create a temporary update file.'); }
        $response = wp_safe_remote_get($url, ['timeout'=>60, 'redirection'=>0, 'stream'=>true, 'filename'=>$file,
            'limit_response_size'=>self::MAX_PACKAGE_BYTES, 'user-agent'=>'Convertino Updater/' . Convertino_Core::VERSION,
            'cookies'=>[], 'headers'=>[]]);
        $handle = fopen($file, 'rb'); $signature = $handle ? fread($handle, 4) : '';
        if ($handle) { fclose($handle); }
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200
            || $signature !== "PK\x03\x04" || filesize($file) >= self::MAX_PACKAGE_BYTES) {
            wp_delete_file($file);
            return self::error('package_invalid', 'The update ZIP could not be downloaded. Your installed plugin has not been changed.');
        }
        return $file;
    }
    public static function source($source, $remote, $upgrader, $extra) {
        if (!self::own_update($extra) || is_wp_error($source)) { return $source; }
        global $wp_filesystem;
        $offered = self::offered();
        $header = $wp_filesystem->get_contents(trailingslashit($source) . 'convertino.php');
        $core = $wp_filesystem->get_contents(trailingslashit($source) . 'includes-core.php');
        if (!$offered || !is_string($header) || !is_string($core)
            || !preg_match('/^\s*\* Plugin Name:\s*Convertino\s*$/m', $header)
            || !preg_match('/^\s*\* Version:\s*(\d+\.\d+\.\d+)\s*$/m', $header, $version)
            || $version[1] !== ($offered->new_version ?? '') || !str_contains($core, "const VERSION = '" . $version[1] . "';")) {
            return self::error('package_version', 'The downloaded plugin does not match the published release version.');
        }
        foreach (['includes-admin.php', 'includes-updates.php', 'assets/tracker.js', 'assets/admin.js', 'assets/admin.css'] as $required) {
            if (!$wp_filesystem->is_file(trailingslashit($source) . $required)) { return self::error('package_incomplete', 'The published Convertino release is missing required files.'); }
        }
        // GitHub archives use an owner/repo/hash folder; WordPress needs convertino/.
        $target = trailingslashit(dirname(untrailingslashit($source))) . 'convertino/';
        if (untrailingslashit($source) === untrailingslashit($target)) { return $source; }
        return $wp_filesystem->move($source, $target, true) ? $target : self::error('package_folder', 'Could not prepare the Convertino update folder.');
    }
    public static function check() {
        if (!current_user_can('update_plugins')) { wp_die('You cannot check plugin updates.'); }
        check_admin_referer('convertino_check_updates');
        self::release(true); wp_clean_plugins_cache(); wp_update_plugins();
        wp_safe_redirect(admin_url('admin.php?page=convertino#convertino-updates')); exit;
    }
    public static function screen() {
        $cached = get_site_transient(self::cache_key());
        $release = is_array($cached) ? ($cached['release'] ?? null) : null;
        $message = !self::token() ? 'No GitHub connection is configured on this site.'
            : ($release ? (version_compare($release['version'], Convertino_Core::VERSION, '>') ? 'Version ' . $release['version'] . ' is available.' : 'You have the latest published version.')
                : ($cached['error'] ?? 'Ready to check GitHub for published releases.'));
        ?>
        <section id="convertino-updates" class="convertino-section"><h2>Plugin updates</h2>
            <p>Installed version: <strong><?php echo esc_html(Convertino_Core::VERSION); ?></strong></p>
            <p role="status"><?php echo esc_html($message); ?></p>
            <p class="convertino-muted">Updates come directly from GitHub releases, just like Adminino. Updates preserve your page setup and analytics data. Automatic updates follow your WordPress choice.</p>
            <?php if (current_user_can('update_plugins')): ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="convertino_check_updates"><?php wp_nonce_field('convertino_check_updates'); ?><button class="button">Check for updates</button></form>
                <?php if ($release && version_compare($release['version'], Convertino_Core::VERSION, '>')): ?><p><a class="button button-primary" href="<?php echo esc_url(admin_url('plugins.php')); ?>">Open Plugins to update</a></p><?php endif; ?>
            <?php endif; ?>
            <p class="convertino-muted">This private repository needs a read-only token in wp-config.php: CONVERTINO_GITHUB_TOKEN, or Adminino’s existing MK_GITHUB_TOKEN with access to Convertino. The token stays on this server and is sent only to GitHub’s API. Update checks send no analytics records or site URL.</p>
        </section>
        <?php
    }
}
