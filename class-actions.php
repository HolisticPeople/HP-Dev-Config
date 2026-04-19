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
			'recover_codex_runner' => [
				'label' => 'Recover Codex runner after prod→staging push',
				'description' => 'Repairs staging Codex runner settings, runner.env, cron entries, daemon, and runs one worker pass.',
				'runner' => [__CLASS__, 'run_recover_codex_runner'],
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

	private static function is_safe_dev_environment() {
		$site_url = (string) get_site_url();
		$host = (string) wp_parse_url($site_url, PHP_URL_HOST);
		$wp_env = function_exists('wp_get_environment_type') ? (string) wp_get_environment_type() : '';

		if ($wp_env && $wp_env !== 'production') {
			return true;
		}

		return (
			strpos($host, 'hpdevplus') !== false
			|| strpos($host, 'staging') !== false
			|| strpos($host, 'kinsta.cloud') !== false
		);
	}

	private static function read_runner_env($env_file) {
		$values = [];
		if (!is_readable($env_file)) {
			return $values;
		}

		$lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) {
			return $values;
		}

		foreach ($lines as $line) {
			if (!preg_match('/^export\s+([A-Z0-9_]+)=(.*)$/', trim($line), $matches)) {
				continue;
			}
			$value = trim($matches[2]);
			$value = trim($value, "\"'");
			$values[$matches[1]] = $value;
		}

		return $values;
	}

	private static function write_runner_env($env_file, array $values) {
		$dir = dirname($env_file);
		if (!is_dir($dir) && !wp_mkdir_p($dir)) {
			return ['ok' => false, 'message' => 'Cannot create runner state directory'];
		}

		$lines = [];
		foreach ($values as $key => $value) {
			$key = preg_replace('/[^A-Z0-9_]/', '', (string) $key);
			if ($key === '') {
				continue;
			}
			$escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
			$lines[] = 'export ' . $key . '="' . $escaped . '"';
		}

		$written = file_put_contents($env_file, implode("\n", $lines) . "\n");
		if ($written === false) {
			return ['ok' => false, 'message' => 'Cannot write runner.env'];
		}

		@chmod($env_file, 0600);
		return ['ok' => true, 'message' => 'runner.env updated'];
	}

	private static function shell_command($command, $input = '') {
		if (!function_exists('proc_open')) {
			return [
				'ok' => false,
				'exit_code' => 127,
				'stdout' => '',
				'stderr' => 'proc_open is disabled',
			];
		}

		$descriptor_spec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$process = proc_open($command, $descriptor_spec, $pipes);
		if (!is_resource($process)) {
			return [
				'ok' => false,
				'exit_code' => 127,
				'stdout' => '',
				'stderr' => 'Failed to start shell command',
			];
		}

		fwrite($pipes[0], (string) $input);
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit_code = proc_close($process);

		return [
			'ok' => $exit_code === 0,
			'exit_code' => $exit_code,
			'stdout' => is_string($stdout) ? trim($stdout) : '',
			'stderr' => is_string($stderr) ? trim($stderr) : '',
		];
	}

	private static function resolve_hp_core_plugin_root() {
		$candidates = [
			WP_PLUGIN_DIR . '/hp-core-infrastructure',
			WP_PLUGIN_DIR . '/HP-Core-Infrastructure',
		];

		foreach ($candidates as $candidate) {
			if (is_dir($candidate) && file_exists($candidate . '/hp-core-infrastructure.php')) {
				return $candidate;
			}
		}

		$matches = glob(WP_PLUGIN_DIR . '/*/hp-core-infrastructure.php');
		if (is_array($matches) && !empty($matches[0])) {
			return dirname($matches[0]);
		}

		return '';
	}

	private static function derive_runner_id() {
		$site_url = (string) get_site_url();
		$host = (string) wp_parse_url($site_url, PHP_URL_HOST);
		$host = strtolower($host ?: 'staging');
		$host = preg_replace('/[^a-z0-9]+/', '-', $host);
		$host = trim((string) $host, '-');

		if (strpos($host, 'hpdevplus') !== false) {
			return 'hp-dev-plus-staging';
		}

		return ($host ?: 'hp-dev') . '-codex-runner';
	}

	private static function recover_runner_settings($runner_id) {
		$option = get_option('hp_core_ai_settings', []);
		if (!is_array($option)) {
			$option = [];
		}
		if (!isset($option['runner']) || !is_array($option['runner'])) {
			$option['runner'] = [];
		}

		$before = $option['runner'];
		$option['runner']['enabled'] = true;
		$option['runner']['mode'] = 'kinsta_local_cron';
		$option['runner']['label'] = $option['runner']['label'] ?? 'Primary Codex Runner';
		$option['runner']['expected_interval_minutes'] = absint($option['runner']['expected_interval_minutes'] ?? 5) ?: 5;
		$option['runner']['max_jobs_per_run'] = absint($option['runner']['max_jobs_per_run'] ?? 3) ?: 3;
		$option['runner']['job_timeout_seconds'] = absint($option['runner']['job_timeout_seconds'] ?? 120) ?: 120;
		update_option('hp_core_ai_settings', $option, false);

		$status = get_option('hp_core_ai_runner_status', []);
		if (is_array($status)) {
			$status['runner_id'] = $runner_id;
			update_option('hp_core_ai_runner_status', $status, false);
		}

		return $before !== $option['runner'];
	}

	public static function run_recover_codex_runner($mode = 'enable') {
		if ($mode === 'ignore') {
			return ['ok' => true, 'message' => 'Codex runner recovery ignored', 'changed' => false];
		}

		if (!self::is_safe_dev_environment()) {
			return [
				'ok' => false,
				'message' => 'Refused to recover Codex runner on a production-looking environment.',
				'changed' => false,
			];
		}

		$core_root = self::resolve_hp_core_plugin_root();
		if ($core_root === '') {
			return [
				'ok' => false,
				'message' => 'HP Core plugin root not found.',
				'changed' => false,
			];
		}

		$wp_root = rtrim(ABSPATH, '/\\');
		$site_root = dirname($wp_root);
		$runner_home = $site_root . '/.hp-codex-runner';
		$state_dir = $runner_home . '/state';
		$env_file = $state_dir . '/runner.env';
		$existing_env = self::read_runner_env($env_file);
		$runner_id = self::derive_runner_id();
		$codex_bin = $existing_env['HP_CODEX_BIN'] ?? ($runner_home . '/app/node_modules/@openai/codex-linux-x64/vendor/x86_64-unknown-linux-musl/codex/codex');
		$php_bin = $existing_env['HP_PHP_BIN'] ?? '/usr/bin/php';

		$messages = [];
		$changed = false;

		$settings_changed = self::recover_runner_settings($runner_id);
		$changed = $changed || $settings_changed;
		$messages[] = $settings_changed ? 'HP Core runner settings repaired' : 'HP Core runner settings already correct';

		$env_values = array_merge($existing_env, [
			'HP_WP_ROOT' => $wp_root,
			'HP_CODEX_RUNNER_HOME' => $runner_home,
			'HP_HP_CORE_PLUGIN_ROOT' => $core_root,
			'HP_PHP_BIN' => $php_bin,
			'HP_CODEX_BIN' => $codex_bin,
			'HP_CODEX_RUNNER_ID' => $runner_id,
			'HP_CODEX_DAEMON_HOST' => '127.0.0.1',
			'HP_CODEX_DAEMON_PORT' => '17777',
			'CODEX_HOME' => $runner_home . '/auth',
		]);
		$env_result = self::write_runner_env($env_file, $env_values);
		$messages[] = $env_result['message'];
		if (!$env_result['ok']) {
			return ['ok' => false, 'message' => implode('; ', $messages), 'changed' => $changed];
		}
		$changed = true;

		$cron_lines = [
			'*/5 * * * * ' . $core_root . '/bin/kinsta-codex-runner-daemon.sh ensure',
			'*/5 * * * * ' . $core_root . '/bin/kinsta-codex-runner-cron.sh',
		];
		$current_cron = self::shell_command('crontab -l 2>/dev/null || true');
		$cron_body = $current_cron['stdout'];
		$kept_lines = [];
		foreach (preg_split('/\r\n|\r|\n/', $cron_body) as $line) {
			if (trim($line) === '') {
				continue;
			}
			if (strpos($line, 'kinsta-codex-runner-daemon.sh') !== false || strpos($line, 'kinsta-codex-runner-cron.sh') !== false) {
				continue;
			}
			$kept_lines[] = $line;
		}
		$new_cron = implode("\n", array_merge($kept_lines, $cron_lines)) . "\n";
		$cron_result = self::shell_command('crontab -', $new_cron);
		if ($cron_result['ok']) {
			$messages[] = 'Runner cron entries restored';
			$changed = true;
		} else {
			$messages[] = 'Cron restore failed: ' . ($cron_result['stderr'] ?: 'unknown error');
		}

		$daemon_cmd = 'cd ' . escapeshellarg($core_root) . ' && ./bin/kinsta-codex-runner-daemon.sh ensure';
		$daemon_result = self::shell_command($daemon_cmd);
		$messages[] = $daemon_result['ok']
			? 'Runner daemon ensured'
			: 'Daemon ensure failed: ' . ($daemon_result['stderr'] ?: $daemon_result['stdout'] ?: 'unknown error');

		$worker_cmd = 'cd ' . escapeshellarg($core_root) . ' && ./bin/kinsta-codex-runner-cron.sh';
		$worker_result = self::shell_command($worker_cmd);
		$messages[] = $worker_result['ok']
			? 'Runner worker pass completed'
			: 'Worker pass failed: ' . ($worker_result['stderr'] ?: $worker_result['stdout'] ?: 'unknown error');

		$ok = $cron_result['ok'] && $daemon_result['ok'] && $worker_result['ok'];
		return [
			'ok' => $ok,
			'message' => implode('; ', $messages),
			'changed' => $changed || $ok,
		];
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
	 * 
	 * WooCommerce stores consumer_key as hash, but consumer_secret as PLAINTEXT.
	 * The mcp-session-relax MU plugin authenticates using the hashed key lookup.
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
			return ['ok' => true, 'message' => 'API key already exists (key_id: ' . $existing . ')', 'changed' => false];
		}

		// Delete any old MCP keys with same description for this user (cleanup)
		$wpdb->delete($table, [
			'user_id' => $user_id,
			'description' => $description,
		]);

		// Insert the new key
		// Note: WooCommerce stores consumer_secret as plaintext (not hashed)
		// The consumer_key is hashed, truncated_key is last 7 chars of plaintext key
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

		$new_key_id = $wpdb->insert_id;
		return ['ok' => true, 'message' => "API key restored (key_id: {$new_key_id})", 'changed' => true];
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


