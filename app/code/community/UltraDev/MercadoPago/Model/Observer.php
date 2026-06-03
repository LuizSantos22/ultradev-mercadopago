<?php
class UltraDev_MercadoPago_Model_Observer
{
    /**
     * Cron a cada 15min: consulta orders pendentes e atualiza status
     */
    public function checkPendingOrders(): void
    {
        $methods = [
            'ultradev_mercadopago_pix',
            'ultradev_mercadopago_boleto',
        ];

        foreach ($methods as $method) {
            $collection = Mage::getModel('sales/order')->getCollection()
                ->addFieldToFilter('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);

            $collection->getSelect()
                ->join(
                    ['payment' => $collection->getTable('sales/order_payment')],
                    'payment.parent_id = main_table.entity_id AND payment.method = \'' . $method . '\'',
                    []
                );

            foreach ($collection as $order) {
                $this->_updateOrderFromMp($order);
            }
        }
    }

    protected function _updateOrderFromMp(Mage_Sales_Model_Order $order): void
    {
        $payment = $order->getPayment();
        $mpId    = $payment->getAdditionalInformation('mp_order_id');
        if (!$mpId) {
            return;
        }

        try {
            /** @var UltraDev_MercadoPago_Model_Api $api */
            $api      = Mage::getModel('ultradev_mercadopago/api');
            $response = $api->getOrder($mpId);

            $status       = $response['status']        ?? '';
            $statusDetail = $response['status_detail'] ?? '';
            $payBlock     = $response['transactions']['payments'][0] ?? [];
            $payStatus    = $payBlock['status'] ?? '';

            if ($status === 'processed' && $payStatus === 'processed') {
                $methodCode     = $payment->getMethod();
                $approvedStatus = Mage::getStoreConfig('payment/' . $methodCode . '/order_status') ?: 'processing';

                $order->setState(
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                    $approvedStatus,
                    'Pagamento confirmado pelo Mercado Pago. Status: ' . $statusDetail
                );
                $order->save();

                $invoice = $order->prepareInvoice();
                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                $invoice->register();
                $invoice->save();
                $order->addRelatedObject($invoice);
                $order->save();

            } elseif (in_array($status, ['expired', 'cancelled'])) {
                $order->cancel()
                      ->addStatusHistoryComment('Pagamento ' . $status . ' no Mercado Pago.')
                      ->save();
            }
        } catch (Throwable $e) {
            Mage::log(
                '[MP Observer] Erro ao atualizar pedido ' . $order->getIncrementId() . ': ' . $e->getMessage(),
                Zend_Log::ERR,
                'ultradev_mercadopago.log'
            );
        }
    }
}
