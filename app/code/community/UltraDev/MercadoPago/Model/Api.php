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
            Mage::throwException(
                Mage::helper('ultradev_mercadopago')->__('Erro de comunicação com o Mercado Pago.')
            );
        }

        $data = json_decode($raw, true) ?? [];
        $data['_http_status'] = $status;

        Mage::log('[MP] ' . $method . ' ' . $path . ' HTTP=' . $status . ' ' . $raw, Zend_Log::DEBUG, 'ultradev_mercadopago.log');

        return $data;
    }

    /**
     * Monta o array de items a partir dos itens do pedido.
     * A Orders API exige: title, unit_price, quantity, unit_measure, total_amount.
     */
    protected function _buildItems(Mage_Sales_Model_Order $order): array
    {
        $items = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $qty        = (int) $item->getQtyOrdered();
            $unitPrice  = round((float) $item->getPrice(), 2);
            $totalPrice = round($unitPrice * $qty, 2);

            if ($unitPrice <= 0) {
                $totalPrice = round((float) $item->getRowTotal(), 2);
                $unitPrice  = $qty > 0 ? round($totalPrice / $qty, 2) : 0;
            }

            if ($totalPrice <= 0) {
                continue;
            }

            $items[] = [
                'title'        => mb_substr((string) $item->getName(), 0, 256),
                'unit_price'   => number_format($unitPrice, 2, '.', ''),
                'quantity'     => $qty,
                'unit_measure' => 'unit',
                'total_amount' => number_format($totalPrice, 2, '.', ''),
            ];
        }

        // Ajusta diferença de arredondamento/desconto global no último item
        if (!empty($items)) {
            $orderTotal = round((float) $order->getGrandTotal(), 2);
            $itemsTotal = round(array_reduce($items, function ($carry, $i) {
                return $carry + (float) $i['total_amount'];
            }, 0.0), 2);

            $diff = round($orderTotal - $itemsTotal, 2);
            if ($diff != 0) {
                $last     = count($items) - 1;
                $newTotal = round((float) $items[$last]['total_amount'] + $diff, 2);
                if ($newTotal > 0) {
                    $qty = (int) $items[$last]['quantity'];
                    $items[$last]['total_amount'] = number_format($newTotal, 2, '.', '');
                    $items[$last]['unit_price']   = number_format(
                        $qty > 0 ? round($newTotal / $qty, 2) : $newTotal,
                        2, '.', ''
                    );
                }
            }
        }

        return $items;
    }

    /**
     * Cria order de cartão de crédito via Orders API
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
                    'type'   => $data['doc_type']  ?? 'CPF',
                    'number' => $data['doc_number'] ?? '',
                ],
            ],
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

    /**
     * Cria order de Pix via Orders API
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

        if (!empty($data['order'])) {
            $items = $this->_buildItems($data['order']);
            if (!empty($items)) {
                $body['items'] = $items;
            }
        }

        return $this->_request('POST', '/v1/orders', $body);
    }

    /**
     * Cria order de Boleto via Orders API
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

    /**
     * Consulta order por ID
     * GET /v1/orders/{id}
     */
    public function getOrder(string $orderId): array
    {
        return $this->_request('GET', '/v1/orders/' . $orderId);
    }

    /**
     * Consulta pagamento individual por ID
     * GET /v1/payments/{id}
     */
    public function getPayment(string $paymentId): array
    {
        return $this->_request('GET', '/v1/payments/' . $paymentId);
    }
}
