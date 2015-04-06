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

	var $access_token;

	/**
	 * Constructor.
	 */
	public function __construct() {
		/**
		 * Setup WooCommerce Settings Page
		 */
		$this->id    = 'lightspeed-cloud';
		$this->label = __( 'LightSpeed Cloud', 'woocommerce' );

		$this->client_id = 'solepixelwoocoommercelightspeedcloudwordpressplugin'; // 431
		$this->client_secret = 'y46FqectFIiu';

		$this->access_token = get_option( WCLSC_OPT_PREFIX . 'access_token' );

		/**
		 * Add Tab to WooCommerce Settings Page
		 */
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );

		/**
		 * Display Settings
		 */
		add_action( 'woocommerce_sections_' . $this->id, array( $this, 'output_sections' ) );
		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'process_custom_settings' ) );


		/**
		 * Display Custom OAuth field
		 */
		add_action( 'woocommerce_admin_field_wclsc_oauth', array( $this, 'oauth_field' ) );
		add_action( 'woocommerce_admin_field_wclsc_oauth_connected', array( $this, 'oauth_connected_field' ) );

		parent::__construct();
	}

	/**
	 * Pre Authorization Field/Button
	 * @param  [type] $value [description]
	 * @return [type]        [description]
	 */
	function oauth_field( $value ){
		// Registration URL: https://cloud.merchantos.com/oauth/register.php
		$redirect = urlencode( admin_url( '?page=wc-settings&tab=' . $this->id ) );
		$permissions = array(
			'employee:customers',
			'employee:inventory',
			'employee:register'
		);
		$scope = urlencode( implode( ' ', $permissions ) );
		$auth_url = 'https://cloud.merchantos.com/oauth/authorize.php?response_type=code&client_id=' . $this->client_id . '&scope=' . $scope . '&wclsc-source=' . $redirect;

		$tip = isset( $value['tip'] ) ? $value['tip'] : '';
		$description = isset( $value['description'] ) ? $value['description'] : '';

		echo '<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . '</label>
				' . $tip . '
			</th>
			<td class="forminp forminp-' . sanitize_title( $value['type'] ) . '">
				<a id="wclsc_oauth" data-width="479" data-height="405" class="button" href="' . $auth_url . '">' . __( 'Authenticate with your LightSpeed account', 'wclsc' ) . '</a>
				<span id="' . WCLSC_OPT_PREFIX . 'oauth_code" style="display:none;">' . __( 'Paste your code here:', 'wclsc' ) . ' <input type="text" name="' . WCLSC_OPT_PREFIX .'oauth_code" value="" style="width:375px;" /></span>
				' . $description . '
			</td>
		</tr>';
	}

	function oauth_connected_field( $value ){
		$tip = isset( $value['tip'] ) ? $value['tip'] : '';
		$description = isset( $value['description'] ) ? $value['description'] : '';

		echo '<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="' . esc_attr( $value['id'] ) . '">' . esc_html( $value['title'] ) . '</label>
				' . $tip . '
			</th>
			<td class="forminp forminp-' . sanitize_title( $value['type'] ) . '">
				<span style="line-height:2;">' . __( 'Authenticated!', 'wclsc' ) . '</span> &nbsp; <a id="wclsc_oauth_disconnect" data-width="479" data-height="405" class="button" href="#wclsc-oauth-disconnect">' . __( 'Disconnect from LightSpeed', 'wclsc' ) . '</a>
				' . $description . '
			</td>
		</tr>';
	}

	function process_custom_settings(){
		if( isset( $_POST[ WCLSC_OPT_PREFIX . 'oauth_code' ] ) ){
			$code = sanitize_text_field( $_POST[ WCLSC_OPT_PREFIX . 'oauth_code' ] );

			if( $code ){

				$url = 'https://cloud.merchantos.com/oauth/access_token.php';

				$args = array(
					'client_id' => $this->client_id,
					'client_secret' => $this->client_secret,
					'code' => $code,
					'grant_type' => 'authorization_code',
					'redirect_uri' => 'http://api.briandichiara.com/woocommerce-lightspeed-cloud/'
				);

				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_URL, $url );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $args );

				$response = curl_exec( $ch );

				$json = json_decode( $response );

				if( $json ){
					if( isset( $json->error ) ){
						global $woocommerce;
						$logger = class_exists( 'WC_Logger' ) ? new WC_Logger() : $woocommerce->logger();
						$logger->add( 'wclsc', $json->error . ': ' . $json->error_description );
					} else {
						/**
						 * public 'access_token' => string '9276ac4ec14a16033bb4300b564640c0a51883a5' (length=40)
						 * public 'expires_in' => int 3600
						 * public 'token_type' => string 'bearer' (length=6)
						 * public 'scope' => string 'employee:customers employee:inventory employee:register systemuserid:231323' (length=75)
						 */
						$access_token = $json->access_token;
						update_option( WCLSC_OPT_PREFIX . 'access_token', $access_token );
						$this->access_token = $access_token;
					}
				}
			}
		}
	}

	/**
	 * Get settings array
	 *
	 * @return array
	 */
	public function get_settings() {
		global $wc_lightspeed_cloud;

		$sync_status = get_transient( WCLSC_META_PREFIX . 'sync_status' );
		$sync = $wc_lightspeed_cloud->get_sync_status( $sync_status );
		$class = 'wclsc-sync';
		$buttons = '<button class="button wclsc-start-sync">' . __( 'Sync Products Now', 'wclsc' ) . '</button><br />';
		$buttons .= '<button class="button wclsc-start-inventory-sync">' . __( 'Sync Product Inventory', 'wclsc' ) . '</button>';

		if( $sync && ! isset( $sync_status['completed'] ) && ! isset( $sync_status['cancelled'] ) )
			$class .= ' syncing';
		elseif( isset( $sync_status['completed'] ) || isset( $sync_status['cancelled'] ) )
			$sync .= $buttons;
		else
			$sync = $buttons;

		$sync_status = '<div class="' . $class . '">' . $sync . '</div>';

		// shop option
		$shop_options = array( '' => __( 'API Key and Account ID required to setup Shop.', 'wclsc' ) );
		$shops = $wc_lightspeed_cloud->lightspeed->get_shops();
		$shop_default = NULL;

		if( $shops && count( $shops ) > 0 ){
			$shop_options = array( '' => __( 'Please select a shop.', 'wclsc' ) );
			foreach( $shops as $shop ){
				$shop_options[ $shop->shopID ] = $shop->name;
			}
			if( count( $shops ) == 1 ){
				$shop_default = $shops[0]->shopID;
			}
		}

		// employee option
		$employee_options = array( '' => __( 'API Key and Account ID required to setup Employee.', 'wclsc' ) );
		$employees = $wc_lightspeed_cloud->lightspeed->get_employees();
		$employee_default = NULL;

		if( $employees && count( $employees ) > 0 ){
			$employee_options = array( '' => __( 'Please select an employee.', 'wclsc' ) );
			foreach( $employees as $employee ){
				$employee_options[ $employee->employeeID ] = trim( $employee->firstName . ' ' . $employee->lastName );
			}
			if( count( $employees ) == 1 ){
				$employee_default = $employees[0]->employeeID;
			}
		}

		// register option
		$register_options = array( '' => __( 'API Key and Account ID required to setup Register.', 'wclsc' ) );
		$registers = $wc_lightspeed_cloud->lightspeed->get_registers();
		$register_default = NULL;

		if( $registers && count( $registers ) > 0 ){
			$register_options = array( '' => __( 'Please select a register.', 'wclsc' ) );
			foreach( $registers as $register ){
				$register_options[ $register->registerID ] = $register->name;
			}
			if( count( $registers ) ){
				$register_default = $registers[0]->registerID;
			}
		}

		// customer type options
		$cust_type_options = array( '' => __( 'API Key and Account ID required to setup Customer Type.', 'wclsc' ) );
		$customer_types = $wc_lightspeed_cloud->lightspeed->get_customer_types();
		$customer_type_default = NULL;

		if( $customer_types && count( $customer_types ) > 0 ){
			$cust_type_options = array( '' => __( 'Please select a customer type.', 'wclsc' ) );
			foreach( $customer_types as $customer_type ){
				$cust_type_options[ $customer_type->customerTypeID ] = $customer_type->name;
			}
			if( count( $customer_types ) ){
				$customer_type_default = $customer_types[0]->customerTypeID;
			}
		}

		// product sku options
		$sku_options = array(
			'' => __( 'Disable Product Sync', 'wclsc' ),
			'customSku' => __( 'Custom SKU', 'wclsc' ),
			'upc' => __( 'UPC', 'wclsc' ),
			'ean' => __( 'EAN', 'wclsc' ),
			'manufacturerSku' => __( 'Manufacturer\'s SKU', 'wclsc' ),
			'systemSku' => __( 'System ID', 'wclsc' )
		);

		// product sync options
		$sync_options = array(
			'' => __( 'Disable Product Sync', 'wclsc' ),
			'ls2wc' => __( 'LightSpeed &rarr; WooCommerce', 'wclsc' ),
			'wc2ls' => __( 'Woocommerce &rarr; LightSpeed', 'wclsc' )
		);

		$sync_default = 'ls2wc';

		$oauth_setting = array(
			'title' => __( 'oAuth', 'woocommerce' ),
			'desc' 		=> __( 'Connect to your LightSpeed Cloud Account', 'wclsc' ),
			'id' 		=> WCLSC_OPT_PREFIX . 'oauth',
			'type' 		=> 'wclsc_oauth',
			'autoload'  => false
		);

		if( $this->access_token ){
			$oauth_setting = array(
				'title' => __( 'oAuth', 'woocommerce' ),
				'desc' 		=> __( 'Connect to your LightSpeed Cloud Account', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'oauth_connected',
				'type' 		=> 'wclsc_oauth_connected',
				'autoload'  => false
			);
		}

		$lightspeed_cloud_settings = array(

			array( # section start
				'title' => __( 'API Authentication', 'woocommerce' ),
				'type' => 'title',
				'desc' => __( 'Connect to LightSpeed Cloud with oAuth or a manually entered API Key.', 'woocommerce' ),
				'id' => 'api_authentication'
			),

			$oauth_setting,

			array(
				'title' => __( 'Manually enter your API Key', 'woocommerce' ),
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

			array( 'type' => 'sectionend', 'id' => 'api_authentication' ),

			array( # section start
				'title' => __( 'Base Shop Settings', 'woocommerce' ),
				'type' => 'title',
				'desc' => '',
				'id' => 'base_settings'
			),

			array(
				'title' => __( 'Default Shop', 'woocommerce' ),
				'desc' 		=> __( 'Used for maintaining stock/inventory.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'shop_id',
				'type' 		=> 'select',
				'options'	=> $shop_options,
				'default' => $shop_default,
				'autoload'  => false
			),

			array(
				'title' => __( 'Employee', 'wclsc' ),
				'desc' 		=> __( 'Referenced for each sale.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'employee_id',
				'type' 		=> 'select',
				'options'	=> $employee_options,
				'default' => $employee_default,
				'autoload'  => false
			),

			array(
				'title' => __( 'Register', 'wclsc' ),
				'desc' 		=> __( 'Referenced for each sale.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'register_id',
				'type' 		=> 'select',
				'options'	=> $register_options,
				'default' => $register_default,
				'autoload'  => false
			),

			array(
				'title' => __( 'Default Customer Type', 'wclsc' ),
				'desc' 		=> __( 'Associated with each customer.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'customer_type',
				'type' 		=> 'select',
				'options'	=> $cust_type_options,
				'default' => $customer_type_default,
				'autoload'  => false
			),

			array( 'type' => 'sectionend', 'id' => 'base_settings' ),

			array( # section start
				'title' => __( 'Product Sync Options', 'woocommerce' ),
				'type' => 'title',
				'desc' => $sync_status,
				'id' => 'product_sync_options'
			),

			array(
				'title' => __( 'Sync Direction', 'wclsc' ),
				'desc' 		=> __( 'Select the direction in which to sync products', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'sync_direction',
				'type' 		=> 'select',
				'options'	=> $sync_options,
				'default' => $sync_default,
				'autoload'  => false
			),

			array(
				'title' => __( 'Amount per request', 'woocommerce' ),
				'desc' 		=> 'Product sync could take a long time. Each request is performed in sections. Adjust this number of your sync is timing out.',
				'id' 		=> WCLSC_OPT_PREFIX . 'sync_amount',
				'type' 		=> 'number',
				'default'	=> '100',
				'css' 		=> 'width:80px;',
				'autoload'  => false
			),

			array(
				'title' => __( 'SKU Field', 'wclsc' ),
				'desc' 		=> __( 'Select the field used to sync to WooCommerce SKU field.', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'sku_field',
				'type' 		=> 'select',
				'options'	=> $sku_options,
				'default' => '',
				'autoload'  => false
			),

			array(
				'title' => __( 'Sync Categories as', 'wclsc' ),
				'desc' 		=> __( 'Choose how LightSpeed categories should be synced', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'sync_categories',
				'type' 		=> 'select',
				'options'	=> array(
						'' => __( 'Disable Category Sync', 'wclsc' ),
						'categories' => __( 'Categories', 'wclsc' ),
						'tags' => __( 'Tags', 'wclsc' )
					),
				'default' => 'categories',
				'autoload'  => false
			),

			array(
				'title' => __( 'Sync Tags as', 'wclsc' ),
				'desc' 		=> __( 'Choose how LightSpeed tags should be synced', 'wclsc' ),
				'id' 		=> WCLSC_OPT_PREFIX . 'sync_tags',
				'type' 		=> 'select',
				'options'	=> array(
						'' => __( 'Disable Tag Sync', 'wclsc' ),
						'tags' => __( 'Tags', 'wclsc' ),
						'categories' => __( 'Categories', 'wclsc' )
					),
				'default' => 'tags',
				'autoload'  => false
			),

			array( 'type' => 'sectionend', 'id' => 'product_sync_options' ),

			array( # section start
				'title' => __( 'Customer Sync Options', 'woocommerce' ),
				'type' => 'title',
				'desc' => '',
				'id' => 'customer_sync_options'
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

			array( 'type' => 'sectionend', 'id' => 'customer_sync_options' ),

		);

		return apply_filters( 'woocommerce_lightspeed_cloud_settings', $lightspeed_cloud_settings ); // End lightspeed settings
	}
}

endif;

return new WC_Settings_Lightspeed_Cloud();
