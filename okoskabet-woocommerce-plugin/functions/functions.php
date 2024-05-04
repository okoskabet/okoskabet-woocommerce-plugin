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

add_action('woocommerce_review_order_before_submit', 'custom_content_for_custom_shipping_checkout');

function enqueue_google_maps_api()
{
	if (is_checkout()) {  // Check if it's the WooCommerce checkout page
		// Replace 'your_api_key_here' with your actual Google Maps API key
		wp_enqueue_script('google-maps', 'https://maps.googleapis.com/maps/api/js?key=AIzaSyCWrL_MNb7V_GhGOWVfaLhObKk7njUQ8rQ', array(), null, true);
	}
}

add_action('wp_enqueue_scripts', 'enqueue_google_maps_api');

function custom_content_for_custom_shipping_checkout()
{
?>
	<script type="text/javascript">
		jQuery(function($) {
			$(document).ready(function() {

				function initMaps(locations) {

					var mapOptions = {
						center: new google.maps.LatLng(locations[0].gps[0], locations[0].gps[1]), // Default center
						mapTypeControl: false,
						streetViewControl: false,
						zoom: 3

					};
					var map = new google.maps.Map(document.getElementById('maps'), mapOptions);
					// Bounds for centering the map
					var bounds = new google.maps.LatLngBounds();

					// Place markers on the map
					locations.forEach(function(location) {
						var marker = new google.maps.Marker({
							position: {
								lat: location.gps[0],
								lng: location.gps[1]
							},
							map: map,
							title: location.name
						});

						// Extend the bounds to include each marker's position
						bounds.extend(marker.position);
					});

					// Now fit the map to the newly inclusive bounds
					map.fitBounds(bounds);
				}

				let addressFilled = $('#billing_postcode').val();
				$('body').trigger('update_checkout');

				$(document).on('change', '#billing_postcode', function() {
					addressFilled = $('#billing_postcode').val();
					if (addressFilled) {
						$('body').trigger('update_checkout');
					}
				});

				$(document).on('change', '#locationsDropdown', function() {
					let location = $(this).val();
					$('#billing_okoskabet_shed_id').val(location);
					localStorage.setItem("okoSkabetId", location);
				});

				$(document).on('updated_checkout', function() {
					addressFilled = $('#billing_postcode').val();
					if (addressFilled) {
						const currentShipping = $('input[name="shipping_method[0]"]:checked');
						const parrentShipping = currentShipping.parent();

						if (currentShipping.val() === 'hey_okoskabet_shipping_shed') {
							parrentShipping.append('<div id="custom-div"></div>');

							const customDiv = parrentShipping.find('#custom-div');

							if (customDiv.is(':empty')) {

								const myHeaders = new Headers();
								myHeaders.append("Accept", "application/json");
								myHeaders.append("Content-Type", "application/json");
								const requestOptions = {
									method: "GET",
									headers: myHeaders,
									redirect: "follow"
								};

								fetch("https://testshop.stage.heyrobot.com/wp-json/wp/v2/okoskabet/sheds?zip=" + addressFilled, requestOptions)
									.then((response) => response.json())
									.then((result) => {
										customDiv.html('<select name="okoLocations"  id="locationsDropdown" style="width: 100%; margin-top: 20px; margin-bottom: 20px;"></select><div id="maps" style="width: 100%; height: 300px; margin-bottom: 20px;"></div>');
										const dropdown = $('#locationsDropdown');
										if (dropdown) {
											result.results.map(location => {
												$(dropdown).append('<option value="' + location.id + '">' + location.name + '</option>');
											});

										}
										initMaps(result.results);

									})
									.catch((error) => console.error(error));
							}
						} else {
							$('#custom-div').remove();
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
	// $method contains available shipping methods
	$methods['hey_okoskabet_shipping_shed'] = 'WC_Hey_Okoskabet_Shipping_Method_Shed';
	return $methods;
}

function hey_okoskabet_shipping_method_shed_init()
{
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
	// $method contains available shipping methods
	$methods['hey_okoskabet_shipping_home'] = 'WC_Hey_Okoskabet_Shipping_Method_Home';
	return $methods;
}

function hey_okoskabet_shipping_method_home_init()
{
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

	return $fields;
}

/**
 * Display field value on the order edit page
 */
add_action('woocommerce_admin_order_data_after_shipping_address', 'my_custom_checkout_field_display_admin_order_meta', 10, 1);

function my_custom_checkout_field_display_admin_order_meta($order)
{
	echo '' . esc_html__('Økoskabet SHED ID') . ': ' . esc_html($order->get_meta('_billing_okoskabet_shed_id', true)) . '';
}
