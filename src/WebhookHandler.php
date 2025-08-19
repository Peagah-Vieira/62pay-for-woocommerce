<?php

namespace WC62Pay;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookHandler
{

    /**
     * @return void
     */
    public function register_routes(): void
    {
        add_action('woocommerce_api_wc_62pay_webhook', array($this, 'handle'));
    }

    /**
     * @return void
     */
    public function handle(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            status_header(405);
            echo 'Method Not Allowed';
            exit;
        }

        $raw = file_get_contents('php://input');

        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            status_header(400);
            echo 'Invalid payload';
            exit;
        }

        $order_id = isset($payload['order_id']) ? absint($payload['order_id']) : 0;
        $status = isset($payload['status']) ? sanitize_text_field($payload['status']) : '';

        if (!$order_id || !$status) {
            status_header(400);
            echo 'Missing fields';
            exit;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            status_header(404);
            echo 'Order not found';
            exit;
        }

        switch ($status) {
            case 'AWAITING_ISSUE':
            case 'PENDING':
                $order->update_status('on-hold', 'Pagamento pendente (webhook 62Pay).');
                break;
            case 'RECEIVED':
            case 'CONFIRMED':
                if (!$order->is_paid()) {
                    $order->payment_complete($payload['charge_id'] ?? '');
                    $order->add_order_note('Pagamento confirmado via webhook 62Pay.');
                }
                break;
            case 'CANCELED':
                $order->update_status('failed', 'Pagamento cancelado/recusado (webhook 62Pay).');
                break;
        }

        wp_send_json_success(array('ok' => true));
    }
}
