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
            Mage::throwException(
                Mage::helper('ultradev_mercadopago')->__('Erro de comunicação com o Mercado Pago.')
            );
        }

        $data = json_decode($raw, true) ?? [];
        $data['_http_status'] = $status;

        Mage::log('[MP] ' . $method . ' ' . $path . ' HTTP=' . $status . ' ' . $raw, Zend_Log::DEBUG, 'ultradev_mercadopago.log');

        return $data;
    }

    protected function _buildItems(Mage_Sales_Model_Order $order): array
    {
        $items = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $qty       = (int) $item->getQtyOrdered();
            $unitPrice = round((float) $item->getPrice(), 2);

            if ($unitPrice <= 0) {
                $unitPrice = $qty > 0 ? round((float) $item->getRowTotal() / $qty, 2) : 0;
            }

            if ($unitPrice <= 0 || $qty <= 0) {
                continue;
            }

            $items[] = [
                'title'      => mb_substr((string) $item->getName(), 0, 256),
                'unit_price' => number_format($unitPrice, 2, '.', ''),
                'quantity'   => $qty,
            ];
        }

        return $items;
    }

    /**
     * Extrai first_name e last_name do billing address do pedido.
     */
    protected function _buildPayerName(Mage_Sales_Model_Order $order): array
    {
        $billing = $order->getBillingAddress();
        return [
            'first_name' => $billing->getFirstname() ?: '',
            'last_name'  => $billing->getLastname()  ?: '',
        ];
    }

    public function createCcOrder(array $data): array
    {
        $h = $this->_helper();

        $payer = [
            'email'          => $data['payer_email'],
            'identification' => [
                'type'   => $data['doc_type']  ?? 'CPF',
                'number' => $data['doc_number'] ?? '',
            ],
        ];

        if (!empty($data['order'])) {
            $name = $this->_buildPayerName($data['order']);
            $payer['first_name'] = $name['first_name'];
            $payer['last_name']  = $name['last_name'];
        }

        $body = [
            'type'               => 'online',
            'processing_mode'    => 'automatic',
            'total_amount'       => $h->formatAmount($data['amount']),
            'external_reference' => $data['external_reference'],
            'payer'              => $payer,
            'transactions' => [
                'payments' => [
                    [
                        'amount'         => $h->formatAmount($data['amount']),
                        'payment_method' => [
                            'id'           => $data['payment_method_id'],
                            'type'         => $data['payment_type_id'],
                            'token'        => $data['token'],
                            'installments' => (int) $data['installments'],
                        ],
                    ],
                ],
            ],
        ];

        if (!empty($data['order'])) {
            $items = $this->_buildItems($data['order']);
            if (!empty($items)) {
                $body['items'] = $items;
            }
        }

        return $this->_request('POST', '/v1/orders', $body);
    }

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

        $payer = [
            'email' => $data['payer_email'],
        ];

        if (!empty($data['order'])) {
            $name = $this->_buildPayerName($data['order']);
            $payer['first_name'] = $name['first_name'];
            $payer['last_name']  = $name['last_name'];
        }

        $body = [
            'type'               => 'online',
            'processing_mode'    => 'automatic',
            'total_amount'       => $h->formatAmount($data['amount']),
            'external_reference' => $data['external_reference'],
            'payer'              => $payer,
            'transactions' => [
                'payments' => [$payment],
            ],
        ];

        if (!empty($data['order'])) {
            $items = $this->_buildItems($data['order']);
            if (!empty($items)) {
                $body['items'] = $items;
            }
        }

        return $this->_request('POST', '/v1/orders', $body);
    }

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
                    'type'   => $data['doc_type']  ?? 'CPF',
                    'number' => $data['doc_number'] ?? '',
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

        if (!empty($data['order'])) {
            $items = $this->_buildItems($data['order']);
            if (!empty($items)) {
                $body['items'] = $items;
            }
        }

        return $this->_request('POST', '/v1/orders', $body);
    }

    public function getOrder(string $orderId): array
    {
        return $this->_request('GET', '/v1/orders/' . $orderId);
    }

    public function getPayment(string $paymentId): array
    {
        return $this->_request('GET', '/v1/payments/' . $paymentId);
    }
}
