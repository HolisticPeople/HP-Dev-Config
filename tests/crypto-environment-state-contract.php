<?php
declare(strict_types=1);

$stateDir = sys_get_temp_dir() . '/hp-dev-config-crypto-test-' . bin2hex(random_bytes(4));
define('ABSPATH', __DIR__ . '/');
define('WP_PLUGIN_DIR', __DIR__ . '/missing-plugins');
define('HP_DEV_CONFIG_STATE_DIR', $stateDir);

$GLOBALS['test_options'] = [
    'hp_governance_settings' => ['production' => ['treasury_address' => 'production'], 'staging' => ['treasury_address' => 'staging']],
    'woocommerce_hp_crypto_usdc_settings' => ['enabled' => 'yes', 'title' => 'Test USDC'],
    'hp_login_provider_flags' => ['ethereum_wallet' => true],
];
$GLOBALS['test_host'] = 'env-holisticpeoplecom-hpdevplus.kinsta.cloud';

function get_option($key, $default = false) { return $GLOBALS['test_options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['test_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['test_options'][$key]); return true; }
function home_url($path = '/') { return 'https://' . $GLOBALS['test_host'] . $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_mkdir_p($dir) { return mkdir($dir, 0700, true); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function get_current_user_id() { return 1; }
function apply_filters($tag, $value, ...$args) {
    if ($tag === 'hp_governance_environment') return 'staging';
    if ($tag === 'hp_governance_allowed_chains') return [84532 => 'Base Sepolia'];
    if ($tag === 'hp_governance_chain_allowed') return ($args[0] ?? 0) === 84532;
    return $value;
}

require dirname(__DIR__) . '/class-crypto-environment-state.php';

function assert_true($condition, $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    echo "PASS: {$message}\n";
}

$snapshot = \DevCfg\Crypto_Environment_State::snapshot();
assert_true(!empty($snapshot['ok']), 'Snapshot succeeds on staging');
assert_true(is_readable($stateDir . '/hp-crypto-staging-state.json'), 'Snapshot is stored in protected state directory');
$raw = (string) file_get_contents($stateDir . '/hp-crypto-staging-state.json');
assert_true(strpos($raw, 'production') === false, 'Snapshot excludes production settings bucket');

$GLOBALS['test_options']['hp_governance_settings'] = ['production' => ['treasury_address' => 'new-production']];
$GLOBALS['test_options']['woocommerce_hp_crypto_usdc_settings'] = ['enabled' => 'yes'];
$GLOBALS['test_options']['hp_login_provider_flags'] = ['ethereum_wallet' => true];
$GLOBALS['test_options']['hp_governance_environment'] = 'production';
$restored = \DevCfg\Crypto_Environment_State::restore_and_verify();
assert_true(!empty($restored['ok']), 'Restore and invariant gate succeeds');
assert_true($GLOBALS['test_options']['hp_governance_settings']['production']['treasury_address'] === 'new-production', 'Restore does not overwrite production bucket');
assert_true($GLOBALS['test_options']['hp_governance_settings']['staging']['treasury_address'] === 'staging', 'Restore recovers staging Governance bucket');
assert_true($GLOBALS['test_options']['woocommerce_hp_crypto_usdc_settings']['enabled'] === 'no', 'Gateway is forced off after restore');
assert_true(empty($GLOBALS['test_options']['hp_login_provider_flags']['ethereum_wallet']), 'Wallet login is forced off after restore');
assert_true(!isset($GLOBALS['test_options']['hp_governance_environment']), 'Copied database environment override is removed');

$GLOBALS['test_host'] = 'holisticpeople.com';
$refused = \DevCfg\Crypto_Environment_State::snapshot();
assert_true(empty($refused['ok']), 'Snapshot refuses to run on production');

@unlink($stateDir . '/hp-crypto-staging-state.json');
@rmdir($stateDir);
