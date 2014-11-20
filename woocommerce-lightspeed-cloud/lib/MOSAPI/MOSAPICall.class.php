<?php

if( class_exists( 'MOSAPICall' ) ) return;

if( ! class_exists( 'MOScURL' ) ) return;

class MOSAPICall {
	protected $_mos_api_url = 'https://api.merchantos.com/API/';

	protected $_api_key;
	protected $_account_num;

	/**
	 * MerchantOS API Call
	 * @var string
	 */
	var $api_call;

	/**
	 * MerchantOS API Action
	 * @var string
	 */
	var $api_action;

	public function __construct( $api_key, $account_num )
	{
		$this->_api_key = $api_key;
		$this->_account_num = $account_num;
	}

	public function makeAPICall( $controlname, $action = 'Read', $unique_id = null, $data = array(), $query_str = '', $emitter = 'json' )
	{

		$this->api_call = $controlname;
		$this->api_action = $action;

		$custom_request = 'GET';

		switch ( $action )
		{
			case 'Create':
				$custom_request = 'POST';
				break;
			case 'Read':
				$custom_request = 'GET';
				break;
			case 'Update':
				$custom_request = 'PUT';
				break;
			case 'Delete':
				$custom_request = 'DELETE';
				break;
		}

		$curl = new MOScURL();
		$curl->setBasicAuth( $this->_api_key, 'apikey' );
		$curl->setVerifyPeer( false );
		$curl->setVerifyHost( 0 );
		$curl->setCustomRequest( $custom_request );

		$control_url = $this->_mos_api_url . str_replace( '.', '/', str_replace('Account.', 'Account.' . $this->_account_num . '.', $controlname ) );

		if ( $unique_id ) {
			$control_url .= '/' . $unique_id;
		}

		if ( $query_str ) {
			if( is_array( $query_str ) )
				$query_str = $this->build_query_string( $query_str );

			$control_url .= '.' . $emitter . '?' . $query_str;
		} else {
			$control_url .= '.' . $emitter;
		}

		if( is_array( $data ) && count( $data ) > 0 ){
			$body = json_encode( $data );
		} elseif ( is_object( $data ) ) {
			$body = $data->asXML();
		} else {
			$body = '';
		}

		return self::_makeCall( $curl, $control_url, $body );
	}

	protected static function _makeCall( $curl, $url, $body )
	{
		$result = $curl->call( $url, $body );

		try {
			$return = json_decode( $result );
		} catch( Exception $e ){
			throw new Exception( 'MerchantOS API Call Error: ' . $e->getMessage() . ', Response: ' . $result );
		}

		if ( ! is_object( $return ) ) {
			try {
				$return = new SimpleXMLElement( $result );
			} catch ( Exception $e ) {
				throw new Exception( 'MerchantOS API Call Error: ' . $e->getMessage() . ', Response: ' . $result );
			}

			if ( ! is_object( $return ) ) {
				throw new Exception( 'MerchantOS API Call Error: Could not parse XML, Response: ' . $result );
			}
		}

		return $return;
	}

	private function build_query_string( $data ){
		if( function_exists( 'http_build_query' ) ){
			return http_build_query( $data );
		} else {
			$qs = '';
			foreach( $data as $key => $value ){
				$append = urlencode( $key ) . '=' . urlencode( $value );
				$qs .= $qs ? '&' . $append : $append;
			}
			return $qs;
		}
	}
}
