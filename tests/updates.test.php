<?php
// Standalone contracts: fake network and filesystem, no website credentials or records.
define('ABSPATH', '/unused/');
define('CONVERTINO_FILE', '/plugins/convertino/convertino.php');
define('MINUTE_IN_SECONDS', 60); define('HOUR_IN_SECONDS', 3600);
class Convertino_Core { const VERSION = '0.1.2'; }
class WP_Error {
    public function __construct(public $code, public $message) {}
    public function get_error_message() { return $this->message; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($x) { return $x instanceof WP_Error; }
function plugin_basename($file) { return 'convertino/convertino.php'; }
function wp_parse_url($url) { return parse_url($url); }
function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES); }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function untrailingslashit($s) { return rtrim($s, '/'); }
function add_filter(...$args) { $GLOBALS['hooks'][] = $args; }
function add_action(...$args) { $GLOBALS['hooks'][] = $args; }
function get_site_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_site_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; $GLOBALS['ttl'] = $ttl; }
function wp_remote_retrieve_response_code($r) { return $r['response']['code']; }
function wp_remote_retrieve_body($r) { return $r['body'] ?? ''; }
function wp_remote_retrieve_header($r, $name) { return $r['headers'][$name] ?? ''; }
function wp_safe_remote_get($url, $args) {
    $GLOBALS['requests'][] = [$url, $args];
    $r = $GLOBALS['responses'][$url] ?? new WP_Error('network', 'offline');
    if (!empty($args['stream']) && !is_wp_error($r)) { file_put_contents($args['filename'], $r['body'] ?? ''); }
    return $r;
}
function wp_tempnam($name) { return tempnam(sys_get_temp_dir(), 'convertino-test-'); }
function wp_delete_file($file) { unlink($file); $GLOBALS['deleted'][] = $file; }
class Fake_Filesystem {
    public $files = []; public $moves = []; public $move_result = true;
    public function get_contents($path) { return $this->files[basename($path)] ?? false; }
    public function is_file($path) { return isset($this->files[basename($path)]); }
    public function move($from, $to, $overwrite) { $this->moves[] = [$from,$to,$overwrite]; return $this->move_result; }
}
require __DIR__ . '/../includes-updates.php';
$count = 0;
function expect($yes, $label) { global $count; $count++; if (!$yes) { throw new Exception($label); } }
function response($code, $body = '', $headers = []) { return ['response'=>['code'=>$code], 'body'=>$body, 'headers'=>$headers]; }
$GLOBALS['cache']=[]; $GLOBALS['hooks']=[]; $GLOBALS['requests']=[]; $GLOBALS['responses']=[];
Convertino_Updates::boot();
expect(count($GLOBALS['hooks']) === 5, 'Five scoped hooks');
define('MK_GITHUB_TOKEN', 'FAKE_TEST_TOKEN');
$sha = str_repeat('a', 40); $api = Convertino_Updates::API;
$release = ['tag_name'=>'v0.1.3','draft'=>false,'prerelease'=>false,'body'=>'<script>unsafe</script>'];
$GLOBALS['responses'][$api . '/releases/latest'] = response(200, json_encode($release));
$GLOBALS['responses'][$api . '/commits/v0.1.3'] = response(200, json_encode(['sha'=>$sha]));
expect(Convertino_Updates::update('other', [], 'other/main.php', []) === 'other', 'Other update untouched');
expect(count($GLOBALS['requests']) === 0, 'Other plugin does not cause a request');
$offer = Convertino_Updates::update(false, [], 'convertino/convertino.php', []);
expect($offer['version'] === '0.1.3' && $offer['convertino_commit'] === $sha, 'Published version pins commit');
expect($offer['package'] === $api . '/zipball/' . $sha, 'Download immutable commit, never main');
expect($GLOBALS['ttl'] === 21600, 'Success cached six hours');
expect(empty($GLOBALS['requests'][0][1]['headers']['Authorization']), 'Public requests ignore an existing site token');
expect(empty($GLOBALS['requests'][0][1]['cookies']), 'Public requests send no site cookies');
expect($GLOBALS['requests'][0][1]['redirection'] === 0, 'API never auto-forwards token');
expect($GLOBALS['requests'][0][1]['user-agent'] === 'Convertino Updater/0.1.2', 'No site URL in user agent');
expect(!str_contains(json_encode($offer), 'FAKE_TEST_TOKEN'), 'No credential in WordPress offer');
Convertino_Updates::release(); expect(count($GLOBALS['requests']) === 2, 'Cache avoids repeated checks');
expect(Convertino_Updates::information('other', 'plugin_information', (object)['slug'=>'other']) === 'other', 'Other details untouched');
$info=Convertino_Updates::information(false, 'plugin_information', (object)['slug'=>'convertino']);
expect(!str_contains($info->sections['changelog'], '<script>'), 'Escape release notes');
foreach (['tag_name'=>'main','draft'=>true,'prerelease'=>true] as $key=>$bad) {
    expect(is_wp_error(Convertino_Updates::validate(array_merge($release, [$key=>$bad]), $sha)), 'Reject '.$key);
}
expect(is_wp_error(Convertino_Updates::validate($release, 'main')), 'Reject movable branch');
foreach (['http://api.github.com/repos/rinothecoder/convertino/zipball/'.$sha, $api.'/zipball/main', $api.'/zipball/'.$sha.'?token=x','https://api.github.com/repos/other/repo/zipball/'.$sha] as $url) {
    expect(!Convertino_Updates::package_allowed($url,$sha), 'Reject unrelated credential destination');
}
$url = 'https://codeload.github.com/rinothecoder/convertino/legacy.zip/'.$sha.'?token=TEMPORARY';
foreach (['https://evil.test/a.zip','https://codeload.github.com/other/repo/legacy.zip/'.$sha,'https://codeload.github.com/rinothecoder/convertino/legacy.zip/main','https://user:pass@codeload.github.com/rinothecoder/convertino/legacy.zip/'.$sha] as $bad) {
    expect(!Convertino_Updates::download_url_allowed($bad,$sha), 'Reject unsafe redirect');
}
expect(Convertino_Updates::download_url_allowed($url,$sha), 'Accept exact pinned GitHub archive redirect');
$GLOBALS['cache']['update_plugins'] = (object)['response'=>['convertino/convertino.php'=>(object)[
    'package'=>$offer['package'],'new_version'=>'0.1.3','convertino_commit'=>$sha]]];
$extra = ['plugin'=>'convertino/convertino.php','action'=>'update','type'=>'plugin'];
$GLOBALS['responses'][$offer['package']]=response(302, '', ['location'=>$url]);
$GLOBALS['responses'][$url]=response(200, "PK\x03\x04ZIP_FIXTURE");
expect(Convertino_Updates::download('other-file',$offer['package'],null,['plugin'=>'other/main.php']) === 'other-file', 'Other download untouched');
$download = Convertino_Updates::download(false,$offer['package'],null,$extra);
expect(is_string($download) && file_get_contents($download) === "PK\x03\x04ZIP_FIXTURE", 'Return downloaded ZIP'); unlink($download);
$requests=$GLOBALS['requests']; $last=end($requests);
expect(empty($last[1]['headers']['Authorization']), 'Do not forward token to archive host');
expect($last[1]['redirection'] === 0, 'Do not follow unexpected archive redirects');
expect(!str_contains(json_encode($GLOBALS['cache']), 'TEMPORARY'), 'Do not cache signed download URL');
expect(is_wp_error(Convertino_Updates::download(false,'https://evil.test/a.zip',null,$extra)), 'Reject unannounced package');
$GLOBALS['responses'][$url]=response(200, '<html>failure</html>');
expect(is_wp_error(Convertino_Updates::download(false,$offer['package'],null,$extra)), 'Reject non-ZIP');
expect(!file_exists(end($GLOBALS['deleted'])), 'Failed temporary file removed');
$GLOBALS['responses'][$offer['package']]=response(302,'',['location'=>'https://evil.test/a.zip']);
$before=count($GLOBALS['requests']);
expect(is_wp_error(Convertino_Updates::download(false,$offer['package'],null,$extra)), 'Reject unexpected redirect before requesting it');
expect(count($GLOBALS['requests']) === $before+1, 'Unsafe redirect never requested');
$GLOBALS['cache']['update_plugins']->response['convertino/convertino.php']->new_version='0.1.1';
expect(is_wp_error(Convertino_Updates::download(false,$offer['package'],null,$extra)), 'Downgrade rejected');
$GLOBALS['cache']['update_plugins']->response['convertino/convertino.php']->new_version='0.1.3';
$GLOBALS['wp_filesystem']=new Fake_Filesystem;
foreach (['includes-admin.php','includes-updates.php','tracker.js','admin.js','admin.css'] as $f) { $GLOBALS['wp_filesystem']->files[$f]='runtime'; }
$GLOBALS['wp_filesystem']->files['convertino.php']="<?php\n/**\n * Plugin Name: Convertino\n * Version: 0.1.3\n */";
$GLOBALS['wp_filesystem']->files['includes-core.php']="const VERSION = '0.1.3';";
expect(Convertino_Updates::source('/tmp/other/','',null,['plugin'=>'other/main.php']) === '/tmp/other/', 'Other extraction untouched');
expect(Convertino_Updates::source('/tmp/github-hash/','',null,$extra) === '/tmp/convertino/', 'Keep installed folder name');
$GLOBALS['wp_filesystem']->files['convertino.php']=str_replace('0.1.3','0.1.2',$GLOBALS['wp_filesystem']->files['convertino.php']);
expect(is_wp_error(Convertino_Updates::source('/tmp/github-hash/','',null,$extra)), 'Release and archive version mismatch rejected');
$GLOBALS['wp_filesystem']->files['convertino.php']=str_replace('0.1.2','0.1.3',$GLOBALS['wp_filesystem']->files['convertino.php']);
unset($GLOBALS['wp_filesystem']->files['tracker.js']);
expect(is_wp_error(Convertino_Updates::source('/tmp/github-hash/','',null,$extra)), 'Missing runtime rejected');
$GLOBALS['responses'][$api.'/releases/latest']=response(404);
$GLOBALS['responses'][$api]=response(200,json_encode(['name'=>'convertino']));
$r=Convertino_Updates::release(true);
expect(is_wp_error($r) && $r->get_error_code()==='convertino_github_no_release', 'Connected repository without releases recognized');
expect($GLOBALS['ttl']===900, 'Failure cached fifteen minutes');
$GLOBALS['responses'][$api]=response(404); $r=Convertino_Updates::release(true);
expect($r->get_error_code()==='convertino_github_access', 'No repo access actionable');
$GLOBALS['responses'][$api.'/releases/latest']=new WP_Error('network','offline');
expect(is_wp_error(Convertino_Updates::release(true)), 'Offline check fails safely');
$before=count($GLOBALS['requests']); Convertino_Updates::release();
expect(count($GLOBALS['requests'])===$before, 'Failure cache avoids hammering API');
// Old configured tokens, including empty overrides, do not affect public access.
define('CONVERTINO_GITHUB_TOKEN', '');
$GLOBALS['responses'][$api.'/releases/latest']=response(200,json_encode($release));
$GLOBALS['responses'][$api.'/commits/v0.1.3']=response(200,json_encode(['sha'=>$sha]));
expect(!is_wp_error(Convertino_Updates::release(true)), 'Public access works with an empty legacy token');
foreach ($GLOBALS['requests'] as $request) { expect(empty($request[1]['headers']['Authorization']), 'No request sends a legacy site token'); }
echo "PASS: $count GitHub updater checks\n";
