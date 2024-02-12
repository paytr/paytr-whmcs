<?php
require_once __DIR__ . '/../../../init.php';
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}
App::load_function('gateway');
App::load_function('invoice');
$gatewayModuleName = basename('paytr', '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);
// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?php echo $gatewayParams['name'] ?></title>
</head>
<body>
<script src="https://www.paytr.com/js/iframeResizer.min.js"></script>
<iframe src="https://www.paytr.com/odeme/guvenli/<?php echo filter_var($_GET['token'], FILTER_SANITIZE_STRING); ?>" id="paytriframe" frameborder="0" scrolling="no" style="width: 100%;"></iframe>
<script>iFrameResize({},'#paytriframe');</script>
</body>
</html>
