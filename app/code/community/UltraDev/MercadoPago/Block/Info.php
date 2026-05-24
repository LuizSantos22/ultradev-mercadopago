<?php
class UltraDev_MercadoPago_Block_Info extends Mage_Payment_Block_Info
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ultradev/mercadopago/info.phtml');
    }
}
