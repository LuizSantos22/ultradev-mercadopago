<?php
class UltraDev_MercadoPago_Model_Core
{
    const LOG_FILE = 'ultradev-mercadopago.log';

    /**
     * @return UltraDev_MercadoPago_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('ultradev_mercadopago');
    }

    protected function _getQuote()
    {
        if (Mage::app()->getStore()->isAdmin()) {
            return Mage::getSingleton('adminhtml/session_quote')->getQuote();
        }
        return Mage::getSingleton('checkout/session')->getQuote();
    }

    protected function _getOrder($incrementId)
    {
        return Mage::getModel('sales/order')->loadByIncrementId($incrementId);
    }

    /**
     * Monta o payload base para POST /v1/payments
     */
    public function makeBasePreference(array $paymentInfo = array())
    {
        $helper  = $this->_helper();
        $quote   = $this->_getQuote();
        $orderId = $quote->getReservedOrderId();
        $order   = $this->_getOrder($orderId);

        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $billing  = $quote->getBillingAddress()->getData();

        $email     = $customer->getEmail()     ?: ($billing['email']     ?? '');
        $firstName = $customer->getFirstname() ?: ($billing['firstname'] ?? '');
        $lastName  = $customer->getLastname()  ?: ($billing['lastname']  ?? '');

        // Itens do pedido
        $items = array();
        foreach ($order->getAllVisibleItems() as $item) {
            $product  = $item->getProduct();
            $items[]  = array(
                'id'          => $item->getSku(),
                'title'       => $product->getName(),
                'description' => $product->getName(),
                'quantity'    => (int) $item->getQtyOrdered(),
                'unit_price'  => $helper->formatAmount((float) $product->getPrice()),
            );
        }

        $preference = array(
            'external_reference'   => $orderId,
            'notification_url'     => Mage::getUrl('ultradevmp/notifications/custom', array('_secure' => true)),
            'description'          => sprintf('Pedido #%s', $orderId),
            'transaction_amount'   => $helper->getOrderAmount($quote),
            'statement_descriptor' => Mage::getStoreConfig(UltraDev_MercadoPago_Helper_Data::XML_PATH_STATEMENT) ?: 'Loja Online',
            'binary_mode'          => (bool) Mage::getStoreConfigFlag(UltraDev_MercadoPago_Helper_Data::XML_PATH_BINARY_MODE),
            'payer'                => array(
                'email'      => $email,
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'address'    => array(
                    'zip_code'    => $billing['postcode'] ?? '',
                    'street_name' => trim(($billing['street'] ?? '') . ' ' . ($billing['city'] ?? '')),
                ),
            ),
            'additional_info' => array(
                'items' => $items,
                'payer' => array(
                    'first_name'        => $firstName,
                    'last_name'         => $lastName,
                    'registration_date' => date('Y-m-d\TH:i:s', $customer->getCreatedAtTimestamp() ?: time()),
                    'phone'             => array('area_code' => '0', 'number' => $billing['telephone'] ?? ''),
                    'address'           => array(
                        'zip_code'    => $billing['postcode'] ?? '',
                        'street_name' => $billing['street']   ?? '',
                    ),
                ),
            ),
        );

        // Endereço de entrega
        if ($order->canShip()) {
            $ship = $order->getShippingAddress()->getData();
            $preference['additional_info']['shipments']['receiver_address'] = array(
                'zip_code'    => $ship['postcode'] ?? '',
                'street_name' => trim(($ship['street'] ?? '') . ' ' . ($ship['city'] ?? '')),
                'apartment'   => '-',
            );
        }

        // Identificação CPF/CNPJ
        if (!empty($paymentInfo['doc_type']) && !empty($paymentInfo['doc_number'])) {
            $preference['payer']['identification'] = array(
                'type'   => $paymentInfo['doc_type'],
                'number' => $paymentInfo['doc_number'],
            );
        }

        return $preference;
    }

    /**
     * POST /v1/payments — lança exceção em caso de erro
     */
    public function postPayment(array $preference)
    {
        $helper   = $this->_helper();
        $response = $helper->mpPost('/v1/payments', $preference);

        if (in_array((int)($response['status'] ?? 0), array(200, 201), true)) {
            return $response;
        }

        $causes = $response['response']['cause'] ?? array();
        $msg    = '';
        foreach ($causes as $cause) {
            $msg .= $this->_userMessageFromCode((string)($cause['code'] ?? '')) . ' ';
        }
        if (empty(trim($msg))) {
            $msg = Mage::helper('ultradev_mercadopago')->__('Erro ao processar pagamento. Tente novamente.');
        }

        $helper->log('postPayment error', self::LOG_FILE, $response['response']);
        Mage::throwException(trim($msg));
    }

    /**
     * GET /v1/payments/:id
     */
    public function getPayment($paymentId)
    {
        return $this->_helper()->mpGet('/v1/payments/' . (int) $paymentId);
    }

    /**
     * Cria preferência no Checkout Pro (redirect)
     */
    public function createCheckoutProPreference(array $basePreference)
    {
        $baseUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK, true);

        $preference = $basePreference;
        $preference['back_urls'] = array(
            'success' => $baseUrl . 'checkout/onepage/success',
            'pending' => $baseUrl . 'checkout/onepage/success',
            'failure' => $baseUrl . 'checkout/onepage/failure',
        );
        $preference['auto_return'] = 'approved';

        // Checkout Pro não usa estes campos do transparent
        foreach (array('transaction_amount','token','installments','payment_method_id','payment_type_id') as $k) {
            unset($preference[$k]);
        }

        $response = $this->_helper()->mpPost('/checkout/preferences', $preference);

        if (in_array((int)($response['status'] ?? 0), array(200, 201), true)) {
            return $response['response'];
        }

        Mage::throwException(
            Mage::helper('ultradev_mercadopago')->__('Erro ao criar preferência no MercadoPago.')
        );
    }

    protected function _userMessageFromCode($code)
    {
        $map = array(
            '2001' => 'Token do cartão inválido ou expirado.',
            '2067' => 'Token do cartão inválido.',
            '3003' => 'Token do cartão inválido.',
            '4001' => 'Método de pagamento não informado.',
            '4012' => 'E-mail do pagador não informado.',
            '4017' => 'Valor da transação não informado.',
            '4023' => 'Número de parcelas não informado.',
            '4074' => 'Emissor inválido.',
            '4128' => 'Documento do pagador não informado.',
        );

        return isset($map[$code])
            ? $map[$code]
            : Mage::helper('ultradev_mercadopago')->__('Erro no pagamento (código %s).', $code);
    }
}
