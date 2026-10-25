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
	<h2 class="oko-settings-group" style="font-size:1.6em;"><?php esc_html_e( 'Standard settings', O_TEXTDOMAIN ); ?></h2>
	<p class="description"><?php esc_html_e( 'What every shop needs: the connection to Økoskabet, the texts at checkout and the webhooks. The extras your shop can choose come further down.', O_TEXTDOMAIN ); ?></p>
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
				'desc'            => __('The merchant\'s API key from Økoskabet\'s back office, under "API & Webhooks" (NOT the WooCommerce "Access token"). Used for all API calls.', O_TEXTDOMAIN),
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
			// What an unset option now behaves as (see the checkout config),
			// so the radio shows the truth instead of neither choice.
			'default'          => 'inline',
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

	$cmb->add_field(
		array(
			'name'    => __('Ask for flat, floor and door', O_TEXTDOMAIN),
			'desc'    => __('Adds a second address line at checkout, for a shop whose checkout has none. Leave it off if your customers write the floor on the street line itself ("Eksempelvej 12, 1. tv") — the address register is given it there and reads it, and two places to write one thing means some customers write it twice.', O_TEXTDOMAIN),
			'id'      => '_ask_for_floor',
			'type'    => 'checkbox',
		)
	);

	// Off until the shop turns it on. Off means neither field is read — ours
	// is not drawn, and a shop's own billing_order_id is left to the shop —
	// see oko_shop_takes_additions().
	$cmb->add_field(
		array(
			'name'    => __('Ask about additions to an earlier order', O_TEXTDOMAIN),
			'desc'    => __('Adds the field "Adding to an earlier order? Write its order number here" at checkout. If the number and the email match an order whose delivery can still take more, the goods go on that delivery and Økoskabet\'s shipping costs 0 kr. If your checkout already has a field of its own named billing_order_id, that one is used instead of ours. Off: the plugin\'s field is not shown, and a field of your own named billing_order_id is not read.', O_TEXTDOMAIN),
			'id'      => '_ask_for_addition_order',
			'type'    => 'checkbox',
			'default' => '',
		)
	);

	$cmb->add_field(
		array(
			'name'       => __('Shipping in a row of its own', O_TEXTDOMAIN),
			'desc'       => __('Shipping methods shown in their own row under Shipping at checkout, e.g. an add-on to an earlier order.', O_TEXTDOMAIN),
			'id'         => '_separate_shipping_methods',
			'type'       => 'multicheck',
			'options_cb' => 'oko_all_shipping_method_choices',
		)
	);

	$cmb->add_field(
		array(
			'name'            => __('Heading of that row', O_TEXTDOMAIN),
			'id'              => '_separate_shipping_label',
			'type'            => 'text',
			'sanitization_cb' => 'sanitize_text_field',
			'attributes'      => array('placeholder' => __('Other options', O_TEXTDOMAIN)),
		)
	);

	// For a shop that has its own shipping method for add-ons, as Gaardmester
	// does. The customer who picks it and writes the earlier order's number
	// has the goods put on that delivery, as if they had picked one of ours.
	$cmb->add_field(
		array(
			'name'       => __('Shipping methods for an add-on to an earlier order', O_TEXTDOMAIN),
			'desc'       => __('Your own shipping methods that mean "add this to an order I have already placed". A customer who picks one and writes the earlier order\'s number at checkout has the goods put on that order\'s delivery with Økoskabet. Økoskabet\'s own shipping methods always work this way and need not be ticked. Only when "Ask about additions to an earlier order" is on.', O_TEXTDOMAIN),
			'id'         => '_addon_shipping_methods',
			'type'       => 'multicheck',
			'options_cb' => 'oko_all_shipping_method_choices',
		)
	);

	// Off until the shop turns it on, like everything new. The checkout script
	// and its REST routes both check this, see Address_Autocomplete.
	$cmb->add_field(
		array(
			'name'    => __('Address suggestions at checkout', O_TEXTDOMAIN),
			'desc'    => __('While the customer types their address, suggest addresses from the Danish address register and fill in street, floor and door, postcode and city. Fewer parcels go to an address that cannot be found. The customer can ignore the suggestions and type as before. Works in the classic checkout.', O_TEXTDOMAIN),
			'id'      => \okoskabet_woocommerce_plugin\Integrations\Address_Autocomplete::SETTING,
			'type'    => 'checkbox',
			'default' => '',
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

	// Whether Økoskabet can reach this shop at all. The plugin never sees a
	// call that is stopped in front of WordPress, so it cannot report the
	// blockage — only when something last got through. "Aldrig" is the answer
	// that matters: it means the road has been closed the whole time.
	$cmb->add_field(
		array(
			'name' => __('Contact from Økoskabet', O_TEXTDOMAIN),
			'id'   => '_oko_inbound_seen',
			'type' => 'title',
			'desc' => sprintf(
				'<p>%s<br />%s</p><p><em>%s</em></p>',
				esc_html(sprintf(
					/* translators: %s: how long ago, e.g. "2 hours", or "never". */
					__('Last status message received: %s', O_TEXTDOMAIN),
					oko_inbound_seen_label(oko_inbound_last_seen('webhook'))
				)),
				esc_html(sprintf(
					/* translators: %s: how long ago, e.g. "2 hours", or "never". */
					__('Last product lookup received: %s', O_TEXTDOMAIN),
					oko_inbound_seen_label(oko_inbound_last_seen('products'))
				)),
				esc_html__('Both are calls from Økoskabet into this shop. "Never" means they are not getting through — usually a firewall or a security plugin in front of the site, not a setting here.', O_TEXTDOMAIN)
			),
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
			// A plain 'default' would tick this again every time the shop
			// saves it off: an unticked checkbox stores nothing, and CMB2
			// shows the default whenever nothing is stored. So a shop could
			// never close this door, and the page said it was open when it
			// was not. Same remedy as the two event lists below.
			'default_cb' => function () {
				return get_option(OKO_SETTINGS_SAVED_OPTION) ? '' : 'on';
			},
		)
	);

	if ( ! $oko_multi_merchant_active ) {
		// The steps a shipment passes, as this shop's own Økoskabet account
		// reports them, plus the label print — which is not a step but the
		// moment the boxes come into being. Read from the account so a step
		// added later can be chosen without a plugin release.
		// `oko_merchant_events()` rather than the list with the label written
		// in front of it: printing the label belongs where it happens, after
		// the order is registered. This screen is the one a shop with a single
		// account sees — Gaardmester's — and it built its own copy of the
		// list, so the same fix had to be made twice. Now it is made once.
		$oko_status_event_options = oko_merchant_events( 'default' );

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

		// Only where the shop's gateway can actually take the money on one of
		// these events. Nexi and its kin charge the card when the order is
		// COMPLETED, so for them this list would promise something that never
		// happens — the completion events below are what fetches the money.
		$oko_gateway = (string) (o_get_settings()['_payment_gateway'] ?? 'auto');
		if (\okoskabet_woocommerce_plugin\Integrations\Payment_Capture::capture_events_are_useful_for_shop(
			$oko_gateway,
			\okoskabet_woocommerce_plugin\Integrations\Payment_Capture::enabled_gateway_ids()
		)) {
			$cmb->add_field(
				array(
					'name'    => __('Capture events', O_TEXTDOMAIN),
					'desc'    => __('Choose which events from Økoskabet should capture the payment.', O_TEXTDOMAIN),
					'id'      => '_capture_events',
					'type'       => 'multicheck',
					'options'    => $oko_status_event_options,
					'default_cb' => function () {
						return oko_event_default(array('label_printed'));
					},
				)
			);
		} else {
			$cmb->add_field(
				array(
					'name' => __('Capture events', O_TEXTDOMAIN),
					'desc' => __('Your payment gateway takes the money when the order is marked completed, and not before. Choose below which event should complete the order — that is also the moment the customer is charged.', O_TEXTDOMAIN),
					'id'   => '_capture_events_not_available',
					'type' => 'title',
				)
			);
		}

		$cmb->add_field(
			array(
				'name'             => __('Completion events', O_TEXTDOMAIN),
				'desc'             => __('Choose which events from Økoskabet should mark the order as completed.', O_TEXTDOMAIN),
				'id'               => '_webhook_events',
				'type'             => 'multicheck',
				'options'          => $oko_status_event_options,
				'default_cb'       => function () {
					return oko_event_default(array('fulfilled'));
				},
			)
		);
	}

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

	cmb2_metabox_form(O_TEXTDOMAIN . '_options', O_TEXTDOMAIN . '-settings');

	// =====================================================================
	// Webhook reference docs — placed BELOW the Save button as collapsible
	// reference, not a setting. Most admins set webhooks up once and never
	// look at this again; keeping it folded reduces clutter.
	// =====================================================================
	$site_url = esc_html( get_site_url() );
	?>
	<details class="oko-webhook-docs" style="background:#f9f9f9; border-left:4px solid #0073aa; padding:14px 18px; margin-top:24px; border-radius:4px;">
		<summary style="font-weight:600; cursor:pointer; padding:4px 0;">
			<?php esc_html_e( 'Webhook reference & response codes', O_TEXTDOMAIN ); ?>
		</summary>

		<h4 style="margin-top:14px;"><?php esc_html_e( 'How to configure webhooks at Økoskabet', O_TEXTDOMAIN ); ?></h4>
		<ol>
			<li><?php esc_html_e( 'Log in to Økoskabet\'s dashboard.', O_TEXTDOMAIN ); ?></li>
			<li><?php esc_html_e( 'Navigate to webhook settings.', O_TEXTDOMAIN ); ?></li>
			<?php if ( $oko_multi_merchant_active ) : ?>
				<li>
					<?php esc_html_e( 'For EACH merchant configured here, register a separate webhook in Økoskabet with its merchant-specific URL (shown next to each merchant in the table above) and the matching webhook secret you saved on the merchant record. Each merchant has its own signing secret — never share a secret across merchants.', O_TEXTDOMAIN ); ?>
				</li>
			<?php else : ?>
				<li>
					<?php
					echo wp_kses(
						sprintf(
							/* translators: %s = full webhook URL */
							__( 'Register a webhook in Økoskabet pointing at <code>%s</code> with Method <code>POST</code>, Content-Type <code>application/json</code>, and the signing secret you set in the Webhook Secret field above.', O_TEXTDOMAIN ),
							$site_url . '/wp-json/wp/v2/okoskabet/webhook'
						),
						array( 'code' => array(), 'strong' => array() )
					);
					?>
				</li>
			<?php endif; ?>
			<li>
				<?php esc_html_e( 'Select the events you want Økoskabet to send — typically:', O_TEXTDOMAIN ); ?>
				<ul style="margin-top:4px;">
					<li>✓ <?php esc_html_e( 'In Shed', O_TEXTDOMAIN ); ?></li>
					<li>✓ <?php esc_html_e( 'Order Delivered', O_TEXTDOMAIN ); ?></li>
				</ul>
				<em style="font-size:0.9em; color:#666;">
					<?php esc_html_e( 'Only events you have ticked under "Capture events" or "Completion events" trigger any action — the rest are accepted and ignored.', O_TEXTDOMAIN ); ?>
				</em>
			</li>
		</ol>

		<h4><?php esc_html_e( 'Expected payload format', O_TEXTDOMAIN ); ?></h4>
		<pre style="background:#f1f1f1; padding:10px; border-radius:4px; overflow-x:auto; font-size:12px;">{
  "event": "reservation_updated",
  "shipment_reference": "12345",
  "changes": {
    "status": { "previous": "fulfilled", "value": "delivered" }
  }
}</pre>
		<p><em style="font-size:0.9em; color:#666;">
			<?php esc_html_e( 'The plugin maps Økoskabet\'s "reservation_updated" event into the internal events shown in the settings above (in_shed, order_delivered) by inspecting the "changes" payload.', O_TEXTDOMAIN ); ?>
		</em></p>

		<h4><?php esc_html_e( 'Authentication', O_TEXTDOMAIN ); ?></h4>
		<p>
			<?php esc_html_e( 'Each webhook is verified by an HMAC-SHA256 signature of the raw request body, using the merchant\'s webhook secret. Økoskabet computes the signature on its side and sends it as the lowercase hex value of this header:', O_TEXTDOMAIN ); ?>
		</p>
		<pre style="background:#f1f1f1; padding:10px; border-radius:4px; overflow-x:auto; font-size:12px;">X-HMAC-SHA256: &lt;hex(hmac_sha256(body, webhook_secret))&gt;</pre>
		<p>
			<?php
			echo wp_kses(
				__( 'If the header is missing, malformed, or does not match, the request is rejected with HTTP 401. The <strong>API key</strong> field is NOT used to authenticate webhooks — it\'s only used for outbound API calls to Økoskabet.', O_TEXTDOMAIN ),
				array( 'strong' => array() )
			);
			?>
		</p>

		<h4><?php esc_html_e( 'Test a webhook locally with curl', O_TEXTDOMAIN ); ?></h4>
		<?php
		$test_webhook_url = $oko_multi_merchant_active
			? $site_url . '/wp-json/wp/v2/okoskabet/webhook/&lt;merchant_id&gt;'
			: $site_url . '/wp-json/wp/v2/okoskabet/webhook';
		?>
		<pre style="background:#f1f1f1; padding:10px; border-radius:4px; overflow-x:auto; font-size:12px;">BODY='{"event":"order_delivered","shipment_reference":"12345"}'
SECRET="your-webhook-secret"
SIG=$(printf "%s" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST "<?php echo $test_webhook_url; // already escaped above ?>" \
  -H "Content-Type: application/json" \
  -H "X-HMAC-SHA256: $SIG" \
  --data-raw "$BODY"</pre>

		<h4 style="border-top:1px dashed #c3c4c7; padding-top:14px;">
			<?php esc_html_e( 'Response codes', O_TEXTDOMAIN ); ?>
		</h4>
		<ul>
			<li><strong>200 OK</strong> — <?php esc_html_e( 'webhook processed.', O_TEXTDOMAIN ); ?></li>
			<li><strong>401 Unauthorized</strong> — <?php esc_html_e( 'invalid or missing HMAC signature.', O_TEXTDOMAIN ); ?></li>
			<li><strong>403 Forbidden</strong> — <?php esc_html_e( 'webhook functionality is disabled in plugin settings, OR the order belongs to a different merchant than the one in the webhook URL.', O_TEXTDOMAIN ); ?></li>
			<li><strong>404 Not Found</strong> — <?php esc_html_e( 'the merchant ID in the URL is unknown, OR the order referenced by shipment_reference does not exist.', O_TEXTDOMAIN ); ?></li>
			<li><strong>409 Conflict</strong> — <?php esc_html_e( 'more than one order has the reference in shipment_reference, and none of them is touched. Check the orders for a copied Økoskabet reference.', O_TEXTDOMAIN ); ?></li>
			<li><strong>500 Internal Server Error</strong> — <?php esc_html_e( 'the merchant has no webhook secret configured. Edit the merchant and paste a value.', O_TEXTDOMAIN ); ?></li>
			<li><strong>503 Service Unavailable</strong> — <?php esc_html_e( 'WooCommerce is not loaded.', O_TEXTDOMAIN ); ?></li>
		</ul>

		<h4><?php esc_html_e( 'Success response example', O_TEXTDOMAIN ); ?></h4>
		<pre style="background:#f1f1f1; padding:10px; border-radius:4px; overflow-x:auto; font-size:12px;">{
  "success": true,
  "message": "Webhook processed",
  "event": "order_delivered",
  "raw_event": "reservation_updated",
  "order_id": "12345",
  "merchant_id": "default",
  "capture_result": null,
  "new_status": "completed"
}</pre>
	</details>
	<?php
	?>
</div>