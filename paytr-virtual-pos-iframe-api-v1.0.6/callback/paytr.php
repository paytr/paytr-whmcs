<?php

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Retrieve data returned in payment gateway callback
// Varies per payment gateway
$hash               = $_POST["hash"];
$merchant_oid       = $_POST["merchant_oid"];
$order_id           = explode('WHMCS', $_POST["merchant_oid"]);
$invoice_id         = explode('SP', $order_id[0])[1];
$invoice_id_cancel  = explode('SP', $order_id[0])[1];
$status             = $_POST["status"];
$total_amount       = $_POST["total_amount"];
$payment_type       = $_POST["payment_type"];
$payment_amount     = $_POST["payment_amount"];
$currency           = $_POST["currency"];
$installment_count  = $_POST["installment_count"];
$merchant_id        = $_POST["merchant_id"];
$test_mode          = $_POST["test_mode"];
$transactionStatus  = is_array($status) && isset($status['success']) && $status['success'] ? 'OK' : 'FAILED';

/**
 * Validate callback authenticity.
 *
 * Most payment gateways provide a method of verifying that a callback
 * originated from them. In the case of our example here, this is achieved by
 * way of a shared secret which is used to build and compare a hash.
 */
$hash = base64_encode( hash_hmac('sha256', $merchant_oid.$gatewayParams['merchantSalt'].$status.$total_amount, $gatewayParams['merchantKey'], true) );

if ($_POST["hash"] != $hash) {
    $transactionStatus = 'Hash Verification Failure';
}

echo 'OK';

/**
 * Validate Callback Invoice ID.
 *
 * Checks invoice ID is a valid invoice number. Note it will count an
 * invoice in any status as valid.
 *
 * Performs a die upon encountering an invalid Invoice ID.
 *
 * Returns a normalised invoice ID.
 *
 * @param int $invoice_id Invoice ID
 * @param string $gatewayName Gateway Name
 */
$invoice_id = checkCbInvoiceID($invoice_id, $gatewayParams['name']);

/**
 * Check Callback Transaction ID.
 *
 * Performs a check for any existing transactions with the same given
 * transaction number.
 *
 * Performs a die upon encountering a duplicate.
 *
 * @param string $merchant_oid Unique Transaction ID
 */
checkCbTransID($merchant_oid);

/**
 * Log Transaction.
 *
 * Add an entry to the Gateway Log for debugging purposes.
 *
 * The debug data can be a string or an array. In the case of an
 * array it will be
 *
 * @param string $gatewayName        Display label
 * @param string|array $debugData    Data to log
 * @param string $transactionStatus  Status
 */
logTransaction($gatewayParams['name'], $_POST, $transactionStatus);

if ($_POST["status"] == 'success') {
    /**
     * Add Invoice Payment.
     *
     * Applies a payment transaction entry to the given invoice ID.
     *
     * @param int $invoiceId         Invoice ID
     * @param string $transactionId  Transaction ID
     * @param float $paymentAmount   Amount paid (defaults to full balance)
     * @param float $paymentFee      Payment fee (optional)
     * @param string $gatewayModule  Gateway module name
     */
    addInvoicePayment(
        $invoice_id,
        $merchant_oid,
        '',
        '',
        $gatewayModuleName
    );
}
