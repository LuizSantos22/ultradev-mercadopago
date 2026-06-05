<?php
class UltraDev_MercadoPago_Block_Info_Cc extends Mage_Payment_Block_Info
{
    protected $_statusLabels = [
        'processed'       => 'Aprovado',
        'in_review'       => 'Em revisão',
        'pending'         => 'Pendente',
        'authorized'      => 'Autorizado',
        'action_required' => 'Ação necessária',
        'cancelled'       => 'Cancelado',
        'expired'         => 'Expirado',
        'failed'          => 'Recusado',
        'rejected'        => 'Recusado',
    ];

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
            $data[Mage::helper('ultradev_mercadopago')->__('Nº Pedido MercadoPago')] = $v;
        }

        $status = $info->getAdditionalInformation('mp_pay_status')
                ?: $info->getAdditionalInformation('mp_order_status');
        if ($status) {
            $data[Mage::helper('ultradev_mercadopago')->__('Status do Pagamento')] =
                $this->_statusLabels[$status] ?? ucfirst($status);
        }

        if ($v = $info->getAdditionalInformation('mp_installments')) {
            $data[Mage::helper('ultradev_mercadopago')->__('Parcelas')] = $v . 'x';
        }

        return $transport->addData($data);
    }
}
