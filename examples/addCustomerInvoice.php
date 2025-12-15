<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    $vendorPath = __DIR__ . '/../vendor/autoload.php';

    if (!file_exists($vendorPath)) {
        throw new \Exception('Vendor autoload not found at: ' . $vendorPath);
    }

    $loader = require $vendorPath;

    $obj = new \QBWCServer\applications\AddCustomerInvoiceApp([
        'login' => 'Admin',
        'password' => '1',
        'iterator' => null
    ]);

    \QBWCServer\launcher\SoapLauncher::start($obj);
} catch (\Throwable $e) {
    error_log('QBWC Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: text/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="utf-8"?>';
    echo '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">';
    echo '<SOAP-ENV:Body>';
    echo '<SOAP-ENV:Fault>';
    echo '<faultcode>Server</faultcode>';
    echo '<faultstring>' . htmlspecialchars($e->getMessage()) . '</faultstring>';
    echo '</SOAP-ENV:Fault>';
    echo '</SOAP-ENV:Body>';
    echo '</SOAP-ENV:Envelope>';
    exit;
}