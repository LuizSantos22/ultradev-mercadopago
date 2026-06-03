<?php
class UltraDev_MercadoPago_NotificationController extends Mage_Core_Controller_Front_Action
{
    /**
     * Webhook: POST /ultradev-mercadopago/notification/webhook
     * O MP envia notificações do tópico "order" quando o status muda.
     */
    public function webhookAction(): void
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];

        Mage::log('[MP Webhook] ' . $raw, Zend_Log::DEBUG, 'ultradev_mercadopago.log');

        $topic  = $data['type']            ?? $this->getRequest()->getParam('topic');
        $mpId   = $data['data']['id']      ?? $this->getRequest()->getParam('id');

        if ($topic !== 'order' || empty($mpId)) {
            $this->getResponse()->setHttpResponseCode(200)->setBody('ignored');
            return;
        }

        try {
            /** @var UltraDev_MercadoPago_Model_Api $api */
            $api      = Mage::getModel('ultradev_mercadopago/api');
            $response = $api->getOrder($mpId);

            $extRef = $response['external_reference'] ?? '';
            if (!$extRef) {
                $this->getResponse()->setHttpResponseCode(200)->setBody('no_ref');
                return;
            }

            $order = Mage::getModel('sales/order')->loadByIncrementId($extRef);
            if (!$order->getId()) {
                $this->getResponse()->setHttpResponseCode(200)->setBody('order_not_found');
                return;
            }

            $status    = $response['status']        ?? '';
            $detail    = $response['status_detail'] ?? '';
            $payBlock  = $response['transactions']['payments'][0] ?? [];
            $payStatus = $payBlock['status'] ?? '';

            $payment   = $order->getPayment();
            $payment->setAdditionalInformation('mp_order_status', $status);
            $payment->setAdditionalInformation('mp_pay_status',   $payStatus);
            $payment->save();

            if ($status === 'processed' && $payStatus === 'processed'
                && $order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT
            ) {
                $methodCode     = $payment->getMethod();
                $approvedStatus = Mage::getStoreConfig('payment/' . $methodCode . '/order_status') ?: 'processing';

                $order->setState(
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                    $approvedStatus,
                    'Pagamento confirmado via webhook. Status: ' . $detail
                );

                if (!$order->hasInvoices()) {
                    $invoice = $order->prepareInvoice();
                    $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
                    $invoice->register();
                    $invoice->save();
                    $order->addRelatedObject($invoice);
                }

                $order->save();
            } elseif (in_array($status, ['expired', 'cancelled'])) {
                $order->cancel()
                      ->addStatusHistoryComment('Pagamento ' . $status . ' via webhook.')
                      ->save();
            }
        } catch (Throwable $e) {
            Mage::log('[MP Webhook] Erro: ' . $e->getMessage(), Zend_Log::ERR, 'ultradev_mercadopago.log');
        }

        $this->getResponse()->setHttpResponseCode(200)->setBody('ok');
    }
}
