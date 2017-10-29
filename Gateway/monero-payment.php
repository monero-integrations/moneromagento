<?php

require_once('library.php');
    
class Monero
{
    private $monero_daemon;
    
    public function __construct()
    {
        $this->monero_daemon = new Monero_Library('http://127.0.0.1:18082/json_rpc'); // TODO: Get address:port from admin panel
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
    
    public function paymentid_cookie()
    {
        if (!isset($_COOKIE['payment_id']))
        {
            $payment_id = bin2hex(openssl_random_pseudo_bytes(8));
            setcookie('payment_id', $payment_id, time() + 2700);
        }
        else
            $payment_id = $_COOKIE['payment_id'];
        return $payment_id;
    }
    
    public function changeto($amount, $currency)
    {
        $rate = $this->retriveprice($currency);
        $price_converted = $amount / $rate;
        $converted_rounded = round($price_converted, 12); //the moneo wallet can't handle decimals smaller than 0.000000000001
        return $converted_rounded;
    }
    
    public function verify_payment($payment_id, $amount)
    {
        $message = "We are waiting for your payment to be confirmed";
        $amount_atomic_units = $amount * 1000000000000;
        $get_payments_method = $this->monero_daemon->get_payments($payment_id);
        if (isset($get_payments_method["payments"][0]["amount"]))
        {
            if ($get_payments_method["payments"][0]["amount"] >= $amount_atomic_units)
            {
                $message = "Payment has been received and confirmed. Thanks!";
            }
        }
        return $message;
    }
    public function integrated_address($payment_id)
    {
        $integrated_address = $this->monero_daemon->make_integrated_address($payment_id);
        $parsed_address = $integrated_address[integrated_address];
        return $parsed_address;
    }
}

$currency = 'USD'; //use 'USD' as default currency

$decoded = base64_decode($_GET[xmr]);
$numericOnly = preg_replace( '/[^0-9]/', '', $decoded );
$total = $numericOnly / 100;

$daemon = new Monero();
$price = $daemon->changeto($total, $currency);
$payment_id = $daemon->paymentid_cookie();
$integrated_address = $daemon->integrated_address($payment_id);
$status = $daemon->verify_payment($payment_id, $price);
echo "
<head>
<!--Import Google Icon Font-->
<link href='https://fonts.googleapis.com/icon?family=Material+Icons' rel='stylesheet'>
<link href='https://fonts.googleapis.com/css?family=Montserrat:400,800' rel='stylesheet'>

<link href='xmr-style.css' rel='stylesheet'>

<!--Let browser know website is optimized for mobile-->
<meta name='viewport' content='width=device-width, initial-scale=1.0'/>
</head>

<body>
<!-- page container  -->
<div class='page-container'>


<!-- monero container payment box -->
<div class='container-xmr-payment'>


<!-- header -->
<div class='header-xmr-payment'>
<span class='logo-xmr'><img src='img/logomonero.png' /></span>
<span class='xmr-payment-text-header'><h2>MONERO PAYMENT</h2></span>
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
<div class='xmr-address-box'>$integrated_address</div>
</div>
<div class='xmr-qr-code'>
<span class='xmr-label'>Or scan QR:</span>
<div class='xmr-qr-code-box'><img src='img/qr.png' /></div>
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
<!-- end monero container payment box -->

</div>
<!-- end page container  -->
</body>
";

echo "<script type='text/javascript'>setTimeout(function () { location.reload(true); }, 30000);</script>";
