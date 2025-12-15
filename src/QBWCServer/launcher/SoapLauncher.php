<?php
namespace QBWCServer\launcher;

class SoapLauncher
{
    public static function start($object)
    {
        error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
        ini_set('display_errors', 0);

        try {
            if (!file_exists($object->_config['wsdlPath'])) {
                throw new \Exception('WSDL file not found: ' . $object->_config['wsdlPath']);
            }

            $server = new \SoapServer($object->_config['wsdlPath'], $object->_config['soapOptions']);
            $server->setObject($object);
            $server->handle();
        } catch (\Throwable $e) {
            error_log('SoapLauncher Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            header('Content-Type: text/xml; charset=utf-8');
            echo '<?xml version="1.0" encoding="utf-8"?>';
            echo '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/">';
            echo '<SOAP-ENV:Body>';
            echo '<SOAP-ENV:Fault>';
            echo '<faultcode>Server</faultcode>';
            echo '<faultstring>' . htmlspecialchars($e->getMessage()) . '</faultstring>';
            echo '</SOAP-ENV:Fault>';
            echo '</SOAP-ENV:Body>';
            echo '</SOAP-ENV:Envelope>';
        }
    }
}