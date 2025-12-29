<?php
/**
 * Plugin Name: HP Dev Configuration
 * Plugin URI: https://github.com/HolisticPeople/HP-Dev-Config
 * Description: One-click dev/staging setup under Tools → Dev Configuration. Choose plugins to force enable/disable and run predefined actions (e.g., noindex). Changes apply only when you click Apply; no auto-enforcement.
 * Version: 2.3.0
 * Author: HolisticPeople
 * Author URI: https://holisticpeople.com
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!function_exists('dev_cfg_array_get')) {
	function dev_cfg_array_get($array, $key, $default = null) {
		return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
	}
}

if (!defined('DEV_CFG_PLUGIN_VERSION')) {
    define('DEV_CFG_PLUGIN_VERSION', '2.3.0');
}

class DevCfgPlugin {
	const OPTION_KEY = 'dev_config_plugin_settings';
	const MENU_SLUG = 'dev-config-tools';
	const DEFAULT_CONFIG_NAME = 'Default';

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'register_tools_page']);
		add_action('admin_init', [__CLASS__, 'handle_post_actions']);
		// Add Settings link in Plugins list
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [__CLASS__, 'plugin_action_links']);
	}

	public static function register_tools_page() {
		add_management_page(
			'Dev Configuration',
			'Dev Configuration',
			'manage_options',
			self::MENU_SLUG,
			[__CLASS__, 'render_page']
		);
	}

	/**
	 * Get all settings including saved configurations
	 */
	public static function get_settings() {
		$defaults = [
			'saved_configs' => [],
			'active_config' => self::DEFAULT_CONFIG_NAME,
			'ui_prefs' => [
				'preserve_refresh' => true,
			],
		];
		$settings = get_option(self::OPTION_KEY, []);
		if (!is_array($settings)) {
			$settings = [];
		}
		
		// Migration: convert old format to new format
		if (isset($settings['plugin_policies']) && !isset($settings['saved_configs'])) {
			$migrated = [
				'saved_configs' => [
					self::DEFAULT_CONFIG_NAME => [
						'plugin_policies' => dev_cfg_array_get($settings, 'plugin_policies', []),
						'other_actions' => dev_cfg_array_get($settings, 'other_actions', []),
					]
				],
				'active_config' => self::DEFAULT_CONFIG_NAME,
				'ui_prefs' => dev_cfg_array_get($settings, 'ui_prefs', $defaults['ui_prefs']),
			];
			// Save migrated settings
			update_option(self::OPTION_KEY, $migrated);
			return array_replace_recursive($defaults, $migrated);
		}
		
		return array_replace_recursive($defaults, $settings);
	}

	/**
	 * Get the active configuration data
	 */
	public static function get_active_config() {
		$settings = self::get_settings();
		$activeName = dev_cfg_array_get($settings, 'active_config', self::DEFAULT_CONFIG_NAME);
		$configs = dev_cfg_array_get($settings, 'saved_configs', []);
		
		if (isset($configs[$activeName])) {
			return $configs[$activeName];
		}
		
		// Return defaults if no config exists
		return [
			'plugin_policies' => [],
			'other_actions' => [
				'noindex' => true,
				'fluent_smtp_simulation' => 'enable',
			],
		];
	}

	/**
	 * Get list of all saved configuration names
	 */
	public static function get_config_names() {
		$settings = self::get_settings();
		$configs = dev_cfg_array_get($settings, 'saved_configs', []);
		return array_keys($configs);
	}

	public static function update_settings($settings) {
		update_option(self::OPTION_KEY, $settings);
	}

	/**
	 * Save a configuration with a given name
	 */
	public static function save_config($name, $plugin_policies, $other_actions) {
		$settings = self::get_settings();
		if (!isset($settings['saved_configs'])) {
			$settings['saved_configs'] = [];
		}
		$settings['saved_configs'][$name] = [
			'plugin_policies' => $plugin_policies,
			'other_actions' => $other_actions,
		];
		$settings['active_config'] = $name;
		self::update_settings($settings);
	}

	/**
	 * Delete a configuration by name
	 */
	public static function delete_config($name) {
		$settings = self::get_settings();
		if (isset($settings['saved_configs'][$name])) {
			unset($settings['saved_configs'][$name]);
			// If we deleted the active config, switch to first available or create default
			if ($settings['active_config'] === $name) {
				$remaining = array_keys($settings['saved_configs']);
				$settings['active_config'] = !empty($remaining) ? $remaining[0] : self::DEFAULT_CONFIG_NAME;
			}
			self::update_settings($settings);
			return true;
		}
		return false;
	}

	/**
	 * Set active configuration
	 */
	public static function set_active_config($name) {
		$settings = self::get_settings();
		if (isset($settings['saved_configs'][$name])) {
			$settings['active_config'] = $name;
			self::update_settings($settings);
			return true;
		}
		return false;
	}

	private static function sanitize_policies($rawPolicies) {
		$policies = [];
		if (!is_array($rawPolicies)) {
			return $policies;
		}
		foreach ($rawPolicies as $pluginFile => $policy) {
			$pluginFile = sanitize_text_field($pluginFile);
			$policy = sanitize_text_field($policy);
			if (!in_array($policy, ['enable', 'disable', 'ignore'], true)) {
				$policy = 'ignore';
			}
			$policies[$pluginFile] = $policy;
		}
		return $policies;
	}

    private static function sanitize_other_actions($rawActions) {
        $actions = [];
        if (!is_array($rawActions)) {
            return $actions;
        }
        // Special handling: FluentSMTP simulation radio (ignore/enable/disable)
        if (isset($rawActions['fluent_smtp_simulation'])) {
            $mode = sanitize_text_field($rawActions['fluent_smtp_simulation']);
            if ($mode === 'enable' || $mode === 'disable') {
                $actions['fluent_smtp_simulation'] = $mode; // pass mode to runner
            }
            unset($rawActions['fluent_smtp_simulation']);
        }
        foreach ($rawActions as $key => $val) {
            $key = sanitize_key($key);
            $actions[$key] = (bool)$val;
        }
        return $actions;
    }

	public static function handle_post_actions() {
		if (!is_admin() || !current_user_can('manage_options')) {
			return;
		}
		if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
			return;
		}

		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$settings = self::get_settings();
		$activeConfig = self::get_active_config();
		
		$postedPolicies = isset($_POST['dev_cfg_policy']) ? self::sanitize_policies($_POST['dev_cfg_policy']) : null;
		$postedActions = isset($_POST['dev_cfg_action']) ? self::sanitize_other_actions($_POST['dev_cfg_action']) : null;
		$uiSelections = [
			'plugin_policies' => is_array($postedPolicies) ? $postedPolicies : dev_cfg_array_get($activeConfig, 'plugin_policies', []),
			'other_actions' => is_array($postedActions) ? $postedActions : dev_cfg_array_get($activeConfig, 'other_actions', []),
		];
		$GLOBALS['dev_cfg_ui_selections'] = $uiSelections;

		// Handle refresh
		if (isset($_POST['dev_cfg_refresh'])) {
			check_admin_referer('dev_cfg_refresh');
			add_settings_error('dev_cfg', 'refreshed', 'Plugin list refreshed. Selections preserved.', 'updated');
			return;
		}

		// Handle load configuration
		if (isset($_POST['dev_cfg_load_config'])) {
			if (!isset($_POST['dev_cfg_nonce_config']) || !wp_verify_nonce($_POST['dev_cfg_nonce_config'], 'dev_cfg_config_action')) {
				wp_die('Security check failed. Please try again.');
			}
			$configName = isset($_POST['dev_cfg_select_config']) ? sanitize_text_field($_POST['dev_cfg_select_config']) : '';
			if ($configName && self::set_active_config($configName)) {
				$loadedConfig = self::get_active_config();
				$GLOBALS['dev_cfg_ui_selections'] = [
					'plugin_policies' => dev_cfg_array_get($loadedConfig, 'plugin_policies', []),
					'other_actions' => dev_cfg_array_get($loadedConfig, 'other_actions', []),
				];
				add_settings_error('dev_cfg', 'loaded', 'Configuration "' . esc_html($configName) . '" loaded.', 'updated');
			} else {
				add_settings_error('dev_cfg', 'load_error', 'Failed to load configuration.', 'error');
			}
			return;
		}

		// Handle save as new configuration
		if (isset($_POST['dev_cfg_save_as_new'])) {
			if (!isset($_POST['dev_cfg_nonce_config']) || !wp_verify_nonce($_POST['dev_cfg_nonce_config'], 'dev_cfg_config_action')) {
				wp_die('Security check failed. Please try again.');
			}
			$newName = isset($_POST['dev_cfg_new_config_name']) ? sanitize_text_field(trim($_POST['dev_cfg_new_config_name'])) : '';
			if (empty($newName)) {
				add_settings_error('dev_cfg', 'name_error', 'Please provide a name for the new configuration.', 'error');
				return;
			}
			$policies = is_array($postedPolicies) ? $postedPolicies : dev_cfg_array_get($activeConfig, 'plugin_policies', []);
			$actions = is_array($postedActions) ? $postedActions : dev_cfg_array_get($activeConfig, 'other_actions', []);
			self::save_config($newName, $policies, $actions);
			add_settings_error('dev_cfg', 'saved_new', 'Configuration "' . esc_html($newName) . '" saved.', 'updated');
			return;
		}

		// Handle delete configuration
		if (isset($_POST['dev_cfg_delete_config'])) {
			if (!isset($_POST['dev_cfg_nonce_config']) || !wp_verify_nonce($_POST['dev_cfg_nonce_config'], 'dev_cfg_config_action')) {
				wp_die('Security check failed. Please try again.');
			}
			$configToDelete = isset($_POST['dev_cfg_select_config']) ? sanitize_text_field($_POST['dev_cfg_select_config']) : '';
			if ($configToDelete && self::delete_config($configToDelete)) {
				$loadedConfig = self::get_active_config();
				$GLOBALS['dev_cfg_ui_selections'] = [
					'plugin_policies' => dev_cfg_array_get($loadedConfig, 'plugin_policies', []),
					'other_actions' => dev_cfg_array_get($loadedConfig, 'other_actions', []),
				];
				add_settings_error('dev_cfg', 'deleted', 'Configuration "' . esc_html($configToDelete) . '" deleted.', 'updated');
			} else {
				add_settings_error('dev_cfg', 'delete_error', 'Failed to delete configuration.', 'error');
			}
			return;
		}

		// Save configuration (update current)
		if (isset($_POST['dev_cfg_save'])) {
			if (!isset($_POST['dev_cfg_nonce_save']) || !wp_verify_nonce($_POST['dev_cfg_nonce_save'], 'dev_cfg_save')) {
				wp_die('Security check failed. Please try again.');
			}
			
			// Save MCP credentials if provided
			if (isset($_POST['mcp_creds']) && is_array($_POST['mcp_creds'])) {
				require_once __DIR__ . '/class-actions.php';
				foreach (['staging', 'production'] as $env) {
					if (isset($_POST['mcp_creds'][$env])) {
						$ck = isset($_POST['mcp_creds'][$env]['consumer_key']) ? sanitize_text_field($_POST['mcp_creds'][$env]['consumer_key']) : '';
						$cs = isset($_POST['mcp_creds'][$env]['consumer_secret']) ? sanitize_text_field($_POST['mcp_creds'][$env]['consumer_secret']) : '';
						$uid = isset($_POST['mcp_creds'][$env]['user_id']) ? absint($_POST['mcp_creds'][$env]['user_id']) : 1;
						if ($ck || $cs) {
							DevCfg\Actions::save_mcp_credentials($env, $ck, $cs, $uid);
						}
					}
				}
			}
			
			$policies = is_array($postedPolicies) ? $postedPolicies : dev_cfg_array_get($activeConfig, 'plugin_policies', []);
			$actions = is_array($postedActions) ? $postedActions : dev_cfg_array_get($activeConfig, 'other_actions', []);
			
			$currentName = dev_cfg_array_get($settings, 'active_config', self::DEFAULT_CONFIG_NAME);
			self::save_config($currentName, $policies, $actions);

			add_settings_error('dev_cfg', 'saved', 'Configuration "' . esc_html($currentName) . '" saved (MCP credentials updated).', 'updated');
			return;
		}

		if (isset($_POST['dev_cfg_apply'])) {
			if (!isset($_POST['dev_cfg_nonce_apply']) || !wp_verify_nonce($_POST['dev_cfg_nonce_apply'], 'dev_cfg_apply')) {
				wp_die('Security check failed. Please try again.');
			}
			
			// Save MCP credentials if provided
			if (isset($_POST['mcp_creds']) && is_array($_POST['mcp_creds'])) {
				require_once __DIR__ . '/class-actions.php';
				foreach (['staging', 'production'] as $env) {
					if (isset($_POST['mcp_creds'][$env])) {
						$ck = isset($_POST['mcp_creds'][$env]['consumer_key']) ? sanitize_text_field($_POST['mcp_creds'][$env]['consumer_key']) : '';
						$cs = isset($_POST['mcp_creds'][$env]['consumer_secret']) ? sanitize_text_field($_POST['mcp_creds'][$env]['consumer_secret']) : '';
						$uid = isset($_POST['mcp_creds'][$env]['user_id']) ? absint($_POST['mcp_creds'][$env]['user_id']) : 1;
						if ($ck || $cs) { // Only save if at least one field is provided
							DevCfg\Actions::save_mcp_credentials($env, $ck, $cs, $uid);
						}
					}
				}
			}
			
			$policies = is_array($postedPolicies) ? $postedPolicies : [];
			$actions = is_array($postedActions) ? $postedActions : [];

			$currentName = dev_cfg_array_get($settings, 'active_config', self::DEFAULT_CONFIG_NAME);
			self::save_config($currentName, $policies, $actions);

			$results = self::apply_configuration($policies, $actions);
			$summary = self::format_results_notice($results);
			// Build popup summary counts
            $enabledCount = 0; $disabledCount = 0; $pluginFailed = 0;
            foreach (dev_cfg_array_get($results, 'plugins', []) as $res) {
                if (is_array($res)) {
                    if (!empty($res['changed'])) {
                        if ($res['result'] === 'activated') { $enabledCount++; }
                        if ($res['result'] === 'deactivated') { $disabledCount++; }
                    }
                    $msg = isset($res['result']) ? $res['result'] : '';
                    if (stripos($msg, 'error') !== false || stripos($msg, 'failed') !== false || stripos($msg, 'missing') !== false) { $pluginFailed++; }
                } else {
                    if ($res === 'activated') { $enabledCount++; }
                    elseif ($res === 'deactivated') { $disabledCount++; }
                    elseif (is_string($res) && (stripos($res, 'error') !== false || stripos($res, 'failed') !== false || stripos($res, 'missing') !== false)) { $pluginFailed++; }
                }
            }
			$actionsOk = 0; $actionsFailed = 0; $actionLines = [];
			foreach (dev_cfg_array_get($results, 'actions', []) as $key => $res) {
				$msg = is_array($res) ? ($res['message'] ?? $res['result']) : (string)$res;
				$actionLines[] = $key . ': ' . $msg;
				$isError = stripos($msg, 'error') !== false || stripos($msg, 'failed') !== false;
				if (!empty($res['changed']) && !$isError) { $actionsOk++; }
				if ($isError) { $actionsFailed++; }
			}
			$popup = "Plugins enabled: $enabledCount\nPlugins disabled: $disabledCount\nPlugin failures: $pluginFailed\nActions success: $actionsOk, failed: $actionsFailed";
			if ($actionLines) { $popup .= "\n\nActions detail:\n" . implode("\n", $actionLines); }
			set_transient('dev_cfg_apply_summary_popup', $popup, 60);

			add_settings_error('dev_cfg', 'applied', $summary, 'updated');
		}
	}

private static function format_results_notice($results) {
	$lines = [];
	if (!empty($results['plugins'])) {
		foreach ($results['plugins'] as $file => $res) {
			if (is_array($res)) {
				$text = isset($res['result']) ? $res['result'] : '';
			} else {
				$text = (string)$res;
			}
			$lines[] = sprintf('%s: %s', esc_html($file), esc_html($text));
		}
	}
	if (!empty($results['actions'])) {
		foreach ($results['actions'] as $key => $res) {
			if (is_array($res)) {
				$text = isset($res['message']) ? $res['message'] : (isset($res['result']) ? $res['result'] : '');
			} else {
				$text = (string)$res;
			}
			$lines[] = sprintf('%s: %s', esc_html($key), esc_html($text));
		}
	}
	return implode('<br>', $lines);
}

private static function apply_configuration($policies, $actions) {
	$pluginResults = [];
	$actionResults = [];

	foreach ($policies as $pluginFile => $policy) {
		if ($policy === 'ignore') {
			$pluginResults[$pluginFile] = ['result' => 'ignored', 'changed' => false];
			continue;
		}
		$pluginPath = WP_PLUGIN_DIR . '/' . $pluginFile;
		if (!file_exists($pluginPath)) {
			$pluginResults[$pluginFile] = ['result' => 'file missing', 'changed' => false];
			continue;
		}
		$before = is_plugin_active($pluginFile);
		if ($policy === 'enable') {
			if ($before) {
				$pluginResults[$pluginFile] = ['result' => 'already active', 'changed' => false];
			} else {
				$res = activate_plugin($pluginFile);
				if (is_wp_error($res)) {
					$pluginResults[$pluginFile] = ['result' => 'activate error: ' . $res->get_error_message(), 'changed' => false];
				} else {
					$after = is_plugin_active($pluginFile);
					$pluginResults[$pluginFile] = ['result' => $after ? 'activated' : 'activation uncertain', 'changed' => $after != $before];
				}
			}
		} elseif ($policy === 'disable') {
			if (!$before) {
				$pluginResults[$pluginFile] = ['result' => 'already inactive', 'changed' => false];
			} else {
				deactivate_plugins([$pluginFile], true);
				$after = is_plugin_active($pluginFile);
				$pluginResults[$pluginFile] = ['result' => $after ? 'deactivation failed' : 'deactivated', 'changed' => $after != $before];
			}
		}
	}

	require_once __DIR__ . '/class-actions.php';
	$registry = DevCfg\Actions::registry();
    foreach ($actions as $key => $enabled) {
		if (!$enabled) {
			continue;
		}
		if (!isset($registry[$key]) || !is_callable($registry[$key]['runner'])) {
			$actionResults[$key] = ['result' => 'unknown action', 'changed' => false];
			continue;
		}
		try {
            // If the action value is a string (mode), pass it to the runner
            if (is_string($enabled)) {
                $out = call_user_func($registry[$key]['runner'], $enabled);
            } else {
                $out = call_user_func($registry[$key]['runner']);
            }
			if (is_array($out) && isset($out['ok'])) {
				$actionResults[$key] = [
					'result'  => $out['ok'] ? ($out['message'] ?? 'ok') : ('failed' . (!empty($out['message']) ? ': ' . $out['message'] : '')),
					'changed' => isset($out['changed']) ? (bool)$out['changed'] : true,
					'message' => $out['message'] ?? ''
				];
			} else {
				$actionResults[$key] = ['result' => 'ok', 'changed' => true];
			}
		} catch (Throwable $e) {
			$actionResults[$key] = ['result' => 'error: ' . $e->getMessage(), 'changed' => false];
		}
	}

	return [
		'plugins' => $pluginResults,
		'actions' => $actionResults,
	];
}

	public static function render_page() {
		if (!current_user_can('manage_options')) {
			wp_die('Insufficient permissions');
		}

		$settings = self::get_settings();
		$activeConfig = self::get_active_config();
		$configNames = self::get_config_names();
		$activeConfigName = dev_cfg_array_get($settings, 'active_config', self::DEFAULT_CONFIG_NAME);
		
		$ui = isset($GLOBALS['dev_cfg_ui_selections']) && is_array($GLOBALS['dev_cfg_ui_selections'])
			? $GLOBALS['dev_cfg_ui_selections']
			: [
				'plugin_policies' => dev_cfg_array_get($activeConfig, 'plugin_policies', []),
				'other_actions' => dev_cfg_array_get($activeConfig, 'other_actions', []),
			];

		if (!function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$allPlugins = get_plugins();

		require_once __DIR__ . '/admin-page.php';
	}

	public static function plugin_action_links($links) {
		$url = admin_url('tools.php?page=' . self::MENU_SLUG);
		$links[] = '<a href="' . esc_url($url) . '">Settings</a>';
		return $links;
	}
}

DevCfgPlugin::init();

