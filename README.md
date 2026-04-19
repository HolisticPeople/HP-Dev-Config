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
