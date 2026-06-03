<?php
class UltraDev_MercadoPago_Block_Info_Cc extends Mage_Payment_Block_Info
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ultradev/mercadopago/info/cc.phtml');
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info      = $this->getInfo();
        $data      = [];

        if ($v = $info->getAdditionalInformation('mp_order_id')) {
            $data[Mage::helper('ultradev_mercadopago')->__('Order MP')] = $v;
        }
        if ($v = $info->getAdditionalInformation('mp_pay_status')) {
            $data[Mage::helper('ultradev_mercadopago')->__('Status')] = $v;
        }
        if ($v = $info->getAdditionalInformation('mp_installments')) {
            $data[Mage::helper('ultradev_mercadopago')->__('Parcelas')] = $v . 'x';
        }

        return $transport->addData($data);
    }
}
