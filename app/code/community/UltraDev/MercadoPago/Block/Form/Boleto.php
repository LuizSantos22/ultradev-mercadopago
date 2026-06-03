<?php
class UltraDev_MercadoPago_Block_Form_Boleto extends Mage_Payment_Block_Form
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ultradev/mercadopago/form/boleto.phtml');
    }
}
