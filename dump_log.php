<?php
$files = [
    __DIR__ . '/src/QBWCServer/applications/qbwc_add_customer_invoice_app.log',
    __DIR__ . '/qbwc_add_customer_invoice_app.log'
];

foreach ($files as $f) {
    if (file_exists($f)) {
        echo "File: $f\n";
        echo "Size: " . filesize($f) . "\n";
        echo "Last Modified: " . date("Y-m-d H:i:s", filemtime($f)) . "\n";
        echo "Tail:\n";
        // Seek to end minus 4000
        $fp = fopen($f, 'r');
        fseek($fp, -4000, SEEK_END);
        echo fread($fp, 4000);
        fclose($fp);
        echo "\n-----------------------------------\n";
    } else {
        echo "File not found: $f\n------------------\n";
    }
}
