<?php
// Credentials from AddCustomerInvoiceApp.php
$dsn = "mysql:host=198.136.53.98;dbname=techwyse_shopify_ptl_db;charset=utf8mb4";
$dbUser = "techwyse_shopify_ptl_user";
$dbPass = "^Y!1iOEn?O3p";

echo "Connecting to $dsn ...\n";

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected successfully.\n";

    echo "Querying for pending orders...\n";
    $stmt = $pdo->prepare("SELECT id, shopify_order_id, status, created_at FROM orders_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 5");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($rows) > 0) {
        echo "Found " . count($rows) . " pending orders:\n";
        foreach ($rows as $row) {
            print_r($row);
        }
    } else {
        echo "No pending orders found.\n";
    }

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
