<?php
// Credentials for LOCAL database
$host = 'localhost';
$db = 'techwyse_shopify_ptl_db';
$user = 'techwyse_shopify_ptl_user';
$pass = '^Y!1iOEn?O3p';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

echo "Connecting to Local DB: $dsn ...\n";

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    echo "Connected successfully to LOCAL database.\n";

    // Test query
    echo "Checking for 'orders_queue' table...\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'orders_queue'");
    if ($stmt->rowCount() > 0) {
        echo "Table 'orders_queue' exists.\n";

        $stmt = $pdo->query("SELECT count(*) as count FROM orders_queue");
        $row = $stmt->fetch();
        echo "Row count: " . $row['count'] . "\n";
    } else {
        echo "WARNING: Table 'orders_queue' NOT found.\n";
    }

} catch (\PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
    echo "Hint: Did you create the database and user locally?\n";
}
