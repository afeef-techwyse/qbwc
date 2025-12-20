<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private $dsn = "mysql:host=localhost;dbname=techwyse_shopify_ptl_db;charset=utf8mb4";
    private $dbUser = "techwyse_shopify_ptl_user";
    private $dbPass = "^Y!1iOEn?O3p";

    private $orders = [];
    private $stage = 'query_customer';
    private $customerName;
    private $currentItemIndex = 0;
    private $currentOrderItems = [];
    private $currentDbOrderId = null;
    private $requestCounter = 0;

    /**
     * Normalize and truncate item FullName for QuickBooks.
     * QuickBooks Item FullName max length is 31 characters.
     * Uses multibyte-safe functions to preserve UTF-8.
     *
     * Clean string of common non-ASCII characters that break QBXML.
     */
    private function cleanString($str)
    {
        $replacements = [
            '“' => '"',
            '”' => '"',
            '‘' => "'",
            '’' => "'",
            '–' => '-',
            '—' => '-',
            '…' => '...'
        ];
        // Normalize newlines to spaces for single-line fields if needed, 
        // but for descriptions newlines might be okay. 
        // For now, simple replacement.
        return strtr(trim((string) $str), $replacements);
    }

    /**
     * Normalize and truncate item FullName for QuickBooks.
     * QuickBooks Item FullName max length is 31 characters.
     */
    private function normalizeItemFullName($name)
    {
        $name = $this->cleanString($name);

        if ($name === '') {
            return 'Unknown Item';
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($name, 'UTF-8') > 30) {
                return mb_substr($name, 0, 30, 'UTF-8');
            }
            return $name;
        }

        if (strlen($name) > 30) {
            return substr($name, 0, 30);
        }
        return $name;
    }

    // ---------------------- Database Methods ----------------------
    private function getDbConnection()
    {
        try {
            $pdo = new \PDO($this->dsn, $this->dbUser, $this->dbPass);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            return $pdo;
        } catch (\PDOException $e) {
            $this->log("Database connection error: " . $e->getMessage());
            return null;
        }
    }

    private function fetchPendingOrders()
    {
        $pdo = $this->getDbConnection();
        if (!$pdo) {
            $this->log("Failed to connect to database");
            return false;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, shopify_order_id, payload FROM orders_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $this->log("No pending orders found");
                return false;
            }

            // payload may be a JSON string (possibly double-encoded)
            $payload = $row['payload'];
            $decoded = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                // try decoding again if payload itself is a JSON string containing JSON
                $decoded2 = json_decode($payload, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded2)) {
                    $decoded = $decoded2;
                }
            }

            $this->log("Fetched order payload (raw): " . substr($payload, 0, 1000));
            $this->log("Fetched order payload (decoded): " . json_encode($decoded));

            if ($decoded) {
                $order = $this->transformShopifyOrder($decoded, $row['id']);
                if ($order) {
                    $this->orders = [$order];
                    $this->log("Fetched pending order: " . json_encode($order));
                    return true;
                }
            }

            return false;
        } catch (\PDOException $e) {
            $this->log("Error fetching orders: " . $e->getMessage());
            return false;
        }
    }

    private function transformShopifyOrder($shopifyData, $dbId)
    {
        $this->log("transformShopifyOrder - raw shopifyData type: " . gettype($shopifyData));

        // If input is a JSON string, attempt to decode
        if (is_string($shopifyData)) {
            $this->log("transformShopifyOrder - attempting to decode JSON string");
            $decoded = json_decode($shopifyData, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $shopifyData = $decoded;
                $this->log("transformShopifyOrder - successfully decoded JSON string");
            } else {
                $this->log("transformShopifyOrder - JSON decode error: " . json_last_error_msg());
            }
        }

        $this->log("transformShopifyOrder - shopifyData after decode: " . json_encode($shopifyData));

        $customer = $shopifyData['customer'] ?? null;
        $this->log("transformShopifyOrder - customer: " . json_encode($customer));
        $shippingAddress = $shopifyData['shipping_address'] ?? $shopifyData['billing_address'] ?? null;
        $this->log("transformShopifyOrder - shippingAddress: " . json_encode($shippingAddress));
        $lineItems = $shopifyData['items'] ?? $shopifyData['line_items'] ?? [];
        $this->log("transformShopifyOrder - lineItems: " . json_encode($lineItems));

        if (!$customer && !$shippingAddress) {
            $this->log("Incomplete customer/address data for order ID: {$dbId}");
            return null;
        }

        if (!$customer) {
            $customer = [
                'first_name' => $shippingAddress['first_name'] ?? 'Valued',
                'last_name' => $shippingAddress['last_name'] ?? 'Customer',
                'email' => $shippingAddress['email'] ?? '',
                'phone' => $shippingAddress['phone'] ?? ''
            ];
        }

        $transformedLineItems = [];
        foreach ($lineItems as $item) {
            $rawTitle = $item['sku'] ?? $item['title'] ?? $item['name'] ?? 'Unknown Item';

            // --- SPLIT-ON-SYNC LOGIC START ---
            $price = (float) ($item['price'] ?? $item['total_price'] ?? 0);
            $mainLinePrice = $price;
            $addonLines = [];

            if (isset($item['properties']) && is_array($item['properties'])) {
                $addonsFound = [];
                foreach ($item['properties'] as $prop) {
                    $pName = is_array($prop) ? ($prop['name'] ?? '') : ($prop->name ?? '');
                    $pValue = is_array($prop) ? ($prop['value'] ?? '') : ($prop->value ?? '');

                    // Check for consolidated format: "Variant Name (SKU: xxx ; Price: yyy)"
                    // Property Name is the Category (e.g. "Ladders & Steps")
                    if (preg_match('/^(.*?)\s*\(SKU:\s*(.*?)\s*;\s*Price:\s*(.*?)\)/i', $pValue, $matches)) {
                        $key = trim($pName); // Category
                        $variantTitle = trim($matches[1]);

                        if (!isset($addonsFound[$key]))
                            $addonsFound[$key] = [];
                        $addonsFound[$key]['sku'] = trim($matches[2]);
                        $addonsFound[$key]['price'] = str_replace(['$', ','], '', trim($matches[3]));
                        $addonsFound[$key]['variant'] = $variantTitle;
                    }

                    // Legacy "Addon:" handling (keep for safety)
                    if (strpos($pName, 'Addon:') === 0 && preg_match('/\(SKU:\s*(.*?)\s*;\s*Price:\s*(.*?)\)/i', $pValue, $matches)) {
                        $key = trim(substr($pName, strlen('Addon:')));
                        if (!isset($addonsFound[$key]))
                            $addonsFound[$key] = [];
                        $addonsFound[$key]['sku'] = trim($matches[1]);
                        $addonsFound[$key]['price'] = str_replace(['$', ','], '', trim($matches[2]));
                        // Try to extract variant title from value if possible, else default
                        $vTitle = preg_replace('/\(SKU:.*\)/i', '', $pValue);
                        $addonsFound[$key]['variant'] = trim($vTitle);
                    }

                    if (strpos($pName, 'Addon SKU:') === 0) {
                        $key = trim(substr($pName, strlen('Addon SKU:')));
                        if (!isset($addonsFound[$key]))
                            $addonsFound[$key] = [];
                        $addonsFound[$key]['sku'] = $pValue;
                    }
                    if (strpos($pName, 'Addon Price:') === 0) {
                        $key = trim(substr($pName, strlen('Addon Price:')));
                        if (!isset($addonsFound[$key]))
                            $addonsFound[$key] = [];
                        $addonsFound[$key]['price'] = str_replace(['$', ','], '', $pValue);
                    }
                }
            } else {
                // FALLBACK: Parse from description string if properties array is undefined
                $desc = $item['description'] ?? '';
                if ($desc) {
                    // 1. Generalized Consolidated Format
                    // Matches: "Category: Variant Name (SKU: S [;|] Price: P)"
                    // We look for "Key: Value (SKU..." pattern with NO "Addon SKU" allowed in Key.
                    if (preg_match_all('/(?:^|;)\s*([^:;]+):\s*([^:;]+?)\s*\(SKU:\s*(.*?)\s*[;|]\s*Price:\s*(.*?)\)/i', $desc, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $m) {
                            $key = trim($m[1]);
                            // Skip legacy keys if they match by accident (unlikely with strict regex)
                            if (strpos($key, 'Addon SKU') === 0 || strpos($key, 'Addon Price') === 0)
                                continue;

                            $variantTitle = trim($m[2]);

                            if (!isset($addonsFound[$key]))
                                $addonsFound[$key] = [];
                            $addonsFound[$key]['sku'] = trim($m[3]);
                            $addonsFound[$key]['price'] = str_replace(['$', ','], '', trim($m[4]));
                            $addonsFound[$key]['variant'] = $variantTitle;
                        }
                    }

                    // 2. Legacy "Addon:" Prefix Format (if still present)
                    if (preg_match_all('/Addon:\s*(.*?):\s*(.*?)\s*\(SKU:\s*(.*?)\s*[;|]\s*Price:\s*(.*?)\)/i', $desc, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $m) {
                            $key = trim($m[1]);
                            $variantTitle = trim($m[2]);
                            if (!isset($addonsFound[$key]))
                                $addonsFound[$key] = [];
                            $addonsFound[$key]['sku'] = trim($m[3]);
                            $addonsFound[$key]['price'] = str_replace(['$', ','], '', trim($m[4]));
                            $addonsFound[$key]['variant'] = $variantTitle;
                        }
                    }

                    // 3. Legacy Separated Format Support via Explode
                    $parts = explode(';', $desc);
                    foreach ($parts as $part) {
                        $part = trim($part);
                        if (strpos($part, 'Addon SKU:') === 0) {
                            $remainder = trim(substr($part, 10));
                            $lastColon = strrpos($remainder, ':');
                            if ($lastColon !== false) {
                                $key = trim(substr($remainder, 0, $lastColon));
                                $value = trim(substr($remainder, $lastColon + 1));
                                if (!isset($addonsFound[$key]))
                                    $addonsFound[$key] = [];
                                if (!isset($addonsFound[$key]['sku']))
                                    $addonsFound[$key]['sku'] = $value;
                            }
                        }
                        if (strpos($part, 'Addon Price:') === 0) {
                            $remainder = trim(substr($part, 12));
                            $lastColon = strrpos($remainder, ':');
                            if ($lastColon !== false) {
                                $key = trim(substr($remainder, 0, $lastColon));
                                $value = trim(substr($remainder, $lastColon + 1));
                                if (!isset($addonsFound[$key]))
                                    $addonsFound[$key] = [];
                                if (!isset($addonsFound[$key]['price']))
                                    $addonsFound[$key]['price'] = str_replace(['$', ','], '', $value);
                            }
                        }
                    }
                }
            }

            if (!empty($addonsFound)) {
                foreach ($addonsFound as $key => $data) {
                    $this->log("Split-on-Sync: Processing found addon key '$key': " . json_encode($data));
                    if (isset($data['sku']) && isset($data['price'])) {
                        // Check if Addon SKU matches Main SKU
                        if (strcasecmp($data['sku'], $rawTitle) === 0) {
                            $this->log("Split-on-Sync: Addon SKU {$data['sku']} matches Main Product SKU. Skipping split (keeping in main line).");
                            continue;
                        }

                        $this->log("Split-on-Sync: Found Addon SKU {$data['sku']} with price {$data['price']}. Splitting from main item.");
                        $aPrice = (float) $data['price'];
                        $aPriceFmt = number_format($aPrice, 2, '.', '');
                        // Deduct from Main
                        $mainLinePrice -= $aPrice;

                        $addonName = $data['sku']; // Use SKU as Title for ItemRef
                        $variantName = $data['variant'] ?? '';
                        $desc = $variantName ? "$key: $variantName" : "Addon: $key";

                        $transformedLineItems[] = [
                            'title' => $this->normalizeItemFullName($addonName),
                            'name' => $desc, // Use description as Name for logging/debugging
                            'quantity' => isset($item['quantity']) ? (int) $item['quantity'] : 1,
                            'price' => $aPriceFmt,
                            // Explicit description
                            'description' => $desc
                        ];
                    }
                }
            }
            // --- SPLIT-ON-SYNC LOGIC END ---

            $transformedLineItems[] = [
                // title is the Item FullName used in QuickBooks; ensure it's <= 31 chars
                'title' => $this->normalizeItemFullName($rawTitle),
                // keep the original (longer) display name in 'name' for descriptions
                'name' => $this->cleanString($item['title'] ?? $item['name'] ?? ''),
                'quantity' => isset($item['quantity']) ? (int) $item['quantity'] : 1,
                'price' => number_format($mainLinePrice, 2, '.', ''), // Use Reduced Price
                'description' => $this->cleanString($item['description'] ?? ($item['name'] ?? $item['title'] ?? ''))
            ];
        }

        return [
            'db_id' => $dbId,
            'id' => $shopifyData['order_id'] ?? $shopifyData['id'] ?? $dbId,
            'order_number' => $shopifyData['order_number'] ?? $shopifyData['name'] ?? "ORD-{$dbId}",
            'customer' => [
                'first_name' => $this->cleanString($customer['first_name'] ?? ''),
                'last_name' => $this->cleanString($customer['last_name'] ?? ''),
                'email' => $this->cleanString($customer['email'] ?? ''),
                'phone' => $this->cleanString($shippingAddress['phone'] ?? $customer['phone'] ?? ''),
                'default_address' => [
                    'company' => $this->cleanString($shippingAddress['company'] ?? ''),
                    'address1' => $this->cleanString($shippingAddress['address1'] ?? $shippingAddress['address_1'] ?? ''),
                    'city' => $this->cleanString($shippingAddress['city'] ?? ''),
                    'province' => $this->cleanString($shippingAddress['province'] ?? $shippingAddress['province_code'] ?? ''),
                    'zip' => $this->cleanString($shippingAddress['zip'] ?? $shippingAddress['postal_code'] ?? ''),
                    'country' => $this->cleanString($shippingAddress['country'] ?? '')
                ]
            ],
            'line_items' => $transformedLineItems
        ];
    }

    private function updateOrderStatus($dbId, $status)
    {
        $pdo = $this->getDbConnection();
        if (!$pdo) {
            $this->log("Failed to connect to database for status update");
            return;
        }

        try {
            $stmt = $pdo->prepare("UPDATE orders_queue SET status = :status WHERE id = :id");
            $stmt->execute([':status' => $status, ':id' => $dbId]);
            $this->log("Updated order {$dbId} status to: {$status}");
        } catch (\PDOException $e) {
            $this->log("Error updating order status: " . $e->getMessage());
        }
    }

    // ---------------------- State Persistence ----------------------
    private function loadState()
    {
        $path = sys_get_temp_dir() . '/qbwc_app_state.json';
        if (file_exists($path)) {
            $state = json_decode(file_get_contents($path), true);
            if (is_array($state)) {
                $this->stage = $state['stage'] ?? $this->stage;
                $this->currentItemIndex = $state['itemIndex'] ?? 0;
                $this->currentOrderItems = $state['orderItems'] ?? [];
                $this->currentDbOrderId = $state['dbOrderId'] ?? null;
                $this->requestCounter = $state['requestCounter'] ?? 0;
                $this->orders = $state['orders'] ?? [];
                $this->customerName = $state['customerName'] ?? null;
            }
        }

        if (empty($this->orders)) {
            $this->fetchPendingOrders();
        }
    }

    private function saveState()
    {
        $state = [
            'stage' => $this->stage,
            'itemIndex' => $this->currentItemIndex,
            'orderItems' => $this->currentOrderItems,
            'dbOrderId' => $this->currentDbOrderId,
            'requestCounter' => $this->requestCounter,
            'orders' => $this->orders,
            'customerName' => $this->customerName
        ];
        file_put_contents(sys_get_temp_dir() . '/qbwc_app_state.json', json_encode($state));
    }

    private function resetState()
    {
        $this->orders = [];
        $this->stage = 'query_customer';
        $this->currentItemIndex = 0;
        $this->currentOrderItems = [];
        $this->currentDbOrderId = null;
        $this->customerName = null;
        @unlink(sys_get_temp_dir() . '/qbwc_app_state.json');
    }

    // ---------------------- Logging ----------------------
    private function log($msg)
    {
        $logFile = __DIR__ . '/qbwc_add_customer_invoice_app.log';
        if (file_put_contents($logFile, $msg . ' at ' . date('Y-m-d H:i:s') . "\n", FILE_APPEND) === false) {
            error_log("Failed to write to log: $logFile");
        }
    }

    // ---------------------- QBWC Methods ----------------------
    public function sendRequestXML($object)
    {
        $this->loadState();
        $id = ++$this->requestCounter;
        $this->log("[$id] Sent XML request");
        $this->saveState();

        if (empty($this->orders)) {
            if (!$this->fetchPendingOrders()) {
                $this->log("No pending orders to process.");
                $this->resetState();
                return new SendRequestXML('');
            }
        }

        $order = $this->orders[0];
        $this->currentDbOrderId = $order['db_id'];
        $this->customerName = trim(($order['customer']['first_name'] ?? '') . ' ' . ($order['customer']['last_name'] ?? ''));

        $qbxmlVersion = ($object->qbXMLMajorVers ?? '2') . "." . ($object->qbXMLMinorVers ?? '0');

        $this->log("Stage: {$this->stage} -- Order: {$order['order_number']} (Customer: {$this->customerName})");

        if ($this->stage === 'query_customer') {
            $xml = '<?xml version="1.0" encoding="utf-8"?>' .
                "\n<?qbxml version=\"{$qbxmlVersion}\"?>\n" .
                "<QBXML>\n  <QBXMLMsgsRq onError=\"stopOnError\">\n    <CustomerQueryRq requestID=\"" . $this->generateGUID() . "\">\n      <FullName>" . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . "</FullName>\n    </CustomerQueryRq>\n  </QBXMLMsgsRq>\n</QBXML>";

            $this->log("Sending CustomerQueryRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_customer') {
            $cust = $order['customer'];
            $addr = $cust['default_address'] ?? [];
            $xml = '<?xml version="1.0" encoding="utf-8"?>' .
                "\n<?qbxml version=\"{$qbxmlVersion}\"?>\n" .
                "<QBXML>\n  <QBXMLMsgsRq onError=\"stopOnError\">\n    <CustomerAddRq requestID=\"" . $this->generateGUID() . "\">\n      <CustomerAdd>\n        <Name>" . htmlspecialchars($this->customerName, ENT_XML1, 'UTF-8') . "</Name>\n        <CompanyName>" . htmlspecialchars($addr['company'] ?? '', ENT_XML1, 'UTF-8') . "</CompanyName>\n        <FirstName>" . htmlspecialchars($cust['first_name'] ?? '', ENT_XML1, 'UTF-8') . "</FirstName>\n        <LastName>" . htmlspecialchars($cust['last_name'] ?? '', ENT_XML1, 'UTF-8') . "</LastName>\n        <BillAddress>\n          <Addr1>" . htmlspecialchars($addr['address1'] ?? '', ENT_XML1, 'UTF-8') . "</Addr1>\n          <City>" . htmlspecialchars($addr['city'] ?? '', ENT_XML1, 'UTF-8') . "</City>\n          <State>" . htmlspecialchars($addr['province'] ?? '', ENT_XML1, 'UTF-8') . "</State>\n          <PostalCode>" . htmlspecialchars($addr['zip'] ?? '', ENT_XML1, 'UTF-8') . "</PostalCode>\n          <Country>" . htmlspecialchars($addr['country'] ?? '', ENT_XML1, 'UTF-8') . "</Country>\n        </BillAddress>\n        <Phone>" . htmlspecialchars($cust['phone'] ?? '', ENT_XML1, 'UTF-8') . "</Phone>\n        <Email>" . htmlspecialchars($cust['email'] ?? '', ENT_XML1, 'UTF-8') . "</Email>\n      </CustomerAdd>\n    </CustomerAddRq>\n  </QBXMLMsgsRq>\n</QBXML>";

            $this->log("Sending CustomerAddRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'check_item') {
            $currentItem = $this->currentOrderItems[$this->currentItemIndex] ?? null;
            if (!$currentItem) {
                // Nothing to check -> move to invoice
                $this->stage = 'add_invoice';
                $this->saveState();
                return new SendRequestXML('');
            }

            $this->log("Checking if item exists: {$currentItem}");
            $xml = '<?xml version="1.0" encoding="utf-8"?>' .
                "\n<?qbxml version=\"{$qbxmlVersion}\"?>\n" .
                "<QBXML>\n  <QBXMLMsgsRq onError=\"stopOnError\">\n    <ItemQueryRq requestID=\"" . $this->generateGUID() . "\">\n      <FullName>" . htmlspecialchars($currentItem, ENT_XML1, 'UTF-8') . "</FullName>\n    </ItemQueryRq>\n  </QBXMLMsgsRq>\n</QBXML>";

            $this->log("Sending ItemQueryRq XML:\n$xml");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_item') {
            $currentItem = $this->currentOrderItems[$this->currentItemIndex] ?? null;
            $order = $this->orders[0] ?? null;
            $line = null;
            if ($order && isset($order['line_items'])) {
                foreach ($order['line_items'] as $li) {
                    if ((string) $li['title'] === (string) $currentItem) {
                        $line = $li;
                        break;
                    }
                }
            }
            $itemTitle = $line['name'] ?? $line['title'] ?? $currentItem;
            $itemPrice = isset($line['price']) ? (float) $line['price'] : 0.0;
            $itemPrice = number_format($itemPrice, 2, '.', '');

            $xml = '<?xml version="1.0" encoding="utf-8"?>' .
                "\n<?qbxml version=\"{$qbxmlVersion}\"?>\n" .
                "<QBXML>\n  <QBXMLMsgsRq onError=\"stopOnError\">\n    <ItemNonInventoryAddRq requestID=\"" . $this->generateGUID() . "\">\n      <ItemNonInventoryAdd>\n        <Name>" . htmlspecialchars($currentItem, ENT_XML1, 'UTF-8') . "</Name>\n        <SalesOrPurchase>\n          <Desc>" . htmlspecialchars($itemTitle, ENT_XML1, 'UTF-8') . "</Desc>\n          <Price>" . $itemPrice . "</Price>\n          <AccountRef>\n            <FullName>Sales</FullName>\n          </AccountRef>\n        </SalesOrPurchase>\n      </ItemNonInventoryAdd>\n    </ItemNonInventoryAddRq>\n  </QBXMLMsgsRq>\n</QBXML>";

            $this->log("Sending ItemNonInventoryAddRq XML for {$currentItem}");
            $this->saveState();
            return new SendRequestXML($xml);
        }

        if ($this->stage === 'add_invoice') {
            $this->log("Preparing InvoiceAdd for order {$order['order_number']}");
            $xml = '<?xml version="1.0" encoding="utf-8"?>' .
                "\n<?qbxml version=\"{$qbxmlVersion}\"?>\n" .
                "<QBXML>\n  <QBXMLMsgsRq onError=\"stopOnError\">\n    <InvoiceAddRq requestID=\"" . $this->generateGUID() . "\">\n      <InvoiceAdd>\n        <CustomerRef><FullName>" . htmlentities($this->customerName, ENT_XML1, 'UTF-8') . "</FullName></CustomerRef>\n        <RefNumber>" . htmlentities($order['order_number'], ENT_XML1, 'UTF-8') . "</RefNumber>\n        <Memo>Order " . htmlentities($order['order_number'], ENT_XML1, 'UTF-8') . "</Memo>\n";

            foreach ($order['line_items'] as $item) {
                $lineQty = isset($item['quantity']) ? (int) $item['quantity'] : 1;
                $lineRateFloat = (float) ($item['price'] ?? 0);
                $lineRate = number_format($lineRateFloat, 2, '.', '');
                $lineAmount = number_format($lineQty * $lineRateFloat, 2, '.', '');
                $lineDesc = htmlentities($item['description'] ?? $item['name'] ?? $item['title'] ?? '', ENT_XML1, 'UTF-8');
                $itemFullName = htmlentities($item['title'] ?? $item['name'] ?? '', ENT_XML1, 'UTF-8');

                $xml .= "        <InvoiceLineAdd>\n" .
                    "          <ItemRef><FullName>{$itemFullName}</FullName></ItemRef>\n" .
                    "          <Desc>{$lineDesc}</Desc>\n" .
                    "          <Quantity>{$lineQty}</Quantity>\n" .
                    "          <Rate>{$lineRate}</Rate>\n" .
                    "          <Amount>{$lineAmount}</Amount>\n" .
                    "        </InvoiceLineAdd>\n";
            }

            $xml .= "      </InvoiceAdd>\n    </InvoiceAddRq>\n  </QBXMLMsgsRq>\n</QBXML>";

            $this->log("Sending InvoiceAddRq XML:\n" . $xml);
            $this->saveState();
            return new SendRequestXML($xml);
        }

        $this->log("Unexpected stage in sendRequestXML: {$this->stage}");
        $this->saveState();
        return new SendRequestXML('');
    }

    public function receiveResponseXML($object)
    {
        $this->loadState();
        $id = $this->requestCounter;
        $this->log("[$id] Received XML response");

        if (empty($this->orders)) {
            $this->fetchPendingOrders();
        }

        $response = @simplexml_load_string($object->response);
        if ($response === false) {
            // Log the full object to see if there's an hresult or message
            $debugInfo = var_export($object, true);
            $this->log("Failed to parse response XML. Full Object: " . $debugInfo);
            return new ReceiveResponseXML(100);
        }

        $this->log("Current stage in receiveResponseXML: {$this->stage}");

        if ($this->stage === 'query_customer') {
            if (isset($response->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                $this->log("Customer EXISTS in QuickBooks --> Skipping add, moving to check items.");
                $order = $this->orders[0];
                $this->currentOrderItems = array_column($order['line_items'], 'title');
                $this->currentItemIndex = 0;
                $this->stage = 'check_item';
            } else {
                $this->log("Customer NOT FOUND in QuickBooks --> Will add customer.");
                $this->stage = 'add_customer';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_customer') {
            $this->log("CustomerAdd completed. Moving to check items.");
            $order = $this->orders[0];
            $this->currentOrderItems = array_column($order['line_items'], 'title');
            $this->currentItemIndex = 0;
            $this->stage = 'check_item';
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'check_item') {
            $itemFound = false;
            if (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemNonInventoryRet)) {
                $itemFound = true;
            } elseif (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemInventoryRet)) {
                $itemFound = true;
            } elseif (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemServiceRet)) {
                $itemFound = true;
            }

            if (!$itemFound) {
                $this->log("Item missing: " . ($this->currentOrderItems[$this->currentItemIndex] ?? 'unknown') . " — will add it.");
                $this->stage = 'add_item';
                $this->saveState();
                return new ReceiveResponseXML(50);
            } else {
                $this->log("Item exists: " . ($this->currentOrderItems[$this->currentItemIndex] ?? 'unknown'));
                $this->currentItemIndex++;
                if ($this->currentItemIndex < count($this->currentOrderItems)) {
                    $this->stage = 'check_item';
                } else {
                    $this->stage = 'add_invoice';
                }
                $this->saveState();
                return new ReceiveResponseXML(50);
            }
        }

        if ($this->stage === 'add_item') {
            $this->log("Item added: " . ($this->currentOrderItems[$this->currentItemIndex] ?? 'unknown'));
            $this->currentItemIndex++;
            if ($this->currentItemIndex < count($this->currentOrderItems)) {
                $this->stage = 'check_item';
            } else {
                $this->stage = 'add_invoice';
            }
            $this->saveState();
            return new ReceiveResponseXML(50);
        }

        if ($this->stage === 'add_invoice') {
            $this->log("InvoiceAdd completed for Order #" . ($this->orders[0]['order_number'] ?? 'unknown'));

            if ($this->currentDbOrderId) {
                $this->updateOrderStatus($this->currentDbOrderId, 'invoice_done');
            }

            // Reset state for next order
            $this->orders = [];
            $this->stage = 'query_customer';
            $this->currentItemIndex = 0;
            $this->currentOrderItems = [];
            $this->currentDbOrderId = null;

            if ($this->fetchPendingOrders()) {
                $this->log("Moving to next pending order.");
                $this->saveState();
                return new ReceiveResponseXML(50);
            }

            $this->log("No more pending orders. Done!");
            $this->resetState();
            return new ReceiveResponseXML(100);
        }

        $this->log("Unexpected stage in receiveResponseXML: {$this->stage}");
        $this->saveState();
        return new ReceiveResponseXML(100);
    }
}