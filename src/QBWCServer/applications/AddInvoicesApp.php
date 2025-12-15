<?php
namespace QBWCServer\applications;

use QBWCServer\base\AbstractQBWCApplication;
use QBWCServer\response\ReceiveResponseXML;
use QBWCServer\response\SendRequestXML;

class AddInvoicesApp extends AbstractQBWCApplication
{
    // Step tracking - use session for persistence
    private $itemsToAdd = ['Consulting Services', 'Hosting'];

    public function __construct($config = [])
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        parent::__construct($config);
    }

    public function getStep()
    {
        return $_SESSION['addInvoice_step'] ?? 'check_items';
    }

    public function setStep($step)
    {
        $_SESSION['addInvoice_step'] = $step;
    }

    public function getCurrentItemIndex()
    {
        return $_SESSION['addInvoice_currentItemIndex'] ?? 0;
    }

    public function setCurrentItemIndex($index)
    {
        $_SESSION['addInvoice_currentItemIndex'] = $index;
    }

    public function resetState()
    {
        unset($_SESSION['addInvoice_step']);
        unset($_SESSION['addInvoice_currentItemIndex']);
    }

    /**
     * Build and return QBXML to send to QuickBooks
     */
    public function sendRequestXML($object)
    {
        $qbxmlVersion = $this->_config['qbxmlVersion'] ?? '16.0';
        $step = $this->getStep();
        $this->log_this("Current Step: {$step}");

        switch ($step) {
            case 'check_items':
                $request = $this->buildItemQueryXML($qbxmlVersion);
                break;
            case 'add_item':
                $request = $this->buildItemAddXML($qbxmlVersion);
                break;
            case 'add_invoice':
                $request = $this->buildInvoiceAddXML($qbxmlVersion);
                break;
            default:
                $this->log_this("Unknown step reached.");
                $request = new SendRequestXML('');
                break;
        }

        // Log XML being sent for debugging
        $this->log_this("XML being sent to QuickBooks:\n" . $request->sendRequestXMLResult);

        return $request;
    }

    /**
     * Handle response from QuickBooks
     */
    public function receiveResponseXML($object)
    {
        $responseXML = $object->response;
        $step = $this->getStep();
        $currentIndex = $this->getCurrentItemIndex();

        $this->log_this("Response received for step: " . $step);
        $this->log_this("Raw response XML:\n" . $responseXML);

        $response = simplexml_load_string($responseXML);
        if (!$response) {
            $this->log_this("ERROR: Failed to parse XML response.");
            $this->resetState();
            return new ReceiveResponseXML(100); // Stop to avoid infinite loop
        }

        switch ($step) {
            case 'check_items':
                $itemFound = false;

                // Check for multiple item types
                if (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemNonInventoryRet)) {
                    $itemFound = true;
                } elseif (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemInventoryRet)) {
                    $itemFound = true;
                } elseif (isset($response->QBXMLMsgsRs->ItemQueryRs->ItemServiceRet)) {
                    $itemFound = true;
                }

                if (!$itemFound) {
                    $this->log_this("Item missing: " . $this->itemsToAdd[$currentIndex] . " — will add it.");
                    $this->setStep('add_item');
                    return new ReceiveResponseXML(0); // ask QBWC to call sendRequestXML again
                } else {
                    $this->log_this("Item exists: " . $this->itemsToAdd[$currentIndex]);
                    $currentIndex++;
                    $this->setCurrentItemIndex($currentIndex);
                    if ($currentIndex < count($this->itemsToAdd)) {
                        $this->setStep('check_items');
                    } else {
                        $this->setStep('add_invoice');
                    }
                    return new ReceiveResponseXML(0);
                }

            case 'add_item':
                $this->log_this("Item added: " . $this->itemsToAdd[$currentIndex]);
                $currentIndex++;
                $this->setCurrentItemIndex($currentIndex);
                if ($currentIndex < count($this->itemsToAdd)) {
                    $this->setStep('check_items');
                } else {
                    $this->setStep('add_invoice');
                }
                return new ReceiveResponseXML(0);

            case 'add_invoice':
                $this->log_this("Invoice add response received.");

                // Check if QuickBooks returned an error
                $status = (string)($response->QBXMLMsgsRs->InvoiceAddRs->attributes()['statusCode'] ?? '');
                $message = (string)($response->QBXMLMsgsRs->InvoiceAddRs->attributes()['statusMessage'] ?? '');

                if ($status == '0') {
                    $this->log_this("Invoice successfully created in QuickBooks.");
                    $this->log_this("Status: {$status}, Message: {$message}");
                    $this->resetState();
                    return new ReceiveResponseXML(100); // 100 = done
                } else {
                    $this->log_this("ERROR: QuickBooks returned error creating invoice.");
                    $this->log_this("Status: {$status}, Message: {$message}");
                    $this->log_this("Raw response: " . $responseXML);
                    $this->resetState();
                    return new ReceiveResponseXML(100); // 100 = done
                }

            default:
                $this->log_this("Unknown step reached: " . $step);
                $this->resetState();
                return new ReceiveResponseXML(100);
        }
    }

    /**
     * Helper: Build ItemQueryRq XML
     */
    private function buildItemQueryXML($qbxmlVersion)
    {
        $currentIndex = $this->getCurrentItemIndex();
        $currentItem = $this->itemsToAdd[$currentIndex];
        $this->log_this("Checking if item exists: {$currentItem}");

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="{$qbxmlVersion}"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemQueryRq requestID="{$currentIndex}">
      <FullName>{$currentItem}</FullName>
    </ItemQueryRq>
  </QBXMLMsgsRq>
</QBXML>
XML;
        return new SendRequestXML($xml);
    }

    /**
     * Helper: Build ItemNonInventoryAddRq XML
     */
    private function buildItemAddXML($qbxmlVersion)
    {
        $currentIndex = $this->getCurrentItemIndex();
        $currentItem = $this->itemsToAdd[$currentIndex];
        $this->log_this("Adding NonInventory item: {$currentItem}");

        $xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<?qbxml version="{$qbxmlVersion}"?>
<QBXML>
  <QBXMLMsgsRq onError="stopOnError">
    <ItemNonInventoryAddRq requestID="{$currentIndex}">
      <ItemNonInventoryAdd>
        <Name>{$currentItem}</Name>
        <SalesOrPurchase>
          <Desc>{$currentItem}</Desc>
          <Price>0.00</Price>
          <AccountRef>
            <FullName>Sales</FullName>
          </AccountRef>
        </SalesOrPurchase>
      </ItemNonInventoryAdd>
    </ItemNonInventoryAddRq>
  </QBXMLMsgsRq>
</QBXML>
XML;
        return new SendRequestXML($xml);
    }

    /**
     * Helper: Build InvoiceAddRq XML
     */
    private function buildInvoiceAddXML($qbxmlVersion)
    {
        $this->log_this("Building invoice add request for customer: John Doe");

        $xml = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
        $xml .= "<?qbxml version=\"{$qbxmlVersion}\"?>\n";
        $xml .= "<QBXML>\n";
        $xml .= "  <QBXMLMsgsRq onError=\"stopOnError\">\n";
        $xml .= "    <InvoiceAddRq requestID=\"1\">\n";
        $xml .= "      <InvoiceAdd>\n";
        $xml .= "        <CustomerRef>\n";
        $xml .= "          <FullName>John Doe</FullName>\n";
        $xml .= "        </CustomerRef>\n";
        $xml .= "        <TxnDate>2025-10-27</TxnDate>\n";
        $xml .= "        <RefNumber>INV-1001</RefNumber>\n";
        $xml .= "        <BillAddress>\n";
        $xml .= "          <Addr1>John Doe</Addr1>\n";
        $xml .= "          <Addr2>123 Main Street</Addr2>\n";
        $xml .= "          <City>New York</City>\n";
        $xml .= "          <State>NY</State>\n";
        $xml .= "          <PostalCode>10001</PostalCode>\n";
        $xml .= "        </BillAddress>\n";
        $xml .= "        <InvoiceLineAdd>\n";
        $xml .= "          <ItemRef>\n";
        $xml .= "            <FullName>Consulting Services</FullName>\n";
        $xml .= "          </ItemRef>\n";
        $xml .= "          <Desc>Consulting Fee for October</Desc>\n";
        $xml .= "          <Quantity>1</Quantity>\n";
        $xml .= "          <Rate>500.00</Rate>\n";
        $xml .= "        </InvoiceLineAdd>\n";
        $xml .= "        <InvoiceLineAdd>\n";
        $xml .= "          <ItemRef>\n";
        $xml .= "            <FullName>Hosting</FullName>\n";
        $xml .= "          </ItemRef>\n";
        $xml .= "          <Desc>Website Hosting (1 Month)</Desc>\n";
        $xml .= "          <Quantity>1</Quantity>\n";
        $xml .= "          <Rate>100.00</Rate>\n";
        $xml .= "        </InvoiceLineAdd>\n";
        $xml .= "      </InvoiceAdd>\n";
        $xml .= "    </InvoiceAddRq>\n";
        $xml .= "  </QBXMLMsgsRq>\n";
        $xml .= "</QBXML>";

        return new SendRequestXML($xml);
    }
}