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
	 * List of current errors
	 * @var array
	 */
	var $error_log = array();

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

	function __construct( $api_key = NULL ){
		$this->api_settings = array(
			'key' => get_option( WCLSC_OPT_PREFIX . 'api_key', $api_key ),
			'password' => get_option( WCLSC_OPT_PREFIX . 'api_password', 'apikey' ),
			'account_id' => get_option( WCLSC_OPT_PREFIX . 'account_id', '' ),
			'customer_contact_address' => get_option( WCLSC_OPT_PREFIX . 'customer_contact_address', 'none' )
		);

		if( $this->api_settings['key'] )
			$this->merchantos = new MOSAPICall( $this->api_settings['key'], $this->api_settings['account_id'] );
	}

	function ready(){
		$ready = ( $this->api_settings['key'] && $this->api_settings['account_id'] && $this->merchantos );
		if( ! $ready )
			$this->error = __( 'Missing API credentials.', 'wclsc' );

		return $ready;
	}

	function get_error(){
		return $this->error;
	}

	function log_errors(){
		if( $error = $this->get_error() ){
			$this->get_error_log();
			if( $this->merchantos->api_call ){
				$error .= ' [' . $this->merchantos->api_call;
				if( $this->merchantos->api_action ){
					$error .= ': ' . $this->merchantos->api_action;
				}
				$error .= ']';
			}

			$this->error_log[ current_time( 'timestamp' ) . '|' . uniqid() ] = $error;
			update_option( WCLSC_OPT_PREFIX . 'error_log', $this->error_log );
		}
	}

	function get_error_log(){
		if( ! count( $this->error_log ) )
			$this->error_log = get_option( WCLSC_OPT_PREFIX . 'error_log', array() );

		return $this->error_log;
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
		return get_option( WCLSC_OPT_PREFIX . 'shop_id', false );
	}

	function get_employee_id(){
		return get_option( WCLSC_OPT_PREFIX . 'employee_id', false );
	}

	function get_register_id(){
		return get_option( WCLSC_OPT_PREFIX . 'register_id', false );
	}

	function get_customer_type(){
		return get_option( WCLSC_OPT_PREFIX . 'customer_type', false );
	}

	function get_item_id( $product_id ){
		return get_post_meta( $product_id, WCLSC_META_PREFIX . 'item_id', true );
	}

	function get_customer_id( $user_id = NULL ){
		if( $this->customer_id )
			return $this->customer_id;

		if( ! $user_id )
			$user_id = get_current_user_id();

		return get_user_meta( $user_id, WCLSC_META_PREFIX . 'customer_id', true );
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

		return get_option( WCLSC_OPT_PREFIX . 'tax_category_' . $rate_code, true );
	}

	function format_money( $money ){
		$money = number_format( $money, 2, '.', '' );

		return (string) $money;
	}


	function is_valid_request( $request ){
		$attributes = '@attributes';

		if( isset( $request->$attributes ) && is_object( $request->$attributes ) ){
			return $request->$attributes->count;
		}

		// must be an error
		if( isset( $request->httpCode ) && $request->httpCode != '200' )
			$this->error = (string) $request->httpMessage . ' (' . __( 'Error', 'wclsc' ) . ' ' . (string) $request->httpCode . ': ' . $request->errorClass . ')';
			if( $request->message )
				$this->error .= ' - ' . $request->message;
		else
			$this->error = __( 'Error connecting to API', 'wclsc' );

		$this->log_errors();

		return false;
	}

	function get_shops(){
		if( ! $this->ready() )
			return false;

		$shops = $this->merchantos->makeAPICall( 'Account.Shop', 'Read' );

		if( $this->is_valid_request( $shops ) == 1 ){
			return array( $shops->Shop );
		} elseif( $this->is_valid_request( $shops ) > 1 ){
			return $shops->Shop;
		}

		return array();
	}


	function get_customer( $customer_id ){
		if( ! $this->ready() )
			return false;

		// create search query
		$lookup = array(
			'archived' => 0,
			'limit' => '50',
			'load_relations' => 'all',
			'customerID' => $customer_id
		);

		$customer = $this->merchantos->makeAPICall( 'Account.Customer', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $customer ) == 1 ){
			return $customer->Customer;
		}

		return false;
	}

	function create_customer( $customer_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $customer_data ) || ! count( $customer_data ) )
			return false;

		$customer = $this->merchantos->makeAPICall( 'Account.Customer', 'Create', NULL, $customer_data, array( 'load_relations' => json_encode( array( 'Contact' ) ) ) );

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

		if( $this->is_valid_request( $customer ) ) // should only ever return 1 customer.
			return $customer->Customer;

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
				return $customer->Customer;
			}
		} else {
			foreach( $customer->Customer as $result ){
				if( $result->Contact->Emails->ContactEmail->address == $customer_data['Contact']['Emails']['ContactEmail']['address'] ){
					return $result;
				}
			}
		}

		return false;
	}

	function get_item( $item_id ){
		if( ! $this->ready() )
			return false;

		// create search query
		$lookup = array(
			'archived' => 0,
			'limit' => '50',
			'load_relations' => 'all',
			'itemID' => $item_id
		);

		$item = $this->merchantos->makeAPICall( 'Account.Item', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $item ) == 1 ){
			return $item->Item;
		}

		return false;
	}

	function create_item( $item_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $item_data ) || ! count( $item_data ) )
			return false;

		$item = $this->merchantos->makeAPICall( 'Account.Item', 'Create', NULL, $item_data, array( 'load_relations' => json_encode( 'all' ) ) );

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
		if( ( ! isset( $item_data['customSku'] ) || ! $item_data['customSku'] ) )
			return false;

		// create search query
		$lookup = array(
			'archived' => 0,
			'limit' => '2',
			'customSku' => $item_data['customSku'],
			'load_relations' => 'all'
		);

		$item = $this->merchantos->makeAPICall( 'Account.Item', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $item ) == 1 ){
			return $item->Item;
		}

		return false;
	}

	function lookup_tag( $tag_name ){
		if( ! $this->ready() )
			return false;

		$lookup = array(
			'archived' => 0,
			'limit' => '1',
			'name' => $tag_name
		);

		$tag = $this->merchantos->makeAPICall( 'Account.Tag', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $tag ) ){
			return $tag->Tag;
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

		$sale = $this->merchantos->makeAPICall( 'Account.Sale', 'Read', $sale_id, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $sale ) ) // should only ever return 1 sale.
			return $sale->Sale;

		return false;
	}

	function create_sale( $sale_data ){
		if( ! $this->ready() )
			return false;

		if( ! is_array( $sale_data ) || ! count( $sale_data ) )
			return false;

		$sale = $this->merchantos->makeAPICall( 'Account.Sale', 'Create', NULL, $sale_data, array( 'load_relations' => 'all' ) );

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

		if( $this->is_valid_request( $sale_line ) ) // should only ever return 1 sale line.
			return $sale_line->SaleLine;

		return false;
	}

	function lookup_payment_type( $payment_type_name ){
		if( ! $this->ready() )
			return false;

		if( ! $payment_type_name )
			return false;

		$lookup = array(
			'archived' => 0,
			'limit' => '50',
			'load_relations' => 'all',
			'name' => $payment_type_name
		);

		$payment_type = $this->merchantos->makeAPICall( 'Account.PaymentType', 'Read', NULL, array(), $lookup );

		if( $this->is_valid_request( $payment_type ) ) // should only ever return 1 payment Type.
			return $payment_type->PaymentType;

		return false;
	}

	function get_tax_categories(){
		if( ! $this->ready() )
			return false;

		$tax_categories = $this->merchantos->makeAPICall( 'Account.TaxCategory', 'Read' );

		if( $this->is_valid_request( $tax_categories ) == 1 ){
			return array( $tax_categories->TaxCategory );
		} elseif( $this->is_valid_request( $tax_categories ) > 1 ){
			return $tax_categories->TaxCategory;
		}

		return array();
	}

	function get_employees(){
		if( ! $this->ready() )
			return false;

		$employees = $this->merchantos->makeAPICall( 'Account.Employee', 'Read', NULL, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $employees ) == 1 ){
			return array( $employees->Employee );
		} elseif( $this->is_valid_request( $employees ) > 1 ){
			return $employees->Employee;
		}

		return array();
	}

	function get_registers(){
		if( ! $this->ready() )
			return false;

		$registers = $this->merchantos->makeAPICall( 'Account.Register', 'Read', NULL, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $registers ) == 1 ){
			return array( $registers->Register );
		} elseif( $this->is_valid_request( $registers ) > 1 ){
			return $registers->Register;
		}

		return array();
	}

	function get_customer_types(){
		if( ! $this->ready() )
			return false;

		$customer_types = $this->merchantos->makeAPICall( 'Account.CustomerType', 'Read', NULL, array(), array( 'load_relations' => 'all' ) );

		if( $this->is_valid_request( $customer_types ) == 1 ){
			return array( $customer_types->CustomerType );
		} elseif( $this->is_valid_request( $customer_types ) > 1 ){
			return $customer_types->CustomerType;
		}

		return array();
	}
}
