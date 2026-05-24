<?php
class UltraDev_MercadoPago_Model_Resource_ThreeDsSession
    extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct()
    {
        $this->_init('ultradev_mercadopago/threeds_session', 'entity_id');
    }
}
