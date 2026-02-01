<?php

/**
 * Represents the view for the administration dashboard.
 *
 * This includes the header, options, and other information that should provide
 * The User Interface to the end user.
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 * @link      https://heyrobot.ai
 */
?>

<div class="wrap">

	<h2><?php echo esc_html(get_admin_page_title()); ?></h2>

	<div id="tabs" class="settings-tab">
		<?php
		require_once plugin_dir_path(__FILE__) . 'settings.php';
		?>
		<?php
		?>
		<div id="tabs-3" class="metabox-holder">
			<div class="postbox">
				<h3 class="hndle"><span><?php esc_html_e('Export Settings', O_TEXTDOMAIN); ?></span></h3>
				<div class="inside">
					<p><?php esc_html_e('Export the plugin\'s settings for this site as a .json file. This will allows you to easily import the configuration to another installation.', O_TEXTDOMAIN); ?></p>
					<form method="post">
						<p><input type="hidden" name="o_action" value="export_settings" /></p>
						<p>
							<?php wp_nonce_field('o_export_nonce', 'o_export_nonce'); ?>
							<?php submit_button(__('Export', O_TEXTDOMAIN), 'secondary', 'submit', false); ?>
						</p>
					</form>
				</div>
			</div>

			<div class="postbox">
				<h3 class="hndle"><span><?php esc_html_e('Import Settings', O_TEXTDOMAIN); ?></span></h3>
				<div class="inside">
					<p><?php esc_html_e('Import the plugin\'s settings from a .json file. This file can be retrieved by exporting the settings from another installation.', O_TEXTDOMAIN); ?></p>
					<form method="post" enctype="multipart/form-data">
						<p>
							<input type="file" name="o_import_file" />
						</p>
						<p>
							<input type="hidden" name="o_action" value="import_settings" />
							<?php wp_nonce_field('o_import_nonce', 'o_import_nonce'); ?>
							<?php submit_button(__('Import', O_TEXTDOMAIN), 'secondary', 'submit', false); ?>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
		?>
	</div>

</div>