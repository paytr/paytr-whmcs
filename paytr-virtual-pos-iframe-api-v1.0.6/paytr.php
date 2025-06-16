<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * @return string[]
 */
function paytr_MetaData()
{
    return array(
        'DisplayName' => 'PayTR Virtual Pos iFrame API',
        'APIVersion' => '1.2'
    );
}

/**
 * @return string[][]
 */
function paytr_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'PayTR Virtual Pos iFrame API',
        ),
        'merchantID' => array(
            'FriendlyName' =>  'Merchant ID',
            'Type' => 'text',
            'Size' => '20',
            'Default' => '',
            'Description' => 'Enter your <a href="https://www.paytr.com/magaza/bilgi" target="_blank">Merchant ID</a> here',
        ),
        'merchantKey' => array(
            'FriendlyName' => 'Merchant Key',
            'Type' => 'text',
            'Size' => '20',
            'Default' => '',
            'Description' => 'Enter your <a href="https://www.paytr.com/magaza/bilgi" target="_blank">Merchant Key</a> here',
        ),
        'merchantSalt' => array(
            'FriendlyName' => 'Merchant Salt',
            'Type' => 'text',
            'Size' => '20',
            'Default' => '',
            'Description' => 'Enter your <a href="https://www.paytr.com/magaza/bilgi" target="_blank">Merchant Salt</a> here',
        ),
        'iframe_v2' => array(
            'FriendlyName' => 'iFrame v2',
            'Type' => 'yesno',
            'Description' => 'Tick to enable iFrame V2',
            'Default' => 'on',
        ),
        'iframe_v2_dark' => array(
            'FriendlyName' => 'iFrame v2 Dark Theme',
            'Type' => 'yesno',
            'Description' => 'Tick to enable dark theme for iFrame V2',
        ),
        'testMode' => array(
            'FriendlyName' => 'Test Mode',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test mode',
        ),
        'lang' => array (
            'FriendlyName' => 'iFrame Language',
            'Type' => 'dropdown',
            'Options' => 'Turkish,English',
            'Description' => 'Set the language of the iframe page.',
            'Default' => 'Turkish',
        ),
    );
}

/**
 * @param $params
 * @return mixed|string
 */
function paytr_link($params)
{
    if( isset( $_SERVER["HTTP_CLIENT_IP"] ) ) {
        $ip = $_SERVER["HTTP_CLIENT_IP"];
    } elseif( isset( $_SERVER["HTTP_X_FORWARDED_FOR"] ) ) {
        $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
    } else {
        $ip = $_SERVER["REMOTE_ADDR"];
    }
    $merchant_oid       = 'SP'.$params['invoiceid'].'WHMCS'.time();
    $user_basket        = base64_encode(json_encode([[$params['description'], $params['amount'], 1]]));
    $no_installment     = 0;
    $max_installment    = 0;
    $amount             = $params['amount'] * 100;
    $address1           = $params['clientdetails']['address1'];
    $address2           = $params['clientdetails']['address2'];
    $city               = $params['clientdetails']['city'];
    $state              = $params['clientdetails']['state'];
    $postcode           = $params['clientdetails']['postcode'];
    $country            = $params['clientdetails']['country'];
    $currency           = $params['currency'] === 'TRY' ? 'TL' : $params['currency'];
    $testmode           = $params['testMode'] ? 1 : 0;
    $hash_str           = $params['merchantID'] .$ip .$merchant_oid .$params['clientdetails']['email'] .$amount .$user_basket.$no_installment.$max_installment.$currency.$testmode;
    $paytr_token        = base64_encode(hash_hmac('sha256',$hash_str.$params['merchantSalt'],$params['merchantKey'],true));

    $post_vals=array(
        'merchant_id'           =>  $params['merchantID'],
        'user_ip'               =>  $ip,
        'merchant_oid'          =>  $merchant_oid,
        'email'                 =>  $params['clientdetails']['email'],
        'payment_amount'        =>  $amount,
        'paytr_token'           =>  $paytr_token,
        'user_basket'           =>  $user_basket,
        'debug_on'              =>  1,
        'no_installment'        =>  $no_installment,
        'max_installment'       =>  $max_installment,
        'user_name'             =>  $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
        'user_address'          =>  $address1 . ' ' . $address2 . ' ' . $city . ' ' . $state . ' ' . $postcode . ' ' . $country,
        'user_phone'            =>  $params['clientdetails']['phonenumber'],
        'merchant_ok_url'       =>  $params['returnurl'],
        'merchant_fail_url'     =>  $params['returnurl'],
        'timeout_limit'         =>  30,
        'currency'              =>  $currency,
        'test_mode'             =>  $testmode,
        'lang'                  =>  $params['lang'] === 'Turkish' ? 'tr' : 'en',
        'iframe_v2'             =>  $params['iframe_v2'],
        'iframe_v2_dark'        =>  $params['iframe_v2_dark'],
    );

    $ch=curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.paytr.com/odeme/api/get-token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1) ;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $result = @curl_exec($ch);
    if(curl_errno($ch)){
        die("PAYTR IFRAME connection error. err:".curl_error($ch));
    }
    curl_close($ch);
    $result=json_decode($result,1);
    if($result['status']=='success'){
        $token=$result['token'];
    } else {
        die("PAYTR IFRAME failed. reason:".$result['reason']);
    }

    return '<form method="post" action="' . $params['systemurl'] . 'modules/gateways/callback/paytr_iframe.php?token='.$token.'">
		<input type="submit" value="'.$params['langpaynow'].'">
		<noscript>
            <div class="errorbox"><b>JavaScript is currently disabled or is not supported by your
            browser.</b><br />Please click the continue button to proceed with the processing of your
            transaction.</div>
            <p align="center"><input type="submit" value="Continue >>" /></p>
        </noscript>
        </form>';
}

/**
 * @param $params
 * @return array|false
 */
function paytr_refund($params)
{
    $merchant_id 	= $params['merchantID'];
    $merchant_key 	= $params['merchantKey'];
    $merchant_salt	= $params['merchantSalt'];
    $merchant_oid   = $params['transid'];
    $return_amount  = $params['amount'];
    $paytr_token=base64_encode(hash_hmac('sha256',$merchant_id.$merchant_oid.$return_amount.$merchant_salt,$merchant_key,true));

    $post_vals=array('merchant_id'=>$merchant_id,
        'merchant_oid'=>$merchant_oid,
        'return_amount'=>$return_amount,
        'paytr_token'=>$paytr_token);

    $ch=curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://www.paytr.com/odeme/iade");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1) ;
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_vals);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 90);
    $result = @curl_exec($ch);
    if(curl_errno($ch))
    {
        echo curl_error($ch);
        curl_close($ch);
    }
    curl_close($ch);
    $result=json_decode($result,1);
    if($result['status']=='success')
    {
        return array(
            // 'success' if successful, otherwise 'declined', 'error' for failure
            'status' => 'success',
            // Data to be recorded in the gateway log - can be a string or array
            'rawdata' => $result,
            // Unique Transaction ID for the refund transaction
            'transid' => $result['merchant_oid'],
            // Optional fee amount for the fee value refunded
            'fees' => $result['return_amount'],
        );
    }
    else
    {
        echo $result['err_no']." - ".$result['err_msg'];
    }
    return false;
}
