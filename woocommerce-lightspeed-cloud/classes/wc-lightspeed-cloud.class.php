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
	 * Debug Mode
	 * @var boolean
	 */
	var $debug = true;

	/**
	 * WooCommerce Debug Logger
	 * @var object
	 */
	var $debug_logger;

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
	 * Flag for trashed post
	 * @var [type]
	 */
	var $trashed;

	/**
	 * For performance tests
	 * @var null
	 */
	var $start_time = NULL;

	/**
	 * For performance tests
	 * @var null
	 */
	var $inventory_start_time = NULL;

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

		global $woocommerce;
		$this->debug_logger = class_exists( 'WC_Logger' ) ? new WC_Logger() : $woocommerce->logger();

		// base plugin actions
		add_action( 'init', array( $this, '_init' ) );

		// Setup Cron
		add_action( 'init', array( $this, 'setup_crons' ) );

    	/**
    	 * WooCommerce Create Customer
    	 */
    	add_action( 'woocommerce_created_customer', array( $this, 'auto_create_lightspeed_customer' ), 10, 3 );
	}

	/**
	 * Init actions and filters
	 * action: init
	 * @return void
	 */
	function _init(){

		/**
		 * Init Actions
		 */

		// Setup Integration with LightSpeed
		add_action( 'admin_init', array( $this, 'setup_integration' ) );

		// Administration actions
		add_action( 'admin_init', array( $this, '_admin_init' ) );

		// Store OAuth Code
		add_action( 'admin_init', array( $this, 'store_oauth_code' ) );

		/**
		 * Ajax Actions
		 */

		// Admin Ajax function to lookup account id and test API
		add_action( 'wp_ajax_wclsc_lookup_account_id', array( $this, 'get_lightspeed_account_id' ) );

		// Admin Ajax to sync a single product
		add_action( 'wp_ajax_wclsc_single_product_sync', array( $this, 'sync_woocommerce_product' ) );

		// Admin Ajax to sync a single product
		add_action( 'wp_ajax_wclsc_force_sync_product', array( $this, 'force_sync_woocommerce_product' ) );

		// Admin Ajax to sync all products
		add_action( 'wp_ajax_wclsc_all_product_sync', array( $this, 'start_woocommerce_product_sync' ) );

		// Admin Ajax to sync product inventory
		add_action( 'wp_ajax_wclsc_product_inventory_sync', array( $this, 'start_woocommerce_inventory_sync' ) );

		// Admin Ajax to stop syncing products
		add_action( 'wp_ajax_wclsc_abort_product_sync', array( $this, 'ajax_stop_sync' ) );

		// Admin Ajax to get sync status
		add_action( 'wp_ajax_wclsc_get_sync_status', array( $this, 'ajax_sync_status' ) );

		// Trigger the cron during sync
		add_action( 'wp_ajax_wclsc_trigger_cron', array( $this, 'trigger_cron' ) );

		/**
		 * Cron Actions
		 */

		# Ajax Triggered Cron Event
		add_action( WCLSC_OPT_PREFIX . 'sync_products', array( $this, 'product_sync' ) );

		# regularly scheduled Cron Event
		add_action( WCLSC_OPT_PREFIX . 'recurring_sync_products', array( $this, 'start_woocommerce_product_sync' ) );

		# Ajax Triggered Cron Event
		add_action( WCLSC_OPT_PREFIX . 'sync_products_inventory', array( $this, 'product_inventory_sync' ) );

		# regularly scheduled Cron Event
		add_action( WCLSC_OPT_PREFIX . 'recurring_sync_product_inventory', array( $this, 'start_woocommerce_inventory_sync' ) );

		# Ajax Triggered Cron Event
		#add_action( WCLSC_OPT_PREFIX . 'sync_products_archived', array( $this, 'product_archived_sync' ) );

		# regularly scheduled Cron Event
		#add_action( WCLSC_OPT_PREFIX . 'recurring_sync_product_archived', array( $this, 'start_woocommerce_archived_sync' ) );

		// Cron Schedules
    	add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );

		/**
		 * Checkout Sync Actions
		 */

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

		/**
		 * Misc Events
		 */

		add_action( 'woocommerce_order_refunded', array( $this, 'refund_order' ), 10, 2 );

		/**
		 * Admin Buttons
		 */

		add_action( 'woocommerce_product_options_stock_fields', array( $this, 'display_update_stock_button' ) );

	}

	/**
	 * admin init actions and filters
	 * @return void
	 */
	function _admin_init(){
		if( isset( $_GET['wclsc-manual-sync'] ) && $_GET['wclsc-manual-sync'] == '1' ){
			$this->product_sync();
		}

		add_action( 'admin_enqueue_scripts', array( $this, '_admin_resources' ) );
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );
		add_filter( 'woocommerce_lightspeed_cloud_settings', array( $this, 'admin_tax_settings' ) );

		// Display information in WC Admin
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'display_lightspeed_debug_info' ) );
		add_action( 'woocommerce_product_options_reviews', array( $this, 'display_lightspeed_product_info' ) );

		add_action( 'woocommerce_get_settings_account', array( $this, 'woocommerce_account_settings' ) );
	}

	function store_oauth_code(){
		if( isset( $_GET['wclsc-lightspeed-oauth-code'] ) ){
			$code = sanitize_text_field( $_GET['wclsc-lightspeed-oauth-code'] );
			update_option( WCLSC_OPT_PREFIX . 'oauth_code', $code );
			echo '<script>window.close();</script>';
			echo '<script>close();</script>';
			exit();
		}
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

	function woocommerce_account_settings( $settings ){
		$new_settings = array();

		foreach( $settings as $setting ){
			if( $setting['type'] == 'sectionend' && $setting['id'] == 'account_registration_options' ){
				$new_settings[] = array(
					'desc'          => __( 'Automatically create a LightSpeed Customer', 'wclsc' ),
					'id'            => WCLSC_OPT_PREFIX . 'woocommerce_create_customer',
					'default'       => 'no',
					'type'          => 'checkbox',
					'autoload'      => false
				);
			}

			$new_settings[] = $setting;
		}

		return $new_settings;
	}

	function setup_integration(){
		// TODO
	}

	function cron_schedules( $schedules ){

		$schedule_options = array();

		$schedule_options['15_mins'] = array(
			'display' => '15 Minutes',
			'interval' => MINUTE_IN_SECONDS * 15
		);

		$schedule_options['30_mins'] = array(
			'display' => '30 Minutes',
			'interval' => MINUTE_IN_SECONDS * 30
		);
		$schedule_options['1_hour'] = array(
			'display' => 'Hour',
			'interval' => HOUR_IN_SECONDS
		);
		$schedule_options['2_hours'] = array(
			'display' => '2 Hours',
			'interval' => HOUR_IN_SECONDS * 2
		);
		$schedule_options['4_hours'] = array(
			'display' => '4 Hours',
			'interval' => HOUR_IN_SECONDS * 4
		);
		$schedule_options['6_hours'] = array(
			'display' => '6 Hours',
			'interval' => HOUR_IN_SECONDS * 6
		);
		$schedule_options['12_hours'] = array(
			'display' => '12 Hours',
			'interval' => HOUR_IN_SECONDS * 12
		);

		// Add each custom schedule into the cron job system.
		foreach( $schedule_options as $schedule_key => $schedule ){
			$schedules[ WCLSC_OPT_PREFIX . $schedule_key ] = array(
				'interval' => $schedule['interval'],
				'display' => __( 'Every ' . $schedule['display'] )
			);
		}

		return $schedules;
	}

	function setup_crons(){
		if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'recurring_sync_products' ) )
			wp_schedule_event( current_time( 'timestamp' ), 'wclsc_12_hours', WCLSC_OPT_PREFIX . 'recurring_sync_products' );

		if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'recurring_sync_product_inventory' ) )
			wp_schedule_event( current_time( 'timestamp' ), 'wclsc_15_mins', WCLSC_OPT_PREFIX . 'recurring_sync_product_inventory' );

		#if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'recurring_sync_product_archived' ) )
		#	wp_schedule_event( current_time( 'timestamp' ), 'wclsc_2_hours', WCLSC_OPT_PREFIX . 'recurring_sync_product_archived' );
	}

	function refund_order( $order_id, $refund_id ){
		$order = wc_get_order( $order_id );
		$refund = new WC_Order_Refund( $refund_id );

		if( ! is_object( $refund ) )
			return;

		$sale_id = $this->lightspeed->get_sale_id( $order_id );

		if( ! $sale_id )
			return;

		// TODO: handle refunds/restock inventory

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

	function display_lightspeed_debug_info(){
		global $post;
		$order_id = $post->ID;

		$sale_id = $this->lightspeed->get_sale_id( $order_id );

		if( $sale_id ){
			$payment_id = $this->lightspeed->get_payment_id( $order_id );
			$sale = $this->lightspeed->get_sale( $sale_id );
			$stored = '1';

			if( ! $payment_id ){
				$payment_id = $this->get_payment_id_from_sale( $sale );
				$stored = '0';
			}
		} else {
			$stored = false;
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

		$item = $this->lightspeed->get_item( $item_id, true );

		include( WCLSC_PATH . '/views/admin/lightspeed-product-details.php' );
	}

	function display_update_stock_button(){
		#echo '<p class="form-field"><input type="button" class="button wclsc-update-item-inventory" value="' . __( 'Pull Inventory from LightSpeed', 'wclsc' ) . '" /></p>';
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
		if( ! $this->debug )
			return;

		$this->get_order_log( $order_id );
		$this->order_log[ current_time( 'timestamp' ) . '|' . uniqid() ] = $data;
		update_post_meta( $order_id, WCLSC_META_PREFIX . 'order_log', $this->order_log );
	}

	function debug_log( $data, $label = '' ){

		$debug = $label ? $label . ': ' . var_export( $data, true ) : var_export( $data, true );

		$this->lightspeed->error = $debug;
		$this->lightspeed->log_errors();
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
		if( ! $this->debug )
			return;

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
			'firstName' => html_entity_decode( $user->first_name ),
			'lastName' => html_entity_decode( $user->last_name ),
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
			$customer_data['title'] = html_entity_decode( $title );

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

	function auto_create_lightspeed_customer( $customer_id, $new_customer_data, $password_generated ){
		if( get_option( WCLSC_OPT_PREFIX . 'woocommerce_create_customer' ) != 'yes' )
			return;

		if( did_action( 'woocommerce_checkout_process' ) )
			return;

		$customer_data = array(
			'Contact' => array(
				'Emails' => array(
					'ContactEmail' => array(
						'address' => $new_customer_data['user_email'],
						'useType' => 'Primary'
					)
				)
			)
		);

		$customer = $this->lightspeed->create_customer( $customer_data );

		if( $customer )
			update_user_meta( $customer_id, WCLSC_META_PREFIX . 'customer_id', $customer->customer_id );
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
				if( $this->lightspeed->api_settings['sync_direction'] == 'wc2ls' ){
					$item = $this->lightspeed->update_item( $item_id, $product_data );
				}
				return;
			}

		}

		// Look up item in LightSpeed
		if( $item = $this->lightspeed->lookup_item( $product_data ) ) {
			$item_id = $item->itemID;

			if( $this->lightspeed->api_settings['sync_direction'] == 'wc2ls' ){
				$this->lightspeed->update_item( $item_id, $product_data );

				$this->log_order_data( $order_id, 'Sync Product: Updated Item ID: ' . $item_id );
			}
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

	function sync_woocommerce_product(){
		$response = array(
			'success' => false,
			'html' => __( 'Failure.', 'wclsc' )
		);

		if( isset( $_POST['product_id'] ) ){
			$product_id = (int)$_POST['product_id'];
			if( $product_id ){
				$response['success'] = true;
				$item_id = $this->lightspeed->get_item_id( $product_id );

				$sync_button = '<a class="button wclsc-sync-product" href="#" data-item-id="' . $item_id . '">' . __( 'Sync with LightSpeed', 'wclsc' ) . '</a>';

				if( ! $item_id ){
					$sku_field = $this->lightspeed->api_settings['sku_field'];
					if( $sku_field ){
						$product = wc_get_product( $product_id );
						$lookup = array( $sku_field => $product->get_sku() );
						$ls_product = $this->lightspeed->lookup_item( $lookup );

						if( $ls_product ){
							$meta = update_post_meta( $product_id, WCLSC_META_PREFIX . 'item_id', $ls_product->itemID );
							$response['success'] = true;
							$response['html'] = __( 'LS Meta Stored successfully.', 'wclsc' );
							$response['html'] .= $sync_button;
						}
					}
				} else {
					$response['html'] = __( 'Found matching LS Item ID: ', 'wclsc' ) . $item_id;
					$response['html'] .= $sync_button;
				}

			} else {
				$response['html'] = __( 'No Product ID.', 'wclsc' );
			}
		}

		wp_send_json( $response );
	}

	function force_sync_woocommerce_product(){
		$response = array(
			'success' => false,
			'html' => __( 'Failure.', 'wclsc' )
		);

		if( isset( $_POST['item_id'] ) ){

			$item_id = (int) sanitize_text_field( $_POST['item_id'] );
			$item = $this->lightspeed->get_item( $item_id, true );

			if( $item && $post_id = $this->sync_lightspeed_item( $item ) ){
				$response['success'] = true;
				$response['html'] = __( 'Redirecting...', 'wclsc' );
				if( $this->trashed ){
					$response['redirect'] = admin_url() . 'edit.php?post_type=product&trashed=1&ids=' . $post_id;
				}
			}

		}

		wp_send_json( $response );
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

		if( $product->get_sku() ){
			$sku_field = $this->lightspeed->api_settings['sku_field'];
			if( $sku_field && $sku_field != 'systemSku' )
				$product_data[ $sku_field ] = $product->get_sku();
		}

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

	function sync_product_category( $post_id, $category, $append = false ){
		if( ! $category )
			return false;

		$method = $this->lightspeed->api_settings['sync_categories'];
		if( ! $method )
			return;

		$taxonomy = $method == 'categories' ? 'product_cat' : 'product_tag';

		$category = apply_filters( 'wclsc_lightspeed_category', $category );

		wp_set_object_terms( $post_id, $category, $taxonomy, $append );
	}

	function sync_product_tags( $post_id, $tags ){
		if( $tags && ! is_array( $tags ) )
			$tags = array( $tags );

		$method = $this->lightspeed->api_settings['sync_tags'];

		if( ! $method )
			return;

		foreach( $tags as &$tag ){
			$tag = apply_filters( 'wclsc_lightspeed_tag', $tag );
			if( $method == 'categories' )
				$tag = apply_filters( 'wclsc_lightspeed_category', $tag );
		}

		$taxonomy = $method == 'tags' ? 'product_tag' : 'product_cat';
		wp_set_object_terms( $post_id, $tags, $taxonomy );
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

		$tax_category = $this->get_tax_category_id( $order );

		$sale_data = array(
			'referenceNumber' => $order_id,
			'referenceNumberSource' => __( 'WooCommerce', 'woocommerce' ),

			'employeeID' => $this->lightspeed->get_employee_id(),
			'registerID' => $this->lightspeed->get_register_id(),
			'customerID' => $customer_id,
			'shipTo' => $this->setup_ship_to_data( $order, $customer_id ),

			'SaleLines' => $this->setup_sale_lines_data( $order, $customer_id ),

			'shopID' => $this->lightspeed->get_shop_id()
		);

		if( is_int( $tax_category ) && $tax_category ){
			$sale_data['taxCategoryID'] = $tax_category;
			#echo'TAX CATEGORY ID<pre>';var_dump($tax_category);echo'</pre>';
			#exit();
		} else {
			$tax_data = $this->setup_tax_data( $order );
			if( $tax_data )
				$sale_data['TaxCategory'] = $tax_data;
		}

		return (array) apply_filters( 'wclsc_sale_data', $sale_data, $order );
	}

	/**
	 * Lookup corresponding LightSpeed Tax Category for the applied tax class.
	 * @param  object $order WooCommerce Order Object
	 * @return int        LightSpeed taxCategoryID
	 */
	function get_tax_category_id( $order ){

		$taxes = $order->get_items( 'tax' );

		$tax_category = 0;

		foreach( $taxes as $tax ){
			$tax_category = $this->lightspeed->get_tax_category_id( $tax['name'], $tax['rate_id'] );
			if( $tax_category )
				break;
		}

		if( $tax_category )
			return $tax_category;

		return 0;
	}

	function setup_tax_data( $order ){
		$tax_data = array(
			'taxCategoryID' => 0
		);

		$taxes = $order->get_tax_totals();

		if( count( $taxes) ){
			foreach( $taxes as $name => $tax ){
				if( ! isset( $tax_data['tax1Name'] ) ){
					$tax_data['tax1Name'] = $tax->label;
					$tax_data['tax1Rate'] = round( $tax->amount );
				} elseif( ! isset( $tax_data['tax2Name'] ) ){
					$tax_data['tax2Name'] = $tax->label;
					$tax_data['tax2Rate'] = round( $tax->amount );
				}
			}
		}

		return $tax_data;
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
		);

		if( $shipping_address && count( $shipping_address ) ){

			$this->log_order_data( $order_id, 'Shipping Address: <pre>' . var_export( $shipping_address, true ) . '</pre>' );

			#$ship_to_data['firstName'] = $shipping_address['first_name'];
			#$ship_to_data['lastName'] = $shipping_address['last_name'];
			#$ship_to_data['company'] = $shipping_address['company'];

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
				#'createTime' => date( 'c', strtotime( $order->order_date ) ),
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
			#'createTime' => date( 'c', strtotime( $order->order_date ) ),
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
				$this->complete_sale( $sale );
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
			'amount' => round( $order->get_total(), 2 ),
			#'createTime' => date( 'c', current_time( 'timestamp' ) ),
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
		} elseif( isset( $_POST['authorize-net-cim-cc-number'] ) ){
			$credit_card_number = $_POST['authorize-net-cim-cc-number'];
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
		} elseif( isset( $_POST['authorize-net-cim-cc-exp-month'] ) && isset( $_POST['authorize-net-cim-cc-exp-year'] ) ){
			$exp = $_POST['authorize-net-cim-cc-exp-month'] . '-' . $_POST['authorize-net-cim-cc-exp-year'];
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
		$sale_method = NULL;

		$authorizenet_settings = get_option( 'woocommerce_authorize_settings' );

		$sale_method = isset( $authorizenet_settings['salemethod'] ) ? $authorizenet_settings['salemethod'] : '';

		if( ! $sale_method && isset( $authorizenet_settings['salemode'] ) ){
			$sale_method = $authorizenet_settings['salemode'];
		}

		/*if( ! $sale_method ){
			$authnetaim_settings = get_option( 'woocommerce_authorize_net_aim_settings' );

			$sale_method = isset( $authnetaim_settings['transaction_type'] ) ? $authnetaim_settings['transaction_type'] : '';
		}*/

		if( ! $sale_method ){
			$authnetcim_settings = get_option( 'woocommerce_authorize_net_cim_settings' );

			$sale_method = isset( $authnetcim_settings['transaction_type'] ) ? $authnetcim_settings['transaction_type'] : '';
		}

		if( strtoupper( $sale_method ) == 'AUTH_ONLY' ){
			$auth_only = true;
		} elseif( strtoupper( $sale_method ) == 'AUTH_CAPTURE' ) {
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

		if( ! $new_status ){
			$this->log_order_data( $order_id, 'Sync Order Status (abort): No New Status.' );
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

	function complete_sale( $sale ){
		if( ! $sale )
			return;

		if( ! $sale->completed ){
			$sale = $this->lightspeed->update_sale( $sale->saleID, array( 'completed' => true ) );
		}
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

	function start_woocommerce_product_sync(){
		$status = $this->get_sync_status();

		$response = array(
			'success' => true,
			'html' => $status
		);

		# the last cron is still running. let's wait.
		if( wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products' ) ){
			if( defined( 'DOING_AJAX' ) && DOING_AJAX )
				wp_send_json( $response );
			return;
		}

		$sync_status = array(
			'started' => date( 'n/j/Y g:i a', current_time( 'timestamp' ) ),
			'sync_amount' => $this->lightspeed->api_settings['sync_amount'],
			'synced' => 0,
			'trashed' => 0,
			'not_synced' => 0
		);

		set_transient( WCLSC_META_PREFIX . 'sync_status', $sync_status );

		// start the cron only if it hasn't already started...
		if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products' ) )
			wp_schedule_single_event( current_time( 'timestamp' ), WCLSC_OPT_PREFIX . 'sync_products' );

		if( defined( 'DOING_AJAX' ) && DOING_AJAX ){
			wp_send_json( $response );
		}
	}

	function start_woocommerce_inventory_sync(){

		# the last cron is still running. let's wait.
		if( wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products_inventory' ) )
			return;

		$sync_status = array(
			'started' => date( 'n/j/Y g:i a', current_time( 'timestamp' ) ),
			'sync_amount' => $this->lightspeed->api_settings['sync_amount'],
			'synced' => 0,
			'not_synced' => 0
		);

		set_transient( WCLSC_META_PREFIX . 'inventory_sync_status', $sync_status );

		// start the cron only if it hasn't already started...
		if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products_inventory' ) )
			wp_schedule_single_event( current_time( 'timestamp' ), WCLSC_OPT_PREFIX . 'sync_products_inventory' );
	}

	function start_woocommerce_archived_sync(){
		# the last cron is still running. let's wait.
		if( wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products_archived' ) )
			return;

		$sync_status = array(
			'started' => date( 'n/j/Y g:i a', current_time( 'timestamp' ) ),
			'sync_amount' => $this->lightspeed->api_settings['sync_amount'],
			'archived' => 0
		);

		set_transient( WCLSC_META_PREFIX . 'archived_sync_status', $sync_status );

		// start the cron only if it hasn't already started...
		if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products_archived' ) )
			wp_schedule_single_event( current_time( 'timestamp' ), WCLSC_OPT_PREFIX . 'sync_products_archived' );
	}

	function product_sync(){
		set_time_limit( HOUR_IN_SECONDS ); # 1 hour
		if( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ){
			error_reporting( E_ERROR | E_WARNING | E_PARSE );
			ini_set( 'display_errors', '1' );
		}

		$sync_status = get_transient( WCLSC_META_PREFIX . 'sync_status' );

		if( isset( $sync_status['is_running'] ) && $sync_status['is_running'] && ! isset( $sync_status['broke'] ) )
			return;

		if( ! isset( $sync_status['offset'] ) || ! $sync_status['offset'] )
			$sync_status['offset'] = 0;

		unset( $sync_status['scheduled'] );
		unset( $sync_status['cancelled'] );

		$sync_status['sync_amount'] = $this->lightspeed->api_settings['sync_amount'];

		$items = $this->lightspeed->get_items( array(
			'archived' => 1,
			'limit' => $sync_status['sync_amount'],
			'offset' => $sync_status['offset']
		) );

		if( $this->lightspeed->total_records )
			$sync_status['total'] = $this->lightspeed->total_records;

		$sync_status['is_running'] = true;

		set_transient( WCLSC_META_PREFIX . 'sync_status', $sync_status );

		$abort = false;

		foreach( $items as $item ){
			// lets make sure it doesn't fail and then just give up.
			if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products' ) )
				wp_schedule_single_event( current_time( 'timestamp' ), WCLSC_OPT_PREFIX . 'sync_products' );

			$this->start_time = microtime( true );

			$sync_status['offset']++;
			$sync_status['broke'] = true;

			// let's make sure it progresses over troublesome records
			set_transient( WCLSC_META_PREFIX . 'sync_status', $sync_status );

			if( $post_id = $this->sync_lightspeed_item( $item ) ){

				if( $this->trashed ){
					$sync_status['trashed']++;
				} else {
					$sync_status['synced']++;
				}

				unset( $sync_status['broke'] );

				set_transient( WCLSC_META_PREFIX . 'sync_status', $sync_status );

				$abort = get_transient( WCLSC_META_PREFIX . 'abort' );

				if( $abort ){
					delete_transient( WCLSC_META_PREFIX . 'abort' );
					break;
				}
			} else {
				$sync_status['not_synced']++;
			}
		}

		unset( $sync_status['is_running'] );
		$sync_status['execution_time'] = round( microtime( true ) - $this->start_time, 3 ) . ' seconds.';

		if( $sync_status['offset'] >= $sync_status['total'] ){
			$sync_status['completed'] = date( 'n/j/Y g:i a', current_time( 'timestamp' ) );
			wp_clear_scheduled_hook( WCLSC_OPT_PREFIX . 'sync_products' );
			delete_transient( WCLSC_META_PREFIX . 'abort' );

			$this->debug_logger->add( 'wclsc', 'PRODUCT SYNC:' . "\r\n" . var_export( $sync_status, true ) );
		}

		if( ( ! isset( $sync_status['completed'] ) || ! $sync_status['completed'] ) && ! $abort ){
			wp_clear_scheduled_hook( WCLSC_OPT_PREFIX . 'sync_products' );
			if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products' ) )
				wp_schedule_single_event( current_time( 'timestamp' ), WCLSC_OPT_PREFIX . 'sync_products' );
			$sync_status['scheduled'] = true;
		}

		set_transient( WCLSC_META_PREFIX . 'sync_status', $sync_status );

		#do_action( 'wclsc_product_sync' );
	}

	function product_inventory_sync(){
		set_time_limit( HOUR_IN_SECONDS ); # 1 hour
		if( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ){
			error_reporting( E_ERROR | E_WARNING | E_PARSE );
			ini_set( 'display_errors', '1' );
		}

		$sync_status = get_transient( WCLSC_META_PREFIX . 'inventory_sync_status' );

		if( isset( $sync_status['is_running'] ) && $sync_status['is_running'] && ! isset( $sync_status['broke'] ) )
			return;

		if( ! isset( $sync_status['offset'] ) || ! $sync_status['offset'] )
			$sync_status['offset'] = 0;

		unset( $sync_status['scheduled'] );
		unset( $sync_status['cancelled'] );

		$sync_status['sync_amount'] = $this->lightspeed->api_settings['sync_amount'];

		$items = $this->lightspeed->get_items( array(
			'limit' => $this->lightspeed->api_settings['sync_amount'],
			'offset' => $sync_status['offset']
		), 'ItemShops' );

		if( $this->lightspeed->total_records )
			$sync_status['total'] = $this->lightspeed->total_records;

		$sync_status['is_running'] = true;

		set_transient( WCLSC_META_PREFIX . 'inventory_sync_status', $sync_status );

		$abort = false;

		foreach( $items as $item ){
			$this->inventory_start_time = microtime( true );

			$sync_status['offset']++;
			$sync_status['broke'] = true;

			set_transient( WCLSC_META_PREFIX . 'inventory_sync_status', $sync_status );

			if( $post_id = $this->sync_lightspeed_item_inventory( $item ) ){

				$sync_status['synced']++;
				unset( $sync_status['broke'] );

				set_transient( WCLSC_META_PREFIX . 'inventory_sync_status', $sync_status );

				$abort = get_transient( WCLSC_META_PREFIX . 'inventory_abort' );

				if( $abort ){
					delete_transient( WCLSC_META_PREFIX . 'inventory_abort' );
					break;
				}
			} else {
				$sync_status['not_synced']++;
			}
		}

		unset( $sync_status['is_running'] );
		$sync_status['execution_time'] = round( microtime( true ) - $this->inventory_start_time, 3 ) . ' seconds.';

		# Are we done yet?
		if( $sync_status['offset'] >= $sync_status['total'] ){
			$sync_status['completed'] = date( 'n/j/Y g:i a', current_time( 'timestamp' ) );
			wp_clear_scheduled_hook( WCLSC_OPT_PREFIX . 'sync_products_inventory' );
			delete_transient( WCLSC_META_PREFIX . 'inventory_abort' );

			$this->debug_logger->add( 'wclsc', 'INVENTORY SYNC:' . "\r\n" . var_export( $sync_status, true ) );
		}

		if( ( ! isset( $sync_status['completed'] ) || ! $sync_status['completed'] ) && ! $abort ){
			wp_clear_scheduled_hook( WCLSC_OPT_PREFIX . 'sync_products_inventory' );

			if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products_inventory' ) )
				wp_schedule_single_event( current_time( 'timestamp' ), WCLSC_OPT_PREFIX . 'sync_products_inventory' );

			$sync_status['scheduled'] = true;
		}

		set_transient( WCLSC_META_PREFIX . 'inventory_sync_status', $sync_status );

		#do_action( 'wclsc_product_inventory_sync' );
	}

	function product_archived_sync(){
		set_time_limit( HOUR_IN_SECONDS ); # 1 hour
		if( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ){
			error_reporting( E_ERROR | E_WARNING | E_PARSE );
			ini_set( 'display_errors', '1' );
		}

		$sync_status = get_transient( WCLSC_META_PREFIX . 'archived_sync_status' );

		if( isset( $sync_status['is_running'] ) && $sync_status['is_running'] )
			return;

		if( ! isset( $sync_status['offset'] ) || ! $sync_status['offset'] )
			$sync_status['offset'] = 0;

		unset( $sync_status['scheduled'] );
		unset( $sync_status['cancelled'] );

		$sync_status['sync_amount'] = $this->lightspeed->api_settings['sync_amount'];

		$items = $this->lightspeed->get_items( array(
			'archived' => 'only',
			'limit' => $this->lightspeed->api_settings['sync_amount'],
			'offset' => $sync_status['offset']
		), NULL );

		if( $this->lightspeed->total_records )
			$sync_status['total'] = $this->lightspeed->total_records;

		$sync_status['is_running'] = true;

		set_transient( WCLSC_META_PREFIX . 'archived_sync_status', $sync_status );

		#$abort = false;

		foreach( $items as $item ){
			#$this->start_time = microtime( true );

			$sync_status['offset']++;

			set_transient( WCLSC_META_PREFIX . 'archived_sync_status', $sync_status );

			if( $this->sync_archived_lightspeed_item( $item ) )
				$sync_status['archived']++;

			#$abort = get_transient( WCLSC_META_PREFIX . 'archived_abort' );

			#if( $abort ){
			#	delete_transient( WCLSC_META_PREFIX . 'archived_abort' );
			#	break;
			#}
		}

		unset( $sync_status['is_running'] );
		#$sync_status['execution_time'] = round( microtime( true ) - $this->start_time, 3 ) . ' seconds.';

		if( $sync_status['offset'] >= $sync_status['total'] ){
			$sync_status['completed'] = date( 'n/j/Y g:i a', current_time( 'timestamp' ) );
			wp_clear_scheduled_hook( WCLSC_OPT_PREFIX . 'archived_sync_status' );
			delete_transient( WCLSC_META_PREFIX . 'archived_abort' );

			$this->debug_logger->add( 'wclsc', 'ARCHIVE SYNC:' . "\r\n" . var_export( $sync_status, true ) );
		}

		if( ( ! isset( $sync_status['completed'] ) || ! $sync_status['completed'] ) && ! $abort ){
			wp_clear_scheduled_hook( WCLSC_OPT_PREFIX . 'sync_products_archived' );

			if( ! wp_next_scheduled( WCLSC_OPT_PREFIX . 'sync_products_archived' ) )
				wp_schedule_single_event( current_time( 'timestamp' ), WCLSC_OPT_PREFIX . 'sync_products_archived' );

			$sync_status['scheduled'] = true;
		}

		set_transient( WCLSC_META_PREFIX . 'archived_sync_status', $sync_status );

		#do_action( 'wclsc_product_archived_sync' );
	}

	function sync_lightspeed_item( $item ){
		if( ! is_object( $item ) )
			return false;

		if( ! $this->start_time )
			$this->start_time = microtime( true );

		# check to see if a product exists with itemID meta
		$post_id = $this->lightspeed->lookup_product_by_item_id( $item->itemID );

		$sku_field = $this->lightspeed->api_settings['sku_field'];

		# check to see if a product exists with SKU meta
		if( ! $post_id && $sku_field && isset( $item->$sku_field ) && $item->$sku_field )
			$post_id = $this->lightspeed->lookup_product_by_sku( $item->$sku_field );

		# Trash the post if it's archived
		if( $item->archived && $item->archived === 'true' ){
			if( $post_id ){
				wp_trash_post( $post_id );
				$this->trashed = true;
			}

			return $post_id;
		}

		# create the product
		if( ! $post_id ){
			global $current_user;
			get_currentuserinfo();

			$new_product = array(
				'post_author' => $current_user->ID,
				'post_content' => '', // long description
				'post_status' => 'publish',
				'post_title' => $item->description,
				'post_type' => 'product'
			);

			# Create product
			$post_id = wp_insert_post( $new_product, $wp_error );
		}

		if( ! $post_id )
			return false;

		# add defaults if not already a value
		if( ! get_post_meta( $post_id, '_visibility', true ) )
			update_post_meta( $post_id, '_visibility', 'visible' );
		if( ! get_post_meta( $post_id, '_virtual', true ) )
			update_post_meta( $post_id, '_virtual', 'no' );
		if( ! get_post_meta( $post_id, '_downloadable', true ) )
			update_post_meta( $post_id, '_downloadable', 'no' );

		# update product title, long description, and short description
		$updated_product = array(
			'ID' => $post_id
		);

		if( $item->description )
			$updated_product['post_title'] = $item->description;

		# update weight, width, height, and length
		$ecomm_data = $this->lightspeed->get_ecommerce_data( $item );

		if( $ecomm_data ){

			if( $ecomm_data['longDescription'] )
				$updated_product['post_content'] = $ecomm_data['longDescription'];
			if( $ecomm_data['shortDescription'] )
				$updated_product['post_excerpt'] = $ecomm_data['shortDescription'];

			if( $ecomm_data['weight'] > 0 )
				update_post_meta( $post_id, '_weight', $ecomm_data['weight'] );
			if( $ecomm_data['width'] > 0 )
				update_post_meta( $post_id, '_width', $ecomm_data['width'] );
			if( $ecomm_data['height'] > 0 )
				update_post_meta( $post_id, '_height', $ecomm_data['height'] );
			if( $ecomm_data['length'] > 0 )
				update_post_meta( $post_id, '_length', $ecomm_data['length'] );
		}

		# set sku and itemID meta
		update_post_meta( $post_id, WCLSC_META_PREFIX . 'item_id', $item->itemID );

		if( $sku_field && isset( $item->$sku_field ) && $item->$sku_field )
			update_post_meta( $post_id, '_sku', $item->$sku_field );

		# update regular price and sale price
		if( $price = $this->lightspeed->get_item_price( $item ) ){
			update_post_meta( $post_id, '_regular_price', $price );
			update_post_meta( $post_id, '_price', $price );
		}

		# update QOH only on force product sync
		#if( defined( 'DOING_AJAX' ) && DOING_AJAX )
			$this->sync_qoh( $item, $post_id );

		# tax
		if( $item->tax )
			update_post_meta( $post_id, '_tax_status', 'taxable' );

		# update categories and tags
		if( isset( $item->Category->name ) )
			$this->sync_product_category( $post_id, $item->Category->name, apply_filters( 'wclsc_lightspeed_category_append', false ) );

		if( isset( $item->Tags->tag ) )
			$this->sync_product_tags( $post_id, $item->Tags->tag );

		# TODO: Update Product Attributes

		do_action( 'wclsc_sync_product', $post_id, $item );

		$updated_product = apply_filters( 'wclsc_sync_product_data', $updated_product );

		if( count( $updated_product ) > 1 )
			wp_update_post( $updated_product );

		return $post_id;
	}

	function sync_lightspeed_item_inventory( $item ){
		if( ! is_object( $item ) )
			return false;

		if( ! $this->inventory_start_time )
			$this->inventory_start_time = microtime( true );

		if( $item->archived && $item->archived !== 'false' )
			return true;

		# check to see if a product exists with itemID meta
		$post_id = $this->lightspeed->lookup_product_by_item_id( $item->itemID );

		$sku_field = $this->lightspeed->api_settings['sku_field'];

		# check to see if a product exists with SKU meta
		if( ! $post_id && $sku_field && isset( $item->$sku_field ) && $item->$sku_field )
			$post_id = $this->lightspeed->lookup_product_by_sku( $item->$sku_field );

		# only sync inventory, do not create new items
		if( ! $post_id )
			return false;

		# update QOH
		$this->sync_qoh( $item, $post_id );

		do_action( 'wclsc_sync_product_inventory', $post_id, $item );

		return $post_id;
	}

	function sync_archived_lightspeed_item( $item ){
		if( ! is_object( $item ) )
			return false;

		#if( ! $this->start_time )
		#	$this->start_time = microtime( true );

		if( $item->archived && $item->archived === 'true' ){

			# check to see if a product exists with itemID meta
			$post_id = $this->lightspeed->lookup_product_by_item_id( $item->itemID );

			$sku_field = $this->lightspeed->api_settings['sku_field'];

			# check to see if a product exists with SKU meta
			if( ! $post_id && $sku_field && isset( $item->$sku_field ) && $item->$sku_field )
				$post_id = $this->lightspeed->lookup_product_by_sku( $item->$sku_field );

			# only trash products, do not create new items
			if( ! $post_id )
				return false;

			# trash it
			wp_trash_post( $post_id );
			$this->trashed = true;

			$this->debug_logger->add( 'wclsc', 'TRASH ARCHIVED ITEM: POST ID ' . $post_id . ' - ITEM ID ' . $item->itemID );

			do_action( 'wclsc_sync_product_archived', $post_id, $item );

			return $post_id;
		}

		return false;
	}

	function sync_qoh( $item, $post_id ){
		$stock = $this->lightspeed->get_item_qoh( $item );

		if( $stock || $stock === '0' ){
			$previous_stock = get_post_meta( $post_id, '_stock', true );

			if( $stock != $previous_stock ){
				update_post_meta( $post_id, '_manage_stock', 'yes' );
				update_post_meta( $post_id, '_stock', $stock );
				if( $stock === '0' )
					update_post_meta( $post_id, '_stock_status', 'outofstock' );
				else
					update_post_meta( $post_id, '_stock_status', 'instock' );

				$this->log_product_data( $post_id, 'Modified Stock from ' . $previous_stock . ' to ' . $stock );
			}
		}
	}

	function get_sync_status( $sync_status = NULL ){
		if( ! $sync_status )
			$sync_status = get_transient( WCLSC_META_PREFIX . 'sync_status' );

		if( ! $sync_status )
			return false;

		if( isset( $sync_status['offset'] ) && $sync_status['offset'] === 0 ){
			$status = __( 'Querying first batch of records from LightSpeed.', 'wclsc' );
		} else {
			$status_text = isset( $sync_status['scheduled'] ) ? __( 'Paused on', 'wclsc' ) : __( 'Syncing', 'wclsc' );
			$status = isset( $sync_status['offset'] ) && isset( $sync_status['total'] ) ? $status_text . ' Record ' . $sync_status['offset'] . ' of ' . $sync_status['total'] : __( 'Awaiting cron to start', 'wclsc' );
		}

		if( isset( $sync_status['completed'] ) )
			$status = __( 'Completed Sync of ', 'wclsc' ) . $sync_status['total'] . __( ' records.', 'wclsc' );


		$html = '<div class="sync-status">';
			if( isset( $sync_status['started'] ) )
				$html .= '<strong>Sync Started:</strong> ' . $sync_status['started'] . '<br />';
			if( $status )
				$html .= '<strong>Status:</strong> ' . $status . '<br />';
			if( isset( $sync_status['scheduled'] ) && isset( $sync_status['offset'] ) && isset( $sync_status['total'] ) )
				$html .= '<strong>Awaiting start of next batch</strong><br />';
			if( isset( $sync_status['sync_amount'] ) )
				$html .= '<strong>Amount Per Request:</strong> ' . $sync_status['sync_amount'] . '<br />';
			#if( isset( $sync_status['execution_time'] ) )
			#	$html .= '<strong>Time to Execute:</strong> ' . $sync_status['execution_time'] . '<br />';
			if( isset( $sync_status['cancelled'] ) )
				$html .= '<strong>Cancelled:</strong> ' . $sync_status['cancelled'] . '<br />';
			if( isset( $sync_status['completed'] ) )
				$html .= '<strong>Completed:</strong> ' . $sync_status['completed'] . '<br />';
			#if( $full_time = wp_next_scheduled( WCLSC_OPT_PREFIX . 'recurring_sync_products' ) )
			#	$html .= '<strong>Next Full Sync:</strong> ' . date( 'n/j/Y g:i a', $full_time ) . '<br />';
			#if( $inventory_time = wp_next_scheduled( WCLSC_OPT_PREFIX . 'recurring_sync_product_inventory' ) )
			#	$html .= '<strong>Next Inventory Sync:</strong> ' . date( 'n/j/Y g:i a', $inventory_time ) . '<br />';
			#$html .= '<strong>Current Server Time:</strong> ' . date( 'n/j/Y g:i a', current_time( 'timestamp' ) ) . '<br />';
			if( ! isset( $sync_status['completed'] ) && ! isset( $sync_status['cancelled'] ) )
				$html .= '<input type="button" class="button wclsc-stop-sync" value="' . __( 'Stop Sync', 'wclsc' ) . '" />';
		$html .= '</div>';

		return $html;
	}

	function ajax_sync_status(){
		$sync_status = get_transient( WCLSC_META_PREFIX . 'sync_status' );
		$status = $this->get_sync_status( $sync_status );

		$response = array(
			'success' => true,
			'html' => $status,
			'status' => $sync_status
		);

		if( isset( $sync_status['completed'] ) && $sync_status['completed'] ){
			$response['complete'] = '1';
		}

		wp_send_json( $response );
	}

	function ajax_stop_sync(){
		set_transient( WCLSC_META_PREFIX . 'abort', '1' );
		wp_clear_scheduled_hook( WCLSC_OPT_PREFIX . 'sync_products' );
		$sync_status = get_transient( WCLSC_META_PREFIX . 'sync_status' );
		$sync_status['cancelled'] = date( 'n/j/Y g:i a', current_time( 'timestamp' ) );
		set_transient( WCLSC_META_PREFIX . 'sync_status', $sync_status );

		$status = $this->get_sync_status( $sync_status );
		$response = array(
			'success' => true,
			'html' => $status
		);

		wp_send_json( $response );
	}

	function trigger_cron(){

		if( defined( 'DOING_AJAX' ) && DOING_AJAX ){

			$sync_status = get_transient( WCLSC_META_PREFIX . 'sync_status' );

			$json = array();

			// Let's fire this every time to make sure it keeps running
			if( ( ! isset( $sync_status['completed'] ) && ! isset( $sync_status['cancelled'] ) ) || ! isset( $sync_status['offset'] ) || ! isset( $sync_status['total'] ) ){
				if( ! wp_schedule_event( WCLSC_OPT_PREFIX . 'sync_products' ) )
					wp_schedule_single_event( current_time( 'timestamp' ), WCLSC_OPT_PREFIX . 'sync_products' );

				wp_remote_get( site_url() . '/wp-cron.php?doing_wp_cron' );

				$json['success'] = true;

			}

			wp_send_json( $json );
		}
	}
}
