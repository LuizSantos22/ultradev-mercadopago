<?php
class UltraDev_MercadoPago_Helper_Data extends Mage_Payment_Helper_Data
{
    const XML_PATH_ACCESS_TOKEN    = 'payment/ultradev_mercadopago/access_token';
    const XML_PATH_PUBLIC_KEY      = 'payment/ultradev_mercadopago/public_key';
    const XML_PATH_LOGS            = 'payment/ultradev_mercadopago/logs';
    const XML_PATH_STATEMENT       = 'payment/ultradev_mercadopago/statement_descriptor';
    const XML_PATH_BINARY_MODE     = 'payment/ultradev_mercadopago/binary_mode';
    const XML_PATH_BOLETO_DUE_DAYS = 'payment/ultradev_mercadopago/boleto_due_days';

    const LOG_FILE = 'ultradev-mercadopago.log';

    public function log($message, $file = '', $data = null)
    {
        if (!Mage::getStoreConfigFlag(self::XML_PATH_LOGS)) {
            return;
        }
        if ($data !== null) {
            $message .= ' — ' . json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        Mage::log($message, null, $file ?: self::LOG_FILE, true);
    }

    public function getAccessToken()
    {
        return (string) Mage::getStoreConfig(self::XML_PATH_ACCESS_TOKEN);
    }

    public function getPublicKey()
    {
        return (string) Mage::getStoreConfig(self::XML_PATH_PUBLIC_KEY);
    }

    public function isSandbox()
    {
        return strpos($this->getAccessToken(), 'TEST-') !== false;
    }

    public function isValidAccessToken($accessToken)
    {
        try {
            $response = $this->mpGet('/v1/payment_methods', $accessToken);
            return !in_array((int)($response['status'] ?? 0), [400, 401], true);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * GET genérico na API do MercadoPago via Varien_Http_Client
     */
    public function mpGet($path, $accessToken = '')
    {
        $token = $accessToken ?: $this->getAccessToken();
        $url   = 'https://api.mercadopago.com' . $path;
        $sep   = (strpos($path, '?') !== false) ? '&' : '?';
        $url  .= $sep . 'access_token=' . urlencode($token);

        $client = new Varien_Http_Client($url);
        $client->setMethod(Varien_Http_Client::GET);
        $client->setHeaders(['Content-Type' => 'application/json']);

        $response = $client->request();
        $body     = json_decode($response->getBody(), true);
        $result   = ['status' => $response->getStatus(), 'response' => $body ?: []];

        $this->log("GET $path", self::LOG_FILE, ['http_status' => $result['status']]);

        return $result;
    }

    /**
     * POST genérico na API do MercadoPago via Varien_Http_Client
     */
    public function mpPost($path, array $payload)
    {
        $token = $this->getAccessToken();
        $url   = 'https://api.mercadopago.com' . $path;
        $sep   = (strpos($path, '?') !== false) ? '&' : '?';
        $url  .= $sep . 'access_token=' . urlencode($token);

        $client = new Varien_Http_Client($url);
        $client->setMethod(Varien_Http_Client::POST);
        $client->setHeaders(['Content-Type' => 'application/json']);
        $client->setRawData(json_encode($payload), 'application/json');

        $response = $client->request();
        $body     = json_decode($response->getBody(), true);
        $result   = ['status' => $response->getStatus(), 'response' => $body ?: []];

        $this->log("POST $path", self::LOG_FILE, [
            'http_status'  => $result['status'],
            'payload_keys' => array_keys($payload),
        ]);

        return $result;
    }

    public function formatAmount($amount)
    {
        return round((float) $amount, 2);
    }

    public function getOrderAmount(Mage_Sales_Model_Quote $quote)
    {
        $total = (float) $quote->getBaseSubtotalWithDiscount()
               + (float) $quote->getShippingAddress()->getShippingAmount()
               + (float) $quote->getShippingAddress()->getBaseTaxAmount();

        return $this->formatAmount($total);
    }
}
