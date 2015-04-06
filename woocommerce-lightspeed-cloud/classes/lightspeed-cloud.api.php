<?php

if( class_exists( 'Lightspeed_Cloud_API' ) ) return;

class Lightspeed_Cloud_API {

	/**
	 * LightSpeed Cloud API Settings
	 * @var array
	 */
	var $api_settings = array();

	/**
	 * Instance of MOSAPICall
	 * @var object
	 */
	var $merchantos;

	/**
	 * Current error message
	 * @var mixed
	 */
	var $error = false;

	/**
	 * Debug Logger
	 * @var object
	 */
	var $logger;

	/**
	 * Store SaleID from LightSpeed
	 * @var int
	 */
	var $sale_id;

	/**
	 * Store CustomerID from LightSpeed
	 * @var int
	 */
	var $customer_id;

	/**
	 * Transient Prefix
	 * @var string
	 */
	var $prefix = WCLSC_OPT_PREFIX;

	/**
	 * Store total records from last request
	 * @var int
	 */
	var $total_records;

	function __construct( $api_key = NULL ){
		$this->api_settings = array(
			'key'						=> get_option( WCLSC_OPT_PREFIX . 'api_key',					$api_key ),
			'password'					=> get_option( WCLSC_OPT_PREFIX . 'api_password',				'apikey' ),
			'account_id'				=> get_option( WCLSC_OPT_PREFIX . 'account_id',					''		 ),
			'shop_id'					=> get_option( WCLSC_OPT_PREFIX . 'shop_id',					''		 ),
			'register_id'				=> get_option( WCLSC_OPT_PREFIX . 'register_id',				''		 ),
			'employee_id'				=> get_option( WCLSC_OPT_PREFIX . 'employee_id',				''		 ),
			'customer_contact_address'	=> get_option( WCLSC_OPT_PREFIX . 'customer_contact_address',	'none'	 ),
			'sync_direction'			=> get_option( WCLSC_OPT_PREFIX . 'sync_direction',				''		 ),
			'sku_field'					=> get_option( WCLSC_OPT_PREFIX . 'sku_field',					''		 ),
			'sync_amount'				=> get_option( WCLSC_OPT_PREFIX . 'sync_amount',				'100'	 ),
			'sync_categories'			=> get_option( WCLSC_OPT_PREFIX . 'sync_categories',			''		 ),
			'sync_tags'					=> get_option( WCLSC_OPT_PREFIX . 'sync_tags',					''		 )
		);

		if( $this->api_settings['key'] )
			$this->merchantos = new MOSAPICall( $this->api_settings['key'], $this->api_settings['account_id'] );

		global $woocommerce;
		$this->logger = class_exists( 'WC_Logger' ) ? new WC_Logger() : $woocommerce->logger();
	}

	function ready(){
		$ready = ( $this->api_settings['key'] && $this->api_settings['account_id'] && $this->merchantos );
		if( ! $ready )
			$this->error = __( 'Missing API credentials.', 'wclsc' );

		return $ready;
	}

	function is_valid_request( $request ){
		$attributes = '@attributes';

		if( isset( $request->$attributes ) && is_object( $request->$attributes ) ){
			$this->total_records = $request->$attributes->count;
			return $request->$attributes->count;
		}

		// must be an error
		if( is_string( $request ) ){
			$this->error = $request;
		} else {
			if( isset( $request->httpCode ) && $request->httpCode != '200' )
				$this->error = (string) $request->httpMessage . ' (' . __( 'Error', 'wclsc' ) . ' ' . (string) $request->httpCode . ( $request->errorClass ? ': ' . $request->errorClass : '' ) . ')';
				if( $request->message )
					$this->error .= ' - ' . $request->message;
				if( $this->merchantos->last_call )
					$this->error .= "\r\n" . var_export( $this->merchantos->last_call, true );
			else
				$this->error = __( 'Error connecting to API', 'wclsc' );
		}

		$this->log_errors();

		return false;
	}

	function get_error(){
		return $this->error;
	}

	function log_errors(){
		if( $error = $this->get_error() ){
			if( $this->merchantos->api_call ){
				$error .= ' [' . $this->merchantos->api_call;
				if( $this->merchantos->api_action ){
					$error .= ': ' . $this->merchantos->api_action;
				}
				$error .= ']';
			}

			$this->logger->add( 'wclsc', $error );
		}
	}

	function get_account_id(){
		if( $this->merchantos ){ // don't use ready() here because account_id is not required
			$account = $this->merchantos->makeAPICall( 'Account' );

			if( isset( $account->Account ) && $account->Account->accountID )
				return (int) $account->Account->accountID;

			if( isset( $account->httpCode ) && $account->httpCode != '200' )
				$this->error = (string) $account->httpMessage . ' (' . __( 'Error', 'wclsc' ) . ' ' . (string) $account->httpCode . ')';
			else
				$this->error = __( 'Error connecting to API', 'wclsc' );
		}

		return false;
	}

	function get_shop_id(){
		return (int) get_option( WCLSC_OPT_PREFIX . 'shop_id', false );
	}

	function get_employee_id(){
		return (int) get_option( WCLSC_OPT_PREFIX . 'employee_id', false );
	}

	function get_register_id(){
		return (int) get_option( WCLSC_OPT_PREFIX . 'register_id', false );
	}

	function get_customer_type(){
		return get_option( WCLSC_OPT_PREFIX . 'customer_type', false );
	}

	function get_item_id( $product_id ){
		return get_post_meta( $product_id, WCLSC_META_PREFIX . 'item_id', true );
	}

	function lookup_product_by_item_id( $item_id ){
		$args = array(
			'post_type' => 'product',
			'post_status' => 'publish',
			'posts_per_page' => 1,
			'meta_query' => array(
				array(
					'key' => WCLSC_META_PREFIX . 'item_id',
					'value' => (int) $item_id
				)
			)
		);

		$query = new WP_Query( $args );

		$post_ids = wp_list_pluck( $query->posts, 'ID' );

		if( count( $post_ids ) )
			return array_shift( array_values( $post_ids ) );

		return false;
	}

	function lookup_product_by_sku( $sku ){
		$args = array(
			'post_type' => 'product',
			'posts_per_page' => 1,
			'meta_key' => '_sku',
			'meta_value' => $sku
		);

		$query = new WP_Query( $args );

		$post_ids = wp_list_pluck( $query->posts, 'ID' );

		if( count( $post_ids ) )
			return array_shift( array_values( $post_ids ) );

		return false;
	}

	function get_customer_id( $user_id = NULL ){
		if( $this->customer_id )
			return $this->customer_id;

		if( ! $user_id )
			$user_id = get_current_user_id();

		return (int) get_user_meta( $user_id, WCLSC_META_PREFIX . 'customer_id', true );
	}

	function get_guest_id( $order_id ){
		return get_post_meta( $order_id, WCLSC_META_PREFIX . 'customer_id', true );
	}

	function get_sale_id( $order_id ){
		if( $this->sale_id )
			return $this->sale_id;

		return get_post_meta( $order_id, WCLSC_META_PREFIX . 'sale_id', true );
	}

	function get_payment_id( $order_id ){
		return get_post_meta( $order_id, WCLSC_META_PREFIX . 'payment_id', true );
	}

	function get_shipping_item_id( $method ){
		$shipping_items = get_option( WCLSC_OPT_PREFIX . 'shipping_items', false );

		if( ! $shipping_items || ! is_array( $shipping_items ) ){
			$shipping_items = array();
		}

		if( ! isset( $shipping_items[ $method ] ) ){
			$shipping_data = array(
				'description' => $method,
				'tax' => true
			);
			$new_shipping_item = $this->create_item( $shipping_data );

			$shipping_items[ $method ] = $new_shipping_item->itemID;

			update_option( WCLSC_OPT_PREFIX . 'shipping_items', $shipping_items );
		}

		return $shipping_items[ $method ];
	}

	function get_tax_category_id( $rate_code, $rate_id ){

		if( ! $rate_code ){
			$_tax = class_exists( 'WC_Tax' ) ? new WC_Tax() : NULL;

			if( $_tax ){
				$rate_code = $_tax->get_rate_code( $rate_id );
			}

			if( ! $id ){
				#$rate_code = $tax_class . '_' . strtolower( str_replace( ' ', '-', $rate_name ) );
				return false;
			}
		}

		return get_option( WCLSC_OPT_PREFIX . 'tax_category_' . $rate_code, 0 );
	}

	function format_money( $money ){
		$money = number_format( $money, 2, '.', '' );

		return (string) $money;
	}

	function get_integrations(){
		if( ! $this->ready() )
			return false;

		if( $transient = $this->get_transient( 'integrations' ) )
			return $transient;

		$integrations = $this->merchantos->makeAPICall( 'Account.Integration', 'Read', NULL, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $integrations ) == 1 ){
			$value = array( $integrations->Integration );
			$this->set_transient( 'integrations', $value );
			return $value;
		} elseif( $this->is_valid_request( $integrations ) > 1 ){
			$value = $integrations->Integration;
			$this->set_transient( 'integrations', $value );
			return $value;
		}

		return false;
	}

	function get_shops(){
		if( ! $this->ready() )
			return false;

		if( $transient = $this->get_transient( 'shops' ) )
			return $transient;

		$shops = $this->merchantos->makeAPICall( 'Account.Shop', 'Read' );

		if( $this->is_valid_request( $shops ) == 1 ){
			$value = array( $shops->Shop );
			$this->set_transient( 'shops', $value );
			return $value;
		} elseif( $this->is_valid_request( $shops ) > 1 ){
			$value = $shops->Shop;
			$this->set_transient( 'shops', $value );
			return $value;
		}

		return array();
	}


	function get_customer( $customer_id ){
		if( ! $this->ready() )
			return false;

		if( $transient = $this->get_transient( 'customer-' . $customer_id ) )
			return $transient;

		// create search query
		$lookup = array(
			'archived' => 0,
			'limit' => '50',
			'load_relations' => 'all',
			'customerID' => $customer_id
		);

		$customer = $this->merchantos->makeAPICall( 'Account.Customer', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $customer ) == 1 ){
			$value = $customer->Customer;
			$this->set_transient( 'customer-' . $customer_id, $value );
			return $value;
		}

		return false;
	}

	function create_customer( $customer_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $customer_data ) || ! count( $customer_data ) )
			return false;

		$customer = $this->merchantos->makeAPICall( 'Account.Customer', 'Create', NULL, $customer_data, array( 'load_relations' => json_encode( array( 'Contact' ) ) ) );

		$this->logger->add( 'wclsc', 'CREATE CUSTOMER: ' . "\r\n" . var_export( $this->merchantos->last_call, true ) );

		if( $this->is_valid_request( $customer ) ) // should only ever return 1 customer.
			return $customer->Customer;

		return false;
	}

	function update_customer( $customer_id, $customer_data ){
		if( ! $this->ready() )
			return false;

		if( ! $customer_id || ! is_array( $customer_data ) || ! count( $customer_data ) )
			return false;

		$customer = $this->merchantos->makeAPICall( 'Account.Customer', 'Update', $customer_id, $customer_data, array( 'load_relations' => json_encode( array( 'Contact' ) ) ) );

		$this->logger->add( 'wclsc', 'UPDATE CUSTOMER: ' . "\r\n" . var_export( $this->merchantos->last_call, true ) );

		if( $this->is_valid_request( $customer ) ) // should only ever return 1 customer.
			return $customer->Customer;

		return false;
	}

	/**
	 * Check customers for an already existing email address.
	 * @param  string $email Email Address
	 * @return mixed        Matching Customer or False if not found.
	 */
	function email_exists( $email ){
		if( ! $this->ready() )
			return false;

		// email address must have a value
		if( ! $email )
			return false;

		// create search query
		$lookup = array(
			'archived' => 0,
			'load_relations' => 'all',
			'limit' => 500
		);

		$customers = $this->merchantos->makeAPICall( 'Account.Customer', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $customers ) <= 0 )
			return false;

		if( $this->is_valid_request( $customers ) == 1 ){
			// match email
			if( $customers->Customer->Contact->Emails->ContactEmail->address == $email ){
				return $customers->Customer;
			}
		} else {
			foreach( $customers->Customer as $result ){
				if( $result->Contact->Emails->ContactEmail->address == $email ){
					return $result;
				}
			}
		}

		return false;
	}

	function lookup_customer( $customer_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $customer_data ) || ! count( $customer_data ) )
			return false;

		// all fields must be set
		if( ! isset( $customer_data['firstName'] ) || ! isset( $customer_data['lastName'] ) || ! isset( $customer_data['Contact']['Emails']['ContactEmail']['address'] ) )
			return false;

		// all fields must have a value
		if( ! $customer_data['firstName'] || ! $customer_data['lastName'] || ! $customer_data['Contact']['Emails']['ContactEmail']['address'] )
			return false;

		if( $transient = $this->get_transient( 'customer-email-' . $customer_data['Contact']['Emails']['ContactEmail']['address'] ) )
			return $transient;

		// create search query
		$lookup = array(
			'archived' => 0,
			'limit' => '50',
			'load_relations' => 'all',
			'firstName' => $customer_data['firstName'],
			'lastName' => $customer_data['lastName']
		);

		$customer = $this->merchantos->makeAPICall( 'Account.Customer', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $customer ) <= 0 )
			return false;

		if( $this->is_valid_request( $customer ) == 1 ){
			// match email
			if( $customer->Customer->Contact->Emails->ContactEmail->address == $customer_data['Contact']['Emails']['ContactEmail']['address'] ){
				$value = $customer->Customer;
				$this->set_transient( 'customer-email-' . $customer_data['Contact']['Emails']['ContactEmail']['address'], $value );
				return $value;
			}
		} else {
			foreach( $customer->Customer as $result ){
				if( $result->Contact->Emails->ContactEmail->address == $customer_data['Contact']['Emails']['ContactEmail']['address'] ){
					$this->set_transient( 'customer-email-' . $customer_data['Contact']['Emails']['ContactEmail']['address'], $result );
					return $result;
				}
			}
		}

		return false;
	}

	function get_item( $item_id, $fresh = false ){
		if( ! $this->ready() )
			return false;

		if( ! $fresh && $transient = $this->get_transient( 'item-' . $item_id ) )
			return $transient;

		// create search query
		$lookup = array(
			'archived' => 1,
			'limit' => '2',
			'load_relations' => 'all',
			'itemID' => $item_id
		);

		$item = $this->merchantos->makeAPICall( 'Account.Item', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $item ) == 1 ){
			$value = $item->Item;
			$this->set_transient( 'item-' . $item_id, $value );
			return $value;
		}

		return false;
	}

	function get_item_price( $item, $use_type = 'Default' ){
		if( isset( $item->Prices->ItemPrice ) ){
			if( is_array( $item->Prices->ItemPrice ) ){
				foreach( $item->Prices->ItemPrice as $price ){
					if( $price->useType == $use_type )
						return $price->amount;
				}
			}
		}
		return false;
	}

	function get_item_qoh( $item ){
		$shop_id = $this->api_settings['shop_id'];
		if( isset( $item->ItemShops->ItemShop ) ){
			if( is_array( $item->ItemShops->ItemShop ) ){
				foreach( $item->ItemShops->ItemShop as $shop ){
					if( $shop->shopID == $shop_id )
						return $shop->qoh;
				}
			}
		}
		return false;
	}

	function get_ecommerce_data( $item ){
		if( ! $this->ready() )
			return false;

		$data = array(
			'weight' => '',
			'width' => '',
			'height' => '',
			'length' => '',
			'longDescription' => '',
			'shortDescription' => ''
		);

		if( isset( $item->ItemECommerce ) ){
			if( isset( $item->ItemECommerce->weight ) )
				$data['weight'] = $item->ItemECommerce->weight;
			if( isset( $item->ItemECommerce->width ) )
				$data['width'] = $item->ItemECommerce->width;
			if( isset( $item->ItemECommerce->height ) )
				$data['height'] = $item->ItemECommerce->height;
			if( isset( $item->ItemECommerce->length ) )
				$data['length'] = $item->ItemECommerce->length;
			if( isset( $item->ItemECommerce->longDescription ) )
				$data['longDescription'] = $item->ItemECommerce->longDescription;
			if( isset( $item->ItemECommerce->shortDescription ) )
				$data['shortDescription'] = $item->ItemECommerce->shortDescription;
		}

		return $data;
	}

	function create_item( $item_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $item_data ) || ! count( $item_data ) )
			return false;

		$item = $this->merchantos->makeAPICall( 'Account.Item', 'Create', NULL, $item_data, array( 'load_relations' => json_encode( 'all' ) ) );

		$this->logger->add( 'wclsc', 'CREATE ITEM: ' . "\r\n" . var_export( $this->merchantos->last_call, true ) );

		if( $this->is_valid_request( $item ) ) // should only ever return 1 item.
			return $item->Item;

		return false;
	}

	function update_item( $item_id, $item_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $item_data ) || ! count( $item_data ) )
			return false;

		$item = $this->merchantos->makeAPICall( 'Account.Item', 'Update', $item_id, $item_data, array( 'load_relations' => json_encode( 'all' ) ) );

		$this->logger->add( 'wclsc', 'UPDATE ITEM: ' . "\r\n" . var_export( $this->merchantos->last_call, true ) );

		if( $this->is_valid_request( $item ) ) // should only ever return 1 item.
			return $item->Item;

		return false;
	}

	function lookup_item( $item_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $item_data ) || ! count( $item_data ) )
			return false;

		// required field must be present and have a value
		if( ! array_key_exists( 'customSku', $item_data ) && ! array_key_exists( 'upc', $item_data ) && ! array_key_exists( 'ean', $item_data ) && ! array_key_exists( 'manufacturerSku', $item_data ) && ! array_key_exists( 'systemSku', $item_data ) )
			return false;

		if( isset( $item_data['customSku'] ) )
			$key = 'customSku';
		elseif( isset( $item_data['upc'] ) )
			$key = 'upc';
		elseif( isset( $item_data['ean'] ) )
			$key = 'ean';
		elseif( isset( $item_data['manufacturerSku'] ) )
			$key = 'manufacturerSku';
		elseif( isset( $item_data['systemSku'] ) )
			$key = 'systemSku';

		if( ! $item_data[ $key ] )
			return false;

		if( $transient = $this->get_transient( 'item-' . $key . '-' . $item_data[ $key ] ) )
			return $transient;

		// create search query
		$lookup = array(
			'archived' => 0,
			'limit' => '2',
			$key => $item_data[ $key ],
			'load_relations' => 'all'
		);

		$item = $this->merchantos->makeAPICall( 'Account.Item', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $item ) == 1 ){
			$value = $item->Item;

			if( isset( $value->itemID ) && $value->itemID ){
				$this->set_transient( 'item-' . $value->itemID, $value );
			}

			$this->set_transient( 'item-' . $key . '-' . $item_data[ $key ], $value );

			return $value;
		}

		return false;
	}

	function get_items( $params = array(), $relations = 'all' ){
		if( ! $this->ready() )
			return false;

		$args = array_merge( array(
			'archived' => 0,
			'limit' => 100,
			'offset' => 0,
			'orderby' => 'description',
			'orderby_desc' => 0
		), $params );

		if( $args['archived'] == 'only' ){
			$archived = $args['archived'];
		} else {
			$archived = $args['archived'] ? 1 : 0;
		}

		$lookup = array(
			'archived' => $archived,
			'limit' => (int) $args['limit'],
			'offset' => (int) $args['offset'],
			'orderby' => $args['orderby'],
			'orderby_desc' => $args['orderby_desc']
		);

		if( $relations ){
			if( is_array( $relations ) ){
				$relations = json_encode( $relations );
			} elseif( is_string( $relations ) && $relations != 'all' && json_decode( $relations ) !== false ) {
				$relations = json_encode( array( $relations ) );
			}
			$lookup['load_relations'] = $relations;
		}

		$items = $this->merchantos->makeAPICall( 'Account.Item', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $items ) == 1 ){
			return array( $items->Item );
		} elseif( $this->is_valid_request( $items ) > 1 ){
			return $items->Item;
		}

		return array();
	}

	function lookup_tag( $tag_name ){
		if( ! $this->ready() )
			return false;

		if( $transient = $this->get_transient( 'tag-' . $tag_name ) )
			return $transient;

		$lookup = array(
			'archived' => 0,
			'limit' => '1',
			'name' => $tag_name
		);

		$tag = $this->merchantos->makeAPICall( 'Account.Tag', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $tag ) ){
			$value = $tag->Tag;
			$this->set_transient( 'tag-' . $tag_name, $value );
			return $value;
		}

		return false;
	}

	function create_tag( $tag_name ){
		if( ! $this->ready() )
			return false;

		$tag_data = array(
			'name' => trim( $tag_name )
		);

		$tag = $this->merchantos->makeAPICall( 'Account.Tag', 'Create', NULL, $tag_data );

		if( $this->is_valid_request( $tag ) ){
			return $tag->Tag;
		}

		return false;
	}

	function get_sale( $sale_id ){
		if( ! $this->ready() )
			return false;

		if( ! $sale_id )
			return false;

		if( $transient = $this->get_transient( 'sale-' . $sale_id ) )
			return $transient;

		$sale = $this->merchantos->makeAPICall( 'Account.Sale', 'Read', $sale_id, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $sale ) ){ // should only ever return 1 sale.
			$value = $sale->Sale;
			$this->set_transient( 'sale-' . $sale_id, $value );
			return $value;
		}

		return false;
	}

	function create_sale( $sale_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $sale_data ) || ! count( $sale_data ) )
			return false;

		$sale = $this->merchantos->makeAPICall( 'Account.Sale', 'Create', NULL, $sale_data, array( 'load_relations' => 'all' ) );

		$this->logger->add( 'wclsc', 'CREATE SALE: ' . "\r\n" . var_export( $this->merchantos->last_call, true ) );

		if( $this->is_valid_request( $sale ) ) // should only ever return 1 sale.
			return $sale->Sale;

		return false;
	}

	function update_sale( $sale_id, $sale_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $sale_data ) || ! count( $sale_data ) )
			return false;

		$sale = $this->merchantos->makeAPICall( 'Account.Sale', 'Update', $sale_id, $sale_data, array( 'load_relations' => 'all' ) );

		$this->logger->add( 'wclsc', 'UPDATE SALE: ' . "\r\n" . var_export( $this->merchantos->last_call, true ) );

		if( $this->is_valid_request( $sale ) ) // should only ever return 1 sale.
			return $sale->Sale;

		return false;
	}

	function create_sale_line( $sale_line_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $sale_line_data ) || ! count( $sale_line_data ) )
			return false;

		$sale_line = $this->merchantos->makeAPICall( 'Account.Sale/SaleLine', 'Create', NULL, $sale_line_data );

		$this->logger->add( 'wclsc', 'CREATE SALE LINE: ' . "\r\n" . var_export( $this->merchantos->last_call, true ) );

		if( $this->is_valid_request( $sale_line ) ) // should only ever return 1 sale line.
			return $sale_line->SaleLine;

		return false;
	}

	function lookup_payment_type( $payment_type_name ){
		if( ! $this->ready() )
			return false;

		if( ! $payment_type_name )
			return false;

		if( $transient = $this->get_transient( 'payment_type-' . $payment_type_name ) )
			return $transient;

		$lookup = array(
			'archived' => 0,
			'limit' => '50',
			'load_relations' => 'all',
			'name' => $payment_type_name
		);

		$payment_type = $this->merchantos->makeAPICall( 'Account.PaymentType', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $payment_type ) ){ // should only ever return 1 payment Type.
			$value = $payment_type->PaymentType;
			$this->get_transient( 'payment_type-' . $payment_type_name, $value );
			return $value;
		}

		return false;
	}

	function get_tax_categories(){
		if( ! $this->ready() )
			return false;

		if( $transient = $this->get_transient( 'tax_categories' ) )
			return $transient;

		$tax_categories = $this->merchantos->makeAPICall( 'Account.TaxCategory', 'Read' );

		if( $this->is_valid_request( $tax_categories ) == 1 ){
			$value = array( $tax_categories->TaxCategory );
			$this->set_transient( 'tax_categories', $value );
			return $value;
		} elseif( $this->is_valid_request( $tax_categories ) > 1 ){
			$value = $tax_categories->TaxCategory;
			$this->set_transient( 'tax_categories', $value );
			return $value;
		}

		return array();
	}

	function get_employees(){
		if( ! $this->ready() )
			return false;

		if( $transient = $this->get_transient( 'employees' ) )
			return $transient;

		$employees = $this->merchantos->makeAPICall( 'Account.Employee', 'Read', NULL, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $employees ) == 1 ){
			$value = array( $employees->Employee );
			$this->set_transient( 'employees', $value );
			return $value;
		} elseif( $this->is_valid_request( $employees ) > 1 ){
			$value = $employees->Employee;
			$this->set_transient( 'employees', $value );
			return $value;
		}

		return array();
	}

	function get_registers(){
		if( ! $this->ready() )
			return false;

		if( $transient = $this->get_transient( 'registers' ) )
			return $transient;

		$registers = $this->merchantos->makeAPICall( 'Account.Register', 'Read', NULL, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $registers ) == 1 ){
			$value = array( $registers->Register );
			$this->set_transient( 'registers', $value );
			return $value;
		} elseif( $this->is_valid_request( $registers ) > 1 ){
			$value = $registers->Register;
			$this->set_transient( 'registers', $value );
			return $value;
		}

		return array();
	}

	function get_customer_types(){
		if( ! $this->ready() )
			return false;

		if( $transient = $this->get_transient( 'customer_types' ) )
			return $transient;

		$customer_types = $this->merchantos->makeAPICall( 'Account.CustomerType', 'Read', NULL, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $customer_types ) == 1 ){
			$value = array( $customer_types->CustomerType );
			$this->set_transient( 'customer_types', $value );
			return $value;
		} elseif( $this->is_valid_request( $customer_types ) > 1 ){
			$value = $customer_types->CustomerType;
			$this->set_transient( 'customer_types', $value );
			return $value;
		}

		return array();
	}

	function set_transient( $key, $value, $expiration = false ){
		if( $expiration === false )
			$expiration = HOUR_IN_SECONDS * 4; // Default to 4 hours
		return set_transient( $this->prefix . 'transient_' . $key, $value, $expiration );
	}

	function get_transient( $key ){
		return false;
		return get_transient( $this->prefix . 'transient_' . $key );
	}

	function delete_transient( $key ){
		return delete_transient( $this->prefix . 'transient_' . $key );
	}
}
