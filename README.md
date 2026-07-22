# HP Dev Configuration (WordPress)

A simple admin tool under Tools > Dev Configuration to:

- List installed plugins with current status
- Choose a per-plugin policy: Enable / Disable / Ignore
- Toggle predefined "Other actions" (extensible via registry)
- **Save and load multiple named configurations**
- Refresh list (preserves selections) and Apply configuration

This plugin does not auto-enforce; it only applies changes when you click Apply.

## Location
- WordPress admin > Tools > Dev Configuration

## Features (v1.1.0)
- **Multiple Configurations**: Save different plugin setups with custom names (e.g., "Staging", "Minimal", "Debug")
- **Quick Switch**: Load any saved configuration with one click
- **Configuration Management**: Create, load, and delete configurations easily

## Storage
- Settings are saved in the `dev_config_plugin_settings` option.

## Other actions (initial stubs)
- `noindex`: sets `blog_public = 0`
- `delete_debug_logs`: removes debug.log files from wp-content
- `fluent_smtp_simulation`: controls FluentSMTP's email simulation mode
- `setup_mcp`: restores WooCommerce MCP credentials and the MCP MU plugin after a production to staging push
- `recover_codex_runner`: repairs the staging Codex runner after a production to staging push by enabling HP Core runner settings, rewriting `runner.env`, restoring runner cron entries, ensuring the daemon, and running one worker pass
- `recover_inspector_worker`: restores the dedicated `wp hp-inspector process --all --quiet` cron after a production to staging push and runs one Inspector worker pass
- `crypto_snapshot`: before a production→staging push, saves approved non-secret staging crypto configuration to protected storage outside the public web root
- `crypto_restore`: after the push, restores the staging Governance bucket, forces wallet login/payment/switches off, and verifies Base Sepolia isolation
- `crypto_verify`: reruns the fail-closed environment/chain/customer-surface gate without changing configuration

Crypto RPC URLs and alert secrets are intentionally excluded. They must remain
in staging server constants or process environment. The default protected state
directory is `dirname(ABSPATH)/private/hp-dev-config`; define
`HP_DEV_CONFIG_STATE_DIR` in staging `wp-config.php` to use a different
environment-local directory.

Run the crypto actions in this order: snapshot on staging, perform the Kinsta
push, then restore and verify. Restoration always leaves the gateway, wallet
login, and Governance capability switches off. Re-enable staging QA surfaces
only after the verification result passes.

Extend by editing `class-actions.php` and adding more entries to the registry.

## Development
- Main file: `hp-dev-config.php`
- View: `admin-page.php`
- Actions registry: `class-actions.php`

## Deploy to Staging (GitHub Actions)
A workflow is included at `.github/workflows/deploy-staging.yml` that deploys this plugin directory to your staging server over SSH on push to `dev`.

Configure these GitHub repo secrets:
- `SSH_HOST`: your staging host (e.g. `staging-xxxx.kinsta.cloud`)
- `SSH_USER`: SSH username
- `SSH_PORT`: SSH port (optional, default `22`)
- `SSH_PRIVATE_KEY`: private key (contents) with access to the staging server
- `REMOTE_PATH`: absolute path to `wp-content/plugins/HP-Dev-Config` on the server

The workflow uses rsync over SSH to sync `HP-Dev-Config/` to your server, excluding `.git` and `.github`.

## Runtime Requirements

- PHP 8.5+

## Changelog

### 3.1.1

- Kept the Tools page version label in sync with the plugin header metadata.
- Added a contract check for plugin header, version constant, and admin heading
  drift.

### 3.1.0

- Added guarded staging crypto configuration snapshot, restore, and invariant
  verification for Kinsta production→staging pushes.
- Crypto restoration preserves only the staging Governance bucket, excludes
  secrets and operational history, and leaves all customer crypto features
  disabled until explicitly re-enabled after QA.
