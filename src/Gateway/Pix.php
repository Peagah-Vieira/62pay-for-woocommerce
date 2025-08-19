<?php

namespace WC62Pay\Gateway;

use Sixtytwopay\Inputs\Customer\CustomerUpdateInput;
use WC62Pay\Support\CustomerResolver;
use WC62Pay\Support\InvoicePixExtractor;
use WC62Pay\Support\InvoicePixPersister;
use WC62Pay\Support\InvoiceResolver;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

class Pix extends Base
{
    private const META_DOCUMENT_NUMBER = '_wc_62pay_document_number';

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

            // Preferir o POST validado; senão, meta existente (recompra, order-pay etc.)
            $doc = isset($_POST['wc_62pay_document_number']) ? wc_clean(wp_unslash($_POST['wc_62pay_document_number'])) : '';
            $doc = $this->normalize_and_validate_document($doc);
            if (empty($doc)) {
                $doc = $order->get_meta(self::META_DOCUMENT_NUMBER);
            } else {
                $order->update_meta_data(self::META_DOCUMENT_NUMBER, $doc);
                // Opcional: sincronizar para billing_cpf/cnpj caso seu tema/plugins usem
                if (strlen($doc) === 11) {
                    $order->update_meta_data('_billing_cpf', $doc);
                } elseif (strlen($doc) === 14) {
                    $order->update_meta_data('_billing_cnpj', $doc);
                }
                $order->save();
            }

            // 1.b) ATUALIZA O CLIENTE NO 62PAY COM O DOCUMENT_NUMBER
            if (!empty($doc)) {
                $type = (strlen($doc) === 14) ? 'LEGAL' : 'NATURAL';

                $payload = CustomerUpdateInput::fromArray([
                    'document_number' => $doc,   // só dígitos
                    'type' => $type,  // LEGAL p/ CNPJ, NATURAL p/ CPF
                ]);

                // Chamada idempotente (se já estiver igual, a API apenas confirma)
                \wc_62pay_client()->customer()->update($customer->id(), $payload);
            }
            // <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<

            // 2) Garante/resolve a invoice PIX
            $invoice = InvoiceResolver::ensure($order, $customer->id(), [
                'payment_method' => 'PIX',
                'due_date' => gmdate('Y-m-d'),
                'description' => sprintf('Pedido #%s – %s', $order->get_order_number(), get_bloginfo('name')),
                'immutable' => true,
                'installments' => 1, // 1x à vista
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
            $logger = wc_get_logger();

            $logger->error(
                '[62Pay] PIX - erro no process_payment',
                [
                    'source' => 'wc-62pay',
                    'order_id' => (int)$order_id,
                    'message' => $e->getMessage(),
                    'code' => (int)$e->getCode(),
                    'exception' => get_class($e),
                    'trace' => $e->getTraceAsString(), // útil em staging; remova em prod se preferir
                ]
            );

            // opcional: nota no pedido
            $order->add_order_note('62Pay: falha ao gerar PIX. ' . $e->getMessage());

            wc_add_notice(__('Falha ao gerar cobrança Pix. Tente novamente.', 'wc-62pay'), 'error');
            return ['result' => 'failure'];
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

    /** Validação dos campos do método */
    public function validate_fields(): bool
    {
        if ($this->id !== (isset($_POST['payment_method']) ? wc_clean(wp_unslash($_POST['payment_method'])) : '')) {
            return true;
        }

        $raw = isset($_POST['wc_62pay_document_number']) ? wc_clean(wp_unslash($_POST['wc_62pay_document_number'])) : '';
        $doc = $this->normalize_and_validate_document($raw);

        if (empty($doc)) {
            wc_add_notice(__('Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.', 'wc-62pay'), 'error');
            return false;
        }

        // Guarde no request para o process_payment() e também meta depois
        $_POST['wc_62pay_document_number'] = $doc;
        return true;
    }

    /** Renderiza campos adicionais do método (checkout clássico) */
    public function payment_fields()
    {
        parent::payment_fields();

        echo '<div class="form-row form-row-wide">';
        woocommerce_form_field('wc_62pay_document_number', [
            'type' => 'text',
            'label' => __('CPF ou CNPJ do pagador', 'wc-62pay'),
            'placeholder' => __('Somente números', 'wc-62pay'),
            'required' => true,
            'class' => ['form-row-wide'],
        ], '');
        echo '</div>';
    }


    /** Valida CPF numérico (11 dígitos) */
    private function is_valid_cpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11 || preg_match('/^(\\d)\\1{10}$/', $cpf)) return false;

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += (int)$cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ((int)$cpf[$t] !== $d) return false;
        }
        return true;
    }

    /** Valida CNPJ numérico (14 dígitos) */
    private function is_valid_cnpj(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D+/', '', $cnpj ?? '');
        if (strlen($cnpj) !== 14 || preg_match('/^(\\d)\\1{13}$/', $cnpj)) return false;

        $calc = function (array $base, array $peso) {
            $s = 0;
            foreach ($base as $i => $n) $s += (int)$n * $peso[$i];
            $r = $s % 11;
            return ($r < 2) ? 0 : 11 - $r;
        };

        $nums = array_map('intval', str_split($cnpj));
        $dig1 = $calc(array_slice($nums, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $dig2 = $calc(array_slice($nums, 0, 12) + [$dig1], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $nums[12] === $dig1 && $nums[13] === $dig2;
    }

    /** Normaliza e valida CPF/CNPJ; retorna somente dígitos ou string vazia */
    private function normalize_and_validate_document(?string $raw): string
    {
        $doc = preg_replace('/\D+/', '', (string)$raw);
        if (strlen($doc) === 11 && $this->is_valid_cpf($doc)) return $doc;
        if (strlen($doc) === 14 && $this->is_valid_cnpj($doc)) return $doc;
        return '';
    }
}
