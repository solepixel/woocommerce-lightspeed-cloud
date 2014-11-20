<?php
/**
 * WooCommerce LightSpeed Settings
 *
 * @author 		Brian DiChiara
 * @category 	Admin
 * @version     2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'WC_Settings_Lightspeed_Cloud' ) ) :

/**
 * WC_Settings_Lightspeed
 */
class WC_Settings_Lightspeed_Cloud extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'lightspeed-cloud';
		$this->label = __( 'LightSpeed Cloud', 'woocommerce' );

		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
		add_action( 'woocommerce_sections_' . $this->id, array( $this, 'output_sections' ) );
		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
	}


	/**
	 * Get settings array
	 *
	 * @return array
	 */
	public function get_settings() {
		global $wc_lightspeed_cloud;

		// shop option
		$shop_options = array( '' => __( 'API Key and Account ID required to setup Shop.', 'wclsc' ) );
		$shops = $wc_lightspeed_cloud->lightspeed->get_shops();

		if( $shops && count( $shops ) > 0 ){
			$shop_options = array( '' => __( 'Please select a shop.', 'wclsc' ) );
			foreach( $shops as $shop ){
				$shop_options[ $shop->shopID ] = $shop->name;
			}
		}

		// employee option
		$employee_options = array( '' => __( 'API Key and Account ID required to setup Employee.', 'wclsc' ) );
		$employees = $wc_lightspeed_cloud->lightspeed->get_employees();

		if( $employees && count( $employees ) > 0 ){
			$employee_options = array( '' => __( 'Please select an employee.', 'wclsc' ) );
			foreach( $employees as $employee ){
				$employee_options[ $employee->employeeID ] = trim( $employee->firstName . ' ' . $employee->lastName );
			}
		}

		// register option
		$register_options = array( '' => __( 'API Key and Account ID required to setup Register.', 'wclsc' ) );
		$registers = $wc_lightspeed_cloud->lightspeed->get_registers();

		if( $registers && count( $registers ) > 0 ){
			$register_options = array( '' => __( 'Please select a register.', 'wclsc' ) );
			foreach( $registers as $register ){
				$register_options[ $register->registerID ] = $register->name;
			}
		}

		// customer type options
		$cust_type_options = array( '' => __( 'API Key and Account ID required to setup Customer Type.', 'wclsc' ) );
		$customer_types = $wc_lightspeed_cloud->lightspeed->get_customer_types();

		if( $customer_types && count( $customer_types ) > 0 ){
			$cust_type_options = array( '' => __( 'Please select a customer type.', 'wclsc' ) );
			foreach( $customer_types as $customer_type ){
				$cust_type_options[ $customer_type->customerTypeID ] = $customer_type->name;
			}
		}

		$lightspeed_cloud_settings = array(

			array( # section start
				'title' => __( 'API Credentials', 'woocommerce' ),
				'type' => 'title',
				'desc' => __( 'Credentials needed to connect to LightSpeed Cloud.', 'woocommerce' ),
				'id' => 'api_credentials'
			),

			array(
				'title' => __( 'API Key', 'woocommerce' ),
				'desc' 		=> '',
				'id' 		=> WCLSC_OPT_PREFIX . 'api_key',
				'type' 		=> 'text',
				'css' 		=> 'min-width:550px;',
				'autoload'  => false
			),
			/*
			# This field is not used. It's always "apikey". This can stay here in case it starts being used by the API
			array(
				'title' => __( 'API Password', 'woocommerce' ),
				'desc' 		=> 'The default is always "apikey". Uncommon to change this.',
				'id' 		=> WCLSC_OPT_PREFIX . 'api_password',
				'type' 		=> 'text',
				'default'	=> 'apikey',
				'autoload'  => false
			),
			*/
			array(
				'title' => __( 'Account ID', 'woocommerce' ),
				'desc' 		=> '',
				'id' 		=> WCLSC_OPT_PREFIX . 'account_id',
				'type' 		=> 'text',
				'default'	=> '',
				'autoload'  => false
			),

			array(
				'title' => __( 'Default Shop', 'woocommerce' ),
				'desc' 		=> __( 'Used for maintaining stock/inventory.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'shop_id',
				'type' 		=> 'select',
				'options'	=> $shop_options,
				'default' => '',
				'autoload'  => false
			),

			array( 'type' => 'sectionend', 'id' => 'api_credentials' ),

			array( # section start
				'title' => __( 'LightSpeed Sync Options', 'woocommerce' ),
				'type' => 'title',
				'desc' => '',
				'id' => 'lightspeed_options'
			),

			array(
				'title' => __( 'Default Customer Contact Address', 'woocommerce' ),
				'desc' 		=> '',
				'id' 		=> WCLSC_OPT_PREFIX . 'customer_contact_address',
				'type' 		=> 'radio',
				'options'	=> array(
					'none' => __( 'None', 'wclsc' ),
					'billing' => __( 'Billing Address', 'wclsc' ),
					'shipping' => __( 'Shipping Address', 'wclsc' )
				),
				'default' => 'none',
				'autoload'  => false
			),

			array(
				'title' => __( 'Employee', 'wclsc' ),
				'desc' 		=> __( 'Referenced for each sale.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'employee_id',
				'type' 		=> 'select',
				'options'	=> $employee_options,
				'default' => '',
				'autoload'  => false
			),

			array(
				'title' => __( 'Register', 'wclsc' ),
				'desc' 		=> __( 'Referenced for each sale.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'register_id',
				'type' 		=> 'select',
				'options'	=> $register_options,
				'default' => '',
				'autoload'  => false
			),

			array(
				'title' => __( 'Default Customer Type', 'wclsc' ),
				'desc' 		=> __( 'Associated with each customer.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'customer_type',
				'type' 		=> 'select',
				'options'	=> $cust_type_options,
				'default' => '',
				'autoload'  => false
			),

			array( 'type' => 'sectionend', 'id' => 'lightspeed_options' ),

		);

		return apply_filters('woocommerce_lightspeed_cloud_settings', $lightspeed_cloud_settings ); // End lightspeed settings
	}
}

endif;

return new WC_Settings_Lightspeed_Cloud();