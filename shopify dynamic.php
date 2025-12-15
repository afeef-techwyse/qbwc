<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;

/**
 * AddCustomerInvoiceApp
 * - Implements correct method signatures expected by QBWC
 * - Persists stage in orders_queue.status
 *
 * Table assumed:
 * CREATE TABLE orders_queue (
 *   id INT AUTO_INCREMENT PRIMARY KEY,
 *   payload TEXT NOT NULL, -- json order payload from shopify
 *   status VARCHAR(50) NOT NULL DEFAULT 'pending',
 *   updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * );
 */
class AddCustomerInvoiceApp extends AbstractQBWCApplication
{
    private $dsn = "mysql:host=shortline.proxy.rlwy.net;port=53111;dbname=railway";
    private $user = "root";
    private $pass = "wTVIYIVbAlJdCqIwbHigEVotdGKGdHNA";


    // runtime
    private $pdo;
    private $currentOrder = null;
    private $stage = 'query_customer';
    private $customerName = '';

    public function __construct()
    {
        parent::__construct();
        $this->pdo = $this->getPDO();
    }

    private function getPDO()
    {
        static $p = null;
        if ($p) return $p;
        $p = new \PDO($this->dsn, $this->dbUser, $this->dbPass, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        return $p;
    }

    /**************************************************************************
     * authenticate($object)
     * QBWC calls this. Must accept $object (per base class signature).
     * Return array: [ticket, company_file_or_empty] OR ['none','nvu'] etc.
     **************************************************************************/
    public function authenticate($object)
    {
        $UserName = $object->UserName ?? $object->userName ?? '';
        $Password = $object->Password ?? $object->password ?? '';

        // Simple example auth; replace with secure check
        $validUser = 'Admin';
        $validPass = '123';

        if ($UserName === $validUser && $Password === $validPass) {
            $ticket = uniqid('qbwc_', true);
            // second array item is company file path (optional) — return empty
            return [$ticket, ''];
        }

        // Not valid — return 'nvu' according to QBWC spec
        return ['none', 'nvu'];
    }

    /**************************************************************************
     * sendRequestXML($object)
     * MUST use $object signature to match abstract.
     * Return: XML string (QBXML) to be processed by QuickBooks, or empty '' when no work.
     **************************************************************************/
    public function sendRequestXML($object)
    {
        // Optional: read qbxmlVersion from config or passed object
        $qbxmlVersion = $this->_config['qbxmlVersion'] ?? '13.0';

        // If we don't have a current order loaded, fetch next one:
        if (!$this->currentOrder) {
            $this->currentOrder = $this->fetchNextOrder();
            if (!$this->currentOrder) {
                // Nothing to do
                return '';
            }
            $order = json_decode($this->currentOrder['payload'], true);
            $this->customerName = trim(($order['customer']['first_name'] ?? '') . ' ' . ($order['customer']['last_name'] ?? ''));
            $this->stage = $this->currentOrder['status'] ?? 'pending';
            // normalize pending -> query_customer
            if ($this->stage === 'pending') $this->stage = 'query_customer';
        } else {
            $order = json_decode($this->currentOrder['payload'], true);
        }

        // Build XML according to stage
        if ($this->stage === 'query_customer') {
            // Mark status in DB so next call resumes correctly if needed
            $this->updateOrderStatus($this->currentOrder['id'], 'query_customer');

            $xml = $this->wrapQBXML(
                $qbxmlVersion,
                '<CustomerQueryRq requestID="' . $this->generateGUID() . '">
                    <FullName>' . $this->xmlEscape($this->customerName) . '</FullName>
                </CustomerQueryRq>'
            );
            return $xml;
        }

        if ($this->stage === 'add_customer') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_customer');

            $cust = $order['customer'] ?? [];
            $addr = $cust['default_address'] ?? [];

            $inner = '<CustomerAddRq requestID="' . $this->generateGUID() . '">
                <CustomerAdd>
                  <Name>' . $this->xmlEscape($this->customerName) . '</Name>
                  <CompanyName>' . $this->xmlEscape($addr['company'] ?? '') . '</CompanyName>
                  <FirstName>' . $this->xmlEscape($cust['first_name'] ?? '') . '</FirstName>
                  <LastName>' . $this->xmlEscape($cust['last_name'] ?? '') . '</LastName>
                  <BillAddress>
                    <Addr1>' . $this->xmlEscape($addr['address1'] ?? '') . '</Addr1>
                    <City>' . $this->xmlEscape($addr['city'] ?? '') . '</City>
                    <State>' . $this->xmlEscape($addr['province'] ?? '') . '</State>
                    <PostalCode>' . $this->xmlEscape($addr['zip'] ?? '') . '</PostalCode>
                    <Country>' . $this->xmlEscape($addr['country'] ?? '') . '</Country>
                  </BillAddress>
                  <Email>' . $this->xmlEscape($cust['email'] ?? '') . '</Email>
                  <Phone>' . $this->xmlEscape($cust['phone'] ?? '') . '</Phone>
                </CustomerAdd>
              </CustomerAddRq>';

            $xml = $this->wrapQBXML($qbxmlVersion, $inner);
            return $xml;
        }

        if ($this->stage === 'add_invoice') {
            $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');

            $itemsXml = '';
            foreach ($order['line_items'] ?? [] as $item) {
                // Use item title/name as ItemRef.FullName — ensure those items exist in QB or replace with a default service item
                $itemsXml .= '
                  <InvoiceLineAdd>
                    <ItemRef><FullName>' . $this->xmlEscape($item['title'] ?? $item['name'] ?? 'Item') . '</FullName></ItemRef>
                    <Desc>' . $this->xmlEscape($item['name'] ?? '') . '</Desc>
                    <Quantity>' . (int)($item['quantity'] ?? 1) . '</Quantity>
                    <Rate>' . $this->xmlEscape($item['price'] ?? '0.00') . '</Rate>
                  </InvoiceLineAdd>';
            }

            $inner = '<InvoiceAddRq requestID="' . $this->generateGUID() . '">
                <InvoiceAdd>
                  <CustomerRef><FullName>' . $this->xmlEscape($this->customerName) . '</FullName></CustomerRef>
                  <RefNumber>' . $this->xmlEscape($order['id'] ?? '') . '</RefNumber>
                  <Memo>Shopify Order #' . $this->xmlEscape($order['order_number'] ?? $order['name'] ?? '') . '</Memo>
                  ' . $itemsXml . '
                </InvoiceAdd>
              </InvoiceAddRq>';

            $xml = $this->wrapQBXML($qbxmlVersion, $inner);
            return $xml;
        }

        // default nothing
        return '';
    }

    /**************************************************************************
     * receiveResponseXML($object)
     * - QBWC will call this with the response from QuickBooks.
     * - Must accept $object signature.
     * - Return integer percent (0-100). 100 means done for this order.
     **************************************************************************/
    public function receiveResponseXML($object)
    {
        $responseXmlStr = $object->response ?? '';
        if (empty(trim($responseXmlStr))) {
            // nothing returned — mark done to avoid endless loop
            return 100;
        }

        // Parse XML safely
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($responseXmlStr);
        if ($xml === false) {
            // malformed XML — mark done to avoid loop and log
            error_log("QBWC: malformed XML in receiveResponseXML: " . implode("\n", array_map(fn($e) => $e->message, libxml_get_errors())));
            libxml_clear_errors();
            return 100;
        }

        // Determine which response we got
        $msg = $xml->QBXMLMsgsRs->children();
        $childName = $msg->getName();

        // If it's CustomerQueryRs
        if ($childName === 'CustomerQueryRs') {
            // If CustomerRet exists => customer exists
            if (isset($xml->QBXMLMsgsRs->CustomerQueryRs->CustomerRet)) {
                // move to add_invoice
                $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
                $this->stage = 'add_invoice';
            } else {
                // move to add_customer
                $this->updateOrderStatus($this->currentOrder['id'], 'add_customer');
                $this->stage = 'add_customer';
            }
            return 50; // more to do for this order
        }

        // If it's CustomerAddRs
        if ($childName === 'CustomerAddRs') {
            // after adding customer go to invoice
            $this->updateOrderStatus($this->currentOrder['id'], 'add_invoice');
            $this->stage = 'add_invoice';
            return 50;
        }

        // If it's InvoiceAddRs
        if ($childName === 'InvoiceAddRs') {
            // final step — mark done
            $this->updateOrderStatus($this->currentOrder['id'], 'invoice_done');
            $this->reset();
            return 100;
        }

        // Unknown response: be safe and finish the cycle
        return 100;
    }

    /**************************************************************************
     * Helper methods
     **************************************************************************/
    private function fetchNextOrder()
    {
        // Try to resume partially processed orders first (query_customer, add_customer, add_invoice)
        $stmt = $this->getPDO()->prepare(
            "SELECT * FROM orders_queue 
             WHERE status IN ('query_customer','add_customer','add_invoice') 
             ORDER BY id ASC LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row) return $row;

        // otherwise pick first pending
        $stmt = $this->getPDO()->prepare("SELECT * FROM orders_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 1");
        $stmt->execute();
        return $stmt->fetch() ?: null;
    }

    private function updateOrderStatus($id, $status)
    {
        $stmt = $this->getPDO()->prepare("UPDATE orders_queue SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $id]);
        // update in-memory copy if matches
        if ($this->currentOrder && (int)$this->currentOrder['id'] === (int)$id) {
            $this->currentOrder['status'] = $status;
        }
    }

    private function reset()
    {
        $this->currentOrder = null;
        $this->stage = 'query_customer';
        $this->customerName = '';
    }

    private function generateGUID()
    {
        if (function_exists('com_create_guid')) {
            return trim(com_create_guid(), '{}');
        }
        // fallback:
        return strtoupper(md5(uniqid((string)rand(), true)));
    }

    private function wrapQBXML($qbxmlVersion, $innerXml)
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n" .
               '<?qbxml version="' . $this->xmlEscape($qbxmlVersion) . '"?>' . "\n" .
               '<QBXML>' . "\n" .
               '  <QBXMLMsgsRq onError="stopOnError">' . "\n" .
               $innerXml . "\n" .
               '  </QBXMLMsgsRq>' . "\n" .
               '</QBXML>';
    }

    private function xmlEscape($s)
    {
        return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}