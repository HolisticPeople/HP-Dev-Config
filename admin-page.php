<?php
if (!defined('ABSPATH')) { exit; }

$allPlugins = isset($allPlugins) ? $allPlugins : [];
$ui = isset($ui) ? $ui : ['plugin_policies' => [], 'other_actions' => []];
$configNames = isset($configNames) ? $configNames : [];
$activeConfigName = isset($activeConfigName) ? $activeConfigName : 'Default';

require_once __DIR__ . '/class-actions.php';
$registry = DevCfg\Actions::registry();
$mcp_creds = DevCfg\Actions::get_mcp_credentials();
$current_env = DevCfg\Actions::detect_environment();

settings_errors('dev_cfg');
?>
<div class="wrap">
	<h1>Dev Configuration <span style="font-weight:normal;color:#666;">v<?php echo esc_html(defined('DEV_CFG_PLUGIN_VERSION') ? DEV_CFG_PLUGIN_VERSION : ''); ?></span></h1>

	<!-- Configuration Management Section -->
	<div class="dev-cfg-config-section" style="background:#f9f9f9; border:1px solid #ccd0d4; border-radius:4px; padding:16px; margin-bottom:20px;">
		<h2 style="margin-top:0; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
			<span class="dashicons dashicons-portfolio" style="font-size:20px;"></span>
			Saved Configurations
		</h2>
		
		<form method="post" style="display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end;">
			<?php wp_nonce_field('dev_cfg_config_action', 'dev_cfg_nonce_config'); ?>
			
			<div style="flex:0 0 auto;">
				<label for="dev_cfg_select_config" style="display:block; margin-bottom:4px; font-weight:600;">Select Configuration:</label>
				<select name="dev_cfg_select_config" id="dev_cfg_select_config" style="min-width:200px; height:32px;">
					<?php if (empty($configNames)): ?>
						<option value="">— No configurations saved —</option>
					<?php else: ?>
						<?php foreach ($configNames as $name): ?>
							<option value="<?php echo esc_attr($name); ?>" <?php selected($activeConfigName, $name); ?>>
								<?php echo esc_html($name); ?>
								<?php if ($name === $activeConfigName): ?> (active)<?php endif; ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
			</div>

			<div style="flex:0 0 auto;">
				<button type="submit" name="dev_cfg_load_config" class="button" <?php echo empty($configNames) ? 'disabled' : ''; ?>>
					<span class="dashicons dashicons-download" style="vertical-align:middle; margin-right:4px;"></span>
					Load Selected
				</button>
			</div>

			<div style="flex:0 0 auto;">
				<button type="submit" name="dev_cfg_delete_config" class="button" onclick="return confirm('Are you sure you want to delete this configuration?');" <?php echo empty($configNames) ? 'disabled' : ''; ?>>
					<span class="dashicons dashicons-trash" style="vertical-align:middle; margin-right:4px;"></span>
					Delete Selected
				</button>
			</div>

			<div style="border-left:1px solid #ccd0d4; padding-left:12px; margin-left:4px; flex:0 0 auto;">
				<label for="dev_cfg_new_config_name" style="display:block; margin-bottom:4px; font-weight:600;">New Configuration Name:</label>
				<div style="display:flex; gap:8px;">
					<input type="text" name="dev_cfg_new_config_name" id="dev_cfg_new_config_name" placeholder="e.g., Staging, Minimal, Debug..." style="width:200px; height:32px;">
					<button type="submit" name="dev_cfg_save_as_new" class="button button-primary">
						<span class="dashicons dashicons-plus-alt" style="vertical-align:middle; margin-right:4px;"></span>
						Save Current as New
					</button>
				</div>
			</div>
		</form>
		
		<?php if (!empty($activeConfigName)): ?>
		<p style="margin-top:12px; margin-bottom:0; color:#666;">
			<strong>Currently editing:</strong> 
			<span style="background:#0073aa; color:#fff; padding:2px 8px; border-radius:3px; font-weight:600;">
				<?php echo esc_html($activeConfigName); ?>
			</span>
		</p>
		<?php endif; ?>
	</div>

	<form method="post">
		<?php wp_nonce_field('dev_cfg_refresh'); ?>
		<p>
			<button type="submit" name="dev_cfg_refresh" class="button">Refresh plugin list</button>
		</p>
	</form>

	<form method="post">
		<?php wp_nonce_field('dev_cfg_apply', 'dev_cfg_nonce_apply'); ?>

		<h2>Plugins</h2>

		<div style="margin:8px 0 12px 0; display:flex; gap:12px; align-items:center;">
			<label>Filter by status:
				<select id="dev-cfg-filter-status">
					<option value="any">Any</option>
					<option value="active">Active</option>
					<option value="inactive">Inactive</option>
				</select>
			</label>
			<label>Filter by policy:
				<select id="dev-cfg-filter-policy">
					<option value="any">Any</option>
					<option value="enable">Enable</option>
					<option value="disable">Disable</option>
					<option value="ignore">Ignore</option>
				</select>
			</label>
			<span style="color:#a00;">Rows highlighted indicate policy conflicts (active vs disable, inactive vs enable).</span>
		</div>

		<table id="dev-cfg-plugins" class="widefat fixed striped">
			<thead>
				<tr>
					<th>Name</th>
					<th>Status</th>
					<th>Policy</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($allPlugins as $file => $data): ?>
				<?php 
					$active = is_plugin_active($file);
					$policy = isset($ui['plugin_policies'][$file]) ? $ui['plugin_policies'][$file] : 'ignore';
					$mismatch = ($policy === 'enable' && !$active) || ($policy === 'disable' && $active);
					$author = '';
					if (!empty($data['AuthorName'])) { $author = $data['AuthorName']; }
					elseif (!empty($data['Author'])) { $author = wp_strip_all_tags($data['Author']); }
					$version = isset($data['Version']) ? $data['Version'] : '';
				?>
				<tr data-status="<?php echo $active ? 'active' : 'inactive'; ?>" data-policy="<?php echo esc_attr($policy); ?>"<?php echo $mismatch ? ' style="background:#fde8e8;"' : ''; ?>>
					<td>
						<?php 
							$label = $data['Name'];
							if ($author !== '') { $label .= ' (' . $author . ')'; }
							if ($version !== '') { $label .= ' - ' . $version; }
							echo esc_html($label);
						?>
					</td>
					<td>
						<span style="display:inline-block;padding:2px 6px;border-radius:3px;background:<?php echo $active ? '#46b450' : '#777'; ?>;color:#fff;">
							<?php echo $active ? 'Active' : 'Inactive'; ?>
						</span>
					</td>
					<td>
						<label style="margin-right:8px;"><input type="radio" name="dev_cfg_policy[<?php echo esc_attr($file); ?>]" value="ignore" <?php checked($policy === 'ignore'); ?> /> Ignore</label>
						<label style="margin-right:8px;"><input type="radio" name="dev_cfg_policy[<?php echo esc_attr($file); ?>]" value="enable" <?php checked($policy === 'enable'); ?> /> Enable</label>
						<label><input type="radio" name="dev_cfg_policy[<?php echo esc_attr($file); ?>]" value="disable" <?php checked($policy === 'disable'); ?> /> Disable</label>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<script type="text/javascript">
		(function(){
			var $ = jQuery;
			function applyFilters(){
				var s = $('#dev-cfg-filter-status').val();
				var p = $('#dev-cfg-filter-policy').val();
				$('#dev-cfg-plugins tbody tr').each(function(){
					var rs = this.getAttribute('data-status');
					var rp = this.getAttribute('data-policy');
					var show = (s === 'any' || s === rs) && (p === 'any' || p === rp);
					this.style.display = show ? '' : 'none';
				});
			}

			function evaluateRow(row){
				var rs = row.getAttribute('data-status');
				var rp = row.getAttribute('data-policy');
				var mismatch = (rp === 'enable' && rs === 'inactive') || (rp === 'disable' && rs === 'active');
				row.style.background = mismatch ? '#fde8e8' : '';
			}
			jQuery(function(){
				$('#dev-cfg-filter-status, #dev-cfg-filter-policy').on('change', applyFilters);
				$('#dev-cfg-plugins').on('change', 'input[name^="dev_cfg_policy["]', function(){
					var row = $(this).closest('tr')[0];
					row.setAttribute('data-policy', this.value);
					evaluateRow(row);
					applyFilters();
				});
				applyFilters();
				// Show apply summary popup if present
				<?php $popup = get_transient('dev_cfg_apply_summary_popup'); if ($popup) { delete_transient('dev_cfg_apply_summary_popup'); ?>
				alert(<?php echo json_encode($popup); ?>);
				<?php } ?>
			});
		})();
		</script>

		<!-- MCP Credentials Section -->
		<div class="dev-cfg-mcp-section" style="background:#f0f6fc; border:1px solid #c3c4c7; border-left:4px solid #2271b1; border-radius:4px; padding:16px; margin:20px 0;">
			<h2 style="margin-top:0; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
				<span class="dashicons dashicons-rest-api" style="font-size:20px; color:#2271b1;"></span>
				MCP Credentials
				<span style="font-size:12px; font-weight:normal; background:<?php echo $current_env === 'staging' ? '#d63638' : '#00a32a'; ?>; color:#fff; padding:2px 8px; border-radius:3px;">
					Current: <?php echo esc_html(ucfirst($current_env)); ?>
				</span>
			</h2>
			<p style="color:#50575e; margin-bottom:16px;">
				Store API credentials for both environments. After a production→staging push, run "Setup MCP" to restore staging keys.
				<br><strong>Note:</strong> These credentials are stored in the database. On production, store BOTH sets so they survive the push.
			</p>
			
			<div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
				<?php foreach (['staging', 'production'] as $env): ?>
				<div style="background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:12px; <?php echo $env === $current_env ? 'box-shadow:0 0 0 2px #2271b1;' : ''; ?>">
					<h3 style="margin:0 0 10px 0; display:flex; align-items:center; gap:6px;">
						<?php echo ucfirst($env); ?> Keys
						<?php if ($env === $current_env): ?>
							<span style="font-size:10px; background:#2271b1; color:#fff; padding:1px 6px; border-radius:2px;">ACTIVE</span>
						<?php endif; ?>
					</h3>
					
					<label style="display:block; margin-bottom:8px;">
						<span style="display:block; font-weight:600; margin-bottom:2px;">Consumer Key:</span>
						<input type="text" name="mcp_creds[<?php echo $env; ?>][consumer_key]" 
							value="<?php echo esc_attr($mcp_creds[$env]['consumer_key']); ?>" 
							placeholder="ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
							style="width:100%; font-family:monospace; font-size:12px;">
					</label>
					
					<label style="display:block; margin-bottom:8px;">
						<span style="display:block; font-weight:600; margin-bottom:2px;">Consumer Secret:</span>
						<input type="password" name="mcp_creds[<?php echo $env; ?>][consumer_secret]" 
							value="<?php echo esc_attr($mcp_creds[$env]['consumer_secret']); ?>" 
							placeholder="cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
							style="width:100%; font-family:monospace; font-size:12px;">
					</label>
					
					<label style="display:block;">
						<span style="display:block; font-weight:600; margin-bottom:2px;">User ID:</span>
						<input type="number" name="mcp_creds[<?php echo $env; ?>][user_id]" 
							value="<?php echo esc_attr($mcp_creds[$env]['user_id'] ?: 1); ?>" 
							min="1" style="width:80px;">
						<span style="color:#666; font-size:12px;">(WordPress admin user)</span>
					</label>
					
					<?php if (!empty($mcp_creds[$env]['consumer_key'])): ?>
						<p style="margin:8px 0 0 0; color:#00a32a; font-size:12px;">
							✓ Credentials stored (key ends: ...<?php echo esc_html(substr($mcp_creds[$env]['consumer_key'], -7)); ?>)
						</p>
					<?php else: ?>
						<p style="margin:8px 0 0 0; color:#d63638; font-size:12px;">
							✗ No credentials stored
						</p>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			
			<p style="margin:12px 0 0 0; font-size:12px; color:#50575e;">
				<strong>Workflow:</strong> 1) Create API keys in WooCommerce → Settings → Advanced → REST API on each environment. 
				2) Enter credentials above on <strong>production</strong>. 3) After any prod→staging push, go to Dev Config and run "Setup MCP".
			</p>
		</div>

		<h2 style="margin-top:24px;">Other actions</h2>
		<table class="widefat fixed striped">
			<thead>
				<tr>
					<th>Action</th>
					<th>Description</th>
					<th>Enable</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ($registry as $key => $meta): ?>
				<tr>
					<td><strong><?php echo esc_html($meta['label']); ?></strong> <code><?php echo esc_html($key); ?></code></td>
					<td><?php echo esc_html($meta['description']); ?></td>
					<td>
					<?php if ($key === 'fluent_smtp_simulation'): ?>
							<?php
							$mode = isset($ui['other_actions']['fluent_smtp_simulation']) ? $ui['other_actions']['fluent_smtp_simulation'] : 'enable';
							?>
						<label style="margin-right:8px;"><input type="radio" name="dev_cfg_action[fluent_smtp_simulation]" value="ignore" <?php checked($mode === 'ignore'); ?> /> Ignore</label>
							<label style="margin-right:8px;"><input type="radio" name="dev_cfg_action[fluent_smtp_simulation]" value="enable" <?php checked($mode === 'enable'); ?> /> Enable</label>
							<label><input type="radio" name="dev_cfg_action[fluent_smtp_simulation]" value="disable" <?php checked($mode === 'disable'); ?> /> Disable</label>
						<?php else: ?>
							<input type="checkbox" name="dev_cfg_action[<?php echo esc_attr($key); ?>]" value="1" <?php checked(!empty($ui['other_actions'][$key])); ?> />
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:16px; display:flex; gap:12px; align-items:center;">
			<?php wp_nonce_field('dev_cfg_save', 'dev_cfg_nonce_save'); ?>
			<button type="submit" name="dev_cfg_save" class="button">
				<span class="dashicons dashicons-saved" style="vertical-align:middle; margin-right:4px;"></span>
				Save to "<?php echo esc_attr($activeConfigName); ?>"
			</button>
			<?php $is_production = function_exists('wp_get_environment_type') ? (wp_get_environment_type() === 'production') : false; ?>
			<button type="submit" name="dev_cfg_apply" class="button button-primary"<?php if ($is_production) { echo ' onclick="return window.confirm(\'Warning: You are on LIVE/production. This will change plugin states and settings. Continue?\')"'; } ?>>
				<span class="dashicons dashicons-controls-play" style="vertical-align:middle; margin-right:4px;"></span>
				Apply fresh dev configuration
			</button>
		</p>
	</form>
</div>
