<?php
// Shopify API Configuration
$shopify_shop_url = 'ptl-stg.myshopify.com'; // Replace with your shop URL
$access_token = 'YOUR_SHOPIFY_ACCESS_TOKEN'; // <--- IMPORTANT: Replace this with your actual Shopify access token on the server!

function getOrderDetails($orderId)
{
    global $shopify_shop_url, $access_token;

    // API endpoint for getting order details
    $url = "https://{$shopify_shop_url}/admin/api/2024-10/orders/{$orderId}.json";

    // Set up cURL request
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Shopify-Access-Token: ' . $access_token,
            'Content-Type: application/json'
        ]
    ]);

    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = 'Curl error: ' . curl_error($ch);
        file_put_contents('log.txt', "Curl Error: $error\n", FILE_APPEND);
        return null;
    }

    curl_close($ch);

    // Check if request was successful
    if ($httpCode === 200) {
        return json_decode($response, true);
    } else {
        $errorMsg = "Error: HTTP Status Code: {$httpCode}\nResponse: {$response}\n";
        file_put_contents('log.txt', $errorMsg, FILE_APPEND);
        return null;
    }
}

// Handle incoming webhook request
$rawData = file_get_contents('php://input');
$orderData = json_decode($rawData, true);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($orderData['id'])) {
    $orderId = $orderData['id'];
    // Validate order ID (should be numeric)
    if (!is_numeric($orderId)) {
        http_response_code(400);
        error_log(json_encode(['error' => 'Invalid order ID']));
        exit;
    }

    // Use the incoming webhook data directly as it contains the full order details.
    // The API call is redundant and currently failing with 404.
    // Webhook payload structure corresponds to the content of 'order', so we wrap it to match existing logic logic if needed,
    // or just adjust logic to use it directly. 

    // Existing logic expects structure: ['order' => ...data...] or we can just adapt the variables.
    // The previous getOrderDetails returned: ['order' => { ... }]
    // The webhook $orderData is: { ... } (the content of the order)
    // So we can simulate the structure:
    $orderDetails = ['order' => $orderData];

    file_put_contents('log.txt', 'order_details: ' . json_encode($orderDetails) . "\n", FILE_APPEND);
    if ($orderDetails && isset($orderDetails['order'])) {
        // Process line items to group them by base products and sub-products
        $processedItems = [];
        $groupedItems = [];

        // Check if this is a draft order
        $isDraftOrder = isset($orderDetails['order']['source_name']) && strpos($orderDetails['order']['source_name'], 'shopify_draft_order') !== false;

        // First pass - group items
        $baseProducts = []; // Store base products to match with sub-products for draft orders
        foreach ($orderDetails['order']['line_items'] as $item) {
            if ($isDraftOrder) {
                // For draft orders: Group by matching product titles
                if (!isset($item['variant_title']) || $item['variant_title'] === null || $item['variant_title'] === '') {
                    // This is a base product
                    $baseProducts[] = $item;
                    $groupId = 'base_' . $item['id'];
                    if (!isset($groupedItems[$groupId])) {
                        $groupedItems[$groupId] = [];
                    }
                    $groupedItems[$groupId][] = $item;
                } else {
                    // Try to find matching base product
                    $matched = false;
                    foreach ($baseProducts as $baseProduct) {
                        if (strpos($item['title'], $baseProduct['title']) === 0) {
                            $groupId = 'base_' . $baseProduct['id'];
                            if (!isset($groupedItems[$groupId])) {
                                $groupedItems[$groupId] = [];
                            }
                            $groupedItems[$groupId][] = $item;
                            $matched = true;
                            break;
                        }
                    }
                    // If no match found, treat as standalone item
                    if (!$matched) {
                        $groupId = 'single_' . $item['id'];
                        if (!isset($groupedItems[$groupId])) {
                            $groupedItems[$groupId] = [];
                        }
                        $groupedItems[$groupId][] = $item;
                    }
                }
            } else {
                // Regular order: Group by group_id
                $groupId = null;
                if (isset($item['properties'])) {
                    foreach ($item['properties'] as $property) {
                        if ($property['name'] === '_group_id') {
                            $groupId = $property['value'];
                            break;
                        }
                    }
                }

                // If no group_id, treat it as its own group
                if (!$groupId) {
                    $groupId = 'single_' . $item['id'];
                }
                if (!isset($groupedItems[$groupId])) {
                    $groupedItems[$groupId] = [];
                }
                $groupedItems[$groupId][] = $item;
            }
        }

        // Second pass - process each group
        foreach ($groupedItems as $groupId => $items) {
            $baseItem = null;
            $subItems = [];
            $totalPrice = 0;
            $description = '';

            // Find base item and sub items
            foreach ($items as $item) {
                if (!isset($item['variant_title']) || $item['variant_title'] === null || $item['variant_title'] === '') {
                    $baseItem = $item;
                } else {
                    $subItems[] = $item;
                }
            }

            // If no base item found, use the first item as base
            if (!$baseItem && count($items) > 0) {
                $baseItem = $items[0];
                array_shift($items);
                $subItems = $items;
            }

            if ($baseItem) {
                // Calculate total price
                $totalPrice = floatval($baseItem['price']) * $baseItem['quantity'];

                // Build description including sub items only
                // Format each sub item as: trimmed_title:variant_title
                $descriptionParts = [];
                $baseTitle = isset($baseItem['title']) ? $baseItem['title'] : '';
                foreach ($subItems as $subItem) {
                    $totalPrice += floatval($subItem['price']) * $subItem['quantity'];

                    // Determine trimmed title: remove base title prefix if present at start
                    $subTitle = isset($subItem['title']) ? $subItem['title'] : '';
                    $trimmed = $subTitle;
                    if ($baseTitle !== '' && strpos($subTitle, $baseTitle) === 0) {
                        $trimmed = trim(substr($subTitle, strlen($baseTitle)));
                    }

                    // Use variant title if available
                    $variant = isset($subItem['variant_title']) ? $subItem['variant_title'] : '';
                    if ($variant !== '') {
                        $descriptionParts[] = $trimmed . ' : ' . $variant;
                    } else {
                        // If no variant title, just add trimmed title
                        $descriptionParts[] = $trimmed;
                    }
                }

                // Prepend base/main title to description (if available) and then add sub-items
                if ($baseTitle !== '') {
                    if (count($descriptionParts) > 0) {
                        $description = $baseTitle . ' ; ' . implode(' ; ', $descriptionParts);
                    } else {
                        $description = $baseTitle;
                    }
                } else {
                    $description = implode(' ; ', $descriptionParts);
                }

                // Add to processed items (price formatted to 2 decimal places)
                $processedItems[] = [
                    'id' => $baseItem['id'],
                    'title' => $baseItem['title'],
                    'description' => $description,
                    'quantity' => $baseItem['quantity'],
                    'price' => number_format($totalPrice, 2, '.', ''),
                    'sku' => (isset($baseItem['sku']) && $baseItem['sku'] !== null && $baseItem['sku'] !== '') ? $baseItem['sku'] : $baseItem['title'],
                    'vendor' => $baseItem['vendor']
                ];
            }
        }

        // Create final payload
        $payload = [
            'order_id' => $orderDetails['order']['id'],
            'order_number' => $orderDetails['order']['name'],
            'created_at' => $orderDetails['order']['created_at'],
            'items' => $processedItems,
            'total_price' => $orderDetails['order']['total_price'],
            'currency' => $orderDetails['order']['currency'],
            'customer' => [
                'first_name' => $orderDetails['order']['customer']['first_name'],
                'last_name' => $orderDetails['order']['customer']['last_name'],
                'email' => $orderDetails['order']['customer']['email']
            ],
            'shipping_address' => $orderDetails['order']['shipping_address']
        ];

        header('Content-Type: application/json');
        $jsonPayload = json_encode($payload, JSON_PRETTY_PRINT);
        echo $jsonPayload;

        // Store in local database
        $servername = "localhost";
        $username = "techwyse_shopify_ptl_user";
        $password = "^Y!1iOEn?O3p";
        $dbname = "techwyse_shopify_ptl_db";

        try {
            $dsn = "mysql:host=$servername;dbname=$dbname;charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            $stmt = $pdo->prepare(
                "INSERT INTO orders_queue (shopify_order_id, payload, status) VALUES (:oid, :payload, 'pending')"
            );
            $stmt->execute([
                ':oid' => $payload['order_id'],
                ':payload' => json_encode($jsonPayload)
            ]);

            error_log("✅ Insert successful for order ID: " . $payload['order_id']);

        } catch (PDOException $e) {
            error_log("❌ PDO Error: " . $e->getMessage());
        }
    } else {
        http_response_code(404);
        print_r(json_encode(['error' => 'Order not found or error occurred']));
    }
} else {
    http_response_code(400);
    print_r(json_encode(['error' => 'Invalid request. Expected POST with order ID']));
}
