<?php
namespace DevCfg;

if (!defined('ABSPATH')) { exit; }

class Actions {
	public static function registry() {
		return [
			'noindex' => [
				'label' => 'Force noindex (blog_public=0)',
				'description' => 'Discourage search engines on this site by setting blog_public=0.',
				'runner' => [__CLASS__, 'run_noindex'],
			],
			'delete_debug_logs' => [
				'label' => 'Delete debug logs (wp-content/debug*.log)',
				'description' => 'Removes debug.log and rotated variants from wp-content to reduce noise on staging.',
				'runner' => [__CLASS__, 'run_delete_debug_logs'],
			],
			'fluent_smtp_simulation' => [
				'label' => 'FluentSMTP Email Simulation',
				'description' => 'Control FluentSMTP\'s "Disable sending all emails" (misc.simulate_emails).',
				'runner' => [__CLASS__, 'run_fluent_smtp_simulation'],
			],
			'setup_mcp' => [
				'label' => 'Setup WooCommerce MCP',
				'description' => 'Creates/restores MU plugin for AI MCP access. Use after production→staging push.',
				'runner' => [__CLASS__, 'run_setup_mcp'],
			],
		];
	}

	public static function run_noindex() {
		update_option('blog_public', '0');
		return ['ok' => true, 'message' => 'blog_public set to 0'];
	}

	public static function run_delete_debug_logs() {
		$dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : WP_CONTENT_DIR; // WP_CONTENT_DIR is defined by WP
		$patterns = [
			$dir . '/debug.log',
			$dir . '/debug.log.*',
			$dir . '/debug-*.log'
		];
		$deleted = 0; $attempted = 0; $errors = [];
		foreach ($patterns as $pattern) {
			$files = glob($pattern);
			if ($files === false) { continue; }
			foreach ($files as $file) {
				$attempted++;
				if (is_file($file) && is_writable($file)) {
					if (@unlink($file)) {
						$deleted++;
					} else {
						$errors[] = basename($file);
					}
				}
			}
		}
		$changed = $deleted > 0;
		$ok = empty($errors);
		$message = $deleted . ' file(s) deleted';
		if (!$ok && $errors) { $message .= '; failed: ' . implode(', ', $errors); }
		return ['ok' => $ok, 'message' => $message, 'changed' => $changed];
	}

	private static function try_update_nested(array &$arr, array $path, $value) {
		$ref =& $arr;
		foreach ($path as $segment) {
			if (!is_array($ref)) { $ref = []; }
			if (!array_key_exists($segment, $ref)) { $ref[$segment] = []; }
			$ref =& $ref[$segment];
		}
		$ref = $value;
	}


	public static function run_fluent_smtp_simulation_on() {
		$optName = 'fluentmail-settings';
		$settings = get_option($optName, []);
		if (!is_array($settings)) {
			return ['ok' => false, 'message' => 'fluentmail-settings option not found', 'changed' => false];
		}
		if (!isset($settings['misc']) || !is_array($settings['misc'])) {
			$settings['misc'] = [];
		}
		$before = isset($settings['misc']['simulate_emails']) ? $settings['misc']['simulate_emails'] : '';
		$settings['misc']['simulate_emails'] = 'yes';
		update_option($optName, $settings);
		return ['ok' => true, 'message' => 'FluentSMTP simulate_emails set to yes', 'changed' => ($before !== 'yes')];
	}

	public static function run_fluent_smtp_simulation_off() {
		$optName = 'fluentmail-settings';
		$settings = get_option($optName, []);
		if (!is_array($settings)) {
			return ['ok' => false, 'message' => 'fluentmail-settings option not found', 'changed' => false];
		}
		if (!isset($settings['misc']) || !is_array($settings['misc'])) {
			$settings['misc'] = [];
		}
		$before = isset($settings['misc']['simulate_emails']) ? $settings['misc']['simulate_emails'] : '';
		$settings['misc']['simulate_emails'] = 'no';
		update_option($optName, $settings);
		return ['ok' => true, 'message' => 'FluentSMTP simulate_emails set to no', 'changed' => ($before !== 'no')];
	}

	public static function run_fluent_smtp_simulation($mode = 'enable') {
		if ($mode === 'ignore') {
			return ['ok' => true, 'message' => 'FluentSMTP simulation ignored', 'changed' => false];
		}
		return $mode === 'disable' ? self::run_fluent_smtp_simulation_off() : self::run_fluent_smtp_simulation_on();
	}

	/**
	 * Get MCP credentials storage option name
	 */
	private static function get_mcp_option_key() {
		return 'hp_dev_config_mcp_credentials';
	}

	/**
	 * Get stored MCP credentials for both environments
	 */
	public static function get_mcp_credentials() {
		$creds = get_option(self::get_mcp_option_key(), []);
		return wp_parse_args($creds, [
			'staging' => ['consumer_key' => '', 'consumer_secret' => '', 'user_id' => 1, 'description' => 'MCP AI Access (Staging)'],
			'production' => ['consumer_key' => '', 'consumer_secret' => '', 'user_id' => 1, 'description' => 'MCP AI Access (Production)'],
		]);
	}

	/**
	 * Save MCP credentials for an environment
	 */
	public static function save_mcp_credentials($env, $consumer_key, $consumer_secret, $user_id = 1) {
		$creds = self::get_mcp_credentials();
		$creds[$env] = [
			'consumer_key' => sanitize_text_field($consumer_key),
			'consumer_secret' => sanitize_text_field($consumer_secret),
			'user_id' => absint($user_id),
			'description' => "MCP AI Access (" . ucfirst($env) . ")",
		];
		update_option(self::get_mcp_option_key(), $creds);
	}

	/**
	 * Detect current environment
	 */
	public static function detect_environment() {
		$site_url = get_site_url();
		if (strpos($site_url, 'hpdevplus') !== false || strpos($site_url, 'staging') !== false) {
			return 'staging';
		}
		return 'production';
	}

	/**
	 * Setup WooCommerce MCP access for AI tools.
	 * Creates the MU plugin AND restores API key from stored credentials.
	 */
	public static function run_setup_mcp() {
		$env = self::detect_environment();
		$creds = self::get_mcp_credentials();
		$env_creds = $creds[$env];
		
		$messages = [];
		$changed = false;

		// Step 1: Create/restore the API key in database
		if (!empty($env_creds['consumer_key']) && !empty($env_creds['consumer_secret'])) {
			$key_result = self::restore_mcp_api_key($env_creds);
			$messages[] = $key_result['message'];
			$changed = $changed || $key_result['changed'];
		} else {
			$messages[] = "No {$env} credentials stored - configure in MCP Settings";
		}

		// Step 2: Create the MU plugin
		$mu_result = self::create_mcp_mu_plugin();
		$messages[] = $mu_result['message'];
		$changed = $changed || $mu_result['changed'];

		return [
			'ok' => true, 
			'message' => implode('; ', $messages), 
			'changed' => $changed
		];
	}

	/**
	 * Restore/create MCP API key in WooCommerce
	 */
	private static function restore_mcp_api_key($creds) {
		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_api_keys';
		
		$consumer_key = $creds['consumer_key'];
		$consumer_secret = $creds['consumer_secret'];
		$user_id = $creds['user_id'];
		$description = $creds['description'];

		// Hash the consumer key (WooCommerce stores it hashed)
		$hashed_key = function_exists('wc_api_hash') ? wc_api_hash($consumer_key) : hash('sha256', $consumer_key);
		
		// Check if key already exists
		$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT key_id FROM {$table} WHERE consumer_key = %s",
			$hashed_key
		));

		if ($existing) {
			return ['ok' => true, 'message' => 'API key already exists', 'changed' => false];
		}

		// Delete any old MCP keys for this user (cleanup)
		$wpdb->delete($table, [
			'user_id' => $user_id,
			'description' => $description,
		]);

		// Insert the new key
		$truncated_key = substr($consumer_key, -7);
		$result = $wpdb->insert($table, [
			'user_id' => $user_id,
			'description' => $description,
			'permissions' => 'read_write',
			'consumer_key' => $hashed_key,
			'consumer_secret' => $consumer_secret,
			'truncated_key' => $truncated_key,
		]);

		if ($result === false) {
			return ['ok' => false, 'message' => 'Failed to create API key: ' . $wpdb->last_error, 'changed' => false];
		}

		return ['ok' => true, 'message' => 'API key restored', 'changed' => true];
	}

	/**
	 * Create the MCP MU plugin file
	 */
	private static function create_mcp_mu_plugin() {
		$mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		
		// Ensure MU plugins directory exists
		if (!is_dir($mu_dir)) {
			if (!wp_mkdir_p($mu_dir)) {
				return ['ok' => false, 'message' => 'Cannot create mu-plugins directory', 'changed' => false];
			}
		}

		$mu_file = $mu_dir . '/mcp-session-relax.php';
		$existed = file_exists($mu_file);
		
		// The MU plugin code - environment agnostic
		$mu_code = <<<'PHP'
<?php
/**
 * MCP Session Handler for WooCommerce MCP
 * Auto-created by HP-Dev-Config plugin.
 * 
 * This plugin enables AI MCP tools (like Cursor) to communicate with WooCommerce.
 * It authenticates API key requests and auto-creates valid MCP sessions.
 */

if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', function() {
    // Only for MCP endpoint
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/woocommerce/mcp') === false) {
        return;
    }
    
    // Get user from API key if present
    $api_key = $_SERVER['HTTP_X_MCP_API_KEY'] ?? '';
    if ($api_key && strpos($api_key, ':') !== false) {
        list($ck, $cs) = explode(':', $api_key, 2);
        if ($ck && $cs && function_exists('wc_api_hash')) {
            global $wpdb;
            $key = $wpdb->get_row($wpdb->prepare(
                "SELECT key_id, user_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
                wc_api_hash($ck)
            ));
            if ($key && $key->user_id) {
                wp_set_current_user($key->user_id);
            }
        }
    }
    
    // Get session ID from header
    $session_id = $_SERVER['HTTP_MCP_SESSION_ID'] ?? '';
    $user_id = get_current_user_id();
    
    if ($user_id && $session_id) {
        // Ensure session exists in user meta
        $sessions = get_user_meta($user_id, 'mcp_adapter_sessions', true);
        if (!is_array($sessions)) {
            $sessions = [];
        }
        
        if (!isset($sessions[$session_id])) {
            // Create the session entry
            $sessions[$session_id] = [
                'created_at' => time(),
                'last_activity' => time(),
                'client_params' => []
            ];
            update_user_meta($user_id, 'mcp_adapter_sessions', $sessions);
        } else {
            // Update last activity
            $sessions[$session_id]['last_activity'] = time();
            update_user_meta($user_id, 'mcp_adapter_sessions', $sessions);
        }
    }
}, 1);

// Clean REST responses for MCP (only on actual REST requests)
add_action('rest_api_init', function () {
    if (!defined('REST_REQUEST') || !REST_REQUEST) {
        return;
    }
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/woocommerce/mcp') === false) {
        return;
    }
    @ini_set('display_errors', '0');
    @ini_set('html_errors', '0');
    error_reporting(E_ERROR | E_PARSE);
}, 0);
PHP;

		// Write the MU plugin
		$written = file_put_contents($mu_file, $mu_code);
		
		if ($written === false) {
			return ['ok' => false, 'message' => 'Failed to write MU plugin', 'changed' => false];
		}

		$env = self::detect_environment();
		return [
			'ok' => true, 
			'message' => $existed ? "MU plugin updated ({$env})" : "MU plugin created ({$env})", 
			'changed' => !$existed
		];
	}
}


