<?php
class UltraDev_MercadoPago_ThreeDsController extends Mage_Core_Controller_Front_Action
{
    const LOG_FILE = 'ultradev-mercadopago-3ds.log';

    /**
     * Página do modal de challenge 3DS
     * URL: /ultradevmp/threeds/challenge
     */
    public function challengeAction()
    {
        $this->loadLayout();
        $this->renderLayout();
    }

    /**
     * AJAX — polling de status durante o challenge
     * URL: /ultradevmp/threeds/status?payment_id=<id>
     */
    public function statusAction()
    {
        $paymentId = (int) $this->getRequest()->getParam('payment_id');

        if (!$paymentId) {
            $this->_json(array('error' => 'payment_id required'), 400);
            return;
        }

        $core     = Mage::getModel('ultradev_mercadopago/core');
        $response = $core->getPayment($paymentId);

        if (!in_array((int)($response['status'] ?? 0), array(200, 201), true)) {
            $this->_json(array('error' => 'payment not found'), 404);
            return;
        }

        $payment = $response['response'];

        Mage::helper('ultradev_mercadopago')->log(
            "3DS status poll #{$paymentId}",
            self::LOG_FILE,
            array('status' => $payment['status'], 'detail' => $payment['status_detail'])
        );

        $this->_json(array(
            'status'        => $payment['status'],
            'status_detail' => $payment['status_detail'],
            'payment_id'    => $payment['id'],
        ));
    }

    /**
     * AJAX — parcelas para o BIN detectado
     * URL: /ultradevmp/threeds/installments?bin=<6digits>&amount=<float>
     * (rota aqui por simplicidade; o JS aponta para installmentsUrl)
     */
    public function installmentsAction()
    {
        $bin    = preg_replace('/\D/', '', (string) $this->getRequest()->getParam('bin'));
        $amount = (float) $this->getRequest()->getParam('amount');

        if (strlen($bin) < 6 || $amount <= 0) {
            $this->_json(array('error' => 'bin e amount obrigatórios'), 400);
            return;
        }

        $helper   = Mage::helper('ultradev_mercadopago');
        $response = $helper->mpGet(
            '/v1/payment_methods/installments?bin=' . $bin
            . '&amount=' . $amount
            . '&payment_type_id=credit_card'
        );

        if (!in_array((int)($response['status'] ?? 0), array(200, 201), true)) {
            $this->_json(array('error' => 'Erro ao buscar parcelas'), 500);
            return;
        }

        $this->_json($response['response']);
    }

    protected function _json($data, $code = 200)
    {
        $this->getResponse()
             ->setHeader('Content-Type', 'application/json', true)
             ->setHttpResponseCode($code)
             ->setBody(json_encode($data));
    }
}
