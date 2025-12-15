<?php

$loader = require __DIR__.'/../vendor/autoload.php';

$obj = new \QBWCServer\applications\AddInvoicesApp([
    'login' => 'Admin',
    'password' => '1',
    'iterator' => null
]);


\QBWCServer\launcher\SoapLauncher::start($obj);