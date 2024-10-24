<?php

namespace MoneroIntegrations\Custompayment\Controller\Gateway;

use Magento\Framework\App\Action\Context;
use RuntimeException;
use InvalidArgumentException;

// Monero_Library is just the contents of library.php. It's super messy but works for now
class Monero_Library
{
    protected $url = null, $is_debug = false, $parameters_structure = 'array';

    protected $curl_options = array(
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 8
    );


    private $httpErrors = array(
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        405 => '405 Method Not Allowed',
        406 => '406 Not Acceptable',
        408 => '408 Request Timeout',
        500 => '500 Internal Server Error',
        502 => '502 Bad Gateway',
        503 => '503 Service Unavailable'
    );

    public function __construct($pUrl)
    {
        $this->validate(false === extension_loaded('curl'), 'The curl extension must be loaded for using this class!');
        $this->validate(false === extension_loaded('json'), 'The json extension must be loaded for using this class!');

        $this->url = $pUrl;
    }

    private function getHttpErrorMessage($pErrorNumber)
    {
        return isset($this->httpErrors[$pErrorNumber]) ? $this->httpErrors[$pErrorNumber] : null;
    }

    public function setDebug($pIsDebug)
    {
        $this->is_debug = !empty($pIsDebug);
        return $this;
    }

    /*  public function setParametersStructure($pParametersStructure)
         {
         if (in_array($pParametersStructure, array('array', 'object')))
         {
         $this->parameters_structure = $pParametersStructure;
         }
         else
         {
         throw new UnexpectedValueException('Invalid parameters structure type.');
         }
         return $this;
         } */

    public function setCurlOptions($pOptionsArray)
    {
        if (is_array($pOptionsArray)) {
            $this->curl_options = $pOptionsArray + $this->curl_options;
        } else {
            throw new InvalidArgumentException('Invalid options type.');
        }
        return $this;
    }

    public function _run($pMethod, $pParams = null)
    {
        static $requestId = 0;
        // generating unique id per process
        $requestId++;
        // check if given params are correct
        $this->validate(false === is_scalar($pMethod), 'Method name has no scalar value');
        // $this->validate(false === is_array($pParams), 'Params must be given as array');
        // send params as an object or an array
        //$pParams = ($this->parameters_structure == 'object') ? $pParams[0] : array_values($pParams);
        // Request (method invocation)
        $request = json_encode(array('jsonrpc' => '2.0', 'method' => $pMethod, 'params' => $pParams, 'id' => $requestId));
        // if is_debug mode is true then add url and request to is_debug
        $this->debug('Url: ' . $this->url . "\r\n", false);
        $this->debug('Request: ' . $request . "\r\n", false);
        $responseMessage = $this->getResponse($request);
        // if is_debug mode is true then add response to is_debug and display it
        $this->debug('Response: ' . $responseMessage . "\r\n", true);
        // decode and create array ( can be object, just set to false )
        $responseDecoded = json_decode($responseMessage, true);
        // check if decoding json generated any errors
        $jsonErrorMsg = $this->getJsonLastErrorMsg();
        $this->validate(!is_null($jsonErrorMsg), $jsonErrorMsg . ': ' . $responseMessage);
        // check if response is correct
        $this->validate(empty($responseDecoded['id']), 'Invalid response data structure: ' . $responseMessage);
        $this->validate($responseDecoded['id'] != $requestId, 'Request id: ' . $requestId . ' is different from Response id: ' . $responseDecoded['id']);
        if (isset($responseDecoded['error'])) {
            $errorMessage = 'Request have return error: ' . $responseDecoded['error']['message'] . '; ' . "\n" .
                'Request: ' . $request . '; ';
            if (isset($responseDecoded['error']['data'])) {
                $errorMessage .= "\n" . 'Error data: ' . $responseDecoded['error']['data'];
            }
            $this->validate(!is_null($responseDecoded['error']), $errorMessage);
        }
        return $responseDecoded['result'];
    }
    protected function &getResponse(&$pRequest)
    {
        // do the actual connection
        $ch = curl_init();
        if (!$ch) {
            throw new RuntimeException('Could\'t initialize a cURL session');
        }
        curl_setopt($ch, CURLOPT_URL, $this->url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $pRequest);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
        curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if (!curl_setopt_array($ch, $this->curl_options)) {
            throw new RuntimeException('Error while setting curl options');
        }
        // send the request
        $response = curl_exec($ch);
        // check http status code
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (isset($this->httpErrors[$httpCode])) {
            throw new RuntimeException('Response Http Error - ' . $this->httpErrors[$httpCode]);
        }
        // check for curl error
        if (0 < curl_errno($ch)) {
            throw new RuntimeException('Unable to connect to ' . $this->url . ' Error: ' . curl_error($ch));
        }
        // close the connection
        curl_close($ch);
        return $response;
    }

    public function validate($pFailed, $pErrMsg)
    {
        if ($pFailed) {
            throw new RuntimeException($pErrMsg);
        }
    }

    protected function debug($pAdd, $pShow = false)
    {
        static $debug, $startTime;
        // is_debug off return
        if (false === $this->is_debug) {
            return;
        }
        // add
        $debug .= $pAdd;
        // get starttime
        $startTime = empty($startTime) ? array_sum(explode(' ', microtime())) : $startTime;
        if (true === $pShow and !empty($debug)) {
            // get endtime
            $endTime = array_sum(explode(' ', microtime()));
            // performance summary
            $debug .= 'Request time: ' . round($endTime - $startTime, 3) . ' s Memory usage: ' . round(memory_get_usage() / 1024) . " kb\r\n";
            echo nl2br($debug);
            // send output immediately
            flush();
            // clean static
            $debug = $startTime = null;
        }
    }

    function getJsonLastErrorMsg()
    {
        if (!function_exists('json_last_error_msg')) {
            function json_last_error_msg()
            {
                static $errors = array(
                    JSON_ERROR_NONE           => 'No error',
                    JSON_ERROR_DEPTH          => 'Maximum stack depth exceeded',
                    JSON_ERROR_STATE_MISMATCH => 'Underflow or the modes mismatch',
                    JSON_ERROR_CTRL_CHAR      => 'Unexpected control character found',
                    JSON_ERROR_SYNTAX         => 'Syntax error',
                    JSON_ERROR_UTF8           => 'Malformed UTF-8 characters, possibly incorrectly encoded'
                );
                $error = json_last_error();
                return array_key_exists($error, $errors) ? $errors[$error] : 'Unknown error (' . $error . ')';
            }
        }

        // Fix PHP 5.2 error caused by missing json_last_error function
        if (function_exists('json_last_error')) {
            return json_last_error() ? json_last_error_msg() : null;
        } else {
            return null;
        }
    }

    public function store()
    {
        return $this->_run('store');
    }

    public function create_address($account_index = 0, $label = '')
    {
        $params = array('account_index' => $account_index, 'label' => $label);
        $create_address_method = $this->_run('create_address', $params);
        $save = $this->store(); // Save wallet state after subaddress creation
        return $create_address_method;
    }

    public function get_transfers($arr)
    {
        $get_parameters = $arr;
        $get_transfers = $this->_run('get_transfers', $get_parameters);
        return $get_transfers;
    }

    public function get_address_index($subaddress)
    {
        $params = array('address' => $subaddress);
        return $this->_run('get_address_index', $params);
    }

    public function address()
    {
        $address = $this->_run('getaddress');
        return $address;
    }

    public function getbalance()
    {
        $balance = $this->_run('getbalance');
        return $balance;
    }
    public function getheight()
    {
        $height = $this->_run('getheight');
        return $height;
    }
    public function incoming_transfer($type)
    {
        $incoming_parameters = array('transfer_type' => $type);
        $incoming_transfers = $this->_run('incoming_transfers', $incoming_parameters);
        return $incoming_transfers;
    }
    public function view_key()
    {
        $query_key = array('key_type' => 'view_key');
        $query_key_method = $this->_run('query_key', $query_key);
        return $query_key_method;
    }
    public function make_integrated_address($payment_id)
    {
        $integrate_address_parameters = array('payment_id' => $payment_id);
        $integrate_address_method = $this->_run('make_integrated_address', $integrate_address_parameters);
        return $integrate_address_method;
    }
    /* A payment id can be passed as a string
     A random payment id will be generated if one is not given */
    public function split_integrated_address($integrated_address)
    {
        if (!isset($integrated_address)) {
            echo "Error: Integrated_Address mustn't be null";
        } else {
            $split_params = array('integrated_address' => $integrated_address);
            $split_methods = $this->_run('split_integrated_address', $split_params);
            return $split_methods;
        }
    }
    public function make_uri($address, $amount, $recipient_name = null, $description = null)
    {
        // If I pass 1, it will be 0.0000001 xmr. Then
        $new_amount = $amount * 100000000;
        $uri_params = array('address' => $address, 'amount' => $new_amount, 'payment_id' => '', 'recipient_name' => $recipient_name, 'tx_description' => $description);
        $uri = $this->_run('make_uri', $uri_params);
        return $uri;
    }
    public function parse_uri($uri)
    {
        $uri_parameters = array('uri' => $uri);
        $parsed_uri = $this->_run('parse_uri', $uri_parameters);
        return $parsed_uri;
    }
    public function transfer($amount, $address, $mixin = 4)
    {
        $new_amount = $amount * 1000000000000;
        $destinations = array('amount' => $new_amount, 'address' => $address);
        $transfer_parameters = array('destinations' => array($destinations), 'mixin' => $mixin, 'get_tx_key' => true, 'unlock_time' => 0, 'payment_id' => '');
        $transfer_method = $this->_run('transfer', $transfer_parameters);
        return $transfer_method;
    }
    public function get_payments($payment_id)
    {
        $get_payments_parameters = array('payment_id' => $payment_id);
        $get_payments = $this->_run('get_payments', $get_payments_parameters);
        return $get_payments;
    }
    public function get_bulk_payments($payment_id, $min_block_height)
    {
        $get_bulk_payments_parameters = array('payment_id' => $payment_id, 'min_block_height' => $min_block_height);
        $get_bulk_payments = $this->_run('get_bulk_payments', $get_bulk_payments_parameters);
        return $get_bulk_payments;
    }
}

class Monero
{
    private $monero_daemon;

    public function __construct($rpc_address, $rpc_port)
    {
        $this->monero_daemon = new Monero_Library('http://' . $rpc_address . ':' . $rpc_port . '/json_rpc');
    }

    public function retriveprice($currency)
    {
        $xmr_price = file_get_contents('https://min-api.cryptocompare.com/data/price?fsym=XMR&tsyms=BTC,USD,EUR,CAD,INR,GBP&extraParams=monero_magento');
        $price = json_decode($xmr_price, TRUE);
        switch ($currency) {
            case 'USD':
                return $price['USD'];
            case 'EUR':
                return $price['EUR'];
            case 'CAD':
                return $price['CAD'];
            case 'GBP':
                return $price['GBP'];
            case 'INR':
                return $price['INR'];
            case 'XMR':
                $price = '1';
                return $price;
        }
    }

    public function subaddress_cookie()
    {
        // Sanitize cookie input
        $xmr_subaddress_cookie = filter_input(INPUT_COOKIE, 'xmr_subaddress', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        // Validate subaddress from cookie
        $isValid = is_string($xmr_subaddress_cookie) && preg_match('/^[0-9a-zA-Z]{95}$/', $xmr_subaddress_cookie);

        if (!$xmr_subaddress_cookie || !$isValid) {
            // Generate subaddress
            $xmr_subaddress = $this->monero_daemon->create_address(0);
            setcookie('xmr_subaddress', $xmr_subaddress['address'], time() + 2700);
        } else {
            $xmr_subaddress = ['address' => $xmr_subaddress_cookie];
        }
        return $xmr_subaddress['address'];
    }

    public function changeto($amount, $currency)
    {
        $rate = $this->retriveprice($currency);
        $price_converted = $amount / $rate;
        $converted_rounded = round($price_converted, 11); //the Monero wallet can't handle decimals smaller than 0.000000000001
        return $converted_rounded;
    }

    protected function check_payment_rpc($subaddress)
    {
        $txs = array();
        $address_index = $this->monero_daemon->get_address_index($subaddress);
        if(!isset($address_index['index']['minor'])) {
          return $txs;
        }
        $address_index = $address_index['index']['minor'];
        $payments = $this->monero_daemon->get_transfers(array( 'in' => true, 'pool' => true, 'subaddr_indices' => array($address_index)));
        if(isset($payments['in'])) {
          foreach($payments['in'] as $payment) {
              $txs[] = array(
                  'amount' => $payment['amount'],
                  'txid' => $payment['txid'],
                  'height' => $payment['height']
              );
          }
        }
        if(isset($payments['pool'])) {
          foreach($payments['pool'] as $payment) {
              $txs[] = array(
                  'amount' => $payment['amount'],
                  'txid' => $payment['txid'],
                  'height' => $payment['height']
              );
          }
        }
        return $txs;
    }

    public function verify_payment($payment_id, $amount, $num_confirmations)
    {
        $message = "We are waiting for your payment.";
        $amount_atomic_units = $amount * 1000000000000;
        $total_received = 0;

        // Fetch transactions for the given payment subaddress
        $txs = $this->check_payment_rpc($payment_id);

        // If num_confirmations is 0, simply check if payment has been received
        if ($num_confirmations == 0) {
            foreach ($txs as $tx) {
                $total_received += $tx['amount'];
            }
            if ($total_received >= $amount_atomic_units) {
                $message = "Payment has been received. Thanks!";
                return ['status' => true, 'message' => $message];
            }
        } else {
            // Get current blockchain height
            $current_blockchain_height = $this->monero_daemon->getheight();
            foreach ($txs as $tx) {
                // Calculate the number of confirmations for this transaction
                $tx_confirmations = $current_blockchain_height['height'] - $tx['height'];

                // Check if the transaction amount is sufficient and it has enough confirmations
                if ($tx_confirmations >= $num_confirmations) {
                    $total_received += $tx['amount'];
                }
            }
            if ($total_received >= $amount_atomic_units) {
                $message = "Payment has been received and confirmed. Thanks!";
                return ['status' => true, 'message' => $message];
            }
        }
        return ['status' => false, 'message' => $message];
    }


    public function integrated_address($payment_id)
    {
        $integrated_address = $this->monero_daemon->make_integrated_address($payment_id);
        $parsed_address = $integrated_address['integrated_address'];
        return $parsed_address;
    }
}

class MoneroPayment extends \Magento\Framework\App\Action\Action
{
    protected $helper;
    protected $checkoutSession;
    protected $_storeManager;
    protected $_cart;

    public function __construct(
        \MoneroIntegrations\Custompayment\Helper\Data $helper,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Framework\App\Action\Context $context
    ) {
        $this->helper = $helper;
        $this->_storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
        $this->_cart = $cart;
        parent::__construct($context);
    }

    public $monero;

    public function execute()
    {
        $first = '';
        $last = '';
        $email = '';
        $phone = '';
        $city = '';
        $street = '';
        $postal = '';
        $country = '';
        $region = '';
        $paramCount = 0;
        $errors = [];

        if (isset($_GET['first'])) {
            $first = htmlspecialchars(filter_input(INPUT_GET, 'first'), ENT_QUOTES, 'UTF-8');
            if (empty($first)) {
                $errors[] = 'First name cannot be empty.';
            }
            $paramCount += 1;
        }
        if (isset($_GET['last'])) {
            $last = htmlspecialchars(filter_input(INPUT_GET, 'last'), ENT_QUOTES, 'UTF-8');
            if (empty($last)) {
                $errors[] = 'Last name cannot be empty.';
            }
            $paramCount += 1;
        }
        if (isset($_GET['email'])) {
            $email = filter_input(INPUT_GET, 'email', FILTER_SANITIZE_EMAIL);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid email format.';
            }
            $paramCount += 1;
        }
        if (isset($_GET['phone'])) {
            $phone = filter_input(INPUT_GET, 'phone', FILTER_SANITIZE_NUMBER_INT);
            if (!preg_match('/^\d{10,15}$/', $phone)) {
                $errors[] = 'Invalid phone number. Must be 10-15 digits.';
            }
            $paramCount += 1;
        }
        if (isset($_GET['city'])) {
            $city = htmlspecialchars(filter_input(INPUT_GET, 'city'), ENT_QUOTES, 'UTF-8');
            if (empty($city)) {
                $errors[] = 'City cannot be empty.';
            }
            $paramCount += 1;
        }
        if (isset($_GET['street'])) {
            $street = htmlspecialchars(filter_input(INPUT_GET, 'street'), ENT_QUOTES, 'UTF-8');
            if (empty($street)) {
                $errors[] = 'Street cannot be empty.';
            }
            $paramCount += 1;
        }
        if (isset($_GET['postal'])) {
            $postal = htmlspecialchars(filter_input(INPUT_GET, 'postal'), ENT_QUOTES, 'UTF-8');
            if (!preg_match('/^\d{4,10}$/', $postal)) {
                $errors[] = 'Invalid postal code.';
            }
            $paramCount += 1;
        }
        if (isset($_GET['country'])) {
            $country = htmlspecialchars(filter_input(INPUT_GET, 'country'), ENT_QUOTES, 'UTF-8');
        }
        if (isset($_GET['region'])) {
            $region = htmlspecialchars(filter_input(INPUT_GET, 'region'), ENT_QUOTES, 'UTF-8');
        }

        // Check for errors
        if (!empty($errors)) {
            // Handle or display errors as needed
            foreach ($errors as $error) {
                echo "<p>Error: $error</p>";
            }
            return;
        }

        $rpc_address = $this->helper->grabConfig('payment/custompayment/rpc_address');
        $rpc_port = $this->helper->grabConfig('payment/custompayment/rpc_port');
        $num_confirmations = $this->helper->grabConfig('payment/custompayment/num_confirmations');
        $monero = new Monero($rpc_address, $rpc_port);

        $currency = 'USD';
        $grandTotal = $this->checkoutSession->getQuote()->getGrandTotal();

        $price = $monero->changeto($grandTotal, $currency);
        $subaddress = $monero->subaddress_cookie();
        $status = $monero->verify_payment($subaddress, $price, $num_confirmations);
        $status_message = $status['message'];
        echo "
        <head>
        <!--Import Google Icon Font-->
        <link href='https://fonts.googleapis.com/icon?family=Material+Icons' rel='stylesheet'>
        <link href='https://fonts.googleapis.com/css?family=Montserrat:400,800' rel='stylesheet'>
        <script src='https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js' type='text/javascript'></script>
        <link rel='stylesheet' href='https://maxcdn.bootstrapcdn.com/bootstrap/5.3.0/css/bootstrap.min.css' type='text/css'>
        <link href='https://cdn.monerointegrations.com/style.css' rel='stylesheet'>

        <!--Let browser know website is optimized for mobile-->
            <meta name='viewport' content='width=device-width, initial-scale=1.0'/>
            </head>

            <body>
            <!-- page container  -->
            <div class='page-container'>


            <!-- Monero container payment box -->
            <div class='container-xmr-payment'>


            <!-- header -->
            <div class='header-xmr-payment'>
            <span class='logo-xmr'><img src='https://cdn.monerointegrations.com/logomonero.png' /></span>
            <span class='xmr-payment-text-header'><h2>MONERO PAYMENT $status_message</h2></span>
            </div>
            <!-- end header -->

            <!-- xmr content box -->
            <div class='content-xmr-payment'>

            <div class='xmr-amount-send'>
            <span class='xmr-label'>Send:</span>
            <div class='xmr-amount-box'>$price</div><div class='xmr-box'>XMR</div>
            </div>

            <div class='xmr-address'>
            <span class='xmr-label'>To this address:</span>
            <div class='xmr-address-box'>$subaddress</div>
            </div>
            <div class='xmr-qr-code'>
            <span class='xmr-label'>Or scan QR:</span>
            <div class='xmr-qr-code-box'><img src='https://api.qrserver.com/v1/create-qr-code/? size=200x200&data=monero:$subaddress' /></div>
            </div>

            <div class='clear'></div>
            </div>

            <!-- end content box -->

            <!-- footer xmr payment -->
            <div class='footer-xmr-payment'>
            <a href='#'>Help</a> | <a href='#'>About Monero</a>
            </div>
            <!-- end footer xmr payment -->

            </div>
            <!-- end Monero container payment box -->

            </div>
            <br></br>
            <div class='container'>
            <div class='row'>
            <div class='col-lg-2'>
                    <form id='first' action='MoneroPayment' action='post'>
                        Firstname
                        <input type='text' name='first' class='form-control' value=$first>
                    </form>
            </div>
            <div class='col-lg-3'>
            <form id='last' action='MoneroPayment' action='post'>
                Lastname
            <input type='text' name='last' class='form-control' value=$last>
            </form>
            </div>
            <div class='col-xs-4'>
            <form id='email' action='MoneroPayment' action='post'>
                E-mail
            <input type='text' name='email' value=$email>
            </form>
            </div>
            <form id='phone' action='MoneroPayment' action='post'>
                Phone Number
            <input type='text' name='phone' value=$phone>
            </form>
            </div>
            <h2> Shipping Address </h2>
            <select id='country'>
                <option value='CA'>Canada</option>
                <option value='US'>United States</option> <!-- TODO: add more counties  -->
            </select>
            <select id='region'>
                <option>---Canada---</option>
                <option value='AB'>Alberta</option>
                <option value='BC'>British Columbia</option>
                <option value='MB'>Manitoba</option>
                <option value='NB'>New Brunswick</option>
                <option value='ON'>Ontario</option>
                <option value='QC'>Quebec</option>
                <option>---United States---</option>
                <option value='AL'>Alabama</option>
                <option value='AK'>Alaska</option>
                <option value='AZ'>Arizona</option>
                <option value='AR'>Arkansas</option>
                <option value='CA'>California</option>
                <option value='CO'>Colorado</option>
                <option value='CT'>Connecticut</option>
                <option value='DE'>Delaware</option>
                <option value='DC'>District of Columbia</option>
                <option value='FL'>Florida</option>
                <option value='GA'>Georgia</option>
                <option value='ID'>Idaho</option>
                <option value='IL'>Illinois</option>
                <option value='IN'>Indiana</option>
                <option value='IA'>Iowa</option>
                <option value='KS'>Kansas</option>
                <option value='KY'>Kentucky</option>
                <option value='LA'>Louisiana</option>
                <option value='ME'>Maine</option>
                <option value='MD'>Maryland</option>
                <option value='MA'>Massachusetts</option>
                <option value='MI'>Michigan</option>
                <option value='MN'>Minnesota</option>
                <option value='MS'>Mississippi</option>
                <option value='MO'>Missouri</option>
                <option value='MT'>Montana</option>
                <option value='NE'>Nebraska</option>
                <option value='NV'>Nevada</option>
                <option value='NH'>New Hampshire</option>
                <option value='NJ'>New Jersey</option>
                <option value='NM'>New Mexico</option>
                <option value='NY'>New York</option>
                <option value='NV'>North Carolina</option>
                <option value='ND'>North Dakota</option>
                <option value='OH'>Ohio</option>
                <option value='OK'>Oklahoma</option>
                <option value='OR'>Oregon</option>
                <option value='PA'>Pennsylvania</option>
                <option value='RI'>Rhode Island</option>
                <option value='SC'>South Carolina</option>
                <option value='SD'>South Dakota</option>
                <option value='TN'>Tennessee</option>
                <option value='TX'>Texas</option>
                <option value='UT'>Utah</option>
                <option value='VT'>Vermont</option>
                <option value='VA'>Virginia</option>
                <option value='WA'>Washington</option>
                <option value='WV'>West Virginia</option>
                <option value='WI'>Wisconsin</option>
                <option value='WY'>Wyoming</option>
            </select>
            <br></br>
            <form id='city' action='MoneroPayment' action='post'>
                City
            <input type='text' name='city' value=$city>
            </form>
            <form id='street' action='MoneroPayment' action='post'>
                Street
            <input type='text' name='street' value=$street>
            </form>
            <form id='postal' action='MoneroPayment' action='post'>
                Postal Code
            <input type='text' name='postal' value=$postal>
            </form>
        
        <button type='button'>Submit</button>
        <p></p>
        <script type='text/javascript'>
        $(document).ready(function(){
                          $('button').click(function(){
                                            var basePath = 'MoneroPayment?';
                                            var firstS = $('#first').serialize();
                                            var lastS = $('#last').serialize();
                                            var emailS = $('#email').serialize();
                                            var phoneS =  $('#phone').serialize();
                                            var cityS = $('#city').serialize();
                                            var streetS = $('#street').serialize();
                                            var postalS = $('#postal').serialize();
                                            var countryS = document.getElementById('country').value;
                                            var regionS = document.getElementById('region').value;
                                            
                                            var redirectUrl = basePath.concat(firstS,'&' , lastS, '&', emailS, '&', phoneS, '&', cityS, '&', streetS, '&', postalS, '&country=', countryS, '&region=', regionS);
                                            window.location.replace(redirectUrl);
                                            });
                          });
        </script>
        </div>
            <!-- end page container  -->
            </body>
            ";
        $items = $this->checkoutSession->getQuote()->getAllItems();
        foreach ($items as $item) {
            $qty = $item->getQty();
            $prodId = $item->getProductId();
        }
        $orderData = [
            'currency_id'  => $currency,
            'email'        => $email,
            'shipping_address' => [
                'firstname'    => $first,
                'lastname'     => $last,
                'street' => $street,
                'city' => $city,
                'country_id' => $country,
                'region' => $region,
                'postcode' => $postal,
                'telephone' => $phone,
                'fax' => $phone,
                'save_in_address_book' => 1
            ],
            'items' => [['product_id' => $prodId, 'qty' => $qty]]
        ];
        if (isset($_GET['ordered'])) {
            echo "<script type='text/javascript'>setTimeout(function () { location.reload(true); }, 30000);</script>"; // reload to try to verify payment after order data given
            if ($status) {
                $this->helper->createOrder($orderData);
            }
        } else {
            if ($paramCount == 7) // check that all fields have been filled out
            {
                echo "<script type='text/javascript'>window.location.replace('MoneroPayment?first=$first&last=$last&email=$email&phone=$phone&city=$city&street=$street&postal=$postal&country=$country&region=$region&ordered=1')</script>";
            }
        }
    }
}
