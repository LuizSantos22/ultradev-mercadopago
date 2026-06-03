<?php
class UltraDev_MercadoPago_Block_Info_Pix extends Mage_Payment_Block_Info
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ultradev/mercadopago/info/pix.phtml');
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info      = $this->getInfo();
        $data      = [];

        if ($v = $info->getAdditionalInformation('mp_order_id')) {
            $data[Mage::helper('ultradev_mercadopago')->__('Order MP')] = $v;
        }
        if ($v = $info->getAdditionalInformation('mp_ticket_url')) {
            $data[Mage::helper('ultradev_mercadopago')->__('Link Pix')] = $v;
        }

        return $transport->addData($data);
    }
}
