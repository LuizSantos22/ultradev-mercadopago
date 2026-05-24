<?php
class UltraDev_MercadoPago_Block_Info extends Mage_Payment_Block_Info
{
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info      = $this->getInfo();
        $data      = array();

        $fields = array(
            'mp_submethod'               => 'Sub-método',
            'payment_id_detail'          => 'ID Pagamento MP',
            'status'                     => 'Status MP',
            'status_detail'              => 'Detalhe MP',
            'payment_method_id'          => 'Método',
            'installments'               => 'Parcelas',
            'trunc_card'                 => 'Cartão',
            'cardholderName'             => 'Titular',
            'payer_identification_type'  => 'Tipo Documento',
            'payer_identification_number'=> 'Documento',
            'boleto_url'                 => 'URL do Boleto',
        );

        foreach ($fields as $key => $label) {
            $val = $info->getAdditionalInformation($key);
            if (!empty($val)) {
                $data[$label] = $val;
            }
        }

        return $transport->setData(array_merge($data, $transport->getData()));
    }
}
