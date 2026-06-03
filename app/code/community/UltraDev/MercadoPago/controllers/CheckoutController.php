<?php
class UltraDev_MercadoPago_CheckoutController extends Mage_Core_Controller_Front_Action
{
    /**
     * Página de sucesso com exibição do QR Code Pix ou link do Boleto
     * URL: /ultradev-mercadopago/checkout/success
     */
    public function successAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $order   = Mage::getModel('sales/order')->load($session->getLastOrderId());

        if (!$order->getId()) {
            $this->_redirect('checkout/cart');
            return;
        }

        $payment = $order->getPayment();
        $method  = $payment->getMethod();

        Mage::register('mp_payment_method', $method);
        Mage::register('mp_payment_info',   $payment->getAdditionalInformation());

        $this->loadLayout();
        $this->renderLayout();
    }
}
