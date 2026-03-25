<?php

require_once __DIR__ . '/../../../init.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

App::load_function('gateway');
App::load_function('invoice');

$gatewayModuleName = 'paytr';
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (empty($gatewayParams['type'])) {
    die("Module Not Activated");
}

// Fixed for PHP 8.2: Removed FILTER_SANITIZE_STRING
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';
// Additional safety: alphanumeric check since PayTR tokens are typically hex or base64-like alphanumeric
$token = preg_replace('/[^a-zA-Z0-9_-]/', '', $token);

?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo htmlspecialchars($gatewayParams['name']); ?></title>
    <style>
        body { margin: 0; padding: 0; background-color: #f8f9fa; }
        #paytriframe { border: 0; min-height: 600px; width: 100%; }
    </style>
</head>
<body>
    <script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
    <iframe src="https://www.paytr.com/odeme/guvenli/<?php echo htmlspecialchars($token); ?>" id="paytriframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>
    <script>
        iFrameResize({
            log: false,
            checkOrigin: false
        }, '#paytriframe');
    </script>
</body>
</html>
