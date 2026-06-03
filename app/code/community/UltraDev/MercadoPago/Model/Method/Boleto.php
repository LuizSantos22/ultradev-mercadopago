<?php
class UltraDev_MercadoPago_Model_Method_Boleto extends Mage_Payment_Model_Method_Abstract
{
    protected $_code                   = 'ultradev_mercadopago_boleto';
    protected $_formBlockType          = 'ultradev_mercadopago/form_boleto';
    protected $_infoBlockType          = 'ultradev_mercadopago/info_boleto';
    protected $_isGateway              = true;
    protected $_canCapture             = true;
    protected $_canUseCheckout         = true;
    protected $_canUseForMultishipping = false;

    public function isAvailable($quote = null): bool
    {
        if (!$this->getConfigData('active')) {
            return false;
        }
        $token = Mage::helper('ultradev_mercadopago')->getAccessToken();
        return !empty($token) && parent::isAvailable($quote);
    }

    public function capture(Varien_Object $payment, $amount)
    {
        $order   = $payment->getOrder();
        $helper  = Mage::helper('ultradev_mercadopago');
        $api     = Mage::getModel('ultradev_mercadopago/api');
        $expiration = $this->getConfigData('boleto_expiration') ?: 'P3D';

        $billing = $order->getBillingAddress();

        // Endereço: linha 1 = rua, linha 2 = número (padrão OpenMage BR)
        $street1 = $billing->getStreet(1) ?: '';
        $street2 = $billing->getStreet(2) ?: '';
        $street3 = $billing->getStreet(3) ?: ''; // bairro em alguns temas
        $street4 = $billing->getStreet(4) ?: '';

        // Heurística: se street2 for numérico, é número; senão pode ser complemento
        $streetName   = $street1;
        $streetNumber = '';
        $neighborhood = '';

        if (is_numeric(preg_replace('/\D/', '', $street2)) && !empty($street2)) {
            $streetNumber = $street2;
            $neighborhood = $street3 ?: $street4;
        } else {
            // tenta extrair número do final da rua
            if (preg_match('/^(.*?)[,\s]+(\d+\w*)$/', $street1, $m)) {
                $streetName   = trim($m[1]);
                $streetNumber = trim($m[2]);
            }
            $neighborhood = $street2 ?: $street3;
        }

        // CPF/CNPJ: tenta taxvat do customer, fallback vazio
        $taxvat    = $order->getCustomerTaxvat() ?: '';
        $docNumber = preg_replace('/\D/', '', $taxvat);
        $docType   = (strlen($docNumber) > 11) ? 'CNPJ' : 'CPF';

        // CEP sem hífen
        $postcode = preg_replace('/\D/', '', $billing->getPostcode() ?: '');

        // Estado: sigla UF (2 letras)
        $region = $billing->getRegionCode() ?: $billing->getRegion() ?: '';
        $state  = strtoupper(substr(trim($region), 0, 2));

        $response = $api->createBoletoOrder([
            'amount'             => $amount,
            'external_reference' => $order->getIncrementId(),
            'payer_email'        => $order->getCustomerEmail(),
            'payer_first_name'   => $billing->getFirstname(),
            'payer_last_name'    => $billing->getLastname(),
            'doc_type'           => $docType,
            'doc_number'         => $docNumber,
            'zip_code'           => $postcode,
            'street_name'        => $streetName,
            'street_number'      => $streetNumber ?: 'S/N',
            'neighborhood'       => $neighborhood,
            'city'               => $billing->getCity(),
            'state'              => $state,
            'expiration_time'    => $expiration,
        ]);

        if (empty($response['id'])) {
            Mage::throwException($helper->__('Erro ao gerar boleto no Mercado Pago.'));
        }

        $mpOrderId = $response['id'];
        $payBlock  = $response['transactions']['payments'][0] ?? [];
        $payId     = $payBlock['id']             ?? '';
        $pm        = $payBlock['payment_method']  ?? [];
        $ticketUrl = $pm['ticket_url']            ?? '';
        $barcode   = $pm['barcode_content']       ?? '';
        $digitable = $pm['digitable_line']        ?? '';

        $payment->setTransactionId($mpOrderId)->setIsTransactionClosed(false);

        $info = $this->getInfoInstance();
        $info->setAdditionalInformation('mp_order_id',       $mpOrderId);
        $info->setAdditionalInformation('mp_payment_id',     $payId);
        $info->setAdditionalInformation('mp_ticket_url',     $ticketUrl);
        $info->setAdditionalInformation('mp_barcode',        $barcode);
        $info->setAdditionalInformation('mp_digitable_line', $digitable);

        $order->setState(
            Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
            'pending_payment',
            'Boleto gerado. Aguardando pagamento. Order MP: ' . $mpOrderId
        );

        return $this;
    }
}
