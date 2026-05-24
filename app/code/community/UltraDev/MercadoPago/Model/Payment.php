<?php
class UltraDev_MercadoPago_Model_Payment extends Mage_Payment_Model_Method_Abstract
{
    protected $_code          = 'ultradev_mercadopago';
    protected $_formBlockType = 'ultradev_mercadopago/form';
    protected $_infoBlockType = 'ultradev_mercadopago/info';

    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canVoid                 = true;
    protected $_canCancelInvoice        = true;
    protected $_isInitializeNeeded      = true;
    protected $_canFetchTransactionInfo = true;
    protected $_canReviewPayment        = true;
    protected $_canSaveCc               = false;

    const SUBMETHOD_CC     = 'cc';
    const SUBMETHOD_PIX    = 'pix';
    const SUBMETHOD_BOLETO = 'boleto';
    const SUBMETHOD_PRO    = 'checkout_pro';

    const STATUS_APPROVED          = 'approved';
    const STATUS_PENDING           = 'pending';
    const STATUS_REJECTED          = 'rejected';
    const DETAIL_PENDING_CHALLENGE = 'pending_challenge';

    const LOG_FILE = 'ultradev-mercadopago.log';

    // ── isAvailable ──────────────────────────────────────────────

    public function isAvailable($quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        $helper = Mage::helper('ultradev_mercadopago');
        $token  = $helper->getAccessToken();
        $pubKey = $helper->getPublicKey();

        if (empty($token) || empty($pubKey)) {
            return false;
        }

        if (!$helper->isSandbox()) {
            $request = Mage::app()->getFrontController()->getRequest();
            if (!$request->isSecure()) {
                return false;
            }
        }

        return $helper->isValidAccessToken($token);
    }

    // ── assignData ───────────────────────────────────────────────

    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }

        $raw  = $data->getData();
        $form = isset($raw['ultradev_mercadopago']) ? $raw['ultradev_mercadopago'] : $raw;

        Mage::helper('ultradev_mercadopago')->log('assignData', self::LOG_FILE, array_keys($form));

        $info      = $this->getInfoInstance();
        $submethod = isset($form['mp_submethod']) ? $form['mp_submethod'] : self::SUBMETHOD_CC;
        $info->setAdditionalInformation('mp_submethod', $submethod);

        switch ($submethod) {
            case self::SUBMETHOD_CC:
                $this->_assignCcData($info, $form);
                break;
            case self::SUBMETHOD_PIX:
            case self::SUBMETHOD_BOLETO:
                $this->_assignTicketData($info, $form, $submethod);
                break;
        }

        return $this;
    }

    protected function _assignCcData(Mage_Payment_Model_Info $info, array $form)
    {
        $info->setAdditionalInformation('token',                 isset($form['token'])               ? $form['token']               : '');
        $info->setAdditionalInformation('payment_method_id',     strtolower(isset($form['payment_method_id']) ? $form['payment_method_id'] : ''));
        $info->setAdditionalInformation('installments',          (int)(isset($form['installments'])   ? $form['installments']   : 1));
        $info->setAdditionalInformation('issuer_id',             isset($form['issuer_id'])            ? $form['issuer_id']            : '');
        $info->setAdditionalInformation('cardholderName',        isset($form['cardholderName'])       ? $form['cardholderName']       : '');
        $info->setAdditionalInformation('trunc_card',            isset($form['trunc_card'])           ? $form['trunc_card']           : '');
        $info->setAdditionalInformation('doc_type',              isset($form['doc_type'])             ? $form['doc_type']             : 'CPF');
        $info->setAdditionalInformation('doc_number',            preg_replace('/\D/', '', isset($form['doc_number']) ? $form['doc_number'] : ''));
        $info->setAdditionalInformation('mp_device_session_id',  isset($form['mp_device_session_id']) ? $form['mp_device_session_id'] : '');
        $info->setAdditionalInformation('payment_type_id',       'credit_card');
    }

    protected function _assignTicketData(Mage_Payment_Model_Info $info, array $form, $submethod)
    {
        $pmId = ($submethod === self::SUBMETHOD_PIX) ? 'pix' : 'bolbradesco';
        $type = ($submethod === self::SUBMETHOD_PIX) ? 'bank_transfer' : 'ticket';

        $info->setAdditionalInformation('payment_method_id', $pmId);
        $info->setAdditionalInformation('payment_type_id',   $type);
        $info->setAdditionalInformation('doc_type',          isset($form['doc_type'])   ? $form['doc_type']   : 'CPF');
        $info->setAdditionalInformation('doc_number',        preg_replace('/\D/', '', isset($form['doc_number']) ? $form['doc_number'] : ''));
    }

    // ── validate ─────────────────────────────────────────────────

    public function validate()
    {
        parent::validate();
        $info      = $this->getInfoInstance();
        $submethod = $info->getAdditionalInformation('mp_submethod');

        if ($submethod === self::SUBMETHOD_CC) {
            if (empty($info->getAdditionalInformation('token'))) {
                Mage::throwException(
                    Mage::helper('ultradev_mercadopago')->__('Token do cartão não encontrado. Verifique os dados e tente novamente.')
                );
            }
        }

        return $this;
    }

    // ── initialize ───────────────────────────────────────────────

    public function initialize($paymentAction, $stateObject)
    {
        $info      = $this->getInfoInstance();
        $submethod = $info->getAdditionalInformation('mp_submethod');

        Mage::helper('ultradev_mercadopago')->log("initialize submethod: $submethod", self::LOG_FILE);

        switch ($submethod) {
            case self::SUBMETHOD_CC:
                return $this->_initCc($stateObject);
            case self::SUBMETHOD_PIX:
                return $this->_initPix($stateObject);
            case self::SUBMETHOD_BOLETO:
                return $this->_initBoleto($stateObject);
            case self::SUBMETHOD_PRO:
                return $this->_initCheckoutPro($stateObject);
            default:
                Mage::throwException('Sub-método de pagamento inválido: ' . $submethod);
        }
    }

    // ── CC + 3DS ─────────────────────────────────────────────────

    protected function _initCc($stateObject)
    {
        $info    = $this->getInfoInstance();
        $core    = Mage::getModel('ultradev_mercadopago/core');
        $helper  = Mage::helper('ultradev_mercadopago');

        $preference = $core->makeBasePreference(array(
            'doc_type'   => $info->getAdditionalInformation('doc_type'),
            'doc_number' => $info->getAdditionalInformation('doc_number'),
        ));

        $preference['token']             = $info->getAdditionalInformation('token');
        $preference['payment_method_id'] = $info->getAdditionalInformation('payment_method_id');
        $preference['installments']      = (int) $info->getAdditionalInformation('installments');
        $preference['payment_type_id']   = 'credit_card';

        $issuerId = $info->getAdditionalInformation('issuer_id');
        if (!empty($issuerId) && $issuerId !== '-1') {
            $preference['issuer_id'] = (int) $issuerId;
        }

        $deviceSessId = $info->getAdditionalInformation('mp_device_session_id');
        if (!empty($deviceSessId)) {
            $preference['device_session_id'] = $deviceSessId;
        }

        $helper->log('CC preference keys', self::LOG_FILE, array_keys($preference));

        $response = $core->postPayment($preference);
        $payment  = $response['response'];

        $info->setAdditionalInformation('payment_id_detail', $payment['id']);
        $info->setAdditionalInformation('status',            $payment['status']);
        $info->setAdditionalInformation('status_detail',     $payment['status_detail']);

        if (!empty($payment['payer']['identification']['type'])) {
            $info->setAdditionalInformation('payer_identification_type',   $payment['payer']['identification']['type']);
            $info->setAdditionalInformation('payer_identification_number', $payment['payer']['identification']['number']);
        }

        // 3DS Challenge
        if (
            $payment['status']        === self::STATUS_PENDING &&
            $payment['status_detail'] === self::DETAIL_PENDING_CHALLENGE &&
            !empty($payment['three_ds_info']['external_resource_url'])
        ) {
            $this->_store3DsChallenge($payment);
            $info->setAdditionalInformation('requires_3ds', 1);

            $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
            $stateObject->setStatus('pending_payment');
            $stateObject->setIsNotified(false);

            $this->_saveOrder();
            return true;
        }

        $this->_applyOrderState($stateObject, $payment['status']);
        $this->_saveOrder();

        return true;
    }

    protected function _store3DsChallenge(array $payment)
    {
        $quote   = Mage::getSingleton('checkout/session')->getQuote();
        $orderId = $quote->getReservedOrderId();

        UltraDev_MercadoPago_Model_ThreeDsSession::saveChallenge(
            (int)    $quote->getId(),
            (string) $orderId,
            (int)    $payment['id'],
            (string) $payment['three_ds_info']['external_resource_url'],
            (string) (isset($payment['three_ds_info']['creq']) ? $payment['three_ds_info']['creq'] : '')
        );
    }

    // ── PIX ──────────────────────────────────────────────────────

    protected function _initPix($stateObject)
    {
        $info = $this->getInfoInstance();
        $core = Mage::getModel('ultradev_mercadopago/core');

        $preference = $core->makeBasePreference(array(
            'doc_type'   => $info->getAdditionalInformation('doc_type'),
            'doc_number' => $info->getAdditionalInformation('doc_number'),
        ));

        $preference['payment_method_id'] = 'pix';
        $preference['payment_type_id']   = 'bank_transfer';

        $response = $core->postPayment($preference);
        $payment  = $response['response'];

        $info->setAdditionalInformation('payment_id_detail', $payment['id']);
        $info->setAdditionalInformation('status',            $payment['status']);
        $info->setAdditionalInformation('status_detail',     $payment['status_detail']);

        $txData = isset($payment['point_of_interaction']['transaction_data'])
                ? $payment['point_of_interaction']['transaction_data']
                : array();

        if (!empty($txData['qr_code_base64'])) {
            $info->setAdditionalInformation('pix_qr_base64', $txData['qr_code_base64']);
        }
        if (!empty($txData['qr_code'])) {
            $info->setAdditionalInformation('pix_qr_code', $txData['qr_code']);
        }

        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        $this->_saveOrder();
        return true;
    }

    // ── BOLETO ───────────────────────────────────────────────────

    protected function _initBoleto($stateObject)
    {
        $info    = $this->getInfoInstance();
        $core    = Mage::getModel('ultradev_mercadopago/core');
        $dueDays = (int) Mage::getStoreConfig(UltraDev_MercadoPago_Helper_Data::XML_PATH_BOLETO_DUE_DAYS) ?: 3;
        $dueDate = date('Y-m-d\T23:59:59.000-03:00', strtotime("+{$dueDays} days"));

        $preference = $core->makeBasePreference(array(
            'doc_type'   => $info->getAdditionalInformation('doc_type'),
            'doc_number' => $info->getAdditionalInformation('doc_number'),
        ));

        $preference['payment_method_id']  = 'bolbradesco';
        $preference['payment_type_id']    = 'ticket';
        $preference['date_of_expiration'] = $dueDate;

        $response = $core->postPayment($preference);
        $payment  = $response['response'];

        $info->setAdditionalInformation('payment_id_detail', $payment['id']);
        $info->setAdditionalInformation('status',            $payment['status']);
        $info->setAdditionalInformation('status_detail',     $payment['status_detail']);
        $info->setAdditionalInformation('boleto_url',        isset($payment['transaction_details']['external_resource_url']) ? $payment['transaction_details']['external_resource_url'] : '');
        $info->setAdditionalInformation('boleto_barcode',    isset($payment['barcode']['content']) ? $payment['barcode']['content'] : '');
        $info->setAdditionalInformation('boleto_due_date',   $dueDate);

        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        $this->_saveOrder();
        return true;
    }

    // ── CHECKOUT PRO ─────────────────────────────────────────────

    protected function _initCheckoutPro($stateObject)
    {
        $core       = Mage::getModel('ultradev_mercadopago/core');
        $preference = $core->makeBasePreference();
        $mpPref     = $core->createCheckoutProPreference($preference);

        $initPoint = isset($mpPref['sandbox_init_point']) ? $mpPref['sandbox_init_point'] : (isset($mpPref['init_point']) ? $mpPref['init_point'] : '');

        $this->getInfoInstance()->setAdditionalInformation('checkout_pro_redirect', $initPoint);
        $this->getInfoInstance()->setAdditionalInformation('status', 'pending');

        $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        $this->_saveOrder();
        return true;
    }

    // ── helpers internos ─────────────────────────────────────────

    protected function _applyOrderState($stateObject, $mpStatus)
    {
        if ($mpStatus === self::STATUS_APPROVED) {
            $stateObject->setState(Mage_Sales_Model_Order::STATE_PROCESSING);
            $stateObject->setStatus('processing');
        } elseif ($mpStatus === self::STATUS_REJECTED) {
            $stateObject->setState(Mage_Sales_Model_Order::STATE_CANCELED);
            $stateObject->setStatus('canceled');
        } else {
            $stateObject->setState(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
            $stateObject->setStatus('pending_payment');
        }
        $stateObject->setIsNotified(false);
    }

    protected function _saveOrder()
    {
        $session = Mage::getSingleton('checkout/session');
        $orderId = $session->getQuote()->getReservedOrderId();
        if ($orderId) {
            Mage::getModel('sales/order')->loadByIncrementId($orderId)->save();
        }
    }

    public function getOrderPlaceRedirectUrl()
    {
        $info      = $this->getInfoInstance();
        $submethod = $info->getAdditionalInformation('mp_submethod');

        if ($submethod === self::SUBMETHOD_PRO) {
            return (string) $info->getAdditionalInformation('checkout_pro_redirect');
        }

        if ($info->getAdditionalInformation('requires_3ds')) {
            return Mage::getUrl('ultradevmp/threeds/challenge', array('_secure' => true));
        }

        return '';
    }
}
