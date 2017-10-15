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

echo "<link rel='stylesheet' href='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css'>";
echo "<div class='row'>
    <div class='col-sm-12 col-md-12 col-lg-12'>
    <div class='panel panel-default' id='PaymentBox_de3a227fb470475'>
    <div class='panel-body'>
    <div class='row'>
    <div class='col-sm-12 col-md-12 col-lg-12'>
    <h3> Monero Payment Box</h3>
    </div>
    <div class='col-sm-3 col-md-3 col-lg-3'>
    <img src='https://chart.googleapis.com/chart?cht=qr&chs=250x250&chl=" . $uri . "' class='img-responsive'>
    </div>
    <div class='col-sm-9 col-md-9 col-lg-9' style='padding:10px;'>
    <h4>$status</h4>
    Send $price <b> XMR</b> to<br/><input type='text'  class='form-control' value='$integrated_address'>
    or scan QR Code with your mobile device<br/><br/>
    <small>If you need help with how to pay with Monero or want to learn more about it, please go to the Monero <a href='https://getmonero.org'>site</a>. </small>
    </div>
    <div class='col-sm-12 col-md-12 col-lg-12'>
    </div>
    </div>
    </div>
    </div>
    </div>
</div>";

echo "<script type='text/javascript'>setTimeout(function () { location.reload(true); }, 30000);</script>";
