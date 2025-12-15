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
	 * Setup WooCommerce MCP access for AI tools.
	 * Creates the MU plugin that enables MCP session handling.
	 * Works on both staging and production environments.
	 */
	public static function run_setup_mcp() {
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
			return ['ok' => false, 'message' => 'Failed to write MU plugin file', 'changed' => false];
		}

		// Detect environment for informational message
		$site_url = get_site_url();
		$is_staging = (strpos($site_url, 'hpdevplus') !== false || strpos($site_url, 'staging') !== false);
		$env_name = $is_staging ? 'staging' : 'production';

		$message = $existed 
			? "MCP MU plugin updated for {$env_name}"
			: "MCP MU plugin created for {$env_name}";

		return ['ok' => true, 'message' => $message, 'changed' => true];
	}
}


