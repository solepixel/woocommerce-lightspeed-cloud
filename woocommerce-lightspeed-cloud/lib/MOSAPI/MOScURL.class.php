<?php

if( class_exists( 'MOScURL' ) ) return;

/**
 * MOScURL wrapps cURL calls in an extensable object. Makes cURL easier, and things that use it more testable.
 *
 * @author Justin Laing
 * @version 1.0
 * @package wrappers
 */

/**
 * MOScURL class
 * @author Justin Laing
 * @version 1.0
 * @package wrappers
 *
 */
class MOScURL
{
	protected $user_agent;
	protected $returntransfer;
	protected $timeout;
	protected $total_timeout;

	protected $verifypeer;
	protected $verifyhost;

	protected $cainfo;

	protected $httpheader;

	/**
	 * A custom request method to use in making the request.
	 * @var string
	 */
	protected $customrequest;

	/**
	 * The authentication type to use.
	 * @var string
	 */
	protected $authtype;

	/**
	 * The username to use with authentication
	 * @var string
	 */
	protected $username;

	/**
	 * The password to use with authentication
	 * @var string
	 */
	protected $password;

	/**
	 * Whether to return the headers with the server response
	 * @var boolean
	 */
	protected $return_headers;

	/**
	 * Set a cookie(s) to send with the request
	 * @var string
	 */
	protected $cookie;

	/**
	 * Whether to turn on certain debug options like CURLINFO_HEADER_OUT
	 * @var boolean
	 */
	protected $debug = false;

	protected $connection;


	/**
	 * Sets the defaults
	 * user agent = "MerchantOS"
	 * return transfer = true
	 * timeout = 60
	 * verify peer = true
	 * verify host = 2 (yes)
	 * http header = nothing
	 *
	 */
	public function __construct()
	{
		$this->setUserAgent("MerchantOS");
		$this->setReturnTransfer(true);
		$this->setTimeout(60);

		$this->setVerifyPeer(true);
		$this->setVerifyHost(2);

		// For testing, curl on the production servers pull the correct certs.
		if( defined( 'INCLUDE_DIR' ) && defined( 'FORM_ROOT' ) )
			$this->setCaInfo( INCLUDE_DIR . FORM_ROOT . '\misc_utils\mos_queue\certs\cacert.pem' );

		$this->setHTTPHeader(false);

		$this->connection = false;
	}

	/**
	 * Set the user agent used: CURLOPT_USERAGENT
	 *
	 * @param string $agent
	 */
	public function setUserAgent($agent)
	{
		$this->user_agent = $agent;
	}

	/**
	 * Sets CURLOPT_RETURNTRANSFER
	 *
	 * @param boolean $returntransfer
	 */
	public function setReturnTransfer($returntransfer)
	{
		$this->returntransfer = $returntransfer;
	}

	/**
	 * Sets CURLOPT_CONNECTTIMEOUT
	 *
	 * @param integer $timeout
	 */
	public function setTimeout($timeout)
	{
		$this->timeout = $timeout;
	}

	/**
	 * Sets CURLOPT_TIMEOUT, this is the total time the curl->call can take. setTimeout by contrast is just a timeout for connecting.
	 *
	 * @param integer $timeout
	 */
	public function setTotalTimeout($timeout)
	{
		$this->total_timeout = $timeout;
	}

	/**
	 * Sets CURLOPT_SSL_VERIFYPEER
	 *
	 * @param boolean $verifypeer
	 */
	public function setVerifyPeer($verifypeer)
	{
		$this->verifypeer = $verifypeer;
	}

	/**
	 * Sets CURLOPT_SSL_VERIFYHOST
	 *
	 * @param integer $verifyhost 0,1,2
	 */
	public function setVerifyHost($verifyhost)
	{
		$this->verifyhost = $verifyhost;
	}

	/**
	 * Sets CURLOPT_CAINFO
	 *
	 * @param string $cainfo False or path to cacert.pem type file
	 */
	public function setCaInfo($cainfo)
	{
		$this->cainfo = $cainfo;
	}

	/**
	 * Sets CURLOPT_HTTPHEADER
	 *
	 * @param array $httpheader
	 */
	public function setHTTPHeader($httpheader)
	{
		$this->httpheader = $httpheader;
	}

	/**
	 * Sets CURLOPT_CUSTOMREQUEST
	 *
	 * @param string $customrequest The custom request method to use.
	 */
	public function setCustomRequest($customrequest)
	{
		$this->customrequest = $customrequest;
	}

	/**
	 * Sets CURLOPT_HTTPAUTH and CURLOPT_USERPWD
	 *
	 * @param string $username The username to send for authentication.
	 * @param string $password The password to send for authentication.
	 */
	public function setBasicAuth($username, $password)
	{
		$this->authtype = 'basic';

		$this->username = $username;

		$this->password = $password;
	}

	/**
	 * Sets CURLOPT_HEADER
	 *
	 * @param boolean $return_headers Whether to return headers from our request
	 */
	public function setReturnHeaders($return_headers)
	{
		$this->return_headers = $return_headers;
	}

	/**
	 * Sets CURLOPT_COOKIE
	 * @param string Cookies to send in the server call, From php manual:
	 * The contents of the "Cookie: " header to be used in the HTTP request. Note that multiple cookies are separated with a semicolon followed by a space (e.g., "fruit=apple; colour=red")
	 */
	public function setCookie($cookie)
	{
		$this->cookie = $cookie;
	}

	/**
	 * Sets CURLINFO_HEADER_OUT and CURLOPT_HEADER
	 * You need to call getInfo(CURLINFO_HEADER_OUT) in order to get the request headers.
	 *
	 * @param boolean $debug Whether to return headers from our request and the response
	 */
	public function setDebug($debug)
	{
		$this->debug = $debug;
		$this->setReturnHeaders($debug);
	}

	/**
	 * Gets the information about the last transfer. If the optional parameter $option is not specified all info about the connection is returned in an array
	 *
	 * @link http://us3.php.net/manual/en/function.curl-getinfo.php
	 * @param constant $option An optional CURLINFO constant to specifically request.
	 * @return string | array
	 */
	public function getInfo($option = null)
	{
		if (!empty($option))
		{
			$info = $this->wrapper_curl_getinfo($option);
		}
		else
		{
			$info = $this->wrapper_curl_getinfo();
		}

		return $info;
	}

	/**
	 * Inits the curl connection (curl_init()) you don't have to call this, call() will do it automatically
	 *
	 */
	public function init()
	{
		$this->connection = $this->wrapper_curl_init();
	}

	/**
	 * Make a cURL call, sets the options and then makes the call
	 *
	 * @param string $url The URL you want to call with cURL
	 * @param string|false $postfields The CURLOPT_POSTFIELDS if there are any
	 * @return string The response returned from the URL called
	 * @throws Exception If cURL hits an error it will be thrown as an Exception the exception message will be curl error message, exception code will be curl error number.
	 */
	public function call($url,$postfields=false)
	{
		if (!$this->connection)
		{
			$this->init();
		}

		$this->wrapper_curl_setopt(CURLOPT_URL, $url);
		$this->wrapper_curl_setopt(CURLOPT_USERAGENT, $this->user_agent);
		$this->wrapper_curl_setopt(CURLOPT_RETURNTRANSFER, $this->returntransfer);
		$this->wrapper_curl_setopt(CURLOPT_CONNECTTIMEOUT, $this->timeout);
		if (isset($this->total_timeout))
		{
			$this->wrapper_curl_setopt(CURLOPT_TIMEOUT, $this->total_timeout);
		}

		// If a custom request method is set use it
		if (!is_null($this->customrequest))
		{
			$this->wrapper_curl_setopt(CURLOPT_CUSTOMREQUEST, $this->customrequest);
		}

		if ($postfields)
		{
			// If we received post data and our we don't have a custom request method we should post the data.
			if (is_null($this->customrequest))
			{
				$this->wrapper_curl_setopt(CURLOPT_POST, true);
			}
			$this->wrapper_curl_setopt(CURLOPT_POSTFIELDS, $postfields);
		}

		// If we have an authtype set use it
		if (!is_null($this->authtype))
		{
			switch ($this->authtype)
			{
			case 'basic':
				$this->wrapper_curl_setopt(CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
				$this->wrapper_curl_setopt(CURLOPT_USERPWD, $this->username.':'.$this->password);
				break;
			}
		}

		// If return headers is true, return the headers with the server response.
		if ($this->return_headers === true)
		{
			$this->wrapper_curl_setopt(CURLOPT_HEADER, true);
		}

		$this->wrapper_curl_setopt(CURLOPT_SSL_VERIFYPEER, $this->verifypeer);
		$this->wrapper_curl_setopt(CURLOPT_SSL_VERIFYHOST, $this->verifyhost);

		if ($this->cainfo && file_exists($this->cainfo))
		{
			$this->wrapper_curl_setopt(CURLOPT_CAINFO, $this->cainfo);
		}

		if ($this->httpheader)
		{
			$this->wrapper_curl_setopt(CURLOPT_HTTPHEADER, $this->httpheader);
		}

		if ($this->cookie)
		{
			$this->wrapper_curl_setopt(CURLOPT_COOKIE, $this->cookie);
		}

		if ($this->debug)
		{
			// If debug is set, we want to be able to get the outgoing headers.
			$this->wrapper_curl_setopt(CURLINFO_HEADER_OUT, true);
		}

		$raw_response = $this->wrapper_curl_exec();

		if ($raw_response === false)
		{
			throw new Exception($this->wrapper_curl_error(),$this->wrapper_curl_errno());
		}

		return $raw_response;
	}

	/**
	 * Force the connection to be closed
	 *
	 */
	public function close()
	{
		$this->wrapper_curl_close();
		$this->connection = false;
	}

	/**
	 * Can override in a extended class to make a test class for cURL that has testable behaviour
	 *
	 * @param unknown_type $opt
	 * @param unknown_type $value
	 * @return unknown
	 */
	protected function wrapper_curl_setopt($opt,$value)
	{
		return curl_setopt($this->connection,$opt,$value);
	}
	/**
	 * Can override in a extended class to make a test class for cURL that has testable behaviour
	 *
	 */
	protected function wrapper_curl_exec()
	{
		return curl_exec($this->connection);
	}
	/**
	 * Can override in a extended class to make a test class for cURL that has testable behaviour
	 *
	 */
	protected function wrapper_curl_close()
	{
		return curl_close($this->connection);
	}
	/**
	 * Can override in a extended class to make a test class for cURL that has testable behaviour
	 *
	 */
	protected function wrapper_curl_init()
	{
		return curl_init();
	}
	/**
	 * Can override in a extended class to make a test class for cURL that has testable behaviour
	 *
	 */
	protected function wrapper_curl_error()
	{
		return curl_error($this->connection);
	}
	/**
	 * Can override in a extended class to make a test class for cURL that has testable behaviour
	 *
	 */
	protected function wrapper_curl_errno()
	{
		return curl_errno($this->connection);
	}

	/**
	 * Can override in an extended class to make a test class for cURL that has testabel behariour
	 *
	 * @param constant $opt The CURLINFO constant you want information about.
	 * @link http://us3.php.net/manual/en/function.curl-getinfo.php
	 * @return string | array
	 */
	protected function wrapper_curl_getinfo($opt)
	{
		if (!empty($opt))
		{
			$info = curl_getinfo($this->connection, $opt);
		}
		else
		{
			$info = curl_getinfo($this->connection);
		}

		return $info;
	}
}
