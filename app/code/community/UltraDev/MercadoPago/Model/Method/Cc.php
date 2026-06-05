<?php
class UltraDev_MercadoPago_Model_Method_Cc extends Mage_Payment_Model_Method_Abstract
{
    protected $_code                    = 'ultradev_mercadopago_cc';
    protected $_formBlockType           = 'ultradev_mercadopago/form_cc';
    protected $_infoBlockType           = 'ultradev_mercadopago/info_cc';
    protected $_isGateway               = true;
    protected $_canCapture              = true;
    protected $_canRefund               = false;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = false;

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
        $info->setAdditionalInformation('mp_token',             $data->getMpToken());
        $info->setAdditionalInformation('mp_payment_method_id', $data->getMpPaymentMethodId());
        $info->setAdditionalInformation('mp_payment_type_id',   $data->getMpPaymentTypeId());
        $info->setAdditionalInformation('mp_installments',      (int) $data->getMpInstallments());
        $info->setAdditionalInformation('mp_doc_type',          $data->getMpDocType());
        $info->setAdditionalInformation('mp_doc_number',        $data->getMpDocNumber());
        return $this;
    }

    public function validate()
    {
        $info  = $this->getInfoInstance();
        $token = $info->getAdditionalInformation('mp_token');
        if (empty($token)) {
            Mage::throwException(Mage::helper('ultradev_mercadopago')->__('Token do cartão não recebido. Tente novamente.'));
        }
        return $this;
    }

    public function capture(Varien_Object $payment, $amount)
    {
        $order  = $payment->getOrder();
        $info   = $this->getInfoInstance();
        $helper = Mage::helper('ultradev_mercadopago');
        $api    = Mage::getModel('ultradev_mercadopago/api');

        $data = [
            'amount'             => $amount,
            'external_reference' => $order->getIncrementId(),
            'payer_email'        => $order->getCustomerEmail(),
            'doc_type'           => $info->getAdditionalInformation('mp_doc_type'),
            'doc_number'         => $info->getAdditionalInformation('mp_doc_number'),
            'token'              => $info->getAdditionalInformation('mp_token'),
            'payment_method_id'  => $info->getAdditionalInformation('mp_payment_method_id'),
            'payment_type_id'    => $info->getAdditionalInformation('mp_payment_type_id') ?: 'credit_card',
            'installments'       => $info->getAdditionalInformation('mp_installments') ?: 1,
            'order'              => $order,
        ];

        $response = $api->createCcOrder($data);

        if (empty($response['id'])) {
            Mage::throwException($helper->__('Erro ao processar pagamento no Mercado Pago.'));
        }

        $orderStatus  = $response['status'] ?? '';
        $mpOrderId    = $response['id'];
        $paymentBlock = $response['transactions']['payments'][0] ?? [];
        $payStatus    = $paymentBlock['status'] ?? '';
        $payId        = $paymentBlock['id'] ?? '';

        $payment->setTransactionId($mpOrderId)
                ->setIsTransactionClosed($orderStatus === 'processed');

        $info->setAdditionalInformation('mp_order_id',     $mpOrderId);
        $info->setAdditionalInformation('mp_payment_id',   $payId);
        $info->setAdditionalInformation('mp_order_status', $orderStatus);
        $info->setAdditionalInformation('mp_pay_status',   $payStatus);

        if ($orderStatus === 'processed' && $payStatus === 'processed') {
            $payment->setIsTransactionClosed(true);
            $statusAprovado = $this->getConfigData('order_status') ?: 'processing';
            $order->setState(
                Mage_Sales_Model_Order::STATE_PROCESSING,
                $statusAprovado,
                'Pagamento aprovado pelo Mercado Pago. Order: ' . $mpOrderId
            );

        } elseif (in_array($orderStatus, ['action_required', 'in_review', 'pending', 'authorized'])) {
            $payment->setIsTransactionClosed(false);
            $order->setState(
                Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
                'pending_payment',
                'Pagamento em análise no Mercado Pago. Order: ' . $mpOrderId . ' / Status: ' . $orderStatus
            );

        } else {
            Mage::throwException($helper->__('Pagamento não aprovado. Status: %s', $orderStatus));
        }

        return $this;
    }
}
