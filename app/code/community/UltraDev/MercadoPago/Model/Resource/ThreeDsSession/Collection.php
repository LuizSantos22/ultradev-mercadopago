<?php
class UltraDev_MercadoPago_Model_Resource_ThreeDsSession_Collection
    extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('ultradev_mercadopago/threeds_session');
    }
}
