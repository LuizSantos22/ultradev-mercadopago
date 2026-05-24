<?php
class UltraDev_MercadoPago_NotificationsController extends Mage_Core_Controller_Front_Action
{
    const LOG_FILE = 'ultradev-mercadopago-notification.log';

    /**
     * @return UltraDev_MercadoPago_Helper_Data
     */
    protected function _helper()
    {
        return Mage::helper('ultradev_mercadopago');
    }

    /**
     * Webhook IPN: GET/POST /ultradevmp/notifications/custom
     * Parâmetros MP: ?type=payment&data_id=<id>
     */
    public function customAction()
    {
        $request = $this->getRequest();
        $helper  = $this->_helper();
        $dataId  = (int) $request->getParam('data_id');
        $type    = $request->getParam('type');

        $helper->log('IPN received', self::LOG_FILE, $request->getParams());

        if (empty($dataId) || $type !== 'payment') {
            $this->_respond('Invalid params', 400);
            return;
        }

        $core     = Mage::getModel('ultradev_mercadopago/core');
        $response = $core->getPayment($dataId);

        if (!in_array((int)($response['status'] ?? 0), array(200, 201), true)) {
            $helper->log('Payment not found', self::LOG_FILE, array('id' => $dataId));
            $this->_respond('Payment not found', 500);
            return;
        }

        $payment = $response['response'];
        $orderId = isset($payment['external_reference']) ? $payment['external_reference'] : '';

        if (empty($orderId)) {
            $this->_respond('No external_reference', 400);
            return;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
        if (!$order->getId()) {
            $helper->log('Order not found', self::LOG_FILE, array('order_id' => $orderId));
            $this->_respond('Order not found', 404);
            return;
        }

        $this->_updateOrderStatus($order, $payment);
        $this->_respond('OK', 200);
    }

    protected function _updateOrderStatus(Mage_Sales_Model_Order $order, array $payment)
    {
        $helper         = $this->_helper();
        $mpStatus       = isset($payment['status'])        ? $payment['status']        : '';
        $mpStatusDetail = isset($payment['status_detail']) ? $payment['status_detail'] : '';

        $helper->log(
            "IPN update order {$order->getIncrementId()} → {$mpStatus}/{$mpStatusDetail}",
            self::LOG_FILE
        );

        $orderPayment = $order->getPayment();
        $orderPayment->setAdditionalInformation('status',        $mpStatus);
        $orderPayment->setAdditionalInformation('status_detail', $mpStatusDetail);

        // Atualiza dados Pix se chegarem via webhook
        $txData = isset($payment['point_of_interaction']['transaction_data'])
                ? $payment['point_of_interaction']['transaction_data']
                : array();
        if (!empty($txData['qr_code'])) {
            $orderPayment->setAdditionalInformation('pix_qr_code',   $txData['qr_code']);
            $orderPayment->setAdditionalInformation('pix_qr_base64', isset($txData['qr_code_base64']) ? $txData['qr_code_base64'] : '');
        }

        switch ($mpStatus) {
            case 'approved':
                if ($order->canInvoice()) {
                    $invoice = $order->prepareInvoice();
                    $invoice->register()->pay();
                    Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->save();
                }
                $order->setState(
                    Mage_Sales_Model_Order::STATE_PROCESSING,
                    'processing',
                    "Pagamento aprovado pelo MercadoPago (ID: {$payment['id']}).",
                    true
                );
                break;

            case 'rejected':
            case 'cancelled':
                if ($order->canCancel()) {
                    $order->cancel();
                }
                $order->addStatusToHistory('canceled', "Pagamento {$mpStatus} pelo MercadoPago.", true);
                break;

            case 'refunded':
                $order->addStatusToHistory('closed', 'Pagamento reembolsado pelo MercadoPago.', true);
                break;

            default:
                $order->addStatusToHistory(
                    'pending_payment',
                    "Status MP: {$mpStatus} / {$mpStatusDetail}",
                    false
                );
        }

        $orderPayment->save();
        $order->save();
    }

    protected function _respond($body, $code)
    {
        $this->getResponse()->setBody($body)->setHttpResponseCode($code);
    }
}
