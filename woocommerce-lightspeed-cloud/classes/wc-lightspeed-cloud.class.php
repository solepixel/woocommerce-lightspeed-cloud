<?php

if( class_exists( 'WC_Lightspeed_Cloud' ) ) return;

if( class_exists( 'Lightspeed_Cloud_API' ) ) return;

/**
 * WC_Lightspeed_Cloud
 *
 * Base WordPress plugin class for WooCommerce LightSpeed Cloud
 *
 * @package WooCommerce LightSpeed Cloud
 * @copyright 2014 Brian DiChiara
 * @since 1.0.0
 */
class WC_Lightspeed_Cloud {

	/**
	 * Instance of Lightspeed_Cloud_API
	 * @var object
	 */
	var $lightspeed;

	/**
	 * Individual Order Log
	 * @var array
	 */
	var $order_log;

	/**
	 * Product Log
	 * @var array
	 */
	var $product_log = array();

	/**
	 * Class constructor
	 */
	function __construct(){
		$this->lightspeed = new Lightspeed_Cloud_API();
	}

	/**
	 * Add necessary hooks
	 * @return void
	 */
	function initialize(){

		// base plugin actions
		add_action( 'init', array( $this, '_init' ) );

	}

	/**
	 * Init actions and filters
	 * action: init
	 * @return void
	 */
	function _init(){

		// Admin Ajax function to lookup account id and test API
		add_action( 'wp_ajax_wclsc_lookup_account_id', array( $this, 'get_lightspeed_account_id' ) );

		// Admin Ajax function to clear logs
		add_action( 'wp_ajax_wclsc_clear_error_log', array( $this, 'clear_error_log' ) );

		// store customer information in LightSpeed
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'sync_lightspeed_customer' ), 10, 2 );

			// store guest information in LightSpeed
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'sync_lightspeed_guest' ), 10, 2 );

		// store order item information in LightSpeed
		add_action( 'woocommerce_add_order_item_meta', array( $this, 'sync_lightspeed_product' ), 10, 3 );

			// add product tags to product data array
			add_filter( 'wclsc_product_data', array( $this, 'attach_product_tags' ), 10, 3 );

			// add product photos to product data array
			add_filter( 'wclsc_product_data', array( $this, 'attach_product_images' ), 10, 3 );

		// store order information in LightSpeed
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'sync_lightspeed_order' ), 10, 2 );

		// store payment information in LightSpeed
		#add_action( 'woocommerce_payment_complete', array( $this, 'sync_lightspeed_payment' ) );
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'sync_lightspeed_payment_filter' ), 10, 2 );

		// sync payment details in LightSpeed
		#add_action( 'woocommerce_order_status_completed', array( $this, 'sync_lightspeed_payment_details' ) );

		// sync order status in LightSpeed
		add_action( 'woocommerce_order_status_changed', array( $this, 'sync_order_status' ), 10, 3 );

		// Administration actions
		add_action( 'admin_init', array( $this, '_admin_init' ) );
	}

	/**
	 * admin init actions and filters
	 * @return void
	 */
	function _admin_init(){
		add_action( 'admin_enqueue_scripts', array( $this, '_admin_resources' ) );
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );
		add_filter( 'woocommerce_lightspeed_cloud_settings', array( $this, 'admin_tax_settings' ) );
		add_filter( 'woocommerce_lightspeed_cloud_settings', array( $this, 'admin_log_settings' ) );

		add_action( 'woocommerce_admin_field_wclsc_error_log', array( $this, 'admin_error_log' ) );

		// Display information in WC Admin
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'display_lightspeed_debug_info' ) );
		add_action( 'woocommerce_product_options_reviews', array( $this, 'display_lightspeed_product_info' ) );
	}

	/**
	 * Enqueue scripts and styles
	 * action: admin_enqueue_scripts
	 * @return void
	 */
	function _admin_resources(){
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_register_style( 'wclsc-admin', WCLSC_DIR . 'css/wclsc-admin' . $min . '.css', array(), WCLSC_VERSION );
		wp_register_script( 'wclsc-admin', WCLSC_DIR . 'js/wclsc-admin' . $min . '.js', array( 'jquery' ), WCLSC_VERSION );

		$js_vars = array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'lookup_account_text' => __( 'Lookup my Account ID', 'wclsc' ),
			'api_key_error' => __( 'You must first provide an API Key.', 'wclsc' ),
			'account_success_text' => __( 'Account ID found. Don\'t forget to Save changes!', 'wclsc' ),
			'confirm_clear_log' => __( 'Are you sure you want to delete the logs?', 'wclsc' ),
			'logs_clear_text' => __( 'Logs have been cleared.', 'wclsc' ),
			'no_errors_message' => __( 'No errors to report', 'wclsc' ) . '. :)',
			'opt_prefix' => WCLSC_OPT_PREFIX
		);

		wp_localize_script( 'wclsc-admin', 'wclsc_vars', $js_vars );

		wp_enqueue_style( 'wclsc-admin' );
		wp_enqueue_script( 'wclsc-admin' );
	}

	/**
	 * Adds WooCommerce Settings page
	 * filter: woocommerce_get_settings_pages
	 * @param array $settings WooCommerce settings pages
	 */
	function add_settings_page( $settings ){
		$settings[] = include( WCLSC_PATH . '/classes/wc-settings-lightspeed.class.php' );
		return $settings;
	}

	/**
	 * Add Tax Settings to LightSpeed Cloud settings page
	 * filter: woocommerce_lightspeed_cloud_settings
	 * @param  array $lightspeed_cloud_settings Settings array
	 * @return array $lightspeed_cloud_settings
	 */
	function admin_tax_settings( $lightspeed_cloud_settings ){
		global $wpdb;

		$tax_category_options = array( '' => __( 'API Key and Account ID required to setup Tax Category.', 'wclsc' ) );
		$tax_categories = $this->lightspeed->get_tax_categories();

		if( ! $tax_categories || count( $tax_categories ) <= 0 )
			return $lightspeed_cloud_settings;

		$tax_category_options = array( '' => __( 'Please select a Tax Category.', 'wclsc' ) );
		foreach( $tax_categories as $tax_category ){
			$tax_category_options[ $tax_category->taxCategoryID ] = $tax_category->tax1Name;
		}

		$tax_classes = array_filter( array_map( 'trim', explode( "\n", get_option( 'woocommerce_tax_classes' ) ) ) );
		$tax_classes = array_merge( array( '' ), $tax_classes );

		$tax_init = false;

		$_tax = class_exists( 'WC_Tax' ) ? new WC_Tax() : NULL;

		foreach( $tax_classes as $tax_class ){
			$tax_rates = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates
				WHERE tax_rate_class = %s
				ORDER BY tax_rate_order
				" ,
				sanitize_title( $tax_class )
			) );

			$tax_class = $tax_class ? $tax_class : 'standard';

			if( $tax_rates && count( $tax_rates ) ){
				if( ! $tax_init ){
					$lightspeed_cloud_settings[] = array( # section start
						'title' => __( 'LightSpeed Tax Categories', 'woocommerce' ),
						'type' => 'title',
						'desc' => '',
						'id' => 'lightspeed_cloud_taxes'
					);
					$tax_init = true;
				}

				foreach( $tax_rates as $rate ){

					$id = NULL;

					if( $_tax ){
						$id = $_tax->get_rate_code( $rate->tax_rate_id );
					}

					if( ! $id ){
						$id = $tax_class . '_' . strtolower( str_replace( ' ', '-', $rate->tax_rate_name ) );
					}

					$title = $rate->tax_rate_name;
					if( $rate->tax_rate_country ){
						$title .= ' (' . $rate->tax_rate_country;
						if( $rate->tax_rate_state ){
							$title .= ', ' . $rate->tax_rate_state;
						}
						$title .= ')';
					}
					$title .= ' - ' . $rate->tax_rate;

					$lightspeed_cloud_settings[] = array(
						'title'		=> $title,
						'desc' 		=> '',
						'id' 		=> WCLSC_OPT_PREFIX . 'tax_category_' . $id,
						'type' 		=> 'select',
						'options'	=> $tax_category_options,
						'default' => '',
						'autoload'  => false
					);
				}
			}
		}

		if( $tax_init ){
			$lightspeed_cloud_settings[] = array( 'type' => 'sectionend', 'id' => 'lightspeed_cloud_taxes' );
		}

		return $lightspeed_cloud_settings;
	}

	/**
	 * Add Log Settings to LightSpeed Cloud settings page
	 * filter: woocommerce_lightspeed_cloud_settings
	 * @param  array $lightspeed_cloud_settings Settings array
	 * @return array $lightspeed_cloud_settings
	 */
	function admin_log_settings( $lightspeed_cloud_settings ){
		$lightspeed_cloud_settings[] = array( # section start
			'title' => __( 'LightSpeed Debug Log', 'wclsc' ),
			'type' => 'title',
			'desc' => '',
			'id' => 'lightspeed_cloud_logs'
		);

		$lightspeed_cloud_settings[] = array(
			'title' => __( 'Error Log', 'wclsc' ),
			'desc' 		=> '',
			'id' 		=> WCLSC_OPT_PREFIX . 'error_log',
			'type' 		=> 'wclsc_error_log',
			'autoload'  => false
		);

		$lightspeed_cloud_settings[] = array( 'type' => 'sectionend', 'id' => 'lightspeed_cloud_logs' );

		return $lightspeed_cloud_settings;
	}

	/**
	 * Hook for WooCommerce LightSpeed settings page field
	 * action: woocommerce_admin_field_wclsc_error_log
	 * @param  array $value Setting field array
	 * @return void
	 */
	function admin_error_log( $value ){
		$error_log = $this->lightspeed->get_error_log();

		if( count( $error_log ) > 0 ){
			$error_log = array_reverse( $error_log, true );
			$error_log_display = '';
			foreach( $error_log as $time => $error ){
				if( strpos( $time, '|' ) !== false ){
					list( $time, $uniqid ) = explode( '|', $time );
				}
				$error_log_display .= '[' . date( 'Y-m-d H:i:s', $time ) . ']  ' . $error . "\r\n";
			}
			echo '<textarea class="' . WCLSC_OPT_PREFIX . 'error_log" readonly="readonly">' . esc_textarea( $error_log_display ) . '</textarea>';
			echo '<input type="button" class="button wclsc-clear-log" value="' . __( 'Clear Log', 'wclsc' ) . '" />';
		} else {
			echo '<p>' . __( 'No errors to report', 'wclsc' ) . '. :)' . '</p>';
		}
	}

	/**
	 * Clear the LightSpeed error logs
	 * @return void
	 */
	function clear_error_log(){
		$response = array(
			'success' => true
		);

		if( ! current_user_can( 'manage_options' ) )
			return;

		delete_option( WCLSC_OPT_PREFIX . 'error_log' );

		wp_send_json( $response );
	}

	function display_lightspeed_debug_info(){
		global $post;
		$order_id = $post->ID;

		$sale_id = $this->lightspeed->get_sale_id( $order_id );

		if( ! $sale_id )
			return;

		$payment_id = $this->lightspeed->get_payment_id( $order_id );
		$sale = $this->lightspeed->get_sale( $sale_id );
		$stored = '1';

		if( ! $payment_id ){
			$payment_id = $this->get_payment_id_from_sale( $sale );
			$stored = '0';
		}

		$order_log = $this->get_order_log( $order_id );

		// begin output.
		include( WCLSC_PATH . '/views/admin/lightspeed-order-details.php' );
	}

	function display_lightspeed_product_info(){

		global $post;
		$product_id = $post->ID;

		$product_log = $this->get_product_log( $product_id );

		$item_id = $this->lightspeed->get_item_id( $product_id );

		include( WCLSC_PATH . '/views/admin/lightspeed-product-details.php' );
	}

	/**
	 * Get order log post meta
	 * @param  int $order_id WooCommerce Order ID (post ID)
	 * @return array  Order Log
	 */
	function get_order_log( $order_id ){
		// Only get Post Meta one time
		if( ! $this->order_log )
			$this->order_log = get_post_meta( $order_id, WCLSC_META_PREFIX . 'order_log', true );

		if( ! $this->order_log || ! is_array( $this->order_log ) )
			$this->order_log = array();

		return $this->order_log;
	}

	/**
	 * Store Order data in a log
	 * @param  int $order_id WooCommerce Order ID
	 * @param  string $data     Data to be logged
	 * @return void
	 */
	function log_order_data( $order_id, $data ){
		$this->get_order_log( $order_id );
		$this->order_log[ current_time( 'timestamp' ) . '|' . uniqid() ] = $data;
		update_post_meta( $order_id, WCLSC_META_PREFIX . 'order_log', $this->order_log );
	}

	/**
	 * Get product log post meta
	 * @param  int $product_id WooCommerce Product ID (post ID)
	 * @return array  Product Log
	 */
	function get_product_log( $product_id ){
		if( isset( $this->product_log[ $product_id ] ) )
			return $this->product_log[ $product_id ];

		if( ! isset( $this->product_log[ $product_id ] ) )
			$this->product_log[ $product_id ] = get_post_meta( $product_id, WCLSC_META_PREFIX . 'product_log', true );

		if( ! $this->product_log[ $product_id ] || ! is_array( $this->product_log[ $product_id ] ) )
			$this->product_log[ $product_id ] = array();

		return $this->product_log[ $product_id ];
	}

	/**
	 * Store Product data in a log
	 * @param  int $product_id WooCommerce Product ID
	 * @param  string $data     Data to be logged
	 * @return void
	 */
	function log_product_data( $product_id, $data ){
		$this->get_product_log( $product_id );
		$this->product_log[ $product_id ][ current_time( 'timestamp' ) . '|' . uniqid() ] = $data;
		update_post_meta( $product_id, WCLSC_META_PREFIX . 'product_log', $this->product_log[ $product_id ] );
	}

	/**
	 * Retrieve Account ID from LightSpeed API using API Key
	 * action: wp_ajax_wclsc_lookup_account_id
	 * @return void
	 */
	function get_lightspeed_account_id(){
		$response = array(
			'success' => false
		);

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( $_POST['api_key'] ) : '';

		if( $api_key ){
			$lookup = new Lightspeed_Cloud_API( $api_key );
			$account_id = $lookup->get_account_id();
			if( $account_id ){
				$response['success'] = true;
				$response['account_id'] = $account_id;
			} else {
				$response['error_message'] = __( 'An error occurred while retrieving your Account ID.', 'wclsc' ) . ' - ' . $lookup->get_error();
			}
		} else {
			$response['error_message'] = __( 'API Key was missing.', 'wclsc' );
		}

		wp_send_json( $response );
	}

	/**
	 * Sync customer data from order with LightSpeed
	 * action: woocommerce_checkout_order_processed
	 * @param  int $order_id WooCommerce Order ID
	 * @param  array $order_data       Posted data for order
	 * @return void
	 */
	function sync_lightspeed_customer( $order_id, $order_data ){
		$user_id = apply_filters( 'woocommerce_checkout_customer_id', get_current_user_id() );
		// get WordPress User
		$user = get_userdata( $user_id );

		if( ! $user )
			return;

		// setup customer array
		$customer_data = $this->setup_customer_data( $user_id, $user, $order_data );

		if( ! count( $customer_data ) )
			return;

		$this->log_order_data( $order_id, 'Customer Data: <pre>' . var_export( $customer_data, true ) . '</pre>' );

		// check if customer LightSpeed ID already exists
		if( $customer_id = $this->lightspeed->get_customer_id( $user_id ) ){

			// only update if this is a valid customer
			if( $this->lightspeed->get_customer( $customer_id ) ){
				$this->lightspeed->customer_id = $customer_id;

				$customer = $this->lightspeed->update_customer( $this->lightspeed->customer_id, $customer_data );

				$this->log_order_data( $order_id, 'Sync Customer: Update Customer (true): ' . $this->lightspeed->customer_id );

				return;
			}

		}

		// Look up customer in LightSpeed
		if( $customer = $this->lightspeed->lookup_customer( $customer_data ) ) {
			$this->lightspeed->update_customer( $this->lightspeed->customer_id, $customer_data );

			// set LS customer id for this user.
			$this->lightspeed->customer_id = $customer->customerID;

			$this->log_order_data( $order_id, 'Sync Customer: Update Customer (false): ' . $this->lightspeed->customer_id );
			update_user_meta( $user_id, WCLSC_META_PREFIX . 'customer_id', $this->lightspeed->customer_id );

		// Create a new customer in LightSpeed
		} else {
			$customer = $this->lightspeed->create_customer( $customer_data );

			// set LS customer id for this user.
			$this->lightspeed->customer_id = $customer->customerID;

			$this->log_order_data( $order_id, 'Sync Customer: Created New Customer ID: ' . $this->lightspeed->customer_id );
			update_user_meta( $user_id, WCLSC_META_PREFIX . 'customer_id', $this->lightspeed->customer_id );
		}

	}

	/**
	 * Create LightSpeed array to create/update Customer
	 * @param  int $user_id        WordPress User ID
	 * @param  object $user        WordPress User object
	 * @param  array $order_data   WooCommerce Order Data
	 * @return array $customer_data
	 */
	function setup_customer_data( $user_id, $user, $order_data ){
		$customer_data = array(
			'firstName' => $user->first_name,
			'lastName' => $user->last_name,
			'Contact' => array(
				'Emails' => array(
					'ContactEmail' => array(
						'address' => $user->user_email, // Required to lookup later
						'useType' => 'Primary'
					)
				)
			)
		);

		// add website if exists
		if( $user->user_url )
			$customer_data['Contact']['Websites']['ContactWebsite'] = array( 'url' => $user->user_url );

		// add title if exists
		$title = get_user_meta( $user_id, 'title', true );
		if( $title )
			$customer_data['title'] = $title;

		// add phone if exists
		$phone = get_user_meta( $user_id, 'phone', true );
		if( ! $phone )
			$phone = get_user_meta( $user_id, 'billing_phone', true );
		if( ! $phone )
			$phone = get_user_meta( $user_id, 'shipping_phone', true );
		if( $phone )
			$customer_data['Contact']['Phones']['ContactPhone'] = array( 'number' => $phone, 'useType' => 'Mobile' );

		// add company if exists
		$company = get_user_meta( $user_id, 'company', true );
		if( ! $company )
			$company = get_user_meta( $user_id, 'billing_company', true );
		if( ! $company )
			$company = get_user_meta( $user_id, 'shipping_company', true );
		if( $company )
			$customer_data['company'] = $company;

		if( $this->lightspeed->api_settings['customer_contact_address'] && $this->lightspeed->api_settings['customer_contact_address'] != 'none' ){
			// set address as Customer ContactAddress based on selection
			$type = $this->lightspeed->api_settings['customer_contact_address'];
			$customer_data['Contact']['Addresses']['ContactAddress'] = array(
				'address1' => get_user_meta( $user_id, $type . '_address_1', true ),
				'address2' => get_user_meta( $user_id, $type . '_address_2', true ),
				'city' => get_user_meta( $user_id, $type . '_city', true ),
				'state' => get_user_meta( $user_id, $type . '_state', true ),
				'zip' => get_user_meta( $user_id, $type . '_postcode', true ),
				'country' => get_user_meta( $user_id, $type . '_country', true )
			);
		}

		$customer_type = $this->lightspeed->get_customer_type();
		if( $customer_type ){
			$customer_data['customerTypeID'] = $customer_type;
		}

		return (array) apply_filters( 'wclsc_customer_data', $customer_data, $user_id, $order_data );
	}

	/**
	 * Add guest data to LightSpeed Customer and attach to order
	 * action: woocommerce_checkout_update_order_meta
	 * @param  int $order_id WooCommerce Order ID
	 * @param  array $order_data    Posted Order Array data
	 * @return void
	 */
	function sync_lightspeed_guest( $order_id, $order_data ){
		// make sure the user is not logged in
		if( is_user_logged_in() )
			return;

		if( ! isset( $order_data['order_id'] ) )
			$order_data['order_id'] = $order_id;

		$guest_data = $this->setup_guest_data( $order_data );

		if( ! count( $guest_data ) )
			return;

		$this->log_order_data( $order_id, 'Guest Data: <pre>' . var_export( $guest_data, true ) . '</pre>' );

		// check if guest LightSpeed ID already exists
		if( $customer_id = $this->lightspeed->get_guest_id( $order_id ) ){

			// only update if this is a valid customer
			if( $this->lightspeed->get_customer( $customer_id ) ){

				$this->lightspeed->customer_id = $customer_id;

				$guest = $this->lightspeed->update_customer( $this->lightspeed->customer_id, $guest_data );

				$this->log_order_data( $order_id, 'Sync Guest: Update Customer (true): ' . $this->lightspeed->customer_id );

				return;
			}

		}

		// Look up guest in LightSpeed
		if( $guest = $this->lightspeed->lookup_customer( $guest_data ) ) {
			$guest = $this->lightspeed->update_customer( $this->lightspeed->customer_id, $guest_data );

			$this->lightspeed->customer_id = $guest->customerID;

			$this->log_order_data( $order_id, 'Sync Guest: Update Customer (false): ' . $this->lightspeed->customer_id );
			update_post_meta( $order_id, WCLSC_META_PREFIX . 'customer_id', $this->lightspeed->customer_id );

		// Create a new guest in LightSpeed
		} else {
			$guest = $this->lightspeed->create_customer( $guest_data );

			// set LightSpeed customer id for this order.
			$this->lightspeed->customer_id = $guest->customerID;

			$this->log_order_data( $order_id, 'Sync Guest: Created New Customer ID: ' . $this->lightspeed->customer_id );
			update_post_meta( $order_id, WCLSC_META_PREFIX . 'customer_id', $this->lightspeed->customer_id );
		}
	}

	/**
	 * Create LightSpeed array to create/update Customer
	 * @param  array $order_data Posted Order Data from WooCommerce
	 * @return array $guest_data
	 */
	function setup_guest_data( $order_data ){
		$guest_data = array(
			'firstName' => $order_data['billing_first_name'],
			'lastName' => $order_data['billing_last_name'],
			'Contact' => array(
				'Emails' => array(
					'ContactEmail' => array(
						'address' => $order_data['billing_email'], // Required to lookup later
						'useType' => 'Primary'
					)
				)
			)
		);

		// add phone if exists
		if( $order_data['billing_phone'] )
			$guest_data['Contact']['Phones']['ContactPhone'] = array( 'number' => $order_data['billing_phone'], 'useType' => 'Mobile' );

		// add company if exists
		if( $order_data['billing_company'] )
			$guest_data['company'] = $order_data['billing_company'];

		if( $this->lightspeed->api_settings['customer_contact_address'] && $this->lightspeed->api_settings['customer_contact_address'] != 'none' ){
			// set address as Customer ContactAddress based on selection
			$type = ! $order_data['ship_to_different_address'] ? 'billing' : $this->lightspeed->api_settings['customer_contact_address'];

			$guest_data['Contact']['Addresses']['ContactAddress'] = array(
				'address1' => $order_data[ $type . '_address_1' ],
				'address2' => $order_data[ $type . '_address_2' ],
				'city' => $order_data[ $type . '_city' ],
				'state' => $order_data[ $type . '_state' ],
				'zip' => $order_data[ $type . '_postcode' ],
				'country' => $order_data[ $type . '_country' ]
			);
		}

		$customer_type = $this->lightspeed->get_customer_type();
		if( $customer_type ){
			$guest_data['customerTypeID'] = $customer_type;
		}

		return (array) apply_filters( 'wclsc_guest_data', $guest_data, $order_data );
	}

	/**
	 * Sync product data from order with LightSpeed
	 * action: woocommerce_add_order_item_meta
	 * @param  int $cart_item_id       WooCommerce Cart Item ID
	 * @param  array $values        Product Details array
	 * @param  string $cart_item_key Cart ID
	 * @return void
	 */
	function sync_lightspeed_product( $cart_item_id, $values, $cart_item_key ){
		$product = isset( $values['data'] ) && is_object( $values['data'] ) ? $values['data'] : NULL;
		$product_id  = $product ? $product->id : apply_filters( 'woocommerce_cart_item_product_id', $cart_item_id, $values, $cart_item_key );
		$product_data = $this->setup_product_data( $product_id, $values, $cart_item_key );

		if( ! count( $product_data ) )
			return;

		$this->log_product_data( $product_id, 'Product Data: <pre>' . var_export( $product_data, true ) . '</pre>' );

		// check if item LightSpeed ID already exists
		if( $item_id = $this->lightspeed->get_item_id( $product_id ) ){
			// only update if valid item id
			if( $this->lightspeed->get_item( $item_id ) ){
				$item = $this->lightspeed->update_item( $item_id, $product_data );
				return;
			}

		}

		// Look up item in LightSpeed
		if( $item = $this->lightspeed->lookup_item( $product_data ) ) {
			$item_id = $item->itemID;

			$this->lightspeed->update_item( $item_id, $product_data );

			$this->log_order_data( $order_id, 'Sync Product: Updated Item ID: ' . $item_id );
			update_post_meta( $product_id, WCLSC_META_PREFIX . 'item_id', $item_id );

		// Create a new item in LightSpeed
		} else {
			$item = $this->lightspeed->create_item( $product_data );

			// set LS item id for this user.
			$item_id = $item->itemID;
			$this->log_product_data( $product_id, 'Sync Product: Created New Item ID: ' . $item_id );
			update_post_meta( $product_id, WCLSC_META_PREFIX . 'item_id', $item_id );
		}

	}

	/**
	 * Create LightSpeed array to create/update Item
	 * @param  int $product_id 			WooCommerce Product ID
	 * @param  array $values     		Product Values array
	 * @param  string $cart_item_key	WC Cart Key
	 * @return array 					$product_data
	 */
	function setup_product_data( $product_id, $values, $cart_item_key ){
		$product = apply_filters( 'woocommerce_cart_item_product', $values['data'], $values, $cart_item_key );

		$product_data = array();

		if( ! is_object( $product ) )
			return $product_data;

		$product_data['description'] = $product->get_title();
		$product_data['tax'] = $product->is_taxable();
		$product_data['Prices'] = array(
			'ItemPrice' => array(
				array(
					'amount' => $product->get_price(),
					'useType' => 'Default'
				)
			)
		);

		if( $product->get_sku() )
			$product_data['customSku'] = $product->get_sku();

		/*

		## replace this with a "reduce stock" function based on backorder setting when order "ships" or is processed

		if( $product->managing_stock() && $in_stock = $product->get_stock_quantity() ){
			$product_data['ItemShops']['itemShop'] = array(
				'qoh' => $in_stock,
				'itemShopID' => $this->lightspeed->get_shop_id()
			);
		}
		*/

		return (array) apply_filters( 'wclsc_product_data', $product_data, $product_id, $product );
	}

	/**
	 * Attach product tags (categories) to product
	 * filter: wclsc_product_data
	 * @param  array $product_data Product Data array
	 * @param  int $product_id	 	WooCommerce Product ID
	 * @param  object $product      WooCommerce Product object
	 * @return array               $product_data
	 */
	function attach_product_tags( $product_data, $product_id, $product ){
		return $product_data; // temporary disabled.

		$categories = $product->get_categories( ',' );

		if( strpos( $categories, ',' ) !== false ){
			$categories = explode( ',', $categories );
		} elseif( $categories ) {
			$categories = array( $categories );
		}

		if( $categories ){
			$attributes = '@attributes';
			$tags = new stdClass();
			$tags->$attributes = new stdClass();
			$tags->$attributes->count = 0;
			$tags->tag = array();

			foreach( $categories as $category ){
				$category = str_replace( ' ', '-', strip_tags( $category ) );
				if( $tag = $this->lightspeed->lookup_tag( $category ) ){
					$tags->tag[] = $tag->name;
				} elseif( $tag = $this->lightspeed->create_tag( $category ) ){
					$tags->tag[] = $tag->name;
				}
			}

			if( $total_tags = count( $tags ) ){
				$tags->$attributes->count = $total_tags;
				$product_data['Tags'] = $tags;
			}
		}

		return $product_data;
	}

	/**
	 * Attach product photos to product
	 * filter: wclsc_product_data
	 * @param  array $product_data Product Data array
	 * @param  int $product_id	 	WooCommerce Product ID
	 * @param  object $product      WooCommerce Product object
	 * @return array               $product_data
	 */
	function attach_product_images( $product_data, $product_id, $product ){
		//TODO
		return $product_data;
	}

	/**
	 * Sync order data with LightSpeed
	 * action: woocommerce_checkout_order_processed
	 * @param  int $order_id  WooCommerce Order ID
	 * @param  array $order_data    Posted data for order
	 * @return void
	 */
	function sync_lightspeed_order( $order_id, $order_data ){
		if( ! isset( $order_data['order_id'] ) )
			$order_data['order_id'] = $order_id;

		$order = new WC_Order( $order_id );

		if( ! is_object( $order ) )
			return;

		$sale_data = $this->setup_sale_data( $order );
		$this->log_order_data( $order_id, 'Sale Data: <pre>' . var_export( $sale_data, true ) . '</pre>' );

		if( $sale_data ){
			$sale = $this->lightspeed->create_sale( $sale_data );

			if( $sale ){
				$this->log_order_data( $order_id, 'Sync Order: New Sale ID:' . $sale->saleID );
				update_post_meta( $order_id, WCLSC_META_PREFIX . 'sale_id', $sale->saleID );

				$this->lightspeed->sale_id = $sale->saleID;
			}
		}
	}

	/**
	 * Setup LightSpeed data to create Sale
	 * @param  object $order WooCommerce Order Object
	 * @return void
	 */
	function setup_sale_data( $order ){
		$order_id = $order->id;
		$customer_id = $this->lightspeed->get_guest_id( $order_id );

		if( ! $customer_id )
			$customer_id = $this->lightspeed->get_customer_id();

		$sale_data = array(
			'timeStamp' => date( 'c', strtotime( $order->order_date ) ),
			'referenceNumber' => $order_id,
			'referenceNumberSource' => __( 'WooCommerce', 'woocommerce' ),

			'taxCategoryID' => $this->get_tax_category_id( $order ),
			'employeeID' => $this->lightspeed->get_employee_id(),
			'registerID' => $this->lightspeed->get_register_id(),
			'customerID' => $customer_id,
			'shipTo' => $this->setup_ship_to_data( $order, $customer_id ),

			'SaleLines' => $this->setup_sale_lines_data( $order, $customer_id ),

			'shopID' => $this->lightspeed->get_shop_id()
		);

		return (array) apply_filters( 'wclsc_sale_data', $sale_data, $order );
	}

	/**
	 * Lookup corresponding LightSpeed Tax Category for the applied tax class.
	 * @param  object $order WooCommerce Order Object
	 * @return int        LightSpeed taxCategoryID
	 */
	function get_tax_category_id( $order ){

		$taxes = $order->get_items( 'tax' );

		foreach( $taxes as $tax ){
			$tax_category = $this->lightspeed->get_tax_category_id( $tax['name'], $tax['rate_id'] );
			if( $tax_category )
				break;
		}

		if( $tax_category )
			return $tax_category;

		return 0;
	}

	/**
	 * Setup LightSpeed array to create/update ShipTo address
	 * @param  object $order       WooCommerce Order Object
	 * @param  int $customer_id LightSpeed CustomerID
	 * @return array $ship_to_data
	 */
	function setup_ship_to_data( $order, $customer_id ){
		$order_id = $order->id;
		$shipping_address = $order->get_formatted_shipping_address();
		if( ! $shipping_address )
			$shipping_address = $order->get_formatted_billing_address();

		$ship_to_data = array(
			'customerID' => $customer_id,
			'shipped' => false,
			'timeStamp' => date( 'c', strtotime( $order->order_date ) )
		);

		if( $shipping_address && count( $shipping_address ) ){

			$this->log_order_data( $order_id, 'Shipping Address: <pre>' . var_export( $shipping_address, true ) . '</pre>' );

			$ship_to_data['firstName'] = $shipping_address['first_name'];
			$ship_to_data['lastName'] = $shipping_address['last_name'];
			$ship_to_data['company'] = $shipping_address['company'];

			$ship_to_data['Contact'] = array(
				'Addresses' => array(
					'ContactAddress' => array(
						'address1' => $shipping_address['address_1'],
						'address2' => $shipping_address['address_2'],
						'city' => $shipping_address['city'],
						'state' => $shipping_address['state'],
						'zip' => $shipping_address['postcode'],
						'country' => $shipping_address['country']
					)
				)
			);
		}

		return (array) apply_filters( 'wclsc_ship_to_data', $ship_to_data, $order, $customer_id );
	}


	/**
	 * Setup LightSpeed array to create SaleLines
	 * @param  object $order       WooCommerce Order Object
	 * @param  int $customer_id LightSpeed CustomerID
	 * @return array $sale_lines_data
	 */
	function setup_sale_lines_data( $order, $customer_id ){
		$sale_lines_data = array();

		foreach( $order->get_items() as $item ){
			$line_data = $this->setup_sale_line_item( $item, $order, $customer_id );

			if( $line_data ){
				$sale_lines_data[] = array( 'SaleLine' => $line_data );
			}
		}

		$shipping_cost = $order->get_total_shipping();
		$shipping_method = $order->get_shipping_method();

		if( $shipping_cost && $shipping_method ){
			$shipping = array(
				'method' => $shipping_method,
				'cost' => $shipping_cost,
				'tax' => $order->get_shipping_tax(),
				'item_id' => $this->lightspeed->get_shipping_item_id( $shipping_method )
			);

			$shipping_data = array(
				'createTime' => date( 'c', strtotime( $order->order_date ) ),
				'timeStamp' => date( 'c', strtotime( $order->order_date ) ),
				'unitQuantity' => 1,
				'tax' => ( $shipping['tax'] > 0 ),
				'taxClassID' => 0,
				'tax1Rate' => $this->lightspeed->format_money( $shipping['tax'] ),

				'unitPrice' => $this->lightspeed->format_money( $shipping['cost'] ),

				'customerID' => $customer_id,
				'itemID' => $shipping['item_id']
			);

			$shipping_data = apply_filters( 'wclsc_sale_line_item_data', $shipping_data, $shipping, $order, $customer_id );

			$sale_lines_data[] = array( 'SaleLine' => $shipping_data );
		}

		return (array) apply_filters( 'wclsc_sale_lines_data', $sale_lines_data, $order, $customer_id );
	}

	/**
	 * Setup LightSpeed array to create Sale.SaleLine
	 * @param  array $item        WooCommerce item array
	 * @param  object $order       WooCommerce Order object
	 * @param  int $customer_id LightSpeed CustomerID
	 * @return array $line_data
	 */
	function setup_sale_line_item( $item, $order, $customer_id ){
		$product_id = isset( $item['variation_id'] ) && $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
		$item_id = $this->lightspeed->get_item_id( $product_id );

		$unit_price = floatval( round( $item['line_total'] / $item['qty'], 2 ) );
		$line_tax = floatval( round( $item['line_tax'], 2 ) );

		$line_data = array(
			'createTime' => date( 'c', strtotime( $order->order_date ) ),
			'timeStamp' => date( 'c', strtotime( $order->order_date ) ),
			'unitQuantity' => $item['qty'],
			'tax' => ( $line_tax > 0 ),

			'unitPrice' => $this->lightspeed->format_money( $unit_price ),

			'customerID' => $customer_id
		);

		if( ! $this->get_tax_category_id( $order ) ){
			$line_data['tax1Rate'] = $this->lightspeed->format_money( $line_tax );
		}

		if( $item_id )
			$line_data['itemID'] = $item_id;

		return (array) apply_filters( 'wclsc_sale_line_item_data', $line_data, $item, $order, $customer_id );
	}

	/**
	 * Convert Money to Float
	 * NO! From: http://stackoverflow.com/questions/5139793/php-unformat-money
	 * From: http://stackoverflow.com/questions/4949279/remove-non-numeric-characters-plus-comma-and-period-from-a-string
	 * @param  string $money Money string
	 * @return float        Float Value of Money
	 */
	function money_to_float( $money, $currency ){
		/*$cleanString = preg_replace( '/([^0-9\.,])/i', '', $money );
		$onlyNumbersString = preg_replace( '/([^0-9])/i', '', $money );

		$separatorsCountToBeErased = strlen( $cleanString ) - strlen( $onlyNumbersString ) - 1;

		$stringWithCommaOrDot = preg_replace( '/([,\.])/', '', $cleanString, $separatorsCountToBeErased );
		$removedThousendSeparator = preg_replace( '/(\.|,)(?=[0-9]{3,}$)/', '', $stringWithCommaOrDot );*/

		$plain = strip_tags( $money );
		if( function_exists( 'get_woocommerce_currency_symbol' ) ){
			$plain = str_replace( get_woocommerce_currency_symbol( $currency ), '', $money );
		}
		$decoded = htmlspecialchars_decode( $plain );
		$numbers_only = preg_replace( '/[^0-9,.]/', '', $decoded );

		return (float) $numbers_only;
	}

	/**
	 * Alternate Sync SalePayments filter
	 * filter: woocommerce_payment_successful_result
	 * @return array $result WC Result array
	 */
	function sync_lightspeed_payment_filter( $result, $order_id ){
		$this->sync_lightspeed_payment( $order_id );
		return $result;
	}

	/**
	 * Sync SalePayments with LightSpeed
	 * action: woocommerce_payment_complete
	 * @param  int $order_id WooCommerce Order ID
	 * @return void
	 */
	function sync_lightspeed_payment( $order_id ){
		$order = new WC_Order( $order_id );

		if( ! is_object( $order ) )
			return;

		$payment_id = $this->lightspeed->get_payment_id( $order_id );

		if( $payment_id ){
			$this->log_order_data( $order_id, 'Sync Payment (abort): Payment ID Exists: ' . $payment_id );
			return;
		}

		$payment_data = $this->setup_payment_data( $order );

		$this->log_order_data( $order_id, 'Payment Data: <pre>' . var_export( $payment_data, true ) . '</pre>' );

		if( $payment_data ){

			$sale_id = $this->lightspeed->get_sale_id( $order_id );

			$sale_data = array(
				'SalePayments' => array(
					'SalePayment' => $payment_data,
					'saleID' => $sale_id
				)
			);

			$this->log_order_data( $order_id, 'Sync Payment: Updating Sale ID: ' . $sale_id );
			$sale = $this->lightspeed->update_sale( $sale_id, $sale_data );

			$this->log_order_data( $order_id, 'Sync Payment (Sale Object): <pre>' . var_export( $sale, true ) . '</pre>' );

			// add payment id to order
			$payment_id = $this->get_payment_id_from_sale( $sale );

			if( $payment_id ){
				$this->log_order_data( $order_id, 'Sync Payment: Created New Payment ID: ' . $payment_id );
				update_post_meta( $order_id, WCLSC_META_PREFIX . 'payment_id', $payment_id );
			} else {
				$this->log_order_data( $order_id, 'Sync Payment: No Payment ID Created' );
			}
		}
	}

	/**
	 * Setup LightSpeed array to create SalePayment
	 * @param  object $order WooCommerce Order Object
	 * @return array $payment_data
	 */
	function setup_payment_data( $order ){
		$order_id = $order->id;

		$register_id = $this->lightspeed->get_register_id();
		$employee_id = $this->lightspeed->get_employee_id();

		$sale_id = $this->lightspeed->get_sale_id( $order_id );

		$payment_type = $this->setup_payment_type_data( $order );
		$payment_type_id = $this->get_payment_type_id( $payment_type );

		$payment_data = array(
			'amount' => $order->get_total(),
			'createTime' => date( 'c', current_time( 'timestamp' ) ),
			'CCCharge' => $this->setup_cccharge_data( $order, $sale_id )
		);

		if( $payment_type_id ){
			$this->log_order_data( $order_id, 'Setup Payment: Payment Type ID: ' . $payment_type_id );
			$payment_data['paymentTypeID'] = $payment_type_id;
			$payment_type['paymentTypeID'] = $payment_type_id;
		}

		if( $payment_type ) {
			$this->log_order_data( $order_id, 'Setup Payment: Payment Type Data: <pre>' . var_export( $payment_type, true ) . '</pre>' );
			$payment_data['PaymentType'] = $payment_type;
		}

		if( $register_id ){
			$payment_data['registerID'] = $register_id;
		}
		if( $employee_id ){
			$payment_data['employeeID'] = $employee_id;
		}
		if( $sale_id ){
			$payment_data['saleID'] = $sale_id;
		}

		return (array) apply_filters( 'wclsc_payment_data', $payment_data );
	}

	/**
	 * Setup LightSpeed array to create PaymentType
	 * @param  object $order WooCommerce Order object
	 * @return array        $payment_type_data
	 */
	function setup_payment_type_data( $order ){
		$order_id = $order->id;
		$payment_type_name = get_post_meta( $order_id, '_payment_method_title', true );

		if( ! $payment_type_name ){
			$payment_type_name = $order->payment_method;
			if( is_string( $payment_type_name ) ){
				$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
				if( isset( $available_gateways[ $payment_type_name ] ) ){
					$gateway = $available_gateways[ $payment_type_name ];
					$payment_type_name = $gateway->get_title();
				}
			} elseif( is_object( $payment_type_name ) ){
				$payment_type_name = $payment_type_name->get_title();
			}
		}

		if( ! $payment_type_name ){
			$this->log_order_data( $order_id, 'Setup Payment Type (abort): Payment Type Name Not Found' );
			return false;
		}

		$payment_type_data = array(
			'name' => $payment_type_name
		);

		return (array) apply_filters( 'wclsc_payment_type_data', $payment_type_data, $order );
	}

	function get_payment_type_id( $payment_type_name ){
		if( ! $payment_type_name )
			return false;

		if( is_array( $payment_type_name ) )
			$payment_type_name = $payment_type_name['name'];

		$payment_type = $this->lightspeed->lookup_payment_type( $payment_type_name );

		if( $payment_type )
			return $payment_type->paymentTypeID;

		return false;
	}

	/**
	 * Setup LightSpeed array to create CCCharge
	 * @param  object $order WooCommerce Order object
	 * @return array $cccharge_data
	 */
	function setup_cccharge_data( $order, $sale_id ){
		// consider setting up separate adapter plugins for payment gateways
		$cccharge_data = array(
			//'gatewayTransID' => NULL, # Authorize.net plugin doesn't allow access to this value. Saving this for later
			//'authCode' => NULL, # Authorize.net plugin doesn't allow access to this value. Saving this for later
			//'response' => NULL, # Authorize.net plugin doesn't allow access to this value. Saving this for later
			'amount' => $order->get_total(),
			'saleID' => $sale_id
		);

		$credit_card_number = $this->get_credit_card_number( $order );
		$exp = $this->get_card_expiration( $order );
		$auth_only = $this->get_auth_only( $order );

		if( $credit_card_number ){
			$cccharge_data['xnum'] = strlen( $credit_card_number ) == 4 ? $credit_card_number : substr( $credit_card_number, -4 );
		}
		if( $exp ){
			$cccharge_data['exp'] = $exp;
		}
		if( is_bool( $auth_only ) ){
			$cccharge_data['authOnly'] = $auth_only;
		}

		return (array) apply_filters( 'wclsc_cccharge_data', $cccharge_data, $order );
	}

	/**
	 * Support Authorize.net payment gateway to get credit card number.
	 * @param  object $order WooCommerce Order object
	 * @return int Credit card number
	 */
	function get_credit_card_number( $order ){
		$credit_card_number = '';

		// Authorize.net support
		if( isset( $_POST['ccnum'] ) ){
			$credit_card_number = $_POST['ccnum'];
		}

		if( ! $credit_card_number ){
			$credit_card_number = $this->get_part_from_order_notes( $order->id, 'ending in' );
		}


		// add some other popular payment gateways here.

		return $credit_card_number;
	}

	/**
	 * Support Authorize.net payment gateway to get expiration date
	 * @param  object $order WooCommerce Order Object
	 * @return string        Expiration Date
	 */
	function get_card_expiration( $order ){
		$exp = '';

		if( isset( $_POST['expmonth'] ) && isset( $_POST['expyear'] ) ){
			$exp = $_POST['expmonth'] . '-' . $_POST['expyear'];
		}

		if( ! $exp ){
			$exp = $this->get_part_from_order_notes( $order->id, 'expires' );
			$exp = str_replace( '/', '-', $exp );
		}

		// add some other popular payment gateways here.

		return $exp;
	}

	/**
	 * Support  Authorize.net payment gateway to get auth method
	 * @param  object $order WooCommerce Order Object
	 * @return bool        Auth Only
	 */
	function get_auth_only( $order ){
		$auth_only = NULL;

		$authorizenet_settings = get_option( 'woocommerce_authorize_settings' );

		$sale_method = isset( $authorizenet_settings['salemethod'] ) ? $authorizenet_settings['salemethod'] : '';

		if( ! $sale_method && isset( $authorizenet_settings['salemode'] ) ){
			$sale_method = $authorizenet_settings['salemode'];
		}

		if( $sale_method == 'AUTH_ONLY' ){
			$auth_only = true;
		} elseif( $sale_method == 'AUTH_CAPTURE' ) {
			$auth_only = false;
		}

		// add some other popular payment gateways here.

		return $auth_only;
	}

	/**
	 * Sync payment information
	 * action: woocommerce_order_status_completed
	 * @param  int $order_id   WooCommerce Order ID
	 * @return void
	 */
	function sync_lightspeed_payment_details( $order_id ){

		$sale_id = $this->lightspeed->get_sale_id( $order_id );

		if( ! $sale_id )
			return;

		$payment_id = $this->lightspeed->get_payment_id( $order_id );

		if( $payment_id )
			return;

		if( ! $payment_id ){
			$sale = $this->lightspeed->get_sale( $sale_id );

			$payment_id = $this->get_payment_id_from_sale( $sale );

			if( $payment_id )
				update_post_meta( $order_id, WCLSC_META_PREFIX . 'payment_id', $payment_id );
		}

		$order = new WC_Order( $order_id );
		$payment_data = $this->setup_payment_data( $order );

		$sale_data = array(
			'SalePayments' => array(
				'SalePayment' => $payment_data,
				'saleID' => $sale_id
			)
		);

		$this->log_order_data( $order_id, 'Sync Payment (Sale Data):<pre>' . var_export( $sale_data, true ) . '</pre>' );

		$sale = $this->lightspeed->update_sale( $sale_id, $sale_data );

		$this->log_order_data( $order_id, 'Sync Payment (Sale):<pre>' . var_export( $sale, true ) . '</pre>' );

		if( $sale && ! $payment_id ){
			// add payment_id
			$payment_id = $this->get_payment_id_from_sale( $sale );

			if( $payment_id )
				update_post_meta( $order_id, WCLSC_META_PREFIX . 'payment_id', $payment_id );
		}
	}

	/**
	 * Sync order status in LightSpeed
	 * action: woocommerce_order_status_changed
	 * @param  int $order_id   WooCommerce Order ID
	 * @param  string $old_status Previous Order Status slug
	 * @param  string $new_status New Order Status slug
	 * @return void
	 */
	function sync_order_status( $order_id, $old_status, $new_status ){
		$sale_id = $this->lightspeed->get_sale_id( $order_id );

		if( ! $sale_id ){
			$this->log_order_data( $order_id, 'Sync Order Status (abort): No Sale ID.' );
			return;
		}

		$sale_data = array(
			'completed' => ( $new_status == 'completed' )
		);

		if( $new_status == 'cancelled' )
			$sale_data['voided'] = true;

		$this->log_order_data( $order_id, 'Sync Order Status: New Status: ' . $new_status );
		$this->log_order_data( $order_id, 'Sync Order Status (Sale Data): <pre>' . var_export( $sale_data, true ) . '</pre>' );

		$sale = $this->lightspeed->update_sale( $sale_id, $sale_data );

		$this->log_order_data( $order_id, 'Sync Order Status (Sale Object): <pre>' . var_export( $sale, true ) . '</pre>' );
	}


	/**
	 * Retrieve a valid salePaymentID from Sale Object
	 * @param  object $sale LightSpeed Sale Object
	 * @return int       salePaymentID
	 */
	function get_payment_id_from_sale( $sale ){
		$payment_id = NULL;

		if( $sale && isset( $sale->SalePayments->SalePayment ) ){
			if( is_array( $sale->SalePayments->SalePayment ) ){
				$payment = $sale->SalePayments->SalePayment[0];
				$payment_id = $payment->salePaymentID;
			} elseif( is_object( $sale->SalePayments->SalePayment ) ) {
				$payment_id = $sale->SalePayments->SalePayment->salePaymentID;
			}
		}

		return $payment_id;
	}


	function get_string_after_string( $string, $search ){
		$found = '';
		if( strpos( $string, $search ) !== false ){
			$parts = explode( $search, $string );
			if( count( $parts ) ){
				$part = substr( trim( $parts[1] ), 0, strpos( trim( $parts[1] ), ' ' ) );
				$part = trim( $part, '.)],:' );
				if( $part )
					$found = $part;
			}
		}

		return $found;
	}

	function get_part_from_order_notes( $order_id, $part ){
		$args = array(
			'post_id' 	=> $order_id,
			'approve' 	=> 'approve',
			'type' 		=> 'order_note'
		);

		remove_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10, 1 );
		$notes = get_comments( $args );
		add_filter( 'comments_clauses', array( 'WC_Comments', 'exclude_order_comments' ), 10, 1 );

		if( $notes ){
			foreach( $notes as $note ):
				if( strpos( $note->comment_content, $part ) !== false ){
					return $this->get_string_after_string( $note->comment_content, $part );
				}
			endforeach;
		}

		return false;
	}
}
