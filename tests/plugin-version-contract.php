<?php
declare(strict_types=1);

$pluginFile = dirname(__DIR__) . '/hp-dev-config.php';
$adminPageFile = dirname(__DIR__) . '/admin-page.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

$pluginSource = (string) file_get_contents($pluginFile);
$adminPageSource = (string) file_get_contents($adminPageFile);

preg_match('/^ \* Version:\s*([^\s]+)/m', $pluginSource, $headerMatches);
preg_match("/define\\(\\s*['\"]DEV_CFG_PLUGIN_VERSION['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $pluginSource, $constantMatches);

assert_true(isset($headerMatches[1]), 'Plugin header version is present');
assert_true(isset($constantMatches[1]), 'DEV_CFG_PLUGIN_VERSION constant is present');
assert_true($constantMatches[1] === $headerMatches[1], 'Version constant matches plugin header');
assert_true(strpos($pluginSource, 'function dev_cfg_plugin_version(): string') !== false, 'Version helper exists');
assert_true(strpos($pluginSource, 'get_plugin_data(__FILE__, false, false)') !== false, 'Version helper reads WordPress plugin metadata');
assert_true(strpos($adminPageSource, 'dev_cfg_plugin_version()') !== false, 'Admin heading uses version helper');
assert_true(strpos($adminPageSource, "defined('DEV_CFG_PLUGIN_VERSION') ? DEV_CFG_PLUGIN_VERSION") === false, 'Admin page no longer renders directly from the version constant');
