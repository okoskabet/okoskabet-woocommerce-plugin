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


add_action('woocommerce_review_order_before_submit', 'hey_after_shipping');
function hey_after_shipping()
{
	echo '<h4>Test</h4>';
}
function hey_output_css()
{
?>
	<style>
		label[for="radio-control-0-hey_okoskabet_shipping"] {
			background: #f00;
			padding: 20px;
		}
	</style>
<?
}
function o_get_settings()
{
	return apply_filters('o_get_settings', get_option(O_TEXTDOMAIN . '-settings'));
}

add_action('woocommerce_review_order_before_submit', 'custom_content_for_custom_shipping_checkout');

function custom_content_for_custom_shipping_checkout()
{
?>
	<div id="custom_shipping_message"></div> <!-- Placeholder for dynamic content -->
	<script type="text/javascript">
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
					if (addressFilled) {
						if ($('input[name="shipping_method[0]"]:checked').val() === 'hey_okoskabet_shipping_shed') {
							if ($('#custom-div').length === 0) {
								async function loadSheds() {
									const myHeaders = new Headers();
									myHeaders.append("authorization", "317B6B7D2A01C154");

									const requestOptions = {
										method: "GET",
										headers: myHeaders,
										redirect: "follow"
									};

									fetch("https://testshop.stage.heyrobot.com/wp-json/wp/v2/okoskabet/sheds", requestOptions)
										.then((response) => response.text())
										.then((result) => console.log(result))
										.catch((error) => console.error(error));
									$('input[name="shipping_method[0]"]:checked').parent().append('<div id="custom-div">Special information related to selected shipping method</div>');
								}
								loadSheds();

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
