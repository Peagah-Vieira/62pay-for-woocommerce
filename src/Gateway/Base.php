<?php

namespace WC62Pay\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

abstract class Base extends \WC_Payment_Gateway
{

    public function __construct()
    {
        $this->supports = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->webhook_secret = $this->get_option('webhook_secret');
        $this->soft_descriptor = $this->get_option('soft_descriptor', get_bloginfo('name'));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Ativar/Desativar', 'wc-62pay'),
                'type' => 'checkbox',
                'label' => __('Ativar este método de pagamento', 'wc-62pay'),
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Título', 'wc-62pay'),
                'type' => 'text',
                'description' => __('Exibido no checkout.', 'wc-62pay'),
                'default' => $this->method_title,
            ),
            'description' => array(
                'title' => __('Descrição', 'wc-62pay'),
                'type' => 'textarea',
                'description' => __('Texto mostrado no checkout.', 'wc-62pay'),
                'default' => $this->method_description,
            ),
            'environment' => array(
                'title' => __('Ambiente', 'wc-62pay'),
                'type' => 'select',
                'default' => 'sandbox',
                'options' => array(
                    'sandbox' => __('Sandbox', 'wc-62pay'),
                    'live' => __('Produção', 'wc-62pay'),
                ),
            ),
            'api_key' => array(
                'title' => __('API Key', 'wc-62pay'),
                'type' => 'password',
                'description' => __('Chave da sua API 62Pay.', 'wc-62pay'),
            ),
            'webhook_secret' => array(
                'title' => __('Webhook Secret', 'wc-62pay'),
                'type' => 'password',
                'description' => __('Use para validar notificações do seu gateway.', 'wc-62pay'),
            ),
            'soft_descriptor' => array(
                'title' => __('Soft Descriptor', 'wc-62pay'),
                'type' => 'text',
                'description' => __('Nome na fatura (se aplicável).', 'wc-62pay'),
                'default' => get_bloginfo('name'),
            ),
        );
    }

    protected function get_client()
    {
        return new \src\API\Client($this->api_key, $this->environment);
    }

    public function validate_fields()
    {
        return true;
    }

    public function payment_fields()
    {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
    }

    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        try {
            $this->get_client()->refund($order, $amount, $reason);
            $order->add_order_note(sprintf('Reembolso solicitado via %s: %s. Motivo: %s', $this->method_title, wc_price($amount), $reason));
            return true;
        } catch (\Exception $e) {
            return new \WP_Error('refund_error', $e->getMessage());
        }
    }
}
