<?php

declare(strict_types=1);

namespace WC62Pay\API;

use Sixtytwopay\Sixtytwopay;

/**
 * Entry point da camada de API do plugin.
 * Encapsula o SDK e fornece métodos de acesso/atalho.
 */
final class Client
{
    private Sixtytwopay $sdk;

    /**
     * @param string $apiKey
     * @param bool $liveMode true => PRODUCTION | false => SANDBOX
     */
    public function __construct(string $apiKey, bool $liveMode = false)
    {
        $environment = $liveMode ? 'PRODUCTION' : 'SANDBOX';
        $this->sdk = new Sixtytwopay($apiKey, $environment);
    }

    /**
     * Constrói a partir das opções salvas no WP (ajuste os nomes das options se necessário).
     */
    public static function fromOptions(): self
    {
        $apiKey = (string)get_option('woocommerce_62pay_api_key', '');
        $liveMode = 'yes' === get_option('woocommerce_62pay_live_mode', 'no');

        if ($apiKey === '') {
            throw new \RuntimeException('62Pay: API key ausente (defina em WooCommerce > Configurações > Pagamentos).');
        }

        return new self($apiKey, $liveMode);
    }

    // -------------------------
    // Atalhos (Services)
    // -------------------------

    public function customer(): \Sixtytwopay\Services\CustomerService
    {
        return $this->sdk->customer();
    }

    public function invoice(): \Sixtytwopay\Services\InvoiceService
    {
        return $this->sdk->invoice();
    }

    public function payment(): \Sixtytwopay\Services\PaymentService
    {
        return $this->sdk->payment();
    }

    public function checkout(): \Sixtytwopay\Services\CheckoutService
    {
        return $this->sdk->checkout();
    }
}
