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




function enqueue_google_maps_api()
{
	if (is_checkout()) {  // Check if it's the WooCommerce checkout page
		// Replace 'your_api_key_here' with your actual Google Maps API key
		wp_enqueue_script('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js', array(), null, true);
		wp_enqueue_style('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css', array(), '3.3.0');
	}
}
add_action('wp_enqueue_scripts', 'enqueue_google_maps_api');


add_action('woocommerce_review_order_before_submit', 'custom_content_for_custom_shipping_checkout', 10);
function custom_content_for_custom_shipping_checkout()
{
?>
	<style>
		<?php
		$settings = o_get_settings();
		if ($settings['_display_option'] == 'modal') {

		?>#oko-shed-custom-div {
			position: fixed;
			display: none;
			top: 5%;
			left: 50%;
			background: white;
			padding: 20px;
			border: 1px solid rgba(0, 0, 0, 0.5);
			border-radius: 3px;
			transform: translateX(-50%);
			z-index: 9999;
			width: 480px;
			max-width: 94%;
			max-height: 90%;
			overflow: scroll;
		}

		<?php
		} else {
		?>.okoButtonModalDone {
			display: none;
		}

		<?php
		}

		?>#billing_okoskabet_shed_id_field {
			display: none;
		}

		#billing_okoskabet_delivery_date_field {
			display: none;
		}

		.marker {
			background-image: url('/wp-content/uploads/2024/05/map_marker.svg');
			background-size: contain;
			width: 50px;
			height: 50px;
			border-radius: 50%;
			cursor: pointer;
		}
	</style>
	<script type="text/javascript">
		jQuery(function($) {
			$(document).ready(function() {


				let currentMap;

				function changeLocation(location) {
					$('#billing_okoskabet_shed_id').val(location);

					const selectedOption = $('#locationsDropdown').find('option:selected');
					const deliveryDates = selectedOption.data('dates').split(",").map(function(item) {
						return item.trim();
					});

					$('#deliveryDatesDropdown').empty();
					const dropdown = $('#deliveryDatesDropdown');

					if (dropdown) {
						deliveryDates.map(deliveryDate => {
							if (deliveryDate) {
								$(dropdown).append('<option  value="' + deliveryDate + '">' + deliveryDate + '</option>');

							}
						});
					}
					$('#deliveryDatesDropdown').trigger('change');
					localStorage.setItem("okoSkabetId", location);
				}

				let openPopUp;

				function initMaps(locations) {

					mapboxgl.accessToken = 'pk.eyJ1IjoiZGFub2tvc2thYmV0IiwiYSI6ImNsOTN5enc5eDF0OXgzcW10ejgyMDI3ZHIifQ.Yy_h5jy-F0E2t0EvnElFag';
					const map = new mapboxgl.Map({
						container: 'map', // container ID
						style: 'mapbox://styles/mapbox/streets-v12', // style URL
						center: [locations.origin.longitude, locations.origin.latitude], // starting position [lng, lat]
						zoom: 11, // starting zoom
					});

					var bounds = new mapboxgl.LngLatBounds();



					locations.sheds.forEach(function(location) {
						var okoIcon = document.createElement('div');
						okoIcon.classList.add("marker");
						const marker1 = new mapboxgl.Marker(okoIcon).setLngLat([location.address.longitude, location.address.latitude]).setPopup(new mapboxgl.Popup({
							closeButton: false
						}).setHTML("<div id='markerPopUp' data-shed=" + location.id + "><h6 style='font-weight: bold; margin-bottom: 0;'>" + location.name + "</h6><div>" + location.address.address + "</div><div>" + location.address.postal_code + " " + location.address.city + "</div></div>")).addTo(map);
						marker1.getPopup().on('open', () => {
							openPopUp = marker1.getPopup();
							const currentShed = $('#markerPopUp').data('shed');
							$('#locationsDropdown').val(currentShed);
							changeLocation(currentShed);
						});
						bounds.extend([location.address.longitude, location.address.latitude]);
					});

					const marker1 = new mapboxgl.Marker().setLngLat([locations.origin.longitude, locations.origin.latitude]).addTo(map);
					bounds.extend([locations.origin.longitude, locations.origin.latitude]);


					map.fitBounds(bounds);

				}

				let addressFilled = $('#billing_postcode').val();

				$(document).on('change', '#billing_postcode', function() {
					addressFilled = $('#billing_postcode').val();
					if (addressFilled) {
						$('body').trigger('update_checkout');
					}
				});

				$(document).on('change', '#locationsDropdown', function() {
					let location = $(this).val();
					changeLocation(location);
					if (openPopUp) openPopUp.remove();
				});

				$(document).on('change', '#deliveryDatesDropdown', function() {
					let deliveryDate = $(this).val();
					$('#billing_okoskabet_delivery_date').val(deliveryDate);

				});

				$(document).on('click', '.okoButtonModalDone', function(event) {
					event.preventDefault();
					$('#oko-shed-custom-div').hide();

				});
				$(document).on('click', '.okoButtonModalOpen', function(event) {
					event.preventDefault();
					$('#oko-shed-custom-div').show();
					initMaps(currentMap);
				});

				$(document).on('updated_checkout', function() {
					$('#billing_okoskabet_delivery_date').val('');
					$('#billing_okoskabet_shed_id').val('');
					$('#oko-shed-custom-div').remove();
					$('#oko-local-custom-div').remove();

					addressFilled = $('#billing_postcode').val();
					if (addressFilled) {
						const currentShipping = $('input[name="shipping_method[0]"]:checked');
						const parrentShipping = currentShipping.parent();

						if (currentShipping.val() === 'hey_okoskabet_shipping_shed') {

							<?php
							if ($settings['_display_option'] == 'modal') {
							?>

								if ($('#oko-shed-custom-div-modal').length === 0) {
									parrentShipping.append('<div id="oko-shed-custom-div-modal"><a href="#" class="button okoButtonModalOpen">Vælg lokationer</a></div>');
								}
							<?php } ?>

							if ($('#oko-shed-custom-div').length === 0) {
								parrentShipping.append('<div id="oko-shed-custom-div"></div>');

								const myHeaders = new Headers();
								myHeaders.append("Accept", "application/json");
								myHeaders.append("Content-Type", "application/json");
								const requestOptions = {
									method: "GET",
									headers: myHeaders,
									redirect: "follow"
								};

								fetch("/wp-json/wp/v2/okoskabet/sheds?zip=" + addressFilled, requestOptions)
									.then((response) => response.json())
									.then((result) => {
										$('#oko-shed-custom-div').html('<select name="okoLocations"  id="locationsDropdown" style="width: 100%; margin-top: 20px; margin-bottom: 20px;"></select><select name="okoDeliveryDates"  id="deliveryDatesDropdown" style="width: 100%; margin-bottom: 20px;"></select><div id="map" style="width: 100%; height: 450px; margin-bottom: 20px;"></div><div className="okoButtonModal"><a href="#" class="button okoButtonModalDone">Done</a></div>');
										const dropdown = $('#locationsDropdown');
										if (dropdown) {
											result.results.sheds.map(location => {
												let delivery_dates = '';
												location.delivery_dates.map(delivery_date => {
													delivery_dates = delivery_dates + delivery_date + ', ';
												});
												console.log("Delivery Dates", delivery_dates)

												$(dropdown).append('<option data-dates="' + delivery_dates + '" value="' + location.id + '">' + location.name + '</option>');
											});
										}
										$('#locationsDropdown').trigger('change');
										currentMap = result.results;
										initMaps(result.results);

									})
									.catch((error) => console.error(error));
							}
						} else {
							if (currentShipping.val() === 'hey_okoskabet_shipping_home') {

								if ($('#oko-local-custom-div').length === 0) {
									parrentShipping.append('<div id="oko-local-custom-div"></div>');

									const myHeaders = new Headers();
									myHeaders.append("Accept", "application/json");
									myHeaders.append("Content-Type", "application/json");
									const requestOptions = {
										method: "GET",
										headers: myHeaders,
										redirect: "follow"
									};

									fetch("/wp-json/wp/v2/okoskabet/home_delivery?zip=" + addressFilled, requestOptions)
										.then((response) => response.json())
										.then((result) => {
											$('#oko-local-custom-div').html('<select name="okoDeliveryDates"  id="deliveryDatesDropdown" style="width: 100%; margin-top: 20px; margin-bottom: 20px;"></select>');
											const dropdown = $('#deliveryDatesDropdown');
											if (dropdown) {
												result.results.delivery_dates.map(deliveryDate => {
													$(dropdown).append('<option value="' + deliveryDate + '">' + deliveryDate + '</option>');
												});
											}
											$('#deliveryDatesDropdown').trigger('change');

										})
										.catch((error) => console.error(error));
								}
							} else {}

						}
					}
				});
			});


		});
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
				$this->method_description = __('Delivery to økoskabet', O_TEXTDOMAIN); // Description shown in admin
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

				$myCost = $this->cost;

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
							'label' => $this->title . ' (' . esc_html__('Discount', O_TEXTDOMAIN) . ')',
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

				$myCost = $this->cost;

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
							'label' => $this->title . ' (' . esc_html__('Discount', O_TEXTDOMAIN) . ')',
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
	echo '' . esc_html__('Økoskabet SHED ID') . ': ' . esc_html($order->get_meta('_billing_okoskabet_shed_id', true)) . '';
	echo '' . esc_html__('Økoskabet Delivery Date') . ': ' . esc_html($order->get_meta('_billing_okoskabet_delivery_date', true)) . '';
}

add_action('woocommerce_thankyou', 'hey_after_order_placed', 10, 1);

/**
 * Custom function to be called after an order is placed.
 *
 * @param int $order_id The order ID.
 */
function hey_after_order_placed($order_id)
{
	if (!$order_id) {
		return;
	}

	// Get the order object
	$order = wc_get_order($order_id);

	if (empty($order)) {
		return;
	}

	$order_shed = $order->get_meta('_billing_okoskabet_shed_id', true);
	$order_delivery_date = $order->get_meta('_billing_okoskabet_delivery_date', true);

	if (empty($order_delivery_date)) {
		return;
	}

	$settings = get_option(O_TEXTDOMAIN . '-settings');

	if (!empty($settings['_api_key'])) {

		$url = "https://okoskabet.dk/api/v1/shipments/";

		$data = !empty($order_shed) ? [
			'shipment_reference' => (string) $order_id,
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
			'shipment_reference' => (string) $order_id,
			'customer' => [
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
				'phone' => $order->get_billing_phone(),
				'email' => $order->get_billing_email(),
			],
			'home_delivery' => [
				'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
				'street_name' => $order->get_shipping_address_1(),
				'city' => $order->get_shipping_city(),
				'postal_code' => $order->get_shipping_postcode(),
				'latitude' => '55.000',
				'longitude' => '12.000',
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

		if ($http_code != 201) {
			// Handle error response
			throw new Exception('Error: ' . $response);
		}
		curl_close($ch);

		$shipment = json_decode($response, true);
		// You can also get other order details, for example:
		/*
			$order_data = $order->get_data(); // Get the order data in an arr

		foreach ($order->get_items() as $item_id => $item) {
			// $item is an instance of WC_Order_Item_Product
			$product_id = $item->get_product_id(); // Get the product ID
			$product_name = $item->get_name(); // Get the product name
			$quantity = $item->get_quantity(); // Get the quantity ordered
			$total = $item->get_total(); // Get the line item total




			// You can now perform your desired actions with the item details
			// For example, logging item details
			error_log('Product ID: ' . $product_id);
			error_log('Product Name: ' . $product_name);
			error_log('Quantity: ' . $quantity);
			error_log('Total: ' . $total);

			// If you need to get the product object
			$product = $item->get_product();
			if ($product) {
				// Perform actions with the product object
				// For example, getting the SKU
				$product_sku = $product->get_sku();
				error_log('Product SKU: ' . $product_sku);
			}
		}*/
	}


	// Perform any other actions you need with the order object
	// ...
}
