<?php

// Define log file path
$logFile = 'order_log.txt';

// Simple log function
function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n");
}

try {
    // Read and decode input
    $rawData = file_get_contents('php://input');
    logMessage("Raw input: " . $rawData);

    $orderData = json_decode($rawData, true);
    logMessage("Decoded order data: " . $orderData);
    logMessage("json encoded order data: " . json_encode($orderData));
    if (!$orderData) {
        http_response_code(400);
        logMessage("❌ Invalid JSON input");
        exit('Invalid data');
    }

    // Database connection
    $dsn = "mysql:host=shortline.proxy.rlwy.net;port=53111;dbname=railway;charset=utf8mb4";
    $username = "root";
    $password = "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA";

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, // Throw exceptions on error
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Insert query
    // Store the exact incoming request body as a JSON string in the `payload` column.
    // Reason: the DB `payload` column is type JSON and MySQL will normalize objects when
    // storing JSON values. By encoding the raw request body as a JSON string we preserve
    // the original bytes/text intact (it will be stored as a JSON string value). When
    // reading back, decode once to get the raw JSON text, then decode the text to get
    // the original structure.
    // Use a plain string (no backslash-escaped newlines) so PDO receives valid SQL.
    $stmt = $pdo->prepare(
        "INSERT INTO orders_queue (shopify_order_id, payload, status) VALUES (:oid, :payload, 'pending')"
    );
    $stmt->execute([
        ':oid' => isset($orderData['order_id']) ? $orderData['order_id'] : ($orderData['id'] ?? null),
        // double-encode: encode the raw request string so the DB stores it exactly
        // as a JSON string value (preserves whitespace, ordering, etc.).
        ':payload' => json_encode($rawData)
    ]);

    // Log the inserted order id (prefer order_id if available)
    $insertedOid = isset($orderData['order_id']) ? $orderData['order_id'] : ($orderData['id'] ?? 'unknown');
    logMessage("✅ Insert successful for order ID: " . $insertedOid);

    http_response_code(200);
    echo "OK";

} catch (PDOException $e) {
    logMessage("❌ PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo "Database error";
} catch (Exception $e) {
    logMessage("❌ General Error: " . $e->getMessage());
    http_response_code(500);
    echo "Error";
}
?>