<?php

/**
 * okoskabet_woocommerce_plugin
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

function hey_okoskabet_shipping_method_init()
{
	if (!class_exists('WC_Hey_Okoskabet_Shipping_Method')) {
		class WC_Hey_Okoskabet_Shipping_Method extends WC_Shipping_Method
		{
			public function __construct()
			{
				$this->id                 = 'hey_okoskabet_shipping'; // ID for the shipping method.
				$this->method_title       = __('Økoskabet', O_TEXTDOMAIN); // Title shown in admin
				$this->method_description = __('Delivery to Økoskabet', O_TEXTDOMAIN); // Description shown in admin
				$this->enabled            = "yes"; // Default state
				$this->title              = __('Delivery to Økoskabet', O_TEXTDOMAIN); // Title shown to user

				$this->init();
			}

			function init()
			{
				// Load the settings.
				$this->init_settings();
				$this->init_form_fields();

				// Save settings in admin if you have any defined
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			public function init_form_fields()
			{
				$form_fields = array(
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
						'default'     => esc_html__('', O_TEXTDOMAIN),
						'desc_tip'    => true
					)
				);
				$this->form_fields = $form_fields;
			}

			public function calculate_shipping($package = array())
			{
				$rate = array(
					'id'       => $this->id,
					'label'    => $this->title,
					'cost'     => '10.00',
					'calc_tax' => 'per_item'
				);

				// Register the rate
				$this->add_rate($rate);
			}
		}
	}
}

add_action('woocommerce_shipping_init', 'hey_okoskabet_shipping_method_init');

function add_hey_okoskabet_shipping_method($methods)
{
	$methods['hey_okoskabet_shipping'] = 'WC_Hey_Okoskabet_Shipping_Method';
	return $methods;
}

add_filter('woocommerce_shipping_methods', 'add_hey_okoskabet_shipping_method');
