<?php
/**
 * Comunicação com a Orders API do Mercado Pago
 * Docs: https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders/overview
 * Endpoint: POST https://api.mercadopago.com/v1/orders
 */
class UltraDev_MercadoPago_Model_Api
{
    protected function _helper(): UltraDev_MercadoPago_Helper_Data
    {
        return Mage::helper('ultradev_mercadopago');
    }

    /**
     * Executa chamada HTTP via cURL
     */
    protected function _request(string $method, string $path, array $body = [], string $idempotencyKey = ''): array
    {
        $helper = $this->_helper();
        $url    = UltraDev_MercadoPago_Helper_Data::API_BASE_URL . $path;

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $helper->getAccessToken(),
            'X-Idempotency-Key: ' . ($idempotencyKey ?: $helper->generateIdempotencyKey()),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'GET') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            Mage::log('[MP] cURL error: ' . $err, Zend_Log::ERR, 'ultradev_mercadopago.log');
            throw new Mage_Payment_Model_Info_Exception(
                Mage::helper('ultradev_mercadopago')->__('Erro de comunicação com o Mercado Pago.')
            );
        }

        $data = json_decode($raw, true) ?? [];
        $data['_http_status'] = $status;

        Mage::log('[MP] ' . $method . ' ' . $path . ' HTTP=' . $status . ' ' . $raw, Zend_Log::DEBUG, 'ultradev_mercadopago.log');

        return $data;
    }

    /**
     * Cria order de cartão de crédito via Orders API
     *
     * $data = [
     *   'amount'             => float,
     *   'external_reference' => string,
     *   'payer_email'        => string,
     *   'doc_type'           => string,   // ex: CPF
     *   'doc_number'         => string,
     *   'token'              => string,   // CardToken gerado pelo MercadoPago.js
     *   'payment_method_id'  => string,   // bandeira: master, visa, elo...
     *   'payment_type_id'    => string,   // credit_card | debit_card
     *   'installments'       => int,
     * ]
     */
    public function createCcOrder(array $data): array
    {
        $h = $this->_helper();

        $body = [
            'type'               => 'online',
            'processing_mode'    => 'automatic',
            'total_amount'       => $h->formatAmount($data['amount']),
            'external_reference' => $data['external_reference'],
            'payer'              => [
                'email'          => $data['payer_email'],
                'identification' => [
                    'type'   => $data['doc_type']   ?? 'CPF',
                    'number' => $data['doc_number']  ?? '',
                ],
            ],
            'transactions' => [
                'payments' => [
                    [
                        'amount'         => $h->formatAmount($data['amount']),
                        'payment_method' => [
                            'id'           => $data['payment_method_id'],
                            'type'         => $data['payment_type_id'],  // credit_card | debit_card
                            'token'        => $data['token'],
                            'installments' => (int) $data['installments'],
                        ],
                    ],
                ],
            ],
        ];

        return $this->_request('POST', '/v1/orders', $body);
    }

    /**
     * Cria order de Pix via Orders API
     *
     * $data = [
     *   'amount'             => float,
     *   'external_reference' => string,
     *   'payer_email'        => string,
     *   'expiration_time'    => string,  // ISO 8601, ex: PT30M
     * ]
     */
    public function createPixOrder(array $data): array
    {
        $h = $this->_helper();

        $payment = [
            'amount'         => $h->formatAmount($data['amount']),
            'payment_method' => [
                'id'   => 'pix',
                'type' => 'bank_transfer',
            ],
        ];

        if (!empty($data['expiration_time'])) {
            $payment['expiration_time'] = $data['expiration_time'];
        }

        $body = [
            'type'               => 'online',
            'processing_mode'    => 'automatic',
            'total_amount'       => $h->formatAmount($data['amount']),
            'external_reference' => $data['external_reference'],
            'payer'              => [
                'email' => $data['payer_email'],
            ],
            'transactions' => [
                'payments' => [$payment],
            ],
        ];

        return $this->_request('POST', '/v1/orders', $body);
    }

    /**
     * Cria order de Boleto via Orders API
     * Endereço do pagador é obrigatório pela API.
     *
     * $data = [
     *   'amount'             => float,
     *   'external_reference' => string,
     *   'payer_email'        => string,
     *   'payer_first_name'   => string,
     *   'payer_last_name'    => string,
     *   'doc_type'           => string,
     *   'doc_number'         => string,
     *   'zip_code'           => string,
     *   'street_name'        => string,
     *   'street_number'      => string,
     *   'neighborhood'       => string,
     *   'city'               => string,
     *   'state'              => string,  // ex: SP
     *   'expiration_time'    => string,  // ISO 8601, ex: P3D
     * ]
     */
    public function createBoletoOrder(array $data): array
    {
        $h = $this->_helper();

        $payment = [
            'amount'         => $h->formatAmount($data['amount']),
            'payment_method' => [
                'id'   => 'boleto',
                'type' => 'ticket',
            ],
        ];

        if (!empty($data['expiration_time'])) {
            $payment['expiration_time'] = $data['expiration_time'];
        }

        $body = [
            'type'               => 'online',
            'processing_mode'    => 'automatic',
            'total_amount'       => $h->formatAmount($data['amount']),
            'external_reference' => $data['external_reference'],
            'payer'              => [
                'email'          => $data['payer_email'],
                'first_name'     => $data['payer_first_name'] ?? '',
                'last_name'      => $data['payer_last_name']  ?? '',
                'identification' => [
                    'type'   => $data['doc_type']   ?? 'CPF',
                    'number' => $data['doc_number']  ?? '',
                ],
                'address'        => [
                    'zip_code'      => $data['zip_code'],
                    'street_name'   => $data['street_name'],
                    'street_number' => $data['street_number'],
                    'neighborhood'  => $data['neighborhood'],
                    'city'          => $data['city'],
                    'state'         => $data['state'],
                ],
            ],
            'transactions' => [
                'payments' => [$payment],
            ],
        ];

        return $this->_request('POST', '/v1/orders', $body);
    }

    /**
     * Consulta order por ID
     * GET /v1/orders/{id}
     */
    public function getOrder(string $orderId): array
    {
        return $this->_request('GET', '/v1/orders/' . $orderId);
    }
}
