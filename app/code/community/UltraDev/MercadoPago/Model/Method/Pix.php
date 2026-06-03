<?php
class UltraDev_MercadoPago_Model_Method_Pix extends Mage_Payment_Model_Method_Abstract
{
    protected $_code                   = 'ultradev_mercadopago_pix';
    protected $_formBlockType          = 'ultradev_mercadopago/form_pix';
    protected $_infoBlockType          = 'ultradev_mercadopago/info_pix';
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

    public function capture(Varien_Object $payment, $amount)
    {
        $order      = $payment->getOrder();
        $helper     = Mage::helper('ultradev_mercadopago');
        $api        = Mage::getModel('ultradev_mercadopago/api');
        $expiration = $this->getConfigData('pix_expiration') ?: 'PT30M';

        $response = $api->createPixOrder([
            'amount'             => $amount,
            'external_reference' => $order->getIncrementId(),
            'payer_email'        => $order->getCustomerEmail(),
            'expiration_time'    => $expiration,
        ]);

        if (empty($response['id'])) {
            Mage::throwException($helper->__('Erro ao gerar Pix no Mercado Pago.'));
        }

        $mpOrderId = $response['id'];
        $payBlock  = $response['transactions']['payments'][0] ?? [];
        $payId     = $payBlock['id']             ?? '';
        $pm        = $payBlock['payment_method']  ?? [];
        $ticketUrl = $pm['ticket_url']            ?? '';
        $qrCode    = $pm['qr_code']               ?? '';
        $qrBase64  = $pm['qr_code_base64']        ?? '';

        $payment->setTransactionId($mpOrderId)->setIsTransactionClosed(false);

        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('mp_order_id',       $mpOrderId);
        $info->setAdditionalInformation('mp_payment_id',     $payId);
        $info->setAdditionalInformation('mp_ticket_url',     $ticketUrl);
        $info->setAdditionalInformation('mp_qr_code',        $qrCode);
        $info->setAdditionalInformation('mp_qr_code_base64', $qrBase64);

        $order->setState(
            Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
            'pending_payment',
            'Pix gerado. Aguardando pagamento. Order MP: ' . $mpOrderId
        );

        return $this;
    }
}
