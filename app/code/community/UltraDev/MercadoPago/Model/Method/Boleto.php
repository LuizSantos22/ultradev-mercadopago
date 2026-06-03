<?php
class UltraDev_MercadoPago_Model_Method_Boleto extends Mage_Payment_Model_Method_Abstract
{
    protected $_code                   = 'ultradev_mercadopago_boleto';
    protected $_formBlockType          = 'ultradev_mercadopago/form_boleto';
    protected $_infoBlockType          = 'ultradev_mercadopago/info_boleto';
    protected $_isGateway              = true;
    protected $_canCapture             = true;
    protected $_canUseCheckout         = true;
    protected $_canUseForMultishipping = false;

    public function isAvailable($quote = null): bool
    {
        if (!$this->getConfigData('active')) {
            return false;
        }
        $token = Mage::helper('ultradev_mercadopago')->getAccessToken();
        return !empty($token) && parent::isAvailable($quote);
    }

    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('mp_doc_type',     $data->getMpDocType());
        $info->setAdditionalInformation('mp_doc_number',   $data->getMpDocNumber());
        $info->setAdditionalInformation('mp_zip_code',     $data->getMpZipCode());
        $info->setAdditionalInformation('mp_street_name',  $data->getMpStreetName());
        $info->setAdditionalInformation('mp_street_number',$data->getMpStreetNumber());
        $info->setAdditionalInformation('mp_neighborhood', $data->getMpNeighborhood());
        $info->setAdditionalInformation('mp_city',         $data->getMpCity());
        $info->setAdditionalInformation('mp_state',        $data->getMpState());
        return $this;
    }

    public function validate()
    {
        $info     = $this->getInfoInstance();
        $required = ['mp_doc_number', 'mp_zip_code', 'mp_street_name', 'mp_street_number', 'mp_city', 'mp_state'];
        foreach ($required as $field) {
            if (empty($info->getAdditionalInformation($field))) {
                Mage::throwException(
                    Mage::helper('ultradev_mercadopago')->__('Preencha todos os dados obrigatórios para boleto.')
                );
            }
        }
        return $this;
    }

    public function capture(Varien_Object $payment, $amount)
    {
        $order      = $payment->getOrder();
        $info       = $this->getInfoInstance();
        $helper     = Mage::helper('ultradev_mercadopago');
        $api        = Mage::getModel('ultradev_mercadopago/api');
        $expiration = $this->getConfigData('boleto_expiration') ?: 'P3D';

        $billingAddress = $order->getBillingAddress();

        $response = $api->createBoletoOrder([
            'amount'             => $amount,
            'external_reference' => $order->getIncrementId(),
            'payer_email'        => $order->getCustomerEmail(),
            'payer_first_name'   => $billingAddress->getFirstname(),
            'payer_last_name'    => $billingAddress->getLastname(),
            'doc_type'           => $info->getAdditionalInformation('mp_doc_type')      ?: 'CPF',
            'doc_number'         => $info->getAdditionalInformation('mp_doc_number'),
            'zip_code'           => $info->getAdditionalInformation('mp_zip_code'),
            'street_name'        => $info->getAdditionalInformation('mp_street_name'),
            'street_number'      => $info->getAdditionalInformation('mp_street_number'),
            'neighborhood'       => $info->getAdditionalInformation('mp_neighborhood')  ?: '',
            'city'               => $info->getAdditionalInformation('mp_city'),
            'state'              => $info->getAdditionalInformation('mp_state'),
            'expiration_time'    => $expiration,
        ]);

        if (empty($response['id'])) {
            Mage::throwException($helper->__('Erro ao gerar boleto no Mercado Pago.'));
        }

        $mpOrderId = $response['id'];
        $payBlock  = $response['transactions']['payments'][0] ?? [];
        $payId     = $payBlock['id']       ?? '';
        $pm        = $payBlock['payment_method'] ?? [];
        $ticketUrl = $pm['ticket_url']      ?? '';
        $barcode   = $pm['barcode_content'] ?? '';
        $digitable = $pm['digitable_line']  ?? '';

        $payment->setTransactionId($mpOrderId)->setIsTransactionClosed(false);

        $info->setAdditionalInformation('mp_order_id',      $mpOrderId);
        $info->setAdditionalInformation('mp_payment_id',    $payId);
        $info->setAdditionalInformation('mp_ticket_url',    $ticketUrl);
        $info->setAdditionalInformation('mp_barcode',       $barcode);
        $info->setAdditionalInformation('mp_digitable_line', $digitable);

        $order->setState(
            Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
            'pending_payment',
            'Boleto gerado. Aguardando pagamento. Order MP: ' . $mpOrderId
        );

        return $this;
    }
}
