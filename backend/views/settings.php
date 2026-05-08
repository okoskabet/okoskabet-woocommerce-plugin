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
			'name'       => __('Standard display window (days)', O_TEXTDOMAIN),
			'desc'       => __('How many days into the future delivery dates are shown by default. Date-based exceptions ("only on a specific day" and "from/until") can extend the display to specific dates outside this window. Recommended: 3-7 for normal operation, higher if you have many exceptions.', O_TEXTDOMAIN),
			'id'         => '_maximum_days_in_future',
			'type'       => 'text',
			'attributes' => array(
				'type'      => 'number',
				'pattern'   => '\d*',
			),
			'sanitization_cb' => 'absint',
			'escape_cb'       => 'absint',
			'default'         => '3'
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

	// Delivery location options settings
	$cmb->add_field(
		array(
			'name' => __('Home Delivery: Delivery Location', O_TEXTDOMAIN),
			'desc' => __('Settings for delivery location on home delivery', O_TEXTDOMAIN),
			'id'   => '_delivery_location_title',
			'type' => 'title',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Show delivery location dropdown', O_TEXTDOMAIN),
			'desc' => __('Show a dropdown with delivery locations from Økoskabet\'s API (e.g. "By the stairs", "By the front door"). Disable to show only the free-text field.', O_TEXTDOMAIN),
			'id'   => '_delivery_location_dropdown',
			'type' => 'checkbox',
			'default' => 'on',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Label: Dropdown', O_TEXTDOMAIN),
			'desc' => __('Label on the dropdown field at checkout', O_TEXTDOMAIN),
			'id'   => '_delivery_location_dropdown_label',
			'type' => 'text',
			'sanitization_cb' => 'sanitize_text_field',
			'default' => 'Leveringsinfo',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Descriptive text', O_TEXTDOMAIN),
			'desc' => __('Text shown above the delivery info dropdown at checkout. Leave empty to hide. Example: "Your order is delivered overnight to your delivery day".', O_TEXTDOMAIN),
			'id'   => '_delivery_location_note_label',
			'type' => 'text',
			'sanitization_cb' => 'sanitize_text_field',
			'default' => '',
		)
	);

	$cmb->add_field(
		array(
			'name'    => __('Hide WooCommerce order note', O_TEXTDOMAIN),
			'desc'    => __('Hide WooCommerce\'s standard "Add order note" field at checkout. Enable if you use Økoskabet\'s delivery info and want only one note field for the customer.', O_TEXTDOMAIN),
			'id'      => '_hide_wc_order_comments',
			'type'    => 'checkbox',
		)
	);

	$cmb->add_field(
		array(
			'name'            => __('Webhook Secret', O_TEXTDOMAIN),
			'desc'            => __('Secret key from your webhook configuration in Økoskabet\'s backoffice (under API & Webhooks). Used to verify that incoming webhooks really come from Økoskabet. Required if webhook functionality is enabled.', O_TEXTDOMAIN),
			'id'              => '_webhook_secret',
			'type'            => 'text',
			'attributes'      => array('type' => 'password'),
			'sanitization_cb' => 'sanitize_text_field',
			'escape_cb'       => 'esc_attr',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Webhook & Payment', O_TEXTDOMAIN),
			'desc' => __('Configure webhook events and payment handling', O_TEXTDOMAIN),
			'id'   => '_webhook_settings_title',
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
			'name'    => __('Payment Gateway', O_TEXTDOMAIN),
			'desc'    => __('Choose which payment gateway is used. "Automatic" attempts to detect it from the order.', O_TEXTDOMAIN),
			'id'      => '_payment_gateway',
			'type'    => 'select',
			'options' => array(
				'auto'             => __('Automatic (detect from order)', O_TEXTDOMAIN),
				'quickpay_gateway' => __('Quickpay', O_TEXTDOMAIN),
				'stripe'           => __('Stripe', O_TEXTDOMAIN),
				'nets_easy'        => __('Nets Easy / DIBS Easy', O_TEXTDOMAIN),
				'pensopay'         => __('Pensopay', O_TEXTDOMAIN),
				'fallback'         => __('Other (change status to "processing")', O_TEXTDOMAIN),
			),
			'default' => 'auto',
		)
	);

	$cmb->add_field(
		array(
			'name'    => __('Capture events', O_TEXTDOMAIN),
			'desc'    => __('Choose which events from Økoskabet should capture the payment (delayed capture). "Label Printed" happens earliest — typically when the webshop prints the label. "In Shed" happens when Økoskabet has placed the package in the shed. "Order Delivered" happens when the customer has collected the package.', O_TEXTDOMAIN),
			'id'      => '_capture_events',
			'type'    => 'multicheck',
			'options' => array(
				'label_printed'   => __('Label Printed', O_TEXTDOMAIN),
				'in_shed'         => __('In Shed', O_TEXTDOMAIN),
				'order_delivered' => __('Order Delivered', O_TEXTDOMAIN),
			),
			'default' => array('label_printed'),
		)
	);

	$cmb->add_field(
		array(
			'name'             => __('Completion events', O_TEXTDOMAIN),
			'desc'             => __('Choose which events from Økoskabet should mark the order as completed. Typically you would choose "Order Delivered" so the order only closes when the customer has collected.', O_TEXTDOMAIN),
			'id'               => '_webhook_events',
			'type'             => 'multicheck',
			'options'          => array(
				'label_printed'   => __('Label Printed', O_TEXTDOMAIN),
				'in_shed'         => __('In Shed', O_TEXTDOMAIN),
				'order_delivered' => __('Order Delivered', O_TEXTDOMAIN),
			),
			'default'          => array('order_delivered'),
		)
	);

	$cmb->add_field(
		array(
			'name'    => __('Allow split checkout', O_TEXTDOMAIN),
			'desc'    => __('When ON: if a customer\'s cart contains items that cannot all be delivered on the same day, they\'ll be guided through one separate order per delivery date. When OFF: a notice tells the customer to remove items so they all share at least one delivery date.', O_TEXTDOMAIN),
			'id'      => '_split_checkout_enabled',
			'type'    => 'checkbox',
			'default' => '',
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