<?php
namespace DevCfg;

if (!defined('ABSPATH')) { exit; }

/**
 * Preserve staging-owned crypto configuration across a Kinsta production to
 * staging push. Secrets remain server-side and are never included here.
 */
final class Crypto_Environment_State {
	private const SCHEMA = 1;
	private const FILE_NAME = 'hp-crypto-staging-state.json';

	public static function snapshot(): array {
		if (!self::is_staging()) {
			return self::failure('Refused: crypto snapshot is staging-only');
		}

		$governance = get_option('hp_governance_settings', []);
		$gateway = get_option('woocommerce_hp_crypto_usdc_settings', []);
		$login_flags = get_option('hp_login_provider_flags', []);
		$payload = [
			'schema' => self::SCHEMA,
			'created_at' => gmdate('c'),
			'source_host' => self::host(),
			'governance_staging' => is_array($governance) && is_array($governance['staging'] ?? null) ? $governance['staging'] : [],
			'gateway' => is_array($gateway) ? $gateway : [],
			'wallet_login_enabled' => is_array($login_flags) && !empty($login_flags['ethereum_wallet']),
		];

		$dir = self::state_dir();
		if (!is_dir($dir) && !wp_mkdir_p($dir)) {
			return self::failure('Cannot create protected crypto state directory');
		}
		$encoded = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if (!is_string($encoded) || file_put_contents(self::state_file(), $encoded . "\n", LOCK_EX) === false) {
			return self::failure('Cannot write crypto staging snapshot');
		}
		@chmod(self::state_file(), 0600);

		return ['ok' => true, 'changed' => true, 'message' => 'Staging crypto configuration snapshot saved outside the public web root'];
	}

	public static function restore_and_verify(): array {
		if (!self::is_staging()) {
			return self::failure('Refused: crypto restore is staging-only');
		}
		if (!is_readable(self::state_file())) {
			return self::failure('Crypto staging snapshot is missing; all crypto features remain disabled');
		}
		$payload = json_decode((string) file_get_contents(self::state_file()), true);
		if (!self::valid_payload($payload)) {
			return self::failure('Crypto staging snapshot is invalid; all crypto features remain disabled');
		}

		// Restore only staging-owned configuration. Production buckets and
		// imported operational history are deliberately not copied back.
		$governance = get_option('hp_governance_settings', []);
		if (!is_array($governance)) { $governance = []; }
		$governance['staging'] = $payload['governance_staging'];
		update_option('hp_governance_settings', $governance, false);

		$gateway = $payload['gateway'];
		$gateway['enabled'] = 'no';
		update_option('woocommerce_hp_crypto_usdc_settings', $gateway, false);
		$flags = get_option('hp_login_provider_flags', []);
		if (!is_array($flags)) { $flags = []; }
		$flags['ethereum_wallet'] = false;
		update_option('hp_login_provider_flags', $flags, false);

		// A DB environment override is not push-proof. Remove it and rely on the
		// server constant/host resolver before checking any chain invariant.
		delete_option('hp_governance_environment');
		self::engage_governance_switches();

		$checks = self::invariants();
		$failed = array_keys(array_filter($checks, static fn($ok): bool => !$ok));
		if ($failed) {
			return self::failure('Crypto restored but remains disabled; failed checks: ' . implode(', ', $failed));
		}

		return ['ok' => true, 'changed' => true, 'message' => 'Staging crypto configuration restored; Base Sepolia verified; gateway, wallet login, and governance switches remain off'];
	}

	public static function verify(): array {
		if (!self::is_staging()) {
			return self::failure('Refused: crypto push verification is staging-only');
		}
		$checks = self::invariants();
		$failed = array_keys(array_filter($checks, static fn($ok): bool => !$ok));
		return $failed
			? self::failure('Crypto push gate failed: ' . implode(', ', $failed))
			: ['ok' => true, 'changed' => false, 'message' => 'Crypto push gate passed: staging, Base Sepolia only, customer features off'];
	}

	private static function invariants(): array {
		$environment = apply_filters('hp_governance_environment', null);
		$allowed = apply_filters('hp_governance_allowed_chains', null);
		$gateway = get_option('woocommerce_hp_crypto_usdc_settings', []);
		$flags = get_option('hp_login_provider_flags', []);
		return [
			'environment_staging' => $environment === 'staging',
			'base_sepolia_allowed' => is_array($allowed) && array_key_exists(84532, $allowed),
			'base_mainnet_rejected' => apply_filters('hp_governance_chain_allowed', null, 8453) === false,
			'gateway_off' => !is_array($gateway) || ($gateway['enabled'] ?? 'no') !== 'yes',
			'wallet_login_off' => !is_array($flags) || empty($flags['ethereum_wallet']),
		];
	}

	private static function engage_governance_switches(): void {
		if (!class_exists('HP_Governance\\Services\\KillSwitchService')) {
			$plugin = WP_PLUGIN_DIR . '/hp-governance/hp-governance.php';
			if (is_readable($plugin)) { require_once $plugin; }
		}
		if (class_exists('HP_Governance\\Services\\KillSwitchService')) {
			\HP_Governance\Services\KillSwitchService::engage_all(get_current_user_id(), 'production to staging push recovery');
		}
	}

	private static function valid_payload($payload): bool {
		return is_array($payload)
			&& ($payload['schema'] ?? null) === self::SCHEMA
			&& is_string($payload['source_host'] ?? null)
			&& self::staging_host((string) $payload['source_host'])
			&& is_array($payload['governance_staging'] ?? null)
			&& is_array($payload['gateway'] ?? null);
	}

	private static function state_dir(): string {
		if (defined('HP_DEV_CONFIG_STATE_DIR') && is_string(HP_DEV_CONFIG_STATE_DIR) && HP_DEV_CONFIG_STATE_DIR !== '') {
			return rtrim(HP_DEV_CONFIG_STATE_DIR, '/\\');
		}
		return dirname(ABSPATH) . '/private/hp-dev-config';
	}

	private static function state_file(): string { return self::state_dir() . '/' . self::FILE_NAME; }
	private static function host(): string { return strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)); }
	private static function is_staging(): bool { return self::staging_host(self::host()); }
	private static function staging_host(string $host): bool {
		return str_contains($host, 'hpdevplus') || str_contains($host, 'staging') || str_ends_with($host, '.kinsta.cloud') || str_ends_with($host, '.local') || str_contains($host, 'localhost');
	}
	private static function failure(string $message): array { return ['ok' => false, 'changed' => false, 'message' => $message]; }
}
