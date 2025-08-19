<?php

namespace WC62Pay\Gateway;

use GuzzleHttp\Exception\GuzzleException;
use Sixtytwopay\Exceptions\ApiException;
use Sixtytwopay\Inputs\Customer\CustomerUpdateInput;
use Sixtytwopay\Responses\InvoiceResponse;
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
    /** -------------------------
     *  Constants / Meta keys
     *  ------------------------- */
    private const META_DOCUMENT_NUMBER = 'wc_62pay_document_number';

    private const PAYMENT_METHOD_CODE = 'BANK_SLIP';

    private static bool $rendered = false;

    public function __construct()
    {
        $this->id = 'wc_62pay_bankslip';
        $this->method_title = __('62Pay – Boleto', 'wc-62pay');
        $this->method_description = __('Geração de boleto bancário.', 'wc-62pay');
        $this->icon = apply_filters('wc_62pay_bankslip_icon', '');

        parent::__construct();

        add_action('woocommerce_thankyou_' . $this->id, [$this, 'append_html_to_thankyou_page']);
        add_action('woocommerce_receipt_' . $this->id, [$this, 'append_html_to_thankyou_page']);
        add_action('woocommerce_view_order', [$this, 'append_html_to_thankyou_page']);
    }

    /**
     * @param $order_id
     * @return array|string[]
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        try {
            $customer = CustomerResolver::ensure($order);

            $doc = $this->resolveDocumentNumberFromRequestOrMeta($order);

            if (!empty($doc)) {
                $this->updateCustomerDocument($customer->id(), $doc);
            }

            $invoice = $this->ensureInvoiceWithBankSlip($order, $customer->id());

            $bankSlip = $this->extractBankSlipPayable($invoice);

            $persist = $this->persistBankSlip($order, $bankSlip);

            $order->add_order_note(sprintf(
                '62Pay: Boleto gerado. Payment ID: %s%s',
                esc_html((string)($bankSlip['payment_id'] ?? '')),
                !empty($persist['pdf_url']) ? ' | PDF salvo: ' . esc_url($persist['pdf_url']) : ''
            ));

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
     * @param $order_id
     * @return void
     */
    public function append_html_to_thankyou_page($order_id): void
    {
        if (self::$rendered) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }
        if ($order->get_payment_method() !== $this->id) {
            return;
        }

        self::$rendered = true;

        $this->renderThankyouSection($order);
    }

    /**
     * @return bool
     */
    public function validate_fields(): bool
    {
        $chosen = isset($_POST['payment_method']) ? wc_clean(wp_unslash($_POST['payment_method'])) : '';
        if ($this->id !== $chosen) {
            return true;
        }

        $raw = isset($_POST['wc_62pay_document_number'])
            ? wc_clean(wp_unslash($_POST['wc_62pay_document_number']))
            : '';

        $doc = $this->normalize_and_validate_document($raw);
        if ($doc === '') {
            wc_add_notice(__('Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.', 'wc-62pay'), 'error');
            return false;
        }

        $_POST['wc_62pay_document_number'] = $doc;
        return true;
    }

    /**
     * @return void
     */
    public function payment_fields(): void
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

    /* ===========================
     * Internals (helpers)
     * =========================== */

    /**
     * @param WC_Order $order
     * @return string
     */
    private function resolveDocumentNumberFromRequestOrMeta(WC_Order $order): string
    {
        $doc = isset($_POST['wc_62pay_document_number'])
            ? wc_clean(wp_unslash($_POST['wc_62pay_document_number']))
            : '';

        $doc = $this->normalize_and_validate_document($doc);

        if ($doc === '') {
            $doc = (string)$order->get_meta(self::META_DOCUMENT_NUMBER);
            $doc = $this->normalize_and_validate_document($doc);
        } else {
            $order->update_meta_data(self::META_DOCUMENT_NUMBER, $doc);
            if (strlen($doc) === 11) {
                $order->update_meta_data('_billing_cpf', $doc);
            } elseif (strlen($doc) === 14) {
                $order->update_meta_data('_billing_cnpj', $doc);
            }
            $order->save();
        }

        return $doc;
    }

    /**
     * @param string $customerId
     * @param string $doc
     * @return void
     * @throws ApiException
     * @throws GuzzleException
     */
    private function updateCustomerDocument(string $customerId, string $doc): void
    {
        $type = (strlen($doc) === 14) ? 'LEGAL' : 'NATURAL';

        $payload = CustomerUpdateInput::fromArray([
            'document_number' => $doc,
            'type' => $type,
        ]);

        \wc_62pay_client()->customer()->update($customerId, $payload);
    }

    /**
     * @param WC_Order $order
     * @param string $customerId
     * @return InvoiceResponse
     * @throws GuzzleException
     * @throws ApiException
     *
     */
    private function ensureInvoiceWithBankSlip(WC_Order $order, string $customerId): InvoiceResponse
    {
        return InvoiceResolver::ensure($order, $customerId, [
            'payment_method' => self::PAYMENT_METHOD_CODE,
            'due_date' => gmdate('Y-m-d'),
            'description' => sprintf('Pedido #%s – %s', $order->get_order_number(), get_bloginfo('name')),
            'immutable' => true,
            'installments' => 1,
            'extra_tags' => ['checkout', 'boleto'],
        ]);
    }

    /**
     * @param $invoice
     * @return array
     */
    private function extractBankSlipPayable($invoice): array
    {
        $bankSlip = InvoiceBankSlipExtractor::firstBankSlipPaymentOrNull($invoice);

        if (!$bankSlip) {
            throw new \RuntimeException('Não foi possível obter os dados do Boleto (payable) na resposta da fatura.');
        }
        return $bankSlip;
    }

    /**
     * @param WC_Order $order
     * @param array $boleto
     * @return array
     */
    private function persistBankSlip(WC_Order $order, array $boleto): array
    {
        return InvoiceBankSlipPersister::persist($order, $boleto);
    }

    /**
     * @param WC_Order $order
     * @return void
     */
    private function renderThankyouSection(WC_Order $order): void
    {
        $id_field = (string)$order->get_meta(InvoiceBankSlipPersister::META_IDENTIFICATION_FIELD); // linha digitável
        $barcode = (string)$order->get_meta(InvoiceBankSlipPersister::META_BARCODE);
        $remotePdf = (string)$order->get_meta(InvoiceBankSlipPersister::META_BANK_SLIP_URL);
        $localPdf = (string)$order->get_meta(InvoiceBankSlipPersister::META_BANK_SLIP_PDF_URL);
        $amount = (int)$order->get_meta(InvoiceBankSlipPersister::META_AMOUNT);
        $status = (string)$order->get_meta(InvoiceBankSlipPersister::META_STATUS);

        $pdf_url = $localPdf !== '' ? $localPdf : $remotePdf;
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

    /* ===========================
     * CPF/CNPJ validation
     * =========================== */

    /**
     * @param string $cpf
     * @return bool
     */
    private function is_valid_cpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D+/', '', $cpf ?? '');
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += (int)$cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ((int)$cpf[$t] !== $d) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $cnpj
     * @return bool
     */
    private function is_valid_cnpj(string $cnpj): bool
    {
        $cnpj = preg_replace('/\D+/', '', $cnpj ?? '');
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $nums = array_map('intval', str_split($cnpj));

        $calc = function (array $base, array $weights): int {
            $sum = 0;
            foreach ($base as $i => $n) {
                $sum += $n * $weights[$i];
            }
            $r = $sum % 11;
            return ($r < 2) ? 0 : 11 - $r;
        };

        $dig1 = $calc(array_slice($nums, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        $baseWithDig1 = array_merge(array_slice($nums, 0, 12), [$dig1]);
        $dig2 = $calc($baseWithDig1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $nums[12] === $dig1 && $nums[13] === $dig2;
    }

    /**
     * @param string|null $raw
     * @return string
     */
    private function normalize_and_validate_document(?string $raw): string
    {
        $doc = preg_replace('/\D+/', '', (string)$raw);
        if (strlen($doc) === 11 && $this->is_valid_cpf($doc)) return $doc;
        if (strlen($doc) === 14 && $this->is_valid_cnpj($doc)) return $doc;
        return '';
    }
}
