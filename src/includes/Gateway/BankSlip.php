<?php

namespace WC62Pay\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class BankSlip extends Base
{

    public function __construct()
    {
        $this->id = 'wc_62pay_boleto';
        $this->method_title = __('62Pay – Boleto', 'wc-62pay');
        $this->method_description = __('Geração de boleto bancário.', 'wc-62pay');
        $this->icon = apply_filters('wc_62pay_boleto_icon', '');
        parent::__construct();

        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
    }

    /**
     * @param $order_id
     * @return array|string[]
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        try {
            $client = $this->get_client();
            $charge = $client->create_charge($order, 'boleto', array());

            if (!empty($charge['boleto_url'])) {
                $order->update_meta_data('_wc_62pay_boleto_url', $charge['boleto_url']);
            }
            if (!empty($charge['boleto_barcode'])) {
                $order->update_meta_data('_wc_62pay_boleto_barcode', $charge['boleto_barcode']);
            }
            $order->save();

            $order->update_status('on-hold', 'Boleto gerado. Aguardando pagamento.');

            return array('result' => 'success', 'redirect' => $this->get_return_url($order));
        } catch (\Exception $e) {
            wc_add_notice('Falha ao gerar boleto: ' . $e->getMessage(), 'error');
            return array('result' => 'failure');
        }
    }

    /**
     * @param $order_id
     * @return void
     */
    public function thankyou_page($order_id): void
    {
        $order = wc_get_order($order_id);
        $url = $order->get_meta('_wc_62pay_boleto_url');
        $barcode = $order->get_meta('_wc_62pay_boleto_barcode');
        echo '<h3>Boleto Bancário</h3>';
        if ($url) {
            echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url($url) . '">Abrir Boleto</a></p>';
        }
        if ($barcode) {
            echo '<p><strong>Linha digitável:</strong><br/><code style="word-break:break-all">' . esc_html($barcode) . '</code></p>';
        }
        echo '<p>Após o pagamento e compensação, seu pedido será atualizado automaticamente.</p>';
    }
}
