<?php
/**
 * Plugin settings page.
 *
 * 95% of installs run with a single Økoskabet merchant. For those, this
 * page renders exactly like it did in v1.3.x — one CMB form at the top
 * with API key, webhook secret, staging, descriptions, payment gateway
 * and capture/completion event lists. The form's values are a facade
 * over the "default merchant" record: we mirror the record into the
 * legacy settings option just before CMB reads it, and copy the saved
 * option values back into the merchant on save (see
 * `Merchants::mirror_default_to_options()` and
 * `Merchants::handle_legacy_options_saved()`).
 *
 * Once the admin opts in to multi-merchant mode (either by clicking
 * "+ Add another Økoskabet merchant" or by configuring more than one
 * merchant), the merchant-specific fields disappear from this CMB form
 * and the merchants table below becomes the source of truth. The
 * underlying datamodel is identical either way — the toggle is purely
 * UX.
 */
$oko_multi_merchant_active = class_exists( '\\okoskabet_woocommerce_plugin\\Integrations\\Merchants' )
	&& \okoskabet_woocommerce_plugin\Integrations\Merchants::is_multi_merchant_mode();

// Mirror the default merchant's stored values into the legacy options
// row so CMB2 renders them in the simple form. Must run BEFORE any
// `new_cmb2_box`/field below picks up its initial value. No-op in
// multi-merchant mode and pre-migration.
if ( class_exists( '\\okoskabet_woocommerce_plugin\\Integrations\\Merchants' ) ) {
	\okoskabet_woocommerce_plugin\Integrations\Merchants::mirror_default_to_options();
}
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

	if ( ! $oko_multi_merchant_active ) {
		$cmb->add_field(
			array(
				'name'            => __('API Key', O_TEXTDOMAIN),
				'desc'            => __('Økoskabet API Key.', O_TEXTDOMAIN),
				'id'              => '_api_key',
				'type'            => 'text',
				'attributes'      => array('type' => 'password'),
				'sanitization_cb' => 'sanitize_text_field',
				'escape_cb'       => 'esc_attr',
				'default'         => '',
			)
		);
	}

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

	if ( ! $oko_multi_merchant_active ) {
		$cmb->add_field(
			array(
				'name'       => __('Standard display window (days)', O_TEXTDOMAIN),
				'desc'       => __('How many days into the future delivery dates are shown by default.', O_TEXTDOMAIN),
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
	}
	?>
	<style>
		.cmb2-id--staging-api {
			display: none;
		}
	</style>
	<?php
	if ( ! $oko_multi_merchant_active ) {
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
	}

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

	if ( ! $oko_multi_merchant_active ) {
		$cmb->add_field(
			array(
				'name'            => __('Webhook Secret', O_TEXTDOMAIN),
				'desc'            => __('Secret key from your webhook configuration.', O_TEXTDOMAIN),
				'id'              => '_webhook_secret',
				'type'            => 'text',
				'attributes'      => array('type' => 'password'),
				'sanitization_cb' => 'sanitize_text_field',
				'escape_cb'       => 'esc_attr',
			)
		);
	}

	$cmb->add_field(
		array(
			'name' => __('Webhook & Payment', O_TEXTDOMAIN),
			'desc' => $oko_multi_merchant_active
				? __('Master switches that apply across all merchants.', O_TEXTDOMAIN)
				: __('Webhook and payment settings.', O_TEXTDOMAIN),
			'id'   => '_webhook_settings_title',
			'type' => 'title',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Webhook Status', O_TEXTDOMAIN),
			'desc' => $oko_multi_merchant_active
				? __('Enable or disable webhook functionality (applies to ALL merchants).', O_TEXTDOMAIN)
				: __('Enable or disable webhook functionality.', O_TEXTDOMAIN),
			'id'   => '_webhook_enabled',
			'type' => 'checkbox',
			'default' => 'on',
		)
	);

	if ( ! $oko_multi_merchant_active ) {
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
				'desc'    => __('Choose which events from Økoskabet should capture the payment.', O_TEXTDOMAIN),
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
				'desc'             => __('Choose which events from Økoskabet should mark the order as completed.', O_TEXTDOMAIN),
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
	}

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
			'name' => $oko_multi_merchant_active
				? __('Legacy Webhook URL', O_TEXTDOMAIN)
				: __('Webhook URL', O_TEXTDOMAIN),
			'desc' => $oko_multi_merchant_active
				? __('Legacy URL — still works and routes to the default merchant. Each merchant has its own dedicated webhook URL listed under "Økoskabet merchants" below, which we recommend you configure at Økoskabet instead.', O_TEXTDOMAIN)
				: __('Copy this URL and configure it in your Økoskabet webhook settings', O_TEXTDOMAIN),
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
								<li><strong>' . __('URL:', O_TEXTDOMAIN) . '</strong> <code>' . esc_html( get_site_url() ) . '/wp-json/wp/v2/okoskabet/webhook</code></li>
								<li><strong>' . __('Method:', O_TEXTDOMAIN) . '</strong> <code>POST</code></li>
								<li><strong>' . __('Content-Type:', O_TEXTDOMAIN) . '</strong> <code>application/json</code></li>
								<li><strong>' . __('Signing secret:', O_TEXTDOMAIN) . '</strong> ' . __('use the value of the <em>Webhook Secret</em> field above. Økoskabet uses this to sign each webhook.', O_TEXTDOMAIN) . '</li>
							</ul>
						</li>
						<li><strong>' . __('Select Events to Send:', O_TEXTDOMAIN) . '</strong>
							<ul style="margin-top: 5px;">
								<li>✓ ' . __('In Shed', O_TEXTDOMAIN) . ' <em>(' . __('if enabled above', O_TEXTDOMAIN) . ')</em></li>
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
					<p>' . __('Each webhook is verified by an HMAC-SHA256 signature of the raw request body, using the Webhook Secret configured above. Økoskabet computes the signature on its side and sends it as the lowercase hex value of the following header:', O_TEXTDOMAIN) . '</p>
					<pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">X-HMAC-SHA256: &lt;hex(hmac_sha256(body, webhook_secret))&gt;</pre>
					<p>' . __('If the header is missing, malformed or does not match, the request is rejected with HTTP 401. The API Key field is <strong>not</strong> used to authenticate webhooks.', O_TEXTDOMAIN) . '</p>

					<h4>' . __('Testing the Webhook:', O_TEXTDOMAIN) . '</h4>
					<p>' . __('You can replay a payload locally with curl. Replace <code>your-webhook-secret</code> with the value from the field above (do not commit it):', O_TEXTDOMAIN) . '</p>
					<pre style="background: #f1f1f1; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px;">BODY=\'{"event":"order_delivered","shipment_reference":"12345"}\'
SECRET="your-webhook-secret"
SIG=$(printf "%s" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk \'{print $2}\')

curl -X POST "' . esc_html( get_site_url() ) . '/wp-json/wp/v2/okoskabet/webhook" \
  -H "Content-Type: application/json" \
  -H "X-HMAC-SHA256: $SIG" \
  --data-raw "$BODY"</pre>
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
						<li><strong>401 Unauthorized:</strong> ' . __('Invalid or missing HMAC signature', O_TEXTDOMAIN) . '</li>
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