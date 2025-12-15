<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "PHP Version: " . PHP_VERSION . "\n";
echo "SOAP Extension: " . (extension_loaded('soap') ? 'Loaded' : 'Not Loaded') . "\n";
echo "Working Directory: " . getcwd() . "\n";
echo "This file: " . __FILE__ . "\n";

$autoloadPath = __DIR__ . '/vendor/autoload.php';
echo "Looking for autoloader at: $autoloadPath\n";
echo "Autoloader exists: " . (file_exists($autoloadPath) ? 'YES' : 'NO') . "\n";

if (file_exists($autoloadPath)) {
    require $autoloadPath;
    echo "Autoloader loaded successfully\n";
} else {
    echo "ERROR: Autoloader not found!\n";
}

$wsdlPath = __DIR__ . '/src/QBWCServer/config/qbwebconnectorsvc.wsdl';
echo "WSDL path: $wsdlPath\n";
echo "WSDL exists: " . (file_exists($wsdlPath) ? 'YES' : 'NO') . "\n";
