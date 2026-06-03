<?php
class UltraDev_MercadoPago_Block_Form_Cc extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ultradev/mercadopago/form/cc.phtml');
    }

    public function getPublicKey(): string
    {
        return Mage::helper('ultradev_mercadopago')->getPublicKey();
    }

    public function getMaxInstallments(): int
    {
        return (int) Mage::getStoreConfig('payment/ultradev_mercadopago_cc/installments') ?: 12;
    }
}
