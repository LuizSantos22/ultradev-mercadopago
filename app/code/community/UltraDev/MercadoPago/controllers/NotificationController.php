<?php
class UltraDev_MercadoPago_NotificationController extends Mage_Core_Controller_Front_Action
{
    public function webhookAction(): void
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true) ?? [];

        Mage::log('[MP Webhook] ' . $raw, Zend_Log::DEBUG, 'ultradev_mercadopago.log');

        if (!$this->_validateSignature($raw)) {
            Mage::log('[MP Webhook] Assinatura inválida — requisição ignorada.', Zend_Log::WARN, 'ultradev_mercadopago.log');
            $this->getResponse()->setHttpResponseCode(401)->setBody('unauthorized');
            return;
        }

        $topic = $data['type']       ?? $this->getRequest()->getParam('topic');
        $mpId  = $data['data']['id'] ?? $this->getRequest()->getParam('id');

        if (empty($mpId) || !in_array($topic, ['order', 'payment'])) {
            $this->getResponse()->setHttpResponseCode(200)->setBody('ignored');
            return;
        }

        try {
            /** @var UltraDev_MercadoPago_Model_Api $api */
            $api = Mage::getModel('ultradev_mercadopago/api');

            // Notificação de pagamento individual — busca via /v1/payments
            // e depois localiza a order pelo external_reference
            if ($topic === 'payment') {
                $payment  = $api->getPayment((string) $mpId);
                $extRef   = $payment['external_reference'] ?? '';
                $status   = $payment['status']             ?? '';
                $detail   = $payment['status_detail']      ?? '';

                if (!$extRef) {
                    $this->getResponse()->setHttpResponseCode(200)->setBody('no_ref');
                    return;
                }

                $order = Mage::getModel('sales/order')->loadByIncrementId($extRef);
                if (!$order->getId()) {
                    $this->getResponse()->setHttpResponseCode(200)->setBody('order_not_found');
                    return;
                }

                $orderPayment = $order->getPayment();
                $orderPayment->setAdditionalInformation('mp_pay_status', $status)->save();

                // Pagamento aprovado via /v1/payments usa status 'approved'
                $approved = ($status === 'approved');

            } else {
                // Notificação de order — busca via /v1/orders
                $response  = $api->getOrder((string) $mpId);
                $extRef    = $response['external_reference'] ?? '';
                $status    = $response['status']             ?? '';
                $detail    = $response['status_detail']      ?? '';
                $payBlock  = $response['transactions']['payments'][0] ?? [];
                $payStatus = $payBlock['status'] ?? '';

                if (!$extRef) {
                    $this->getResponse()->setHttpResponseCode(200)->setBody('no_ref');
                    return;
                }

                $order = Mage::getModel('sales/order')->loadByIncrementId($extRef);
                if (!$order->getId()) {
                    $this->getResponse()->setHttpResponseCode(200)->setBody('order_not_found');
                    return;
                }

                $orderPayment = $order->getPayment();
                $orderPayment->setAdditionalInformation('mp_order_status', $status);
                $orderPayment->setAdditionalInformation('mp_pay_status',   $payStatus);
                $orderPayment->save();

                $approved = ($status === 'processed' && $payStatus === 'processed');
            }

            if ($approved && $order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                $methodCode     = $order->getPayment()->getMethod();
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

            } elseif (in_array($status, ['expired', 'cancelled', 'rejected'])) {
                $order->cancel()
                      ->addStatusHistoryComment('Pagamento ' . $status . ' via webhook.')
                      ->save();
            }

        } catch (Throwable $e) {
            Mage::log('[MP Webhook] Erro: ' . $e->getMessage(), Zend_Log::ERR, 'ultradev_mercadopago.log');
        }

        $this->getResponse()->setHttpResponseCode(200)->setBody('ok');
    }

    protected function _validateSignature(string $rawBody): bool
    {
        $secret = Mage::helper('ultradev_mercadopago')->getWebhookSecret();

        if (empty($secret)) {
            Mage::log('[MP Webhook] webhook_secret não configurado — validação ignorada.', Zend_Log::WARN, 'ultradev_mercadopago.log');
            return true;
        }

        $header = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        if (empty($header)) {
            return false;
        }

        $ts = '';
        $v1 = '';
        foreach (explode(',', $header) as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
            if ($key === 'ts') $ts = $val;
            if ($key === 'v1') $v1 = $val;
        }

        if (empty($ts) || empty($v1)) {
            return false;
        }

        $data      = json_decode($rawBody, true) ?? [];
        $dataId    = $data['data']['id'] ?? '';
        $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

        $manifest = '';
        if (!empty($dataId))    $manifest .= 'id:'         . $dataId    . ';';
        if (!empty($requestId)) $manifest .= 'request-id:' . $requestId . ';';
        if (!empty($ts))        $manifest .= 'ts:'         . $ts        . ';';

        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }
}
