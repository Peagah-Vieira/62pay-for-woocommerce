<?php

namespace WC62Pay\Gateway;

use Sixtytwopay\Inputs\Customer\CustomerUpdateInput;
use WC62Pay\Support\CustomerResolver;
use WC62Pay\Support\InvoiceBankSlipExtractor;
use WC62Pay\Support\InvoiceBankSlipPersister;
use WC62Pay\Support\InvoiceResolver;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

class BankSlip extends Base
{
    private const META_DOCUMENT_NUMBER = '_wc_62pay_document_number';

    public function __construct()
    {
        $this->id = 'wc_62pay_boleto';
        $this->method_title = __('62Pay – Boleto', 'wc-62pay');
        $this->method_description = __('Geração de boleto bancário.', 'wc-62pay');
        $this->icon = apply_filters('wc_62pay_boleto_icon', '');

        parent::__construct();

        // Render em telas relevantes (como no exemplo Asaas)
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'append_html_to_thankyou_page']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'append_html_to_thankyou_page']);
        add_action('woocommerce_view_order', [$this, 'append_html_to_thankyou_page']);
    }

    /**
     * Cria/garante cliente + invoice Boleto, extrai payable e salva metas/arquivos.
     */
    public function process_payment($order_id): array
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

            // 2) Cria/garante a invoice com método BANK_SLIP
            $invoice = InvoiceResolver::ensure($order, $customer->id(), [
                'payment_method' => 'BANK_SLIP',
                'due_date' => gmdate('Y-m-d'),
                'description' => sprintf('Pedido #%s – %s', $order->get_order_number(), get_bloginfo('name')),
                'immutable' => true,
                'installments' => 1,
                'extra_tags' => ['checkout', 'boleto'],
            ]); // -> InvoiceResponse

            // 3) Extrai o primeiro pagamento BANK_SLIP (payable)
            $boleto = InvoiceBankSlipExtractor::firstBankSlipPaymentOrNull($invoice);
            if (!$boleto) {
                throw new \RuntimeException('Não foi possível obter os dados do Boleto (payable) na resposta da fatura.');
            }

            // 4) Persiste metas e (opcional) baixa o PDF
            $persist = InvoiceBankSlipPersister::persist($order, $boleto, true); // true => baixa PDF local

            // Nota administrativa
            $order->add_order_note(sprintf(
                '62Pay: Boleto gerado. Payment ID: %s%s',
                esc_html((string)($boleto['payment_id'] ?? '')),
                !empty($persist['pdf_url']) ? ' | PDF salvo: ' . esc_url($persist['pdf_url']) : ''
            ));

            // 5) Atualiza status e finaliza
            $order->update_status('on-hold', __('Boleto gerado. Aguardando pagamento.', 'wc-62pay'));
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (\Throwable $e) {
            wc_get_logger()->error('[62Pay] Boleto - erro no process_payment', [
                'source' => 'wc-62pay',
                'order_id' => (int)$order_id,
                'message' => $e->getMessage(),
                'code' => (int)$e->getCode(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            wc_add_notice(__('Falha ao gerar boleto. Tente novamente.', 'wc-62pay'), 'error');
            return ['result' => 'failure'];
        }
    }

    /**
     * Renderiza o bloco do boleto nas páginas de obrigado/recibo/visualização do pedido.
     */
    public function append_html_to_thankyou_page($order_id): void
    {
        static $appended = false;

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) return;
        if ($order->get_payment_method() !== $this->id) return;
        if ($appended) return;

        $appended = true;

        // Lê metas gravadas pelo persister
        $id_field = (string)$order->get_meta(InvoiceBankSlipPersister::META_IDENTIFICATION_FIELD); // linha digitável
        $barcode = (string)$order->get_meta(InvoiceBankSlipPersister::META_BARCODE);
        $remote_pdf = (string)$order->get_meta(InvoiceBankSlipPersister::META_BANK_SLIP_URL);
        $local_pdf = (string)$order->get_meta(InvoiceBankSlipPersister::META_BANK_SLIP_PDF_URL);
        $amount = (int)$order->get_meta(InvoiceBankSlipPersister::META_AMOUNT);
        $status = (string)$order->get_meta(InvoiceBankSlipPersister::META_STATUS);

        // Preferir PDF local se existir; normaliza esquema p/ evitar mixed-content
        $pdf_url = $local_pdf !== '' ? $local_pdf : $remote_pdf;
        if ($pdf_url !== '') {
            $pdf_url = set_url_scheme($pdf_url, is_ssl() ? 'https' : 'http');
        }

        echo '<section class="wc-62pay-boleto" style="margin-top:24px">';
        echo '<h3>' . esc_html__('Boleto Bancário', 'wc-62pay') . '</h3>';

        if ($pdf_url !== '') {
            echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url($pdf_url) . '">' .
                esc_html__('Abrir Boleto (PDF)', 'wc-62pay') . '</a></p>';
        }

        if ($id_field !== '') {
            echo '<p><strong>' . esc_html__('Linha digitável:', 'wc-62pay') . '</strong><br/>';
            echo '<code style="word-break:break-all;display:inline-block;padding:6px 8px;background:#f7f7f7;border-radius:4px;">' .
                esc_html($id_field) . '</code></p>';
        }

        if ($barcode !== '') {
            echo '<p><strong>' . esc_html__('Código de barras:', 'wc-62pay') . '</strong><br/>';
            echo '<code style="word-break:break-all;display:inline-block;padding:6px 8px;background:#f7f7f7;border-radius:4px;">' .
                esc_html($barcode) . '</code></p>';
        }

        if ($amount > 0) {
            // Woo helper: format in store currency
            echo '<p><small>' . esc_html__('Valor:', 'wc-62pay') . ' ' . wp_kses_post(wc_price($amount / 100)) . '</small></p>';
        }

        if ($status !== '') {
            echo '<p><small>' . sprintf(
                /* translators: %s = current status */
                    esc_html__('Status do pagamento: %s', 'wc-62pay'),
                    esc_html($status)
                ) . '</small></p>';
        }

        echo '<p>' . esc_html__('Após o pagamento e compensação, seu pedido será atualizado automaticamente.', 'wc-62pay') . '</p>';
        echo '</section>';
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
