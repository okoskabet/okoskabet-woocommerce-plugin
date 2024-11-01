<?php

/**
 * Økoskabet WooCommerce Plugin
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 * @link      https://heyrobot.ai
 */

/**
 * Get the settings of the plugin in a filterable way
 *
 * @since 1.0.0
 * @return array
 */

function o_get_settings()
{
	return apply_filters('o_get_settings', get_option(O_TEXTDOMAIN . '-settings'));
}

function o_check_configuration($value)
{
	$shipping_methods = !empty($shipping_methods) ? $shipping_methods : false;
	if (empty($shipping_methods)) {
		$settings = o_get_settings();

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

		if (!empty($settings['_api_key'])) {
			$curl = curl_init();

			curl_setopt_array($curl, array(
				CURLOPT_URL => $api_url . '/api/v1/configuration',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_HTTPHEADER => array(
					'authorization: ' . $settings['_api_key']
				),
			));

			$response = curl_exec($curl);

			$oko_configuration = json_decode($response, true);

			$shipping_methods = [];
			foreach ($oko_configuration['shipping_methods'] as $method) {
				$shipping_methods[$method['method_code']] = $method;
			}

			curl_close($curl);
		}
	}
	if (empty($shipping_methods[$value])) return false;
	return true;
}




function enqueue_checkout_scripts()
{
	if (is_checkout()) {  // Check if it's the WooCommerce checkout page
		wp_enqueue_script('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js', array(), null, true);
		wp_enqueue_style('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css', array(), '3.3.0');

		wp_enqueue_script('okoskabet-shipping', plugin_dir_url(__DIR__) . 'assets/build/plugin-public.js', array(), null, true);
		wp_enqueue_style('okoskabet-shipping', plugin_dir_url(__DIR__) . 'assets/build/plugin-public.css', array(), null);
	}
}
add_action('wp_enqueue_scripts', 'enqueue_checkout_scripts');


add_action('woocommerce_review_order_before_submit', 'custom_content_for_custom_shipping_checkout', 10);
function custom_content_for_custom_shipping_checkout()
{
	$settings = o_get_settings();
	if (empty($settings['_api_key'])) return;
	$shed_description = !empty($settings['_description_shipping_okoskabet']) ? $settings['_description_shipping_okoskabet'] : 'Afkølet afhentningssted hvor du kan hente dine varer hele døgnet vha. kode.';
	$local_description = !empty($settings['_description_shipping_private']) ? $settings['_description_shipping_private'] : 'Økoskabet leverer dine varer til døren.';
?>
	<script type="text/javascript">
		window._okoskabet_checkout = {
			locale: '<?php echo get_locale() ?>',
			displayOption: '<?php echo $settings['_display_option'] ?>',
			descriptions: {
				homeDelivery: '<?php echo $local_description ?>',
				shedDelivery: '<?php echo $shed_description ?>',
			},
		}
	</script>
<?php
}


add_filter('woocommerce_shipping_methods', 'hey_register_okoskabet_shipping_shed_method');
function hey_register_okoskabet_shipping_shed_method($methods)
{
	if (empty(o_check_configuration('shed'))) return $methods;
	// $method contains available shipping methods
	$methods['hey_okoskabet_shipping_shed'] = 'WC_Hey_Okoskabet_Shipping_Method_Shed';
	return $methods;
}

function hey_okoskabet_shipping_method_shed_init()
{
	if (empty(o_check_configuration('shed'))) return;

	if (!class_exists('WC_Hey_Okoskabet_Shipping_Method_Shed')) {
		class WC_Hey_Okoskabet_Shipping_Method_Shed extends WC_Shipping_Method
		{
			/**
			 * Constructor. The instance ID is passed to this.
			 */
			public function __construct($instance_id = 0)
			{
				$this->id                    = 'hey_okoskabet_shipping_shed';
				$this->instance_id           = absint($instance_id);
				$this->method_title       = __('Økoskabet', O_TEXTDOMAIN); // Title shown in admin
				$this->method_description = __('Delivery to Økoskabet', O_TEXTDOMAIN); // Description shown in admin
				$this->supports              = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);
				$this->instance_form_fields = array(
					'title' => array(
						'title'       => esc_html__('Method Title', O_TEXTDOMAIN),
						'type'        => 'text',
						'description' => esc_html__('Enter the method title', O_TEXTDOMAIN),
						'default'     => esc_html__($this->method_title, O_TEXTDOMAIN),
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => esc_html__('Description', O_TEXTDOMAIN),
						'type'        => 'textarea',
						'description' => esc_html__('Enter the Description', O_TEXTDOMAIN),
						'default'     => esc_html__('', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
					'cost' => array(
						'title'       => esc_html__('Shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the default shipping price', O_TEXTDOMAIN),
						'default'     => esc_html__('49', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
					'costDiscountLimit' => array(
						'title'       => esc_html__('Discounted shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted shipping total order minimum', O_TEXTDOMAIN),
						'default'     => esc_html__('0', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
					'costDiscount' => array(
						'title'       => esc_html__('Discounted shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted price', O_TEXTDOMAIN),
						'default'     => esc_html__('0', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
					'costFreeLimit' => array(
						'title'       => esc_html__('Free shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the free shipping total order minimum', O_TEXTDOMAIN),
						'default'     => esc_html__('0', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
				);

				$this->cost              = $this->get_option('cost');
				$this->costDiscount      = $this->get_option('costDiscount');
				$this->costDiscountLimit = $this->get_option('costDiscountLimit');
				$this->costFreeLimit     = $this->get_option('costFreeLimit');
				$this->title             = $this->get_option('title');
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * calculate_shipping function.
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array())
			{
				$total = 0;

				// Calculate the total cost of items in the package
				foreach ($package['contents'] as $item_id => $values) {
					$total += $values['line_total'];
				}

				$applied_coupons = WC()->cart->get_applied_coupons();

				foreach ($applied_coupons as $coupon_code) {
					$coupon = new WC_Coupon($coupon_code);
					if ($coupon->get_free_shipping()) {
						$this->add_rate(array(
							'id'    => $this->id,
							'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
							'cost'  => 0,
						));
						return;
					}
				}

				if (!empty($this->costFreeLimit)) {
					if ($total >= $this->costFreeLimit) {
						$this->add_rate(array(
							'id'    => $this->id,
							'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
							'cost'  => 0,
						));
						return;
					}
				}

				if (!empty($this->costDiscountLimit)) {
					if ($total >= $this->costDiscountLimit) {
						$this->add_rate(array(
							'id'    => $this->id,
							'label' => $this->title . ' (' . esc_html__('Discounted shipping rate', O_TEXTDOMAIN) . ')',
							'cost'  => $this->costDiscount,
						));
						return;
					}
				}

				$this->add_rate(array(
					'id'    => $this->id,
					'label' => $this->title,
					'cost'  => $this->cost,
				));
			}
		}
	}
}
add_action('woocommerce_shipping_init', 'hey_okoskabet_shipping_method_shed_init');

// Here comes home delivery classes
add_filter('woocommerce_shipping_methods', 'hey_register_okoskabet_shipping_home_method');
function hey_register_okoskabet_shipping_home_method($methods)
{
	if (empty(o_check_configuration('home_delivery'))) return $methods;
	// $method contains available shipping methods
	$methods['hey_okoskabet_shipping_home'] = 'WC_Hey_Okoskabet_Shipping_Method_Home';
	return $methods;
}

function hey_okoskabet_shipping_method_home_init()
{
	if (empty(o_check_configuration('home_delivery'))) return;

	if (!class_exists('WC_Hey_Okoskabet_Shipping_Method_Home')) {
		class WC_Hey_Okoskabet_Shipping_Method_Home extends WC_Shipping_Method
		{
			/**
			 * Constructor. The instance ID is passed to this.
			 */
			public function __construct($instance_id = 0)
			{
				$this->id                    = 'hey_okoskabet_shipping_home';
				$this->instance_id           = absint($instance_id);
				$this->method_title       = __('Home delivery', O_TEXTDOMAIN); // Title shown in admin
				$this->method_description = __('Delivery to your home', O_TEXTDOMAIN); // Description shown in admin
				$this->supports              = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);
				$this->instance_form_fields = array(
					'title' => array(
						'title'       => esc_html__('Method Title', O_TEXTDOMAIN),
						'type'        => 'text',
						'description' => esc_html__('Enter the method title', O_TEXTDOMAIN),
						'default'     => esc_html__($this->method_title, O_TEXTDOMAIN),
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => esc_html__('Description', O_TEXTDOMAIN),
						'type'        => 'textarea',
						'description' => esc_html__('Enter the Description', O_TEXTDOMAIN),
						'default'     => esc_html__('', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
					'cost' => array(
						'title'       => esc_html__('Shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the default shipping price', O_TEXTDOMAIN),
						'default'     => esc_html__('49', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
					'costDiscountLimit' => array(
						'title'       => esc_html__('Discounted shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted shipping total order minimum', O_TEXTDOMAIN),
						'default'     => esc_html__('0', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
					'costDiscount' => array(
						'title'       => esc_html__('Discounted shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted price', O_TEXTDOMAIN),
						'default'     => esc_html__('0', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
					'costFreeLimit' => array(
						'title'       => esc_html__('Free shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the free shipping total order minimum', O_TEXTDOMAIN),
						'default'     => esc_html__('0', O_TEXTDOMAIN),
						'desc_tip'    => true
					),
				);

				$this->cost              = $this->get_option('cost');
				$this->costDiscount      = $this->get_option('costDiscount');
				$this->costDiscountLimit = $this->get_option('costDiscountLimit');
				$this->costFreeLimit     = $this->get_option('costFreeLimit');
				$this->title             = $this->get_option('title');
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * calculate_shipping function.
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array())
			{
				$total = 0;

				// Calculate the total cost of items in the package
				foreach ($package['contents'] as $item_id => $values) {
					$total += $values['line_total'];
				}

				$applied_coupons = WC()->cart->get_applied_coupons();

				foreach ($applied_coupons as $coupon_code) {
					$coupon = new WC_Coupon($coupon_code);
					if ($coupon->get_free_shipping()) {
						$this->add_rate(array(
							'id'    => $this->id,
							'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
							'cost'  => 0,
						));
						return;
					}
				}

				if (!empty($this->costFreeLimit)) {
					if ($total >= $this->costFreeLimit) {
						$this->add_rate(array(
							'id'    => $this->id,
							'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
							'cost'  => 0,
						));
						return;
					}
				}

				if (!empty($this->costDiscountLimit)) {
					if ($total >= $this->costDiscountLimit) {
						$this->add_rate(array(
							'id'    => $this->id,
							'label' => $this->title . ' (' . esc_html__('Discounted shipping rate', O_TEXTDOMAIN) . ')',
							'cost'  => $this->costDiscount,
						));
						return;
					}
				}

				$this->add_rate(array(
					'id'    => $this->id,
					'label' => $this->title,
					'cost'  => $this->cost,
				));
			}
		}
	}
}
add_action('woocommerce_shipping_init', 'hey_okoskabet_shipping_method_home_init');

add_filter('woocommerce_checkout_fields', 'custom_override_checkout_fields');

// Our hooked in function - $fields is passed via the filter!
function custom_override_checkout_fields($fields)
{
	$fields['billing']['billing_okoskabet_shed_id'] = array(
		'label'       => __('Økoskabet ID', 'woocommerce'),
		'placeholder' => _x('', 'placeholder', 'woocommerce'),
		'required'    => false,
		'class'       => array('okoskabet-shed-id form-row-wide'),
		'clear'       => true
	);

	$fields['billing']['billing_okoskabet_delivery_date'] = array(
		'label'       => __('Økoskabet Delivery Date', 'woocommerce'),
		'placeholder' => _x('', 'placeholder', 'woocommerce'),
		'required'    => false,
		'class'       => array('okoskabet-delivery-date form-row-wide'),
		'clear'       => true
	);

	return $fields;
}

/**
 * Display field value on the order edit page
 */
add_action('woocommerce_admin_order_data_after_shipping_address', 'my_custom_checkout_field_display_admin_order_meta', 10, 1);
function my_custom_checkout_field_display_admin_order_meta($order)
{
	$order_done = $order->get_meta('billing_okoskabet_done', true);
	$shed_id = $order->get_meta('_billing_okoskabet_shed_id', true);
	$delivery_date = $order->get_meta('_billing_okoskabet_delivery_date', true);
	echo '<pre>';
	if (!empty($order_done)) {
		echo 'Økoskabet Done' . ': ' . esc_html($order_done) . "\n";
	}
	if (!empty($shed_id)) {
		echo 'Økoskabet SHED ID' . ': ' . esc_html($shed_id) . "\n";
	}
	if (!empty($delivery_date)) {
		echo 'Økoskabet Delivery Date' . ': ' . esc_html($delivery_date) . '';
	}
	echo '</pre>';
}


add_action('woocommerce_order_status_changed', 'hey_after_order_placed', 10, 4);

/**
 * Custom function to be called after an order is placed.
 *
 * @param int $order_id The order ID.
 */
function hey_after_order_placed($order_id, $old_status, $new_status, $order)
{
	if (empty($order)) {
		return;
	}

	$order_number = $order->get_order_number();

	$settings = get_option(O_TEXTDOMAIN . '-settings');
	$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

	if (empty($settings['_api_key'])) {
		error_log("okoskabet_woocommerce_plugin: API key not set");
		return;
	}

	// First process if the order is getting cancelled
	if ($new_status == 'cancelled') {

		$url = $api_url . '/api/v1/shipments/' . $order_number;
		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'authorization: ' . $settings['_api_key'],
			'Content-Length: 0'
		]);

		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		if ($http_code != 204) {
			error_log('okoskabet_woocommerce_plugin: Error trying to delete order ' . $order_number . ', response(' . $http_code . '): ' . $response);
		}
	}

	if ($new_status == 'on-hold' || $new_status == 'processing') {
		// If being created, do some checks and then create
		$order_submitted = get_post_meta($order_id, 'billing_okoskabet_done', true);
		if (!empty($order_submitted)) {
			return;
		}

		if (empty($order->get_transaction_id()) && !empty($order->get_total()) && $order->get_total() > 0) {
			error_log("okoskabet_woocommerce_plugin: Missing transaction id. Not submitting order to Økoskabet");
			return;
		}

		$order_number = $order->get_order_number();

		$order_shed = $order->get_meta('_billing_okoskabet_shed_id', true);
		$order_delivery_date = $order->get_meta('_billing_okoskabet_delivery_date', true);

		if (empty($order_delivery_date)) {
			return;
		}

		$url = $api_url . '/api/v1/shipments/';

		$data = !empty($order_shed) ? [
			'locale' => get_locale(),
			'allow_invalid' => true,
			'shipment_reference' => (string) $order_number,
			'customer' => [
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'phone' => $order->get_billing_phone(),
				'email' => $order->get_billing_email(),
			],
			'notes' => (string) $order->get_customer_note(),
			'delivery_date' => $order->get_meta('_billing_okoskabet_delivery_date', true),
			'reservation' => [
				'shed_id' => $order->get_meta('_billing_okoskabet_shed_id', true),
				'max_duration_days' => 1,
			]
		] : [
			'locale' => get_locale(),
			'allow_invalid' => true,
			'shipment_reference' => (string) $order_number,
			'customer' => [
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
				'phone' => $order->get_billing_phone(),
				'email' => $order->get_billing_email(),
			],
			'home_delivery' => [
				'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
				'address_1' => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
				'city' => $order->get_shipping_city(),
				'postal_code' => $order->get_shipping_postcode()
			],
			'notes' => (string) $order->get_customer_note(),
			'delivery_date' => $order->get_meta('_billing_okoskabet_delivery_date', true),
		];

		$data_json = json_encode($data);

		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'authorization: ' . $settings['_api_key'],
			'Content-Length: ' . strlen($data_json)
		]);

		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $data_json);

		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);

		$shipment = json_decode($response, true);

		if ($http_code != 201) {
			// Set the order status to 'failed'
			$order->update_status('failed', !empty($shipment['error_message']) ? $shipment['error_message'] : 'Order failed before processing.');

			$error_text = !empty($shipment['error_message']) ? $shipment['error_message'] : __("The order could not be completed", O_TEXTDOMAIN);

			throw new Exception($error_text);
		} else {
			$customer_note = $order->get_customer_note();
			$customer_note ?: '';
			$oko_order_note = 'ØKOSKABET ' . $order_delivery_date;
			if (empty($order_shed)) {
				$oko_order_note .=  ' Hjemmelevering';
			} else {
				$oko_order_note .=  ' ' . $order_shed;
			}
			$order->set_customer_note($oko_order_note . "\n" . $customer_note, 0);

			update_post_meta($order_id, 'billing_okoskabet_done', true);
		}
	}
}

add_action('woocommerce_after_checkout_validation', 'okoskabet_woocommerce_plugin_after_checkout_validation');

function okoskabet_woocommerce_plugin_after_checkout_validation($fields)
{
	if ($fields['shipping_method'][0] == 'hey_okoskabet_shipping_shed') {
		if (empty($fields['billing_okoskabet_shed_id']) || empty($fields['billing_okoskabet_delivery_date'])) {
			wc_add_notice(__("Please select an Økoskab and Delivery date before submitting the order.", O_TEXTDOMAIN), 'error');
		}
	}
	if ($fields['shipping_method'][0] == 'hey_okoskabet_shipping_home') {
		if (empty($fields['billing_okoskabet_delivery_date'])) {
			wc_add_notice(__("Please select a Delivery date before submitting the order.", O_TEXTDOMAIN), 'error');
		}
	}
}
