<?php

/**
 * PayTR WHMCS Gateway Callback
 */

require_once __DIR__ . '/../../../init.php';

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'paytr';

function paytrCallbackFail($gatewayName, array $payload, $reason, $statusCode = 400)
{
    logTransaction($gatewayName, $payload, $reason);
    http_response_code((int)$statusCode);
    die('PAYTR: ' . $reason);
}

function paytrCallbackOk()
{
    http_response_code(200);
    echo 'OK';
    exit;
}

function paytrFormatCurrency($currency)
{
    $currency = strtoupper(trim((string)$currency));

    return $currency === 'TRY' ? 'TL' : $currency;
}

function paytrParseMinorAmount($amount)
{
    if (!is_scalar($amount) || !preg_match('/^\d+$/', (string)$amount)) {
        return null;
    }

    return (int)$amount;
}

function paytrDecimalToMinor($amount)
{
    if (!is_scalar($amount)) {
        return null;
    }

    $normalized = str_replace(',', '.', trim((string)$amount));
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
        return null;
    }

    return (int)round((float)$normalized * 100);
}

function paytrMinorToDecimal($amountMinor)
{
    return round(((int)$amountMinor) / 100, 2);
}

function paytrParseMerchantOid($merchantOid)
{
    $pattern = '/^SP(\d+)WHMCS(\d+)M([01])A(\d+)C(TL|USD|EUR|GBP|RUB)R([a-f0-9]{12})$/i';
    if (!preg_match($pattern, (string)$merchantOid, $matches)) {
        return null;
    }

    return array(
        'invoice_id' => (int)$matches[1],
        'created_at' => (int)$matches[2],
        'test_mode' => $matches[3] === '1',
        'amount_minor' => (int)$matches[4],
        'currency' => paytrFormatCurrency($matches[5]),
    );
}

function paytrParseLegacyMerchantOid($merchantOid)
{
    if (!preg_match('/^SP(\d+)WHMCS(\d+)$/', (string)$merchantOid, $matches)) {
        return null;
    }

    return array(
        'invoice_id' => (int)$matches[1],
        'created_at' => (int)$matches[2],
    );
}

function paytrQueryPaymentStatus(array $gatewayParams, $merchantOid)
{
    $token = base64_encode(hash_hmac(
        'sha256',
        $gatewayParams['merchantID'] . $merchantOid . $gatewayParams['merchantSalt'],
        $gatewayParams['merchantKey'],
        true
    ));

    $client = new \GuzzleHttp\Client();
    $response = $client->post('https://www.paytr.com/odeme/durum-sorgu', array(
        'form_params' => array(
            'merchant_id' => $gatewayParams['merchantID'],
            'merchant_oid' => $merchantOid,
            'paytr_token' => $token,
        ),
        'timeout' => 15,
        'connect_timeout' => 5,
    ));

    $result = json_decode($response->getBody()->getContents(), true);
    if (!is_array($result) || ($result['status'] ?? '') !== 'success') {
        $errorCode = (string)($result['err_no'] ?? 'unknown');
        throw new \RuntimeException('PayTR durum sorgusu basarisiz. Hata kodu: ' . $errorCode);
    }

    $paymentMinor = paytrDecimalToMinor($result['payment_amount'] ?? null);
    $totalMinor = paytrDecimalToMinor($result['payment_total'] ?? null);
    $currency = paytrFormatCurrency($result['currency'] ?? '');
    $testMode = (string)($result['test_mode'] ?? '0') === '1';
    $installmentCount = isset($result['taksit']) && preg_match('/^\d+$/', (string)$result['taksit'])
        ? max(1, (int)$result['taksit'])
        : 1;

    if ($paymentMinor === null
        || $totalMinor === null
        || !in_array($currency, array('TL', 'USD', 'EUR', 'GBP', 'RUB'), true)
    ) {
        throw new \RuntimeException('PayTR durum sorgusu gecersiz odeme verisi dondurdu.');
    }

    return array(
        'payment_minor' => $paymentMinor,
        'total_minor' => $totalMinor,
        'currency' => $currency,
        'test_mode' => $testMode,
        'installment_count' => $installmentCount,
    );
}

function paytrGetInvoiceData($invoiceId)
{
    return \WHMCS\Database\Capsule::table('tblinvoices')
        ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
        ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
        ->where('tblinvoices.id', $invoiceId)
        ->select(
            'tblinvoices.id',
            'tblinvoices.userid',
            'tblinvoices.total',
            'tblinvoices.status',
            'tblinvoices.taxrate',
            'tblinvoices.taxrate2',
            'tblcurrencies.code as currency_code'
        )
        ->first();
}

function paytrCallbackLogData(array $payload, $isTestMode)
{
    $allowedFields = array(
        'merchant_oid',
        'status',
        'total_amount',
        'payment_type',
        'payment_amount',
        'currency',
        'installment_count',
        'merchant_id',
        'test_mode',
        'failed_reason_code',
        'failed_reason_msg',
    );
    $logData = array();

    foreach ($allowedFields as $field) {
        if (isset($payload[$field]) && is_scalar($payload[$field])) {
            $logData[$field] = substr((string)$payload[$field], 0, 512);
        }
    }

    if ($isTestMode) {
        $logData['paytr_transaction_environment'] = 'PayTR magazaniz test modundadir.';
    }

    return $logData;
}

function paytrTransactionExists($transactionId)
{
    return \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('transid', $transactionId)
        ->exists();
}

function paytrAddInvoiceTestModeNote($invoiceId, $transactionId)
{
    $connection = \WHMCS\Database\Capsule::connection();

    $connection->transaction(function () use ($invoiceId, $transactionId) {
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->lockForUpdate()
            ->first(array('notes'));

        if (!$invoice || strpos((string)$invoice->notes, $transactionId) !== false) {
            return;
        }

        $note = 'PayTR magazaniz test modundadir. Test odeme basarili gorundu ancak gercek tahsilat olmadigi icin fatura odenmis isaretlenmedi. Islem: ' . $transactionId;
        $notes = trim((string)$invoice->notes . "\n" . $note);

        \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->update(array('notes' => $notes));
    });
}

function paytrAddInstallmentFeeInvoiceItem($invoiceId, $feeAmount, $installmentCount, $taxMode)
{
    global $CONFIG;

    $feeAmount = round((float)$feeAmount, 2);
    if ($feeAmount <= 0) {
        return;
    }

    $invoiceBefore = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first(array('total', 'taxrate', 'taxrate2'));
    if (!$invoiceBefore) {
        throw new \RuntimeException('WHMCS faturasi bulunamadi.');
    }

    $hasTaxedItem = \WHMCS\Database\Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->where('taxed', 1)
        ->exists();
    $taxed = $taxMode === 'follow_invoice'
        && !empty($CONFIG['TaxEnabled'])
        && $hasTaxedItem
        && ((float)$invoiceBefore->taxrate > 0 || (float)$invoiceBefore->taxrate2 > 0);
    $itemAmount = $feeAmount;

    if ($taxed && strcasecmp((string)($CONFIG['TaxType'] ?? 'Exclusive'), 'Inclusive') !== 0) {
        $taxRate = max(0, (float)$invoiceBefore->taxrate) / 100;
        $taxRate2 = max(0, (float)$invoiceBefore->taxrate2) / 100;
        $compound = !empty($CONFIG['TaxL2Compound']);
        $taxFactor = 1 + $taxRate + ($compound ? $taxRate2 * (1 + $taxRate) : $taxRate2);
        $itemAmount = round($feeAmount / max(1, $taxFactor), 2);
    }

    $description = 'Vade Farki (' . (int)$installmentCount . ' Taksit) - PayTR';
    $result = localAPI('UpdateInvoice', array(
        'invoiceid' => $invoiceId,
        'newitemdescription' => array($description),
        'newitemamount' => array(number_format($itemAmount, 2, '.', '')),
        'newitemtaxed' => array($taxed),
    ));

    if (($result['result'] ?? '') !== 'success') {
        throw new \RuntimeException('WHMCS vade farki fatura kalemini ekleyemedi.');
    }

    $invoiceAfter = \WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first(array('total'));
    $actualDifference = round((float)$invoiceAfter->total - (float)$invoiceBefore->total, 2);
    $roundingDifference = round($feeAmount - $actualDifference, 2);

    if (abs($roundingDifference) >= 0.01) {
        $roundingResult = localAPI('UpdateInvoice', array(
            'invoiceid' => $invoiceId,
            'newitemdescription' => array('Vade Farki Vergi Yuvarlama'),
            'newitemamount' => array(number_format($roundingDifference, 2, '.', '')),
            'newitemtaxed' => array(false),
        ));

        if (($roundingResult['result'] ?? '') !== 'success') {
            throw new \RuntimeException('WHMCS vade farki vergi yuvarlamasini ekleyemedi.');
        }
    }

    $finalTotal = (float)\WHMCS\Database\Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->value('total');
    if (abs(round($finalTotal - (float)$invoiceBefore->total, 2) - $feeAmount) >= 0.01) {
        throw new \RuntimeException('WHMCS vade farki toplami PayTR tahsilatiyla eslesmedi.');
    }
}

function paytrAppendTransactionDescription($invoiceId, $transactionId, $gatewayModuleName, $description)
{
    if ($description === '') {
        return;
    }

    $transaction = \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->where('transid', $transactionId)
        ->where('gateway', $gatewayModuleName)
        ->first(array('id', 'description'));

    if (!$transaction || strpos((string)$transaction->description, $description) !== false) {
        return;
    }

    $newDescription = trim((string)$transaction->description . ' [' . $description . ']');
    \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('id', $transaction->id)
        ->update(array('description' => $newDescription));
}

$gatewayParams = getGatewayVariables($gatewayModuleName);
if (empty($gatewayParams['type'])) {
    http_response_code(503);
    die('Module Not Activated');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die('Method Not Allowed');
}

$hash = $_POST['hash'] ?? '';
$merchantOid = $_POST['merchant_oid'] ?? '';
$status = $_POST['status'] ?? '';
$totalAmount = $_POST['total_amount'] ?? '';
$paymentAmount = $_POST['payment_amount'] ?? '';
$currency = $_POST['currency'] ?? '';
$isTestModeCallback = (string)($_POST['test_mode'] ?? '0') === '1';
$callbackLogData = paytrCallbackLogData($_POST, $isTestModeCallback);

$hashString = $merchantOid . $gatewayParams['merchantSalt'] . $status . $totalAmount;
$localHash = base64_encode(hash_hmac('sha256', $hashString, $gatewayParams['merchantKey'], true));

if (!is_string($hash) || !hash_equals($localHash, $hash)) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Hash Verification Failure');
}

if (!in_array($status, array('success', 'failed'), true)) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invalid Payment Status');
}

$orderContext = paytrParseMerchantOid($merchantOid);
$isLegacyOrder = false;
if ($orderContext === null) {
    $legacyContext = paytrParseLegacyMerchantOid($merchantOid);
    if ($legacyContext !== null) {
        $orderContext = $legacyContext;
        $isLegacyOrder = true;
    }
}

if ($orderContext === null) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invalid Merchant Order Context');
}

$invoiceId = checkCbInvoiceID($orderContext['invoice_id'], $gatewayParams['name']);
$invoice = paytrGetInvoiceData($invoiceId);
if (!$invoice) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invoice Lookup Failure');
}

if ($status === 'failed') {
    logTransaction($gatewayParams['name'], $callbackLogData, 'Failed');
    paytrCallbackOk();
}

if (paytrTransactionExists($merchantOid)) {
    logTransaction($gatewayParams['name'], $callbackLogData, 'Duplicate - Already Processed');
    paytrCallbackOk();
}

$callbackPaymentMinor = paytrParseMinorAmount($paymentAmount);
$callbackTotalMinor = paytrParseMinorAmount($totalAmount);
if ($callbackPaymentMinor === null || $callbackTotalMinor === null) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Invalid Callback Amount');
}

try {
    $verifiedPayment = paytrQueryPaymentStatus($gatewayParams, $merchantOid);
}
catch (\Throwable $e) {
    logActivity('PayTR odeme durum sorgusu basarisiz - Invoice ID: ' . $invoiceId . ' - ' . $e->getMessage());
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Payment Status Verification Failure', 503);
}

if ($callbackPaymentMinor !== $verifiedPayment['payment_minor']
    || $callbackTotalMinor !== $verifiedPayment['total_minor']
) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'PayTR Status Amount Mismatch');
}

if ($verifiedPayment['total_minor'] < $verifiedPayment['payment_minor']) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Verified Charged Amount Mismatch');
}

$callbackCurrency = paytrFormatCurrency($currency);
if ($callbackCurrency !== $verifiedPayment['currency']
    || $verifiedPayment['currency'] !== paytrFormatCurrency($invoice->currency_code)
) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'PayTR Status Currency Mismatch');
}

if ($isTestModeCallback !== $verifiedPayment['test_mode']) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'PayTR Status Test Mode Mismatch');
}

if (!$isLegacyOrder
    && ($orderContext['amount_minor'] !== $verifiedPayment['payment_minor']
        || $orderContext['currency'] !== $verifiedPayment['currency']
        || $orderContext['test_mode'] !== $verifiedPayment['test_mode'])
) {
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Signed Order Context Mismatch');
}

if ($verifiedPayment['test_mode']) {
    paytrAddInvoiceTestModeNote($invoiceId, $merchantOid);
    logTransaction($gatewayParams['name'], $callbackLogData, 'Test Payment Verified - Invoice Not Paid');
    paytrCallbackOk();
}

$chargedAmount = paytrMinorToDecimal($verifiedPayment['total_minor']);
$installmentFee = paytrMinorToDecimal(
    $verifiedPayment['total_minor'] - $verifiedPayment['payment_minor']
);
$installmentCount = $verifiedPayment['installment_count'];
$installmentFeeTaxMode = (string)($gatewayParams['installmentFeeTaxMode'] ?? 'not_taxed');

try {
    $wasProcessed = \WHMCS\Database\Capsule::connection()->transaction(function () use (
        $invoiceId,
        $invoice,
        $merchantOid,
        $installmentFee,
        $installmentCount,
        $chargedAmount,
        $gatewayModuleName,
        $callbackCurrency,
        $installmentFeeTaxMode
    ) {
        \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->lockForUpdate()
            ->first(array('id'));

        if (paytrTransactionExists($merchantOid)) {
            return false;
        }

        if ($installmentFee > 0) {
            paytrAddInstallmentFeeInvoiceItem(
                $invoiceId,
                $installmentFee,
                $installmentCount,
                $installmentFeeTaxMode
            );
        }

        addInvoicePayment($invoiceId, $merchantOid, $chargedAmount, 0, $gatewayModuleName);

        if ($installmentFee > 0) {
            paytrAppendTransactionDescription(
                $invoiceId,
                $merchantOid,
                $gatewayModuleName,
                'Vade Farki: ' . number_format($installmentFee, 2, '.', '') . ' ' . $callbackCurrency . ', Taksit: ' . $installmentCount
            );
        }

        return true;
    });
}
catch (\Throwable $e) {
    logActivity('PayTR callback islenemedi - Invoice ID: ' . $invoiceId . ' - ' . $e->getMessage());
    paytrCallbackFail($gatewayParams['name'], $callbackLogData, 'Payment Recording Failure', 500);
}

logTransaction(
    $gatewayParams['name'],
    $callbackLogData,
    $wasProcessed ? 'Successful' : 'Duplicate - Already Processed'
);

paytrCallbackOk();
