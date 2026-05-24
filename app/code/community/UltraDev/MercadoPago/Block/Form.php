<?php
class UltraDev_MercadoPago_Block_Form extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ultradev/mercadopago/form.phtml');
    }

    public function getPublicKey()
    {
        return Mage::helper('ultradev_mercadopago')->getPublicKey();
    }

    public function isSandbox()
    {
        return Mage::helper('ultradev_mercadopago')->isSandbox();
    }

    public function getGrandTotal()
    {
        return (float) Mage::getSingleton('checkout/session')
            ->getQuote()->getGrandTotal();
    }

    public function getDocumentTypes()
    {
        return array(
            array('id' => 'CPF',  'name' => 'CPF'),
            array('id' => 'CNPJ', 'name' => 'CNPJ'),
        );
    }

    public function getCustomerEmail()
    {
        return (string) Mage::getSingleton('customer/session')
            ->getCustomer()->getEmail();
    }
}
