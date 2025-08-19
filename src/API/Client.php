<?php

declare(strict_types=1);

namespace WC62Pay\API;

use Sixtytwopay\Sixtytwopay;

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
        $apiKey = (string)get_option('wc_62pay_api_key', '');
        $liveMode = 'yes' === get_option('wc_62pay_live_mode', 'no');

        if ($apiKey === '') {
            $apiKey = (string)get_option('woocommerce_62pay_api_key', '');
        }
        if (get_option('woocommerce_62pay_live_mode', null) !== null) {
            $liveMode = 'yes' === get_option('woocommerce_62pay_live_mode', 'no');
        }

        if ($apiKey === '') {
            throw new \RuntimeException('62Pay: API key ausente (defina em WooCommerce > 62Pay).');
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
