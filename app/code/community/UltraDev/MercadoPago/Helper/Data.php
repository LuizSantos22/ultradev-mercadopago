<?php
class UltraDev_MercadoPago_Helper_Data extends Mage_Core_Helper_Abstract
{
    const API_BASE_URL = 'https://api.mercadopago.com';

    const XML_PATH_ACCESS_TOKEN         = 'payment/ultradev_mercadopago_cc/access_token';
    const XML_PATH_ACCESS_TOKEN_SANDBOX = 'payment/ultradev_mercadopago_cc/access_token_sandbox';
    const XML_PATH_PUBLIC_KEY           = 'payment/ultradev_mercadopago_cc/public_key';
    const XML_PATH_PUBLIC_KEY_SANDBOX   = 'payment/ultradev_mercadopago_cc/public_key_sandbox';
    const XML_PATH_SANDBOX              = 'payment/ultradev_mercadopago_cc/sandbox';

    public function isSandbox(): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::XML_PATH_SANDBOX);
    }

    public function getAccessToken(): string
    {
        $path = $this->isSandbox()
            ? self::XML_PATH_ACCESS_TOKEN_SANDBOX
            : self::XML_PATH_ACCESS_TOKEN;
        return (string) Mage::getStoreConfig($path);
    }

    public function getPublicKey(): string
    {
        $path = $this->isSandbox()
            ? self::XML_PATH_PUBLIC_KEY_SANDBOX
            : self::XML_PATH_PUBLIC_KEY;
        return (string) Mage::getStoreConfig($path);
    }

    /** Valor monetário como string no formato exigido pela Orders API: "200.00" */
    public function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /** UUID v4 para o header X-Idempotency-Key (obrigatório na Orders API) */
    public function generateIdempotencyKey(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
