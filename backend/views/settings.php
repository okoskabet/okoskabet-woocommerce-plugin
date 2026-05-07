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
			'name'       => __('Standard visningsvindue (dage)', O_TEXTDOMAIN),
			'desc'       => __('Antal dage frem hvor leveringsdatoer vises som standard. Dato-undtagelser ("kun på en bestemt dag" og "fra/indtil") kan udvide visningen til specifikke datoer udenfor dette vindue. Anbefalet: 3-7 for normal drift, højere hvis du har mange undtagelser.', O_TEXTDOMAIN),
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
			'name' => __('Hjemmelevering: Leveringssted', O_TEXTDOMAIN),
			'desc' => __('Indstillinger for leveringssted ved hjemmelevering', O_TEXTDOMAIN),
			'id'   => '_delivery_location_title',
			'type' => 'title',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Vis dropdown med leveringssteder', O_TEXTDOMAIN),
			'desc' => __('Vis en dropdown med leveringssteder fra Økoskabets API (f.eks. "Ved trappen", "Ved hoveddøren"). Slå fra for kun at vise fritekstfeltet.', O_TEXTDOMAIN),
			'id'   => '_delivery_location_dropdown',
			'type' => 'checkbox',
			'default' => 'on',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Label: Dropdown', O_TEXTDOMAIN),
			'desc' => __('Label på dropdown-feltet i checkout (dansk)', O_TEXTDOMAIN),
			'id'   => '_delivery_location_dropdown_label',
			'type' => 'text',
			'default' => 'Leveringssted',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Beskrivende tekst', O_TEXTDOMAIN),
			'desc' => __('Tekst der vises over Leveringsinfo-dropdownen på checkout. Lad være tom for at skjule. Eksempel: "Din ordre leveres af Sublog natten".', O_TEXTDOMAIN),
			'id'   => '_delivery_location_note_label',
			'type' => 'text',
			'default' => 'Besked til chaufføren (valgfrit)',
		)
	);

	$cmb->add_field(
		array(
			'name'    => __('Skjul WooCommerce ordrenote', O_TEXTDOMAIN),
			'desc'    => __('Skjul WooCommerce\'s standard "Tilføj ordrenote"-felt på checkout. Aktiver hvis du bruger Økoskabet leveringsinfo og kun vil have ét note-felt for kunden.', O_TEXTDOMAIN),
			'id'      => '_hide_wc_order_comments',
			'type'    => 'checkbox',
		)
	);

	$cmb->add_field(
		array(
			'name'    => __('Webhook Secret', O_TEXTDOMAIN),
			'desc'    => __('Secret-nøglen fra din webhook-konfiguration i Økoskabets backoffice (under API & Webhooks). Bruges til at verificere at indkomne webhooks faktisk kommer fra Økoskabet. Påkrævet hvis webhook-funktionalitet er slået til.', O_TEXTDOMAIN),
			'id'      => '_webhook_secret',
			'type'    => 'text',
			'attributes' => array(
				'type' => 'password',
				'autocomplete' => 'off',
			),
		)
	);

	// Webhook settings
	$cmb->add_field(
		array(
			'name' => __('Webhook & Betaling', O_TEXTDOMAIN),
			'desc' => __('Konfigurer webhook-events og betalingshåndtering', O_TEXTDOMAIN),
			'id'   => '_webhook_title',
			'type' => 'title',
		)
	);

	$cmb->add_field(
		array(
			'name' => __('Webhook Status', O_TEXTDOMAIN),
			'desc' => __('Slå webhook-funktionalitet til eller fra', O_TEXTDOMAIN),
			'id'   => '_webhook_enabled',
			'type' => 'checkbox',
			'default' => 'on',
		)
	);

	$cmb->add_field(
		array(
			'name'    => __('Betalingsgateway', O_TEXTDOMAIN),
			'desc'    => __('Vælg hvilken betalingsgateway der bruges. "Automatisk" forsøger at detektere den ud fra ordren.', O_TEXTDOMAIN),
			'id'      => '_payment_gateway',
			'type'    => 'select',
			'default' => 'auto',
			'options' => array(
				'auto'            => __('Automatisk (detekteres fra ordren)', O_TEXTDOMAIN),
				'quickpay_gateway' => __('Quickpay', O_TEXTDOMAIN),
				'stripe'          => __('Stripe', O_TEXTDOMAIN),
				'nets_easy'       => __('Nets Easy / DIBS Easy', O_TEXTDOMAIN),
				'pensopay'        => __('Pensopay', O_TEXTDOMAIN),
				'fallback'        => __('Andet (skift status til "processing")', O_TEXTDOMAIN),
			),
		)
	);

	$cmb->add_field(
		array(
			'name'    => __('Capture-events', O_TEXTDOMAIN),
			'desc'    => __('Vælg hvilke events fra Økoskabet der skal trække betalingen (delayed capture). "Label Printed" sker tidligst — typisk når webshoppen printer labelen. "In Shed" sker når Økoskabet har lagt pakken i skabet. "Order Delivered" sker når kunden har afhentet pakken.', O_TEXTDOMAIN),
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
			'name'             => __('Completion-events', O_TEXTDOMAIN),
			'desc'             => __('Vælg hvilke events fra Økoskabet der skal markere ordren som afsluttet (completed). Typisk vil man vælge "Order Delivered" så ordren først lukkes når kunden har afhentet.', O_TEXTDOMAIN),
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