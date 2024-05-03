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
		okoLocations = [{
				"id": "34d0e004-41db-4340-9fca-3ba7c299373d",
				"name": "City 2 - Gul indgang",
				"gps": [55.644087, 12.274293]
			},
			{
				"id": "8c9c4631-4db3-4151-9ee6-f68d6b90c90f",
				"name": "Frame House Dragør",
				"gps": [55.603768, 12.661167]
			},
			{
				"id": "a4528106-8388-4273-8031-39c74af975c9",
				"name": "FRB.C",
				"gps": [55.682128, 12.53136]
			},
		]


		jQuery(function($) {
			$(document).ready(function() {
				let addressFilled = $('#billing_postcode').val();
				$('body').trigger('update_checkout');

				$(document).on('change', '#billing_postcode', function() {
					addressFilled = $('#billing_postcode').val();
					if (addressFilled) {
						$('body').trigger('update_checkout');
					}
				});

				$(document.body).on('updated_checkout', function() {
					$('#custom-div').remove();

					if (addressFilled) {
						if ($('input[name="shipping_method[0]"]:checked').val() === 'hey_okoskabet_shipping_shed') {
							if ($('#custom-div').length === 0) {
								async function loadSheds() {
									const myHeaders = new Headers();
									myHeaders.append("authorization", "317B6B7D2A01C154");
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
											console.log(result)


											function populateDropdown() {
												const dropdown = document.getElementById('locationsDropdown');
												result.results.map(location => {
													const option = document.createElement('option');
													option.value = location.id;
													option.textContent = location.name;
													dropdown.appendChild(option);
												});
											}

											// Call the function to populate the dropdown
											populateDropdown();
											initMaps(result.results);

										})
										.catch((error) => console.error(error));
								}
								$('input[name="shipping_method[0]"]:checked').parent().append('<div id="custom-div"><select id="locationsDropdown" style="width: 100%; margin-top: 20px; margin-bottom: 20px;"></select><div id="maps" style="width: 100%; height: 300px; margin-bottom: 20px;"></div></div>');
								loadSheds();

							}
						} else {
							$('#custom-div').remove();
						}
					}
				});
			});


		});

		function initMaps(locations) {

			var mapOptions = {
				center: new google.maps.LatLng(locations[0].gps[0], locations[0].gps[1]), // Default center
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
				$this->method_title       = __('Økoskabet Delivery', O_TEXTDOMAIN); // Title shown in admin
				$this->method_description = __('Delivery to økoskabet', O_TEXTDOMAIN); // Description shown in admin
				$this->supports              = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);
				$this->instance_form_fields = array(
					'enabled' => array(
						'title'   => esc_html__('Enable/Disable', O_TEXTDOMAIN),
						'type'    => 'checkbox',
						'label'   => esc_html__('Enable this shipping method', O_TEXTDOMAIN),
						'default' => 'no'
					),
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
						'title'       => esc_html__('Cost', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the method cost', O_TEXTDOMAIN),
						'default'     => esc_html__('49', O_TEXTDOMAIN),
						'desc_tip'    => true
					)
				);

				$this->enabled              = $this->get_option('enabled');
				$this->cost              = $this->get_option('cost');
				$this->title                = $this->get_option('title');
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * calculate_shipping function.
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array())
			{
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
				$this->method_title       = __('Økoskabet Home Delivery', O_TEXTDOMAIN); // Title shown in admin
				$this->method_description = __('Delivery to your home', O_TEXTDOMAIN); // Description shown in admin
				$this->supports              = array(
					'shipping-zones',
					'instance-settings',
				);
				$this->instance_form_fields = array(
					'enabled' => array(
						'title'   => esc_html__('Enable/Disable', O_TEXTDOMAIN),
						'type'    => 'checkbox',
						'label'   => esc_html__('Enable this shipping method', O_TEXTDOMAIN),
						'default' => 'no'
					),
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
						'title'       => esc_html__('Cost', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the method cost', O_TEXTDOMAIN),
						'default'     => esc_html__('49', O_TEXTDOMAIN),
						'desc_tip'    => true
					)
				);

				$this->enabled              = $this->get_option('enabled');
				$this->cost              = $this->get_option('cost');
				$this->title                = $this->get_option('title');
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * calculate_shipping function.
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array())
			{
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
