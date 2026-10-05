<?php
// Standalone contract tests. Network responses are fixtures; no WordPress site is changed.
define('ABSPATH', '/unused/');
define('CONVERTINO_FILE', '/plugins/convertino/convertino.php');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
class Convertino_Core { const VERSION = '0.1.1'; }
class WP_Error {
    public function __construct(public $code, public $message) {}
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function plugin_basename($file) { return 'convertino/convertino.php'; }
function wp_parse_url($url) { return parse_url($url); }
function wp_kses_post($text) { return strip_tags($text, '<p><ul><li><strong>'); }
function add_filter(...$args) { $GLOBALS['hooks'][] = $args; }
function add_action(...$args) { $GLOBALS['hooks'][] = $args; }
function apply_filters($hook, $value) { return $hook === 'convertino_update_manifest_url' ? $GLOBALS['manifest_url'] : $value; }
function get_site_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_site_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; $GLOBALS['ttl'] = $ttl; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_safe_remote_get($url, $args) {
    $GLOBALS['requests'][] = [$url, $args];
    if (!empty($args['stream'])) { file_put_contents($args['filename'], $GLOBALS['zip']); }
    return $GLOBALS['response'];
}
function wp_tempnam($name) { return tempnam(sys_get_temp_dir(), 'convertino-test-'); }
function wp_delete_file($file) { unlink($file); $GLOBALS['deleted'][] = $file; }
require __DIR__ . '/../includes-updates.php';
$count = 0;
function expect($condition, $message) { global $count; $count++; if (!$condition) { throw new Exception($message); } }
$GLOBALS['cache'] = []; $GLOBALS['requests'] = []; $GLOBALS['hooks'] = [];
$GLOBALS['manifest_url'] = 'https://github.com/rinothecoder/convertino-downloads/releases/latest/download/update.json';
$GLOBALS['zip'] = 'release contents';
$release = [
    'slug'=>'convertino','version'=>'0.1.2',
    'download_url'=>'https://github.com/rinothecoder/convertino-downloads/releases/download/v0.1.2/convertino-0.1.2.zip',
    'sha256'=>hash('sha256', $GLOBALS['zip']), 'requires'=>'6.5','requires_php'=>'8.0','tested'=>'7.1.2',
    'homepage'=>'https://github.com/rinothecoder/convertino-downloads','changelog'=>'<p>Update.</p><script>alert(1)</script>',
];
$GLOBALS['response'] = ['response'=>['code'=>200], 'body'=>json_encode($release)];
Convertino_Updates::boot();
expect(count($GLOBALS['hooks']) === 4, 'Expected scoped WordPress integration hooks');
expect(Convertino_Updates::update('other', [], 'other/other.php', []) === 'other', 'Must not change other plugin updates');
expect(count($GLOBALS['requests']) === 0, 'Other plugin must not trigger our network call');
$result = Convertino_Updates::update(false, [], 'convertino/convertino.php', []);
expect($result['version'] === '0.1.2' && $result['convertino_sha256'] === $release['sha256'], 'Offer version with pinned checksum');
expect($GLOBALS['ttl'] === 21600, 'Cache valid releases for six hours');
expect(!isset($GLOBALS['requests'][0][1]['headers']['Authorization']), 'Never send account credentials');
expect($GLOBALS['requests'][0][1]['user-agent'] === 'Convertino Updater/0.1.1', 'Do not put site URL in user agent');
Convertino_Updates::release();
expect(count($GLOBALS['requests']) === 1, 'Cached release must avoid repeated requests');
foreach (['http://github.com/a.zip','https://evil.test/a.zip','https://github.com/other/repo/releases/download/v1/a.zip','https://github.com/rinothecoder/convertino-downloads/archive/main.zip','https://user:secret@github.com/a.zip'] as $url) {
    $bad = $release; $bad['download_url'] = $url;
    expect(is_wp_error(Convertino_Updates::validate($bad)), 'Reject untrusted package ' . $url);
}
foreach (['slug'=>'other','version'=>'0.2.0-beta','sha256'=>'missing','requires_php'=>'no'] as $field=>$value) {
    $bad = $release; $bad[$field] = $value;
    expect(is_wp_error(Convertino_Updates::validate($bad)), 'Reject invalid ' . $field);
}
$info = Convertino_Updates::information('other', 'plugin_information', (object)['slug'=>'other']);
expect($info === 'other', 'Other plugin information unchanged');
$info = Convertino_Updates::information(false, 'plugin_information', (object)['slug'=>'convertino']);
expect(!str_contains($info->sections['changelog'], '<script>'), 'Strip unsafe changelog markup');
$GLOBALS['cache']['update_plugins'] = (object)['response'=>['convertino/convertino.php'=>(object)[
    'package'=>$release['download_url'], 'new_version'=>'0.1.2', 'convertino_sha256'=>$release['sha256'],
]]];
$extra = ['plugin'=>'convertino/convertino.php','type'=>'plugin','action'=>'update'];
expect(Convertino_Updates::download('other-file', $release['download_url'], null, ['plugin'=>'other/other.php']) === 'other-file', 'Other downloads unchanged');
$file = Convertino_Updates::download(false, $release['download_url'], null, $extra);
expect(is_string($file) && file_get_contents($file) === $GLOBALS['zip'], 'Verified package returned to WordPress');
unlink($file);
$GLOBALS['zip'] = 'corrupted download';
expect(is_wp_error(Convertino_Updates::download(false, $release['download_url'], null, $extra)), 'Corrupted ZIP rejected before installation');
expect(!file_exists(end($GLOBALS['deleted'])), 'Rejected temporary download removed');
expect(is_wp_error(Convertino_Updates::download(false, 'https://evil.test/update.zip', null, $extra)), 'Unannounced package rejected');
$GLOBALS['cache']['update_plugins']->response['convertino/convertino.php']->new_version = '0.1.0';
expect(is_wp_error(Convertino_Updates::download(false, $release['download_url'], null, $extra)), 'Reject downgrade');
$GLOBALS['response'] = new WP_Error('network', 'offline');
expect(is_wp_error(Convertino_Updates::release(true)), 'Network outage fails safely');
expect($GLOBALS['ttl'] === 900, 'Retry failed publisher after fifteen minutes');
$before = count($GLOBALS['requests']); Convertino_Updates::release();
expect(count($GLOBALS['requests']) === $before, 'Failure cache avoids repeated requests');
$GLOBALS['manifest_url'] = '';
expect(is_wp_error(Convertino_Updates::release()), 'Unconfigured publisher does not pretend to work');
echo "PASS: $count updater checks\n";
