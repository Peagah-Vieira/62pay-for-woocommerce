<?php

namespace WC62Pay\API;

use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class Client
{

    protected $api_key;
    protected $environment;
    protected $base_url;

    public function __construct($api_key, $environment = 'sandbox')
    {
        $this->api_key = $api_key;
        $this->environment = $environment;
        $this->base_url = $environment === 'live' ? 'https://api.62pay.com' : 'https://sandbox.api.62pay.com';
    }

    public function create_charge(\WC_Order $order, $method, $payload = array())
    {
        $data = array(
            'order_id' => $order->get_id(),
            'amount' => (int)round($order->get_total() * 100),
            'currency' => get_woocommerce_currency(),
            'method' => $method,
            'customer' => array(
                'name' => $order->get_formatted_billing_full_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'tax_id' => $order->get_meta('_billing_cpf', true) ?: $order->get_meta('_billing_cnpj', true),
            ),
            'items' => array_map(function ($item) {
                return array(
                    'name' => $item->get_name(),
                    'quantity' => $item->get_quantity(),
                    'amount' => (int)round($item->get_total() * 100),
                );
            }, $order->get_items()),
            'metadata' => array(
                'woocommerce_order_key' => $order->get_order_key(),
                'site_url' => get_site_url(),
            ),
        );

        $data = array_merge($data, $payload);

        // TODO: implemente chamadas reais usando wp_remote_post para 62Pay.
        switch ($method) {
            case 'credit_card':
                return array('id' => '62pay_cc_' . time(), 'status' => 'approved');
            case 'pix':
                return array(
                    'id' => '62pay_pix_' . time(),
                    'status' => 'pending',
                    'pix_qr' => 'https://via.placeholder.com/260.png?text=QR+Pix',
                    'pix_copia_cola' => '0002012633...DADOS-PIX-62PAY...',
                );
            case 'boleto':
                return array(
                    'id' => '62pay_bol_' . time(),
                    'status' => 'pending',
                    'boleto_url' => 'https://example.com/boleto.pdf',
                    'boleto_barcode' => '34191.79001 01043.510047 91020.150008 1 79970000010000',
                );
        }

        throw new Exception('Método não suportado.');
    }

    public function fetch_charge($charge_id)
    {
        // TODO: chamada real
        return array('id' => $charge_id, 'status' => 'approved');
    }

    /**
     * @param $method
     * @param $path
     * @param $body
     * @return mixed
     * @throws Exception
     */
    protected function request($method, $path, $body = array())
    {
        $url = Client . phptrailingslashit($this->base_url) . ltrim($path, '/');

        $args = array(
            'method' => strtoupper($method),
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 60,
            'body' => !empty($body) ? wp_json_encode($body) : null,
        );
        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300) {
            return $data;
        }

        $message = $data['message'] ?? 'Erro na API 62Pay';

        throw new Exception($message);
    }
}
