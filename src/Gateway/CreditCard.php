<?php

namespace WC62Pay\Gateway;

use GuzzleHttp\Exception\GuzzleException;
use Sixtytwopay\Exceptions\ApiException;
use Sixtytwopay\Inputs\Checkout\CheckoutCreditCardInput;
use WC62Pay\Support\CustomerResolver;
use WC62Pay\Support\InvoiceResolver;
use WC_Order;

if (!defined('ABSPATH')) {
    exit;
}

class CreditCard extends Base
{
    /** -------------------------
     *  Constants / Meta keys
     *  ------------------------- */
    private const META_MAX_INSTALLMENTS = 'installments';
    private const FIELD_INSTALLMENTS = 'wc_62pay_cc_installments';
    private const PAYMENT_METHOD_CODE = 'CREDIT_CARD';
    private const META_DOCUMENT_NUMBER = '_wc_62pay_document_number';

    private int $maxInstallments = 1;

    public function __construct()
    {
        $this->id = 'wc_62pay_cc';
        $this->method_title = __('62Pay – Cartão de Crédito', 'wc-62pay');
        $this->method_description = __('Cobrança via cartão de crédito com checkout transparente.', 'wc-62pay');
        $this->icon = apply_filters('wc_62pay_cc_icon', '');

        parent::__construct();

        $this->maxInstallments = (int)$this->get_option(self::META_MAX_INSTALLMENTS, '1');
        if ($this->maxInstallments < 1) {
            $this->maxInstallments = 1;
        }
        if ($this->maxInstallments > 21) {
            $this->maxInstallments = 21;
        }
    }

    /**
     * @return void
     */
    public function init_form_fields()
    {
        parent::init_form_fields();

        $installments_field = [
            self::META_MAX_INSTALLMENTS => [
                'title' => __('Permitir parcelamento em até', 'wc-62pay'),
                'type' => 'select',
                'default' => '1',
                'options' => $this->buildInstallmentOptions(21),
                'desc_tip' => true,
                'description' => __('Número máximo de parcelas que o cliente poderá escolher no checkout.', 'wc-62pay'),
            ],
        ];

        $fields = [];
        foreach ($this->form_fields as $key => $field) {
            $fields[$key] = $field;
            if ($key === 'environment') {
                $fields = array_merge($fields, $installments_field);
            }
        }
        $this->form_fields = $fields;
    }

    /**
     * @return void
     */
    public function payment_fields()
    {
        parent::payment_fields();

        $installmentOptions = $this->buildInstallmentOptions($this->maxInstallments);

        echo '<div class="form-row form-row-wide">';
        woocommerce_form_field('_wc_62pay_document_number', [
            'type' => 'text',
            'label' => __('CPF ou CNPJ do pagador', 'wc-62pay'),
            'placeholder' => __('Somente números', 'wc-62pay'),
            'required' => true,
            'class' => ['form-row-wide'],
        ], '');
        echo '</div>';

        ?>
        <fieldset id="wc-62pay-cc-fields">
            <p class="form-row form-row-wide">
                <label for="wc-62pay-cc-number">
                    <?php _e('Número do cartão', 'wc-62pay'); ?> <span class="required">*</span>
                </label>
                <input id="wc-62pay-cc-number" name="wc_62pay_cc_number" type="text" autocomplete="off"
                       inputmode="numeric" placeholder="•••• •••• •••• ••••"/>
            </p>

            <p class="form-row form-row-first">
                <label for="wc-62pay-cc-expiry">
                    <?php _e('Validade (MM/AA)', 'wc-62pay'); ?> <span class="required">*</span>
                </label>
                <input id="wc-62pay-cc-expiry" name="wc_62pay_cc_expiry" type="text" autocomplete="off"
                       placeholder="MM/AA"/>
            </p>

            <p class="form-row form-row-last">
                <label for="wc-62pay-cc-cvc">
                    <?php _e('CVC', 'wc-62pay'); ?> <span class="required">*</span>
                </label>
                <input id="wc-62pay-cc-cvc" name="wc_62pay_cc_cvc" type="password" autocomplete="off"
                       placeholder="CVC"/>
            </p>

            <div class="clear"></div>

            <p class="form-row form-row-wide">
                <label for="wc-62pay-cc-holder">
                    <?php _e('Nome impresso no cartão', 'wc-62pay'); ?> <span class="required">*</span>
                </label>
                <input id="wc-62pay-cc-holder" name="wc_62pay_cc_holder" type="text" autocomplete="cc-name"/>
            </p>

            <?php if ($this->maxInstallments > 1): ?>
                <p class="form-row form-row-wide">
                    <label for="<?php echo esc_attr(self::FIELD_INSTALLMENTS); ?>">
                        <?php _e('Parcelas', 'wc-62pay'); ?>
                    </label>
                    <select id="<?php echo esc_attr(self::FIELD_INSTALLMENTS); ?>"
                            name="<?php echo esc_attr(self::FIELD_INSTALLMENTS); ?>">
                        <?php foreach ($installmentOptions as $value => $label): ?>
                            <option value="<?php echo esc_attr($value); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    /**
     * @return bool
     */
    public function validate_fields(): bool
    {
        $required = [
            'wc_62pay_cc_number' => __('Número do cartão é obrigatório.', 'wc-62pay'),
            'wc_62pay_cc_expiry' => __('Validade é obrigatória.', 'wc-62pay'),
            'wc_62pay_cc_cvc' => __('CVC é obrigatório.', 'wc-62pay'),
            'wc_62pay_cc_holder' => __('Nome do portador é obrigatório.', 'wc-62pay'),
        ];

        foreach ($required as $key => $msg) {
            if (empty($_POST[$key])) {
                wc_add_notice($msg, 'error');
                return false;
            }
        }

        $chosenInstallments = isset($_POST[self::FIELD_INSTALLMENTS]) ? (int)$_POST[self::FIELD_INSTALLMENTS] : 1;
        if ($chosenInstallments < 1 || $chosenInstallments > $this->maxInstallments) {
            $_POST[self::FIELD_INSTALLMENTS] = 1;
        }

        return true;
    }

    /**
     * @param $order_id
     * @return array|string[]
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);

        $card = [
            'number' => sanitize_text_field($_POST['wc_62pay_cc_number'] ?? ''),
            'expiry' => sanitize_text_field($_POST['wc_62pay_cc_expiry'] ?? ''),
            'cvc' => sanitize_text_field($_POST['wc_62pay_cc_cvc'] ?? ''),
            'holder' => sanitize_text_field($_POST['wc_62pay_cc_holder'] ?? ''),
        ];

        $installments = isset($_POST[self::FIELD_INSTALLMENTS]) ? (int)$_POST[self::FIELD_INSTALLMENTS] : 1;
        if ($installments < 1 || $installments > $this->maxInstallments) {
            $installments = 1;
        }

        try {
            $customer = CustomerResolver::ensure($order);

            $invoice = InvoiceResolver::ensure($order, $customer->id(), [
                'payment_method' => self::PAYMENT_METHOD_CODE,
                'due_date' => gmdate('Y-m-d'),
                'description' => sprintf('Pedido #%s – %s', $order->get_order_number(), get_bloginfo('name')),
                'immutable' => true,
                'installments' => $installments,
                'extra_tags' => ['checkout', 'credit_card'],
            ]);

            $ccInput = $this->buildCreditCardInput($order, $card, $installments);

            \wc_62pay_client()->checkout()->payWithCreditCard($invoice->id(), $ccInput);

            $order->payment_complete();
            wc_reduce_stock_levels($order_id);

            WC()->cart?->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];

        } catch (ApiException|GuzzleException $e) {
            wc_get_logger()->error('[62Pay] CC - API error', [
                'source' => 'wc-62pay',
                'order_id' => (int)$order_id,
                'message' => $e->getMessage(),
                'code' => (int)$e->getCode(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            $order?->add_order_note('62Pay: falha na cobrança do cartão. ' . $e->getMessage());
            wc_add_notice(__('Falha na comunicação com o processador. Tente novamente.', 'wc-62pay'), 'error');
            return ['result' => 'failure'];

        } catch (\Throwable $e) {
            wc_get_logger()->error('[62Pay] CC - erro inesperado', [
                'source' => 'wc-62pay',
                'order_id' => (int)$order_id,
                'message' => $e->getMessage(),
                'code' => (int)$e->getCode(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            $order?->add_order_note('62Pay: erro inesperado na cobrança do cartão. ' . $e->getMessage());
            wc_add_notice(__('Não foi possível processar o pagamento. Tente novamente.', 'wc-62pay'), 'error');
            return ['result' => 'failure'];
        }
    }

    /* ===========================
     * Internals
     * =========================== */

    private function buildInstallmentOptions(int $max): array
    {
        $opts = ['1' => __('À vista', 'wc-62pay')];
        for ($i = 2; $i <= $max; $i++) {
            $opts[(string)$i] = sprintf(__('%dx', 'wc-62pay'), $i);
        }
        return $opts;
    }

    /**
     * @param WC_Order $order
     * @param array $card
     * @param int $installments
     * @return CheckoutCreditCardInput
     */
    private function buildCreditCardInput(WC_Order $order, array $card, int $installments): CheckoutCreditCardInput
    {
        $billingDoc = $this->resolveDocumentNumberFromRequestOrMeta($order);

        $postal = preg_replace('/\D+/', '', (string)$order->get_billing_postcode());

        $addr1 = trim((string)$order->get_billing_address_1());

        [$addrNumber, $addrStreetNoNumber] = $this->extractAddressParts($addr1);

        $expiry = $this->normalizeExpiry($card['expiry'] ?? '');

        return CheckoutCreditCardInput::fromArray([
            'holder_name' => (string)($card['holder'] ?? ''),
            'number' => (string)($card['number'] ?? ''),
            'card_expiry_date' => $expiry,
            'ccv' => (string)($card['cvc'] ?? ''),
            'installments' => $installments,
            'billing_name' => $order->get_formatted_billing_full_name() ?: null,
            'billing_email' => $order->get_billing_email() ?: null,
            'billing_document_number' => $billingDoc ?: '19068658760',
            'billing_postal_code' => $postal ?: null,
            'billing_address_number' => $addrNumber ?: null,
            'billing_address_complement' => $order->get_billing_address_2() ?: null,
            'billing_phone' => $order->get_billing_phone() ?: null,
        ]);
    }

    /**
     * @param string $raw
     * @return string
     */
    private function normalizeExpiry(string $raw): string
    {
        $raw = trim($raw);
        $raw = str_replace('-', '/', $raw);
        if (!str_contains($raw, '/')) return $raw;

        [$mm, $yy] = array_map('trim', explode('/', $raw, 2));
        if (strlen($yy) === 4) {
            $yy = substr($yy, -2);
        }
        $mm = preg_replace('/\D+/', '', $mm);
        $yy = preg_replace('/\D+/', '', $yy);
        if ($mm !== '' && (int)$mm >= 1 && (int)$mm <= 12 && strlen($yy) === 2) {
            return sprintf('%02d/%s', (int)$mm, $yy);
        }
        return $raw;
    }

    /**
     * @param string $addr1
     * @return array
     */
    private function extractAddressParts(string $addr1): array
    {
        if (preg_match('/^(.*?)[,\s]+(\d+)(?:.*)?$/u', $addr1, $m)) {
            $street = trim($m[1]);
            $num = $m[2];
            return [$num, $street];
        }
        return [null, $addr1];
    }

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
