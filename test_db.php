<?php
try {
    $dsn = "mysql:host=shortline.proxy.rlwy.net;port=53111;dbname=railway";
    $pdo = new PDO($dsn, "root", "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connection successful\n";
    
    // Try to fetch a pending order
    $stmt = $pdo->prepare("SELECT id, shopify_order_id, payload FROM orders_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row) {
        echo "Found pending order:\n";
        echo "ID: " . $row['id'] . "\n";
        echo "Shopify Order ID: " . $row['shopify_order_id'] . "\n";
        
        // Test JSON decoding
        $payload = json_decode($row['payload'], true);
        if ($payload === null) {
            echo "JSON decode error: " . json_last_error_msg() . "\n";
            // Try double-decode if it's a JSON string
            $decoded = json_decode(json_decode($row['payload'], true), true);
            if ($decoded !== null) {
                echo "Successfully double-decoded JSON payload\n";
                print_r($decoded);
            }
        } else {
            echo "Successfully decoded JSON payload\n";
            print_r($payload);
        }
    } else {
        echo "No pending orders found\n";
    }
    
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}