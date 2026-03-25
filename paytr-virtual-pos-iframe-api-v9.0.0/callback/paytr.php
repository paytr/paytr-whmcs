<?php

/**
 * PayTR WHMCS Gateway Callback
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

// Detect module name from filename.
$gatewayModuleName = 'paytr';

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (empty($gatewayParams['type'])) {
    die("Module Not Activated");
}

// Retrieve data returned in payment gateway callback
$hash               = $_POST['hash'] ?? '';
$merchant_oid       = $_POST['merchant_oid'] ?? '';
$status             = $_POST['status'] ?? '';
$total_amount       = $_POST['total_amount'] ?? '';
$payment_amount     = $_POST['payment_amount'] ?? '';
$currency           = $_POST['currency'] ?? '';
$test_mode          = $_POST['test_mode'] ?? '0';

/**
 * Validate callback authenticity.
 * The hash structure must match PayTR's documentation.
 */
$hash_str = $merchant_oid . $gatewayParams['merchantSalt'] . $status . $total_amount;
$local_hash = base64_encode(hash_hmac('sha256', $hash_str, $gatewayParams['merchantKey'], true));

if ($hash !== $local_hash) {
    logTransaction($gatewayParams['name'], $_POST, 'Hash Verification Failure');
    die('PAYTR: Hash Verification Failure');
}

/**
 * Extract Invoice ID from merchant_oid
 * SP{invoiceid}WHMCS{timestamp}
 */
$invoice_id = 0;
if (preg_match('/^SP(\d+)WHMCS/', $merchant_oid, $matches)) {
    $invoice_id = (int)$matches[1];
}

// Fallback logic if naming convention changed (unlikely but safe)
if (!$invoice_id) {
    $order_id_parts = explode('WHMCS', $merchant_oid);
    if (isset($order_id_parts[0])) {
        $invoice_id = str_replace('SP', '', $order_id_parts[0]);
    }
}

// Validate Callback Invoice ID.
$invoice_id = checkCbInvoiceID($invoice_id, $gatewayParams['name']);

// Check Callback Transaction ID (ensure it hasn't been processed yet).
checkCbTransID($merchant_oid);

// Respond 'OK' to PayTR ASAP to prevent retries
echo 'OK';

// Log Transaction.
logTransaction($gatewayParams['name'], $_POST, ($status === 'success' ? 'Successful' : 'Failed'));

if ($status === 'success') {
    /**
     * Add Invoice Payment.
     * @param int $invoiceId         Invoice ID
     * @param string $transactionId  Transaction ID
     * @param float $paymentAmount   Amount paid
     * @param float $paymentFee      Payment fee
     * @param string $gatewayModule  Gateway module name
     */
    addInvoicePayment(
        $invoice_id,
        $merchant_oid,
        '', // amount (empty defaults to invoice total, but better specify if partial allowed)
        '', // fee
        $gatewayModuleName
    );
} else {
    // Optionally log or handle failed payments (WHMCS handle typically logs this via logTransaction above)
}
