<?php
class UltraDev_MercadoPago_Model_System_Config_Source_Installments
{
    public function toOptionArray(): array
    {
        $options = [];
        for ($i = 1; $i <= 12; $i++) {
            $options[] = ['value' => $i, 'label' => $i . 'x'];
        }
        return $options;
    }
}
