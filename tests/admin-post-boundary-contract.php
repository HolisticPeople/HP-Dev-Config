<?php
declare(strict_types=1);

define('DEV_CFG_TEST_BOOTSTRAP', true);
define('ABSPATH', __DIR__ . '/');
define('WP_PLUGIN_DIR', __DIR__ . '/missing-plugins');

$GLOBALS['test_options'] = [];
$GLOBALS['test_settings_errors'] = [];
$GLOBALS['test_actions'] = [];
$GLOBALS['test_filters'] = [];
$GLOBALS['test_current_user_can'] = true;
$GLOBALS['test_is_admin'] = true;
$GLOBALS['test_die_message'] = null;

function add_action($hook, $callback): void { $GLOBALS['test_actions'][$hook][] = $callback; }
function add_filter($hook, $callback): void { $GLOBALS['test_filters'][$hook][] = $callback; }
function plugin_basename($file): string { return basename((string) $file); }
function is_admin(): bool { return (bool) $GLOBALS['test_is_admin']; }
function current_user_can($capability): bool { return (bool) $GLOBALS['test_current_user_can']; }
function wp_verify_nonce($nonce, $action): bool { return $nonce === 'valid-' . $action; }
function check_admin_referer($action): bool { return isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], $action); }
function wp_die($message): void { $GLOBALS['test_die_message'] = $message; throw new RuntimeException((string) $message); }
function add_settings_error($setting, $code, $message, $type = 'error'): void {
	$GLOBALS['test_settings_errors'][] = compact('setting', 'code', 'message', 'type');
}
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['test_options']) ? $GLOBALS['test_options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['test_options'][$key] = $value; return true; }
function set_transient($key, $value, $expiration): bool { $GLOBALS['test_transients'][$key] = $value; return true; }
function get_plugins(): array { return []; }
function is_plugin_active($pluginFile): bool { return false; }
function activate_plugin($pluginFile) { return true; }
function deactivate_plugins($plugins, $silent = false): void {}
function is_wp_error($value): bool { return false; }
function sanitize_text_field($value): string {
	return trim(strip_tags((string) $value));
}
function sanitize_key($key): string {
	$key = strtolower((string) $key);
	return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
}
function absint($value): int { return max(0, (int) $value); }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function wp_parse_args($args, $defaults = []): array {
	return array_replace_recursive($defaults, is_array($args) ? $args : []);
}

require dirname(__DIR__) . '/hp-dev-config.php';

function assert_true($condition, $message): void {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}

	echo "PASS: {$message}\n";
}

function reset_request_state(): void {
	$_GET = ['page' => DevCfgPlugin::MENU_SLUG];
	$_POST = [];
	$GLOBALS['test_options'] = [];
	$GLOBALS['test_settings_errors'] = [];
	$GLOBALS['test_transients'] = [];
	$GLOBALS['test_current_user_can'] = true;
	$GLOBALS['test_is_admin'] = true;
	$GLOBALS['test_die_message'] = null;
	unset($GLOBALS['dev_cfg_ui_selections']);
}

function run_request(array $post, array $seedOptions = []): void {
	reset_request_state();
	$GLOBALS['test_options'] = $seedOptions;
	$_POST = $post;
	DevCfgPlugin::handle_post_actions();
}

$nonceRejected = false;
try {
	run_request(['dev_cfg_save' => '1', 'dev_cfg_nonce_save' => 'invalid']);
} catch (RuntimeException $e) {
	$nonceRejected = true;
}
assert_true($nonceRejected, 'Save branch stops on invalid nonce');
assert_true($GLOBALS['test_die_message'] === 'Security check failed. Please try again.', 'Save branch rejects invalid nonce');

reset_request_state();
$GLOBALS['test_current_user_can'] = false;
$_POST = ['dev_cfg_save' => '1', 'dev_cfg_nonce_save' => 'valid-dev_cfg_save'];
DevCfgPlugin::handle_post_actions();
assert_true($GLOBALS['test_options'] === [], 'Capability gate returns before saving settings');

run_request([
	'dev_cfg_save_as_new' => '1',
	'dev_cfg_nonce_config' => 'valid-dev_cfg_config_action',
	'dev_cfg_new_config_name' => '  ',
]);
assert_true($GLOBALS['test_settings_errors'][0]['code'] === 'name_error', 'Save-as-new requires a non-empty config name');

run_request([
	'dev_cfg_save' => '1',
	'dev_cfg_nonce_save' => 'valid-dev_cfg_save',
	'dev_cfg_policy' => [
		'clean/plugin.php' => 'enable',
		'bad/plugin.php' => 'unexpected',
	],
	'dev_cfg_action' => [
		'fluent_smtp_simulation' => 'disable',
		'recover_codex_runner' => 'ignore',
		'recover_inspector_worker' => 'enable',
		'delete_debug_logs' => '1',
	],
	'mcp_creds' => [
		'staging' => [
			'consumer_key' => ' placeholder-key ',
			'consumer_secret' => ' placeholder-secret ',
			'user_id' => '7',
		],
		'production' => [
			'consumer_key' => '',
			'consumer_secret' => '',
			'user_id' => '9',
		],
	],
]);
$settings = $GLOBALS['test_options'][DevCfgPlugin::OPTION_KEY];
assert_true($settings['saved_configs']['Default']['plugin_policies']['clean/plugin.php'] === 'enable', 'Save branch preserves valid plugin policy');
assert_true($settings['saved_configs']['Default']['plugin_policies']['bad/plugin.php'] === 'ignore', 'Save branch normalizes invalid plugin policy');
assert_true($settings['saved_configs']['Default']['other_actions']['fluent_smtp_simulation'] === 'disable', 'Save branch preserves FluentSMTP action mode');
assert_true($settings['saved_configs']['Default']['other_actions']['recover_codex_runner'] === 'ignore', 'Save branch preserves Codex runner ignore mode');
assert_true($settings['saved_configs']['Default']['other_actions']['recover_inspector_worker'] === 'enable', 'Save branch preserves Inspector worker enable mode');
assert_true($settings['saved_configs']['Default']['other_actions']['delete_debug_logs'] === true, 'Save branch preserves boolean action keys');
$creds = $GLOBALS['test_options']['hp_dev_config_mcp_credentials'];
assert_true($creds['staging']['consumer_key'] === 'placeholder-key', 'Save branch stores sanitized staging MCP key placeholder');
assert_true($creds['staging']['consumer_secret'] === 'placeholder-secret', 'Save branch stores sanitized staging MCP secret placeholder');
assert_true($creds['staging']['user_id'] === 7, 'Save branch stores sanitized staging MCP user ID');
assert_true(!isset($creds['production']) || $creds['production']['consumer_key'] === '', 'Save branch skips empty production MCP credentials');

run_request([
	'dev_cfg_apply' => '1',
	'dev_cfg_nonce_apply' => 'valid-dev_cfg_apply',
	'dev_cfg_policy' => [
		'missing/plugin.php' => 'enable',
	],
	'dev_cfg_action' => [
		'noindex' => '1',
	],
	'mcp_creds' => [
		'production' => [
			'consumer_key' => ' production-placeholder-key ',
			'consumer_secret' => '',
			'user_id' => '3',
		],
	],
]);
$settings = $GLOBALS['test_options'][DevCfgPlugin::OPTION_KEY];
assert_true($settings['saved_configs']['Default']['plugin_policies']['missing/plugin.php'] === 'enable', 'Apply branch saves posted plugin policies');
assert_true($GLOBALS['test_options']['blog_public'] === '0', 'Apply branch runs selected action');
$creds = $GLOBALS['test_options']['hp_dev_config_mcp_credentials'];
assert_true($creds['production']['consumer_key'] === 'production-placeholder-key', 'Apply branch stores sanitized production MCP key placeholder');
assert_true($creds['production']['user_id'] === 3, 'Apply branch stores sanitized production MCP user ID');
assert_true(isset($GLOBALS['test_transients']['dev_cfg_apply_summary_popup']), 'Apply branch writes summary transient');
