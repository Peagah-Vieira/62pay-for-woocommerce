<?php

namespace WC62Pay\Gateway;

use GuzzleHttp\Exception\GuzzleException;
use Sixtytwopay\Exceptions\ApiException;
use Sixtytwopay\Inputs\Customer\CustomerUpdateInput;
use Sixtytwopay\Responses\InvoiceResponse;
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
    /** -------------------------
     *  Constants / Meta keys
     *  ------------------------- */
    private const META_DOCUMENT_NUMBER = '_wc_62pay_document_number';

    private const PAYMENT_METHOD_CODE = 'PIX';

    private static bool $rendered = false;

    public function __construct()
    {
        $this->id = 'wc_62pay_pix';
        $this->method_title = __('62Pay – Pix', 'wc-62pay');
        $this->method_description = __('Cobrança via Pix com QR Code e Copia e Cola.', 'wc-62pay');
        $this->icon = apply_filters('wc_62pay_pix_icon', '');

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

            $invoice = $this->ensureInvoiceWithPix($order, $customer->id());

            $pix = $this->extractPixPayable($invoice);

            $persist = $this->persistPix($order, $pix, true);

            $order->add_order_note(sprintf(
                '62Pay: PIX gerado. Payment ID: %s%s',
                esc_html((string)($pix['payment_id'] ?? '')),
                !empty($persist['qr_png_url']) ? ' | QR salvo: ' . esc_url($persist['qr_png_url']) : ''
            ));

            $order->update_status('on-hold', __('Aguardando pagamento Pix.', 'wc-62pay'));
            $order->save();

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (\Throwable $e) {
            wc_get_logger()->error('[62Pay] PIX - erro no process_payment', [
                'source' => 'wc-62pay',
                'order_id' => (int)$order_id,
                'message' => $e->getMessage(),
                'code' => (int)$e->getCode(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            $order?->add_order_note('62Pay: falha ao gerar PIX. ' . $e->getMessage());

            wc_add_notice(__('Falha ao gerar cobrança Pix. Tente novamente.', 'wc-62pay'), 'error');
            return ['result' => 'failure'];
        }
    }

    /**
     * @param $order_id
     * @return void
     */
    public function append_html_to_thankyou_page($order_id): void
    {
        if (self::$rendered) return;

        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) return;
        if ($order->get_payment_method() !== $this->id) return;

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

        $raw = isset($_POST['_wc_62pay_document_number'])
            ? wc_clean(wp_unslash($_POST['_wc_62pay_document_number']))
            : '';

        $doc = $this->normalize_and_validate_document($raw);

        if ($doc === '') {
            wc_add_notice(__('Informe um CPF (11 dígitos) ou CNPJ (14 dígitos) válido.', 'wc-62pay'), 'error');
            return false;
        }

        $_POST['_wc_62pay_document_number'] = $doc;
        return true;
    }

    /**
     * @return void
     */
    public function payment_fields()
    {
        parent::payment_fields();

        echo '<div class="form-row form-row-wide">';
        woocommerce_form_field('_wc_62pay_document_number', [
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
        $doc = isset($_POST['_wc_62pay_document_number'])
            ? wc_clean(wp_unslash($_POST['_wc_62pay_document_number']))
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
     * @throws ApiException
     * @throws GuzzleException
     */
    private function ensureInvoiceWithPix(WC_Order $order, string $customerId): InvoiceResponse
    {
        return InvoiceResolver::ensure($order, $customerId, [
            'payment_method' => self::PAYMENT_METHOD_CODE,
            'due_date' => gmdate('Y-m-d'),
            'description' => sprintf('Pedido #%s – %s', $order->get_order_number(), get_bloginfo('name')),
            'immutable' => true,
            'installments' => 1,
            'extra_tags' => ['checkout', 'pix'],
        ]);
    }

    /**
     * @param $invoice
     * @return array
     */
    private function extractPixPayable($invoice): array
    {
        $pix = InvoicePixExtractor::firstPixPaymentOrNull($invoice);
        if (!$pix) {
            throw new \RuntimeException('Não foi possível obter os dados do PIX (payable) na resposta da fatura.');
        }
        return $pix;
    }

    /**
     * @param WC_Order $order
     * @param array $pix
     * @param bool $savePng
     * @return array
     */
    private function persistPix(WC_Order $order, array $pix, bool $savePng = true): array
    {
        return InvoicePixPersister::persist($order, $pix, $savePng);
    }

    /**
     * @param WC_Order $order
     * @return void
     */
    private function renderThankyouSection(WC_Order $order): void
    {
        $qr_png_url = (string)$order->get_meta(InvoicePixPersister::META_QR_PNG_URL);
        $qr_base64 = (string)$order->get_meta(InvoicePixPersister::META_QR_BASE64);
        $copy_paste = (string)$order->get_meta(InvoicePixPersister::META_COPY_PASTE);
        $expires_at = (string)$order->get_meta(InvoicePixPersister::META_EXPIRES_AT);
        $status = (string)$order->get_meta(InvoicePixPersister::META_STATUS);

        echo '<section class="wc-62pay-pix" style="margin-top:24px">';
        echo '<h3>' . esc_html__('Pagamento via Pix', 'wc-62pay') . '</h3>';

        if ($qr_png_url !== '') {
            echo '<p>' . esc_html__('Escaneie o QR Code abaixo para pagar:', 'wc-62pay') . '</p>';
            echo '<img style="max-width:260px;height:auto;border:1px solid #eee;padding:8px" src="' . esc_url($qr_png_url) . '" alt="QR Code Pix" />';
        } elseif ($qr_base64 !== '') {
            $src = 'data:image/png;base64,' . $qr_base64;
            echo '<p>' . esc_html__('Escaneie o QR Code abaixo para pagar:', 'wc-62pay') . '</p>';
            echo '<img style="max-width:260px;height:auto;border:1px solid #eee;padding:8px" src="' . esc_attr($src) . '" alt="QR Code Pix" />';
        }

        if ($copy_paste !== '') {
            echo '<p><strong>' . esc_html__('Copia e Cola:', 'wc-62pay') . '</strong><br/>';
            echo '<code style="word-break:break-all;display:inline-block;padding:6px 8px;background:#f7f7f7;border-radius:4px;">' .
                esc_html($copy_paste) . '</code></p>';
        }

        if ($expires_at !== '') {
            echo '<p><em>' . sprintf(
                    esc_html__('Expira em: %s', 'wc-62pay'),
                    esc_html($expires_at)
                ) . '</em></p>';
        }

        if ($status !== '') {
            echo '<p><small>' . sprintf(
                    esc_html__('Status do pagamento: %s', 'wc-62pay'),
                    esc_html($status)
                ) . '</small></p>';
        }

        echo '<p>' . esc_html__('Assim que o pagamento for confirmado, seu pedido será atualizado automaticamente.', 'wc-62pay') . '</p>';
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
