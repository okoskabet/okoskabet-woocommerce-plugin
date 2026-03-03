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
function o_get_settings(): array
{
	return (array) apply_filters('o_get_settings', get_option(O_TEXTDOMAIN . '-settings', array()));
}

function o_check_configuration(string $value): bool
{
	$transient_key = O_TEXTDOMAIN . '_shipping_methods';
	$shipping_methods = get_transient($transient_key);

	if ($shipping_methods === false) {
		$settings = o_get_settings();

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

		if (!empty($settings['_api_key'])) {
			$response = wp_remote_get($api_url . '/api/v1/configuration', array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => $settings['_api_key'],
				),
			));

			if (is_wp_error($response)) {
				return false;
			}

			$http_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($http_code === 200 && !empty($body)) {
				$oko_configuration = json_decode($body, true);

				$shipping_methods = [];
				if (!empty($oko_configuration['shipping_methods'])) {
					foreach ($oko_configuration['shipping_methods'] as $method) {
						$shipping_methods[$method['method_code']] = $method;
					}
				}

				set_transient($transient_key, $shipping_methods, 5 * MINUTE_IN_SECONDS);
			} else {
				return false;
			}
		}
	}

	if (empty($shipping_methods[$value])) return false;
	return true;
}


function enqueue_checkout_scripts(): void
{
	if (is_checkout()) {
		wp_enqueue_script('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js', array(), '3.3.0', true);
		wp_enqueue_style('mapbox-gl-css', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css', array(), '3.3.0');

		wp_enqueue_script('okoskabet-shipping', plugin_dir_url(__DIR__) . 'assets/build/plugin-public.js', array(), O_VERSION, true);
		wp_enqueue_style('okoskabet-shipping', plugin_dir_url(__DIR__) . 'assets/build/plugin-public.css', array(), O_VERSION);
	}
}
add_action('wp_enqueue_scripts', 'enqueue_checkout_scripts');


add_action('woocommerce_review_order_before_submit', 'custom_content_for_custom_shipping_checkout', 10);
function custom_content_for_custom_shipping_checkout(): void
{
	$settings = o_get_settings();
	if (empty($settings['_api_key'])) return;
	$shed_description = !empty($settings['_description_shipping_okoskabet']) ? $settings['_description_shipping_okoskabet'] : 'Afkølet afhentningssted hvor du kan hente dine varer hele døgnet vha. kode.';
	$local_description = !empty($settings['_description_shipping_private']) ? $settings['_description_shipping_private'] : 'Økoskabet leverer dine varer til døren.';
?>
	<script type="text/javascript">
		window._okoskabet_checkout = <?php echo wp_json_encode(array(
			'locale'        => get_locale(),
			'displayOption' => $settings['_display_option'] ?? '',
			'descriptions'  => array(
				'homeDelivery' => $local_description,
				'shedDelivery' => $shed_description,
			),
		)); ?>;
	</script>
<?php
}


add_filter('woocommerce_shipping_methods', 'hey_register_okoskabet_shipping_shed_method');
function hey_register_okoskabet_shipping_shed_method(array $methods): array
{
	if (empty(o_check_configuration('shed'))) return $methods;
	$methods['hey_okoskabet_shipping_shed'] = 'WC_Hey_Okoskabet_Shipping_Method_Shed';
	return $methods;
}

function hey_okoskabet_shipping_method_shed_init(): void
{
	if (empty(o_check_configuration('shed'))) return;

	if (!class_exists('WC_Hey_Okoskabet_Shipping_Method_Shed')) {
		class WC_Hey_Okoskabet_Shipping_Method_Shed extends WC_Shipping_Method
		{
			protected string $cost_value = '0';
			protected string $cost_discount = '0';
			protected string $cost_discount_limit = '0';
			protected string $cost_free_limit = '0';

			public function __construct($instance_id = 0)
			{
				$this->id                    = 'hey_okoskabet_shipping_shed';
				$this->instance_id           = absint($instance_id);
				$this->method_title       = __('Økoskabet', O_TEXTDOMAIN);
				$this->method_description = __('Delivery to Økoskabet', O_TEXTDOMAIN);
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
						'default'     => $this->method_title,
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => esc_html__('Description', O_TEXTDOMAIN),
						'type'        => 'textarea',
						'description' => esc_html__('Enter the Description', O_TEXTDOMAIN),
						'default'     => '',
						'desc_tip'    => true
					),
					'cost' => array(
						'title'       => esc_html__('Shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the default shipping price', O_TEXTDOMAIN),
						'default'     => '49',
						'desc_tip'    => true
					),
					'costDiscountLimit' => array(
						'title'       => esc_html__('Discounted shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted shipping total order minimum', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
					'costDiscount' => array(
						'title'       => esc_html__('Discounted shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted price', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
					'costFreeLimit' => array(
						'title'       => esc_html__('Free shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the free shipping total order minimum', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
				);

				$this->cost_value          = $this->get_option('cost');
				$this->cost_discount       = $this->get_option('costDiscount');
				$this->cost_discount_limit = $this->get_option('costDiscountLimit');
				$this->cost_free_limit     = $this->get_option('costFreeLimit');
				$this->title               = rtrim($this->get_option('title'), ': ');
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array()): void
			{
				$total = 0;
				foreach ($package['contents'] as $values) {
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

				if (!empty($this->cost_free_limit) && $total >= (float) $this->cost_free_limit) {
					$this->add_rate(array(
						'id'    => $this->id,
						'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
						'cost'  => 0,
					));
					return;
				}

				if (!empty($this->cost_discount_limit) && $total >= (float) $this->cost_discount_limit) {
					$this->add_rate(array(
						'id'    => $this->id,
						'label' => $this->title . ' (' . esc_html__('Discounted shipping rate', O_TEXTDOMAIN) . ')',
						'cost'  => $this->cost_discount,
					));
					return;
				}

				$this->add_rate(array(
					'id'    => $this->id,
					'label' => $this->title,
					'cost'  => $this->cost_value,
				));
			}
		}
	}
}
add_action('woocommerce_shipping_init', 'hey_okoskabet_shipping_method_shed_init');

add_filter('woocommerce_shipping_methods', 'hey_register_okoskabet_shipping_home_method');
function hey_register_okoskabet_shipping_home_method(array $methods): array
{
	if (empty(o_check_configuration('home_delivery'))) return $methods;
	$methods['hey_okoskabet_shipping_home'] = 'WC_Hey_Okoskabet_Shipping_Method_Home';
	return $methods;
}

function hey_okoskabet_shipping_method_home_init(): void
{
	if (empty(o_check_configuration('home_delivery'))) return;

	if (!class_exists('WC_Hey_Okoskabet_Shipping_Method_Home')) {
		class WC_Hey_Okoskabet_Shipping_Method_Home extends WC_Shipping_Method
		{
			protected string $cost_value = '0';
			protected string $cost_discount = '0';
			protected string $cost_discount_limit = '0';
			protected string $cost_free_limit = '0';

			public function __construct($instance_id = 0)
			{
				$this->id                    = 'hey_okoskabet_shipping_home';
				$this->instance_id           = absint($instance_id);
				$this->method_title       = __('Home delivery', O_TEXTDOMAIN);
				$this->method_description = __('Delivery to your home', O_TEXTDOMAIN);
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
						'default'     => $this->method_title,
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => esc_html__('Description', O_TEXTDOMAIN),
						'type'        => 'textarea',
						'description' => esc_html__('Enter the Description', O_TEXTDOMAIN),
						'default'     => '',
						'desc_tip'    => true
					),
					'cost' => array(
						'title'       => esc_html__('Shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the default shipping price', O_TEXTDOMAIN),
						'default'     => '49',
						'desc_tip'    => true
					),
					'costDiscountLimit' => array(
						'title'       => esc_html__('Discounted shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted shipping total order minimum', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
					'costDiscount' => array(
						'title'       => esc_html__('Discounted shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted price', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
					'costFreeLimit' => array(
						'title'       => esc_html__('Free shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the free shipping total order minimum', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
				);

				$this->cost_value          = $this->get_option('cost');
				$this->cost_discount       = $this->get_option('costDiscount');
				$this->cost_discount_limit = $this->get_option('costDiscountLimit');
				$this->cost_free_limit     = $this->get_option('costFreeLimit');
				$this->title               = rtrim($this->get_option('title'), ': ');
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array()): void
			{
				$total = 0;
				foreach ($package['contents'] as $values) {
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

				if (!empty($this->cost_free_limit) && $total >= (float) $this->cost_free_limit) {
					$this->add_rate(array(
						'id'    => $this->id,
						'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
						'cost'  => 0,
					));
					return;
				}

				if (!empty($this->cost_discount_limit) && $total >= (float) $this->cost_discount_limit) {
					$this->add_rate(array(
						'id'    => $this->id,
						'label' => $this->title . ' (' . esc_html__('Discounted shipping rate', O_TEXTDOMAIN) . ')',
						'cost'  => $this->cost_discount,
					));
					return;
				}

				$this->add_rate(array(
					'id'    => $this->id,
					'label' => $this->title,
					'cost'  => $this->cost_value,
				));
			}
		}
	}
}
add_action('woocommerce_shipping_init', 'hey_okoskabet_shipping_method_home_init');

add_filter('woocommerce_checkout_fields', 'custom_override_checkout_fields');

function custom_override_checkout_fields(array $fields): array
{
	$fields['billing']['billing_okoskabet_shed_id'] = array(
		'label'       => __('Økoskabet ID', 'woocommerce'),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-shed-id form-row-wide'),
		'clear'       => true
	);

	$fields['billing']['billing_okoskabet_delivery_date'] = array(
		'label'       => __('Økoskabet Delivery Date', 'woocommerce'),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-delivery-date form-row-wide'),
		'clear'       => true
	);

	return $fields;
}

add_action('woocommerce_admin_order_data_after_shipping_address', 'my_custom_checkout_field_display_admin_order_meta', 10, 1);
function my_custom_checkout_field_display_admin_order_meta($order): void
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
		echo 'Økoskabet Delivery Date' . ': ' . esc_html($delivery_date);
	}
	echo '</pre>';
}


add_action('woocommerce_order_status_changed', 'hey_after_order_placed', 10, 4);

/**
 * @param int    $order_id   The order ID.
 * @param string $old_status Previous status.
 * @param string $new_status New status.
 * @param \WC_Order $order   The order object.
 */
function hey_after_order_placed(int $order_id, string $old_status, string $new_status, \WC_Order $order): void
{
	$order_number = $order->get_order_number();

	$settings = o_get_settings();
	$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

	if (empty($settings['_api_key'])) {
		error_log("okoskabet_woocommerce_plugin: API key not set");
		return;
	}

	if ($new_status === 'cancelled') {
		$url = $api_url . '/api/v1/shipments/' . $order_number;

		$response = wp_remote_request($url, array(
			'method'  => 'DELETE',
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $settings['_api_key'],
			),
		));

		if (is_wp_error($response)) {
			error_log('okoskabet_woocommerce_plugin: Error deleting order ' . $order_number . ': ' . $response->get_error_message());
			return;
		}

		$http_code = wp_remote_retrieve_response_code($response);
		if ($http_code !== 204) {
			error_log('okoskabet_woocommerce_plugin: Error trying to delete order ' . $order_number . ', response(' . $http_code . '): ' . wp_remote_retrieve_body($response));
		}
	}

	if ($new_status === 'on-hold' || $new_status === 'processing') {
		$order_submitted = $order->get_meta('billing_okoskabet_done', true);
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

		$is_shed_delivery = false;
		foreach ($order->get_shipping_methods() as $shipping_method) {
			if ($shipping_method->get_method_id() === 'hey_okoskabet_shipping_shed') {
				$is_shed_delivery = true;
				break;
			}
		}

		if (!$is_shed_delivery) {
			$order_shed = '';
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
			'delivery_date' => $order_delivery_date,
			'reservation' => [
				'shed_id' => $order_shed,
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
			'delivery_date' => $order_delivery_date,
		];

		$response = wp_remote_post($url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $settings['_api_key'],
			),
			'body' => wp_json_encode($data),
		));

		if (is_wp_error($response)) {
			$order->update_status('failed', $response->get_error_message());
			throw new \Exception($response->get_error_message());
		}

		$http_code = wp_remote_retrieve_response_code($response);
		$shipment = json_decode(wp_remote_retrieve_body($response), true);

		if ($http_code !== 201) {
			$error_text = !empty($shipment['error_message']) ? $shipment['error_message'] : __("The order could not be completed", O_TEXTDOMAIN);
			$order->update_status('failed', $error_text);
			throw new \Exception($error_text);
		}

		$customer_note = $order->get_customer_note() ?: '';
		$oko_order_note = 'ØKOSKABET ' . $order_delivery_date;
		if (empty($order_shed)) {
			$oko_order_note .= ' Hjemmelevering';
		} else {
			$oko_order_note .= ' ' . $order_shed;
		}
		$order->set_customer_note($oko_order_note . "\n" . $customer_note, 0);

		$order->update_meta_data('billing_okoskabet_done', true);
		$order->save();
	}
}

add_action('woocommerce_after_checkout_validation', 'okoskabet_woocommerce_plugin_after_checkout_validation');

function okoskabet_woocommerce_plugin_after_checkout_validation(array $fields): void
{
	$shipping_method = $fields['shipping_method'][0] ?? '';

	if ($shipping_method === 'hey_okoskabet_shipping_shed') {
		if (empty($fields['billing_okoskabet_shed_id']) || empty($fields['billing_okoskabet_delivery_date'])) {
			wc_add_notice(__("Please select an Økoskab and Delivery date before submitting the order.", O_TEXTDOMAIN), 'error');
		}
	}
	if ($shipping_method === 'hey_okoskabet_shipping_home') {
		if (empty($fields['billing_okoskabet_delivery_date'])) {
			wc_add_notice(__("Please select a Delivery date before submitting the order.", O_TEXTDOMAIN), 'error');
		}
		$_POST['billing_okoskabet_shed_id'] = '';
	}
}
