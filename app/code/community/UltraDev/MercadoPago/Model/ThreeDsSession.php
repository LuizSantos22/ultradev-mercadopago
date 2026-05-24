<?php
class UltraDev_MercadoPago_Model_ThreeDsSession extends Mage_Core_Model_Abstract
{
    protected function _construct()
    {
        $this->_init('ultradev_mercadopago/threeds_session');
    }

    public static function loadByQuoteId($quoteId)
    {
        $model = Mage::getModel('ultradev_mercadopago/threeds_session');
        $collection = $model->getCollection()
            ->addFieldToFilter('quote_id', (int) $quoteId)
            ->setOrder('entity_id', 'DESC')
            ->setPageSize(1);

        return $collection->getFirstItem();
    }

    public static function saveChallenge(
        $quoteId,
        $orderIncrementId,
        $paymentId,
        $externalResourceUrl,
        $creq
    ) {
        $existing = self::loadByQuoteId($quoteId);
        if ($existing->getId()) {
            $existing->delete();
        }

        Mage::getModel('ultradev_mercadopago/threeds_session')
            ->setQuoteId((int) $quoteId)
            ->setOrderIncrementId((string) $orderIncrementId)
            ->setPaymentId((int) $paymentId)
            ->setExternalResourceUrl((string) $externalResourceUrl)
            ->setCreq((string) $creq)
            ->save();
    }

    public static function deleteByQuoteId($quoteId)
    {
        $existing = self::loadByQuoteId($quoteId);
        if ($existing->getId()) {
            $existing->delete();
        }
    }
}
