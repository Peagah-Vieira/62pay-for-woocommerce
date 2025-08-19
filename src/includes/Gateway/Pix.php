<?php

namespace WC62Pay\Gateway;

use WC_Order;
use Woo62Pay\Support\CustomerResolver;
use Woo62Pay\Support\InvoicePixExtractor;
use Woo62Pay\Support\InvoicePixPersister;
use Woo62Pay\Support\InvoiceResolver;

if (!defined('ABSPATH')) {
    exit;
}

class Pix extends Base
{

    public function __construct()
    {
        $this->id = 'wc_62pay_pix';
        $this->method_title = __('62Pay – Pix', 'wc-62pay');
        $this->method_description = __('Cobrança via Pix com QR Code e Copia e Cola.', 'wc-62pay');
        $this->icon = apply_filters('wc_62pay_pix_icon', '');

        parent::__construct();

        // Exibe QR/“Copia e Cola” na página de obrigado
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
    }

    /**
     * Cria/garante cliente + invoice PIX, extrai o payable e salva no pedido.
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        try {
            // 1) Garante/resolve o cliente no 62Pay
            $customer = CustomerResolver::ensure($order); // CustomerResponse

            // 2) Garante/resolve a invoice PIX
            $invoice = InvoiceResolver::ensure($order, $customer->id(), [
                'payment_method' => 'PIX',
                'due_date' => gmdate('Y-m-d'),
                'description' => sprintf('Pedido #%s – %s', $order->get_order_number(), get_bloginfo('name')),
                'immutable' => true,
                'extra_tags' => ['checkout', 'pix'],
            ]); // InvoiceResponse

            // 3) Extrai o primeiro pagamento PIX (payable)
            $pix = InvoicePixExtractor::firstPixPaymentOrNull($invoice);
            if ($pix) {
                // Persiste metas e opcionalmente grava o PNG do QR em uploads/
                $persist = InvoicePixPersister::persist($order, $pix, true);
                $order->add_order_note(sprintf(
                    '62Pay: PIX gerado. Payment ID: %s%s',
                    esc_html((string)($pix['payment_id'] ?? '')),
                    !empty($persist['qr_png_url']) ? ' | QR salvo: ' . esc_url($persist['qr_png_url']) : ''
                ));
            } else {
                // Se por algum motivo a invoice não retornou PIX/payable
                throw new \RuntimeException('Não foi possível obter os dados do PIX (payable) na resposta da fatura.');
            }

            // 4) Atualiza status e segue para a página de obrigado
            // - 'on-hold' costuma ser usado para aguardando pagamento
            $order->update_status('on-hold', __('Aguardando pagamento Pix.', 'wc-62pay'));

            // (opcional) reduzir estoque aqui, caso deseje
            // wc_reduce_stock_levels( $order_id );

            // Salva modificações
            $order->save();

            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            );

        } catch (\Throwable $e) {
            wc_get_logger()->error('[62Pay] PIX - erro no process_payment: ' . $e->getMessage(), array('source' => 'wc-62pay'));
            wc_add_notice(__('Falha ao gerar cobrança Pix. Tente novamente.', 'wc-62pay'), 'error');
            return array('result' => 'failure');
        }
    }

    /**
     * Mostra QR e Copia e Cola usando os metas persistidos pelo persister.
     */
    public function thankyou_page($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        // Lê metas gravadas pelo InvoicePixPersister
        $qr_png_url = $order->get_meta(InvoicePixPersister::META_QR_PNG_URL);   // URL do PNG salvo (se habilitado)
        $qr_base64 = $order->get_meta(InvoicePixPersister::META_QR_BASE64);    // Base64 do PNG (se você preferiu salvar)
        $copy_paste = $order->get_meta(InvoicePixPersister::META_COPY_PASTE);
        $expires_at = $order->get_meta(InvoicePixPersister::META_EXPIRES_AT);
        $status = $order->get_meta(InvoicePixPersister::META_STATUS);

        echo '<h3>' . esc_html__('Pagamento via Pix', 'wc-62pay') . '</h3>';

        if ($qr_png_url) {
            echo '<p>' . esc_html__('Escaneie o QR Code abaixo para pagar:', 'wc-62pay') . '</p>';
            echo '<img style="max-width:260px;height:auto;border:1px solid #eee;padding:8px" src="' . esc_url($qr_png_url) . '" alt="QR Code Pix" />';
        } elseif ($qr_base64) {
            // fallback: exibe usando data URI
            $src = 'data:image/png;base64,' . $qr_base64;
            echo '<p>' . esc_html__('Escaneie o QR Code abaixo para pagar:', 'wc-62pay') . '</p>';
            echo '<img style="max-width:260px;height:auto;border:1px solid #eee;padding:8px" src="' . esc_attr($src) . '" alt="QR Code Pix" />';
        }

        if ($copy_paste) {
            echo '<p><strong>' . esc_html__('Copia e Cola:', 'wc-62pay') . '</strong><br/>';
            echo '<code style="word-break:break-all;display:inline-block;padding:6px 8px;background:#f7f7f7;border-radius:4px;">' . esc_html($copy_paste) . '</code></p>';
        }

        if ($expires_at) {
            echo '<p><em>' . sprintf(
                /* translators: %s = date time string */
                    esc_html__('Expira em: %s', 'wc-62pay'),
                    esc_html($expires_at)
                ) . '</em></p>';
        }

        if ($status) {
            echo '<p><small>' . sprintf(
                /* translators: %s = current status */
                    esc_html__('Status do pagamento: %s', 'wc-62pay'),
                    esc_html($status)
                ) . '</small></p>';
        }

        echo '<p>' . esc_html__('Assim que o pagamento for confirmado, seu pedido será atualizado automaticamente.', 'wc-62pay') . '</p>';
    }
}
