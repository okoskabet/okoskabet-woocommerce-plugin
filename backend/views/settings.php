<?php ?>
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


	cmb2_metabox_form(O_TEXTDOMAIN . '_options', O_TEXTDOMAIN . '-settings');
	?>
</div>