<?php
/*
 * Retrieve these settings on front end in either of these ways:
 *   $my_setting = cmb2_get_option( O_TEXTDOMAIN . '-settings', 'some_setting', 'default' );
 *   $my_settings = get_option( O_TEXTDOMAIN . '-settings', 'default too' );
 * CMB2 Snippet: https://github.com/CMB2/CMB2-Snippet-Library/blob/master/options-and-settings-pages/theme-options-cmb.php
 */
?>
<div id="tabs-1" class="wrap">
	<?php
	$cmb = new_cmb2_box(
		array(
			'id'         => O_TEXTDOMAIN . '_options',
			'hookup'     => false,
			'show_on'    => array('key' => 'options-page', 'value' => array(O_TEXTDOMAIN)),
			'show_names' => true,
		)
	);

	$cmb->add_field(
		array(
			'name'    => __('API Key', O_TEXTDOMAIN),
			'desc'    => __('Økoskabet API Key', O_TEXTDOMAIN),
			'id'      => '_api_key',
			'type'    => 'text',
			'default' => '',
		)
	);

	$cmb->add_field(
		array(
			'name'             => __('Display option', O_TEXTDOMAIN),
			'desc'             => __('Shipping method display option', O_TEXTDOMAIN),
			'id'               => '_display_option',
			'type'             => 'radio_inline',
			'options'          => array(
				'inline' => __('Inline', O_TEXTDOMAIN),
				'modal'   => __('Modal', O_TEXTDOMAIN),
			),
		)
	);

	$cmb->add_field(
		array(
			'name'       => __('Maximum days into the future', O_TEXTDOMAIN),
			'desc'       => __('Delivery options will be visible up to this many days into the future.', O_TEXTDOMAIN),
			'id'         => '_maximum_days_in_future',
			'type'       => 'text',
			'attributes' => array(
				'type'      => 'number',
				'pattern'   => '\d*',
			),
			'sanitization_cb' => 'absint',
			'escape_cb'       => 'absint',
			'default'         => '21'
		)
	);

	?>
	<style>
		.cmb2-id--staging-api {
			display: none;
		}
	</style>
	<?php
	$cmb->add_field(
		array(
			'name' => __('Staging API', O_TEXTDOMAIN),
			'desc' => __('Check this to use staging API', O_TEXTDOMAIN),
			'id'   => '_staging_api',
			'type' => 'checkbox',
		)
	);


	$cmb->add_field(
		array(
			'name' => __('Økoskabet Description (Optional)', O_TEXTDOMAIN),
			'desc' => __('Økoskabet shipping method description, overwrites default description', O_TEXTDOMAIN),
			'id'   => '_description_shipping_okoskabet',
			'type' => 'textarea',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Økoskabet Home Delivery Description (Optional)', O_TEXTDOMAIN),
			'desc' => __('Økoskabet Home Delivery shipping method description, overwrites default description', O_TEXTDOMAIN),
			'id'   => '_description_shipping_private',
			'type' => 'textarea',
		)
	);

	// Webhook settings
	$cmb->add_field(
		array(
			'name' => __('Webhook Settings', O_TEXTDOMAIN),
			'desc' => __('Configure webhook events for automatic order status updates', O_TEXTDOMAIN),
			'id'   => '_webhook_title',
			'type' => 'title',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Webhook Status', O_TEXTDOMAIN),
			'desc' => __('Enable or disable webhook functionality', O_TEXTDOMAIN),
			'id'   => '_webhook_enabled',
			'type' => 'checkbox',
			'default' => 'on',
		)
	);

	$cmb->add_field(
		array(
			'name'             => __('Webhook Events', O_TEXTDOMAIN),
			'desc'             => __('Select which events from Økoskabet should mark the order as completed', O_TEXTDOMAIN),
			'id'               => '_webhook_events',
			'type'             => 'multicheck',
			'options'          => array(
				'label_created' => __('Label Created', O_TEXTDOMAIN),
				'order_delivered' => __('Order Delivered', O_TEXTDOMAIN),
			),
			'default'          => array('order_delivered'),
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Webhook URL', O_TEXTDOMAIN),
			'desc' => __('Copy this URL and configure it in your Økoskabet webhook settings', O_TEXTDOMAIN),
			'id'   => '_webhook_url_display',
			'type' => 'text',
			'default' => get_site_url() . '/wp-json/wp/v2/okoskabet/webhook',
			'attributes' => array(
				'readonly' => 'readonly',
				'style' => 'width: 100%; font-family: monospace;',
			),
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Webhook Configuration Instructions', O_TEXTDOMAIN),
			'desc' => '
				<div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; margin-top: 10px;">
					<h4 style="margin-top: 0;">' . __('How to Configure Webhook in Økoskabet:', O_TEXTDOMAIN) . '</h4>
					<ol>
						<li><strong>' . __('Login to your Økoskabet dashboard', O_TEXTDOMAIN) . '</strong></li>
						<li><strong>' . __('Navigate to Webhook Settings', O_TEXTDOMAIN) . '</strong></li>
						<li><strong>' . __('Add New Webhook with these settings:', O_TEXTDOMAIN) . '</strong>
							<ul style="margin-top: 5px;">
								<li><strong>' . __('URL:', O_TEXTDOMAIN) . '</strong> <code>' . get_site_url() . '/wp-json/wp/v2/okoskabet/webhook</code></li>
								<li><strong>' . __('Method:', O_TEXTDOMAIN) . '</strong> <code>POST</code></li>
								<li><strong>' . __('Content-Type:', O_TEXTDOMAIN) . '</strong> <code>application/json</code></li>
								<li><strong>' . __('Authorization Header:', O_TEXTDOMAIN) . '</strong> <code>' . (!empty($settings['_api_key']) ? $settings['_api_key'] : __('Your API Key', O_TEXTDOMAIN)) . '</code></li>
							</ul>
						</li>
						<li><strong>' . __('Select Events to Send:', O_TEXTDOMAIN) . '</strong>
							<ul style="margin-top: 5px;">
								<li>✓ ' . __('Label Created', O_TEXTDOMAIN) . ' <em>(' . __('if enabled above', O_TEXTDOMAIN) . ')</em></li>
								<li>✓ ' . __('Order Delivered', O_TEXTDOMAIN) . ' <em>(' . __('if enabled above', O_TEXTDOMAIN) . ')</em></li>
							</ul>
						</li>
					</ol>
					
					<h4>' . __('Expected Payload Format:', O_TEXTDOMAIN) . '</h4>
					<pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">{
  "event": "label_created", // or "order_delivered"
  "shipment_reference": "12345", // Your WooCommerce order number
  "data": {
    // Additional event data (optional)
  }
}</pre>
					
					<h4>' . __('Authentication:', O_TEXTDOMAIN) . '</h4>
					<p>' . __('Include your API key in the Authorization header:', O_TEXTDOMAIN) . '</p>
					<pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">Authorization: ' . (!empty($settings['_api_key']) ? $settings['_api_key'] : __('your-api-key-here', O_TEXTDOMAIN)) . '</pre>
					
					<h4>' . __('Testing the Webhook:', O_TEXTDOMAIN) . '</h4>
					<p>' . __('You can test the webhook using curl:', O_TEXTDOMAIN) . '</p>
					<pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">curl -X POST "' . get_site_url() . '/wp-json/wp/v2/okoskabet/webhook" \
  -H "Content-Type: application/json" \
  -H "Authorization: ' . (!empty($settings['_api_key']) ? $settings['_api_key'] : __('your-api-key', O_TEXTDOMAIN)) . '" \
  -d \'{"event": "order_delivered", "shipment_reference": "12345"}\'</pre>
				</div>
			',
			'id'   => '_webhook_instructions',
			'type' => 'title',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Webhook Response Codes', O_TEXTDOMAIN),
			'desc' => '
				<div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #00a32a; margin-top: 10px;">
					<h4 style="margin-top: 0;">' . __('Response Codes:', O_TEXTDOMAIN) . '</h4>
					<ul>
						<li><strong>200 OK:</strong> ' . __('Webhook processed successfully', O_TEXTDOMAIN) . '</li>
						<li><strong>401 Unauthorized:</strong> ' . __('Invalid or missing API key', O_TEXTDOMAIN) . '</li>
						<li><strong>403 Forbidden:</strong> ' . __('Webhook functionality is disabled', O_TEXTDOMAIN) . '</li>
						<li><strong>404 Not Found:</strong> ' . __('Order not found', O_TEXTDOMAIN) . '</li>
						<li><strong>503 Service Unavailable:</strong> ' . __('WooCommerce is not available', O_TEXTDOMAIN) . '</li>
					</ul>
					
					<h4>' . __('Success Response Example:', O_TEXTDOMAIN) . '</h4>
					<pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">{
  "success": true,
  "message": "Order status updated successfully",
  "event": "order_delivered",
  "order_id": "12345",
  "new_status": "completed"
}</pre>
				</div>
			',
			'id'   => '_webhook_responses',
			'type' => 'title',
		)
	);


	/*
			$cmb->add_field(
				array(
					'name'    => __( 'Text', O_TEXTDOMAIN ),
					'desc'    => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'      => 'text',
					'type'    => 'text',
					'default' => 'Default Text',
				)
			);
			$cmb->add_field(
				array(
					'name'    => __( 'Color Picker', O_TEXTDOMAIN ),
					'desc'    => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'      => 'colorpicker',
					'type'    => 'colorpicker',
					'default' => '#bada55',
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Text Medium', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_textmedium',
					'type' => 'text_medium',
					// 'repeatable' => true,
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Website URL', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_url',
					'type' => 'text_url',
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Text Email', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_email',
					'type' => 'text_email',
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Time', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_time',
					'type' => 'text_time',
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Date Picker', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_textdate',
					'type' => 'text_date',
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Date Picker (UNIX timestamp)', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_textdate_timestamp',
					'type' => 'text_date_timestamp',
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Date/Time Picker Combo (UNIX timestamp)', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_datetime_timestamp',
					'type' => 'text_datetime_timestamp',
				)
			);
			$cmb->add_field(
				array(
					'name'         => __( 'Test Money', O_TEXTDOMAIN ),
					'desc'         => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'           => '_textmoney',
					'type'         => 'text_money',
					'before_field' => '€', // Override '$' symbol if needed
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Text Area', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_textarea',
					'type' => 'textarea',
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Text Area for Code', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_textarea_code',
					'type' => 'textarea_code',
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Title Weeeee', O_TEXTDOMAIN ),
					'desc' => __( 'This is a title description', O_TEXTDOMAIN ),
					'id'   => '_title',
					'type' => 'title',
				)
			);
			$cmb->add_field(
				array(
					'name'             => __( 'Test Select', O_TEXTDOMAIN ),
					'desc'             => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'               => '_select',
					'type'             => 'select',
					'show_option_none' => true,
					'options'          => array(
						'standard' => __( 'Option One', O_TEXTDOMAIN ),
						'custom'   => __( 'Option Two', O_TEXTDOMAIN ),
						'none'     => __( 'Option Three', O_TEXTDOMAIN ),
					),
				)
			);
			$cmb->add_field(
				array(
					'name'             => __( 'Test Radio inline', O_TEXTDOMAIN ),
					'desc'             => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'               => '_radio_inline',
					'type'             => 'radio_inline',
					'show_option_none' => 'No Selection',
					'options'          => array(
						'standard' => __( 'Option One', O_TEXTDOMAIN ),
						'custom'   => __( 'Option Two', O_TEXTDOMAIN ),
						'none'     => __( 'Option Three', O_TEXTDOMAIN ),
					),
				)
			);
			$cmb->add_field(
				array(
					'name'    => __( 'Test Radio', O_TEXTDOMAIN ),
					'desc'    => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'      => '_radio',
					'type'    => 'radio',
					'options' => array(
						'option1' => __( 'Option One', O_TEXTDOMAIN ),
						'option2' => __( 'Option Two', O_TEXTDOMAIN ),
						'option3' => __( 'Option Three', O_TEXTDOMAIN ),
					),
				)
			);
			$cmb->add_field(
				array(
					'name'     => __( 'Test Taxonomy Radio', O_TEXTDOMAIN ),
					'desc'     => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'       => '_text_taxonomy_radio',
					'type'     => 'taxonomy_radio',
					'taxonomy' => 'category', // Taxonomy Slug
					// 'inline'  => true, // Toggles display to inline
				)
			);
			$cmb->add_field(
				array(
					'name'     => __( 'Test Taxonomy Select', O_TEXTDOMAIN ),
					'desc'     => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'       => '_taxonomy_select',
					'type'     => 'taxonomy_select',
					'taxonomy' => 'category', // Taxonomy Slug
				)
			);
			$cmb->add_field(
				array(
					'name'     => __( 'Test Taxonomy Multi Checkbox', O_TEXTDOMAIN ),
					'desc'     => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'       => '_multitaxonomy',
					'type'     => 'taxonomy_multicheck',
					'taxonomy' => 'category', // Taxonomy Slug
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Checkbox', O_TEXTDOMAIN ),
					'desc' => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'   => '_checkbox',
					'type' => 'checkbox',
				)
			);
			$cmb->add_field(
				array(
					'name'    => __( 'Test Multi Checkbox', O_TEXTDOMAIN ),
					'desc'    => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'      => '_multicheckbox',
					'type'    => 'multicheck',
					'options' => array(
						'check1' => __( 'Check One', O_TEXTDOMAIN ),
						'check2' => __( 'Check Two', O_TEXTDOMAIN ),
						'check3' => __( 'Check Three', O_TEXTDOMAIN ),
					),
				)
			);
			$cmb->add_field(
				array(
					'name'    => __( 'Test wysiwyg', O_TEXTDOMAIN ),
					'desc'    => __( 'field description (optional)', O_TEXTDOMAIN ),
					'id'      => '_wysiwyg',
					'type'    => 'wysiwyg',
					'options' => array( 'textarea_rows' => 5 ),
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'Test Image', O_TEXTDOMAIN ),
					'desc' => __( 'Upload an image or enter a URL.', O_TEXTDOMAIN ),
					'id'   => '_image',
					'type' => 'file',
				)
			);
			$cmb->add_field(
				array(
					'name'         => __( 'Multiple Files', O_TEXTDOMAIN ),
					'desc'         => __( 'Upload or add multiple images/attachments.', O_TEXTDOMAIN ),
					'id'           => '_file_list',
					'type'         => 'file_list',
					'preview_size' => array( 100, 100 ), // Default: array( 50, 50 )
				)
			);
			$cmb->add_field(
				array(
					'name' => __( 'oEmbed', O_TEXTDOMAIN ),
					'desc' => __( 'Enter a youtube, twitter, or instagram URL. Supports services listed at <a href="http://codex.wordpress.org/Embeds">http://codex.wordpress.org/Embeds</a>.', O_TEXTDOMAIN ),
					'id'   => '_embed',
					'type' => 'oembed',
				)
			);
			$cmb->add_field(
				array(
					'name'         => 'Testing Field Parameters',
					'id'           => '_parameters',
					'type'         => 'text',
					'before_row'   => '<p>before_row_if_2</p>', // Callback
					'before'       => '<p>Testing <b>"before"</b> parameter</p>',
					'before_field' => '<p>Testing <b>"before_field"</b> parameter</p>',
					'after_field'  => '<p>Testing <b>"after_field"</b> parameter</p>',
					'after'        => '<p>Testing <b>"after"</b> parameter</p>',
					'after_row'    => '<p>Testing <b>"after_row"</b> parameter</p>',
				)
			);
			*/

	cmb2_metabox_form(O_TEXTDOMAIN . '_options', O_TEXTDOMAIN . '-settings');
	?>

	<!-- @TODO: Provide other markup for your options page here. -->
</div>