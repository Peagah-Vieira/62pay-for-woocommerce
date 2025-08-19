<?php

namespace WC62Pay\Gateway;

if (!defined('ABSPATH')) {
    exit;
}

class CreditCard extends Base
{

    public function __construct()
    {
        $this->id = 'wc_62pay_cc';
        $this->method_title = __('62Pay – Cartão de Crédito', 'wc-62pay');
        $this->method_description = __('Cobrança via cartão de crédito com checkout transparente.', 'wc-62pay');
        $this->icon = apply_filters('wc_62pay_cc_icon', '');
        parent::__construct();

        $this->installments = $this->get_option('installments', '1');
    }

    public function init_form_fields()
    {
        parent::init_form_fields();

        // Adicionar o campo de parcelamento específico para cartão de crédito
        $installments_field = array(
            'installments' => array(
                'title' => __('Permitir parcelamento em até', 'wc-62pay'),
                'type' => 'select',
                'default' => '1',
                'options' => array(
                    '1' => __('À vista', 'wc-62pay'),
                    '2' => __('2x', 'wc-62pay'),
                    '3' => __('3x', 'wc-62pay'),
                    '4' => __('4x', 'wc-62pay'),
                    '5' => __('5x', 'wc-62pay'),
                    '6' => __('6x', 'wc-62pay'),
                    '7' => __('7x', 'wc-62pay'),
                    '8' => __('8x', 'wc-62pay'),
                    '9' => __('9x', 'wc-62pay'),
                    '10' => __('10x', 'wc-62pay'),
                    '11' => __('11x', 'wc-62pay'),
                    '12' => __('12x', 'wc-62pay'),
                    '13' => __('13x', 'wc-62pay'),
                    '14' => __('14x', 'wc-62pay'),
                    '15' => __('15x', 'wc-62pay'),
                    '16' => __('16x', 'wc-62pay'),
                    '17' => __('17x', 'wc-62pay'),
                    '18' => __('18x', 'wc-62pay'),
                    '19' => __('19x', 'wc-62pay'),
                    '20' => __('20x', 'wc-62pay'),
                    '21' => __('21x', 'wc-62pay')
                ),
            ),
        );

        // Inserir o campo de parcelamento após o campo 'environment'
        $fields = array();
        foreach ($this->form_fields as $key => $field) {
            $fields[$key] = $field;
            if ($key === 'environment') {
                $fields = array_merge($fields, $installments_field);
            }
        }
        $this->form_fields = $fields;
    }

    public function payment_fields()
    {
        parent::payment_fields();
        ?>
        <fieldset id="wc-62pay-cc-fields">
            <p class="form-row form-row-wide">
                <label for="wc-62pay-cc-number"><?php _e('Número do cartão', 'wc-62pay'); ?> <span
                            class="required">*</span></label>
                <input id="wc-62pay-cc-number" name="wc_62pay_cc_number" type="text" autocomplete="off"
                       inputmode="numeric" placeholder="•••• •••• •••• ••••"/>
            </p>
            <p class="form-row form-row-first">
                <label for="wc-62pay-cc-expiry"><?php _e('Validade (MM/AA)', 'wc-62pay'); ?> <span
                            class="required">*</span></label>
                <input id="wc-62pay-cc-expiry" name="wc_62pay_cc_expiry" type="text" autocomplete="off"
                       placeholder="MM/AA"/>
            </p>
            <p class="form-row form-row-last">
                <label for="wc-62pay-cc-cvc"><?php _e('CVC', 'wc-62pay'); ?> <span class="required">*</span></label>
                <input id="wc-62pay-cc-cvc" name="wc_62pay_cc_cvc" type="password" autocomplete="off"
                       placeholder="CVC"/>
            </p>
            <div class="clear"></div>
            <p class="form-row form-row-wide">
                <label for="wc-62pay-cc-holder"><?php _e('Nome impresso no cartão', 'wc-62pay'); ?> <span
                            class="required">*</span></label>
                <input id="wc-62pay-cc-holder" name="wc_62pay_cc_holder" type="text" autocomplete="cc-name"/>
            </p>
        </fieldset>
        <?php
    }

    public function validate_fields()
    {
        $required = array(
            'wc_62pay_cc_number' => __('Número do cartão é obrigatório.', 'wc-62pay'),
            'wc_62pay_cc_expiry' => __('Validade é obrigatória.', 'wc-62pay'),
            'wc_62pay_cc_cvc' => __('CVC é obrigatório.', 'wc-62pay'),
            'wc_62pay_cc_holder' => __('Nome do portador é obrigatório.', 'wc-62pay'),
        );
        foreach ($required as $key => $msg) {
            if (empty($_POST[$key])) {
                wc_add_notice($msg, 'error');
                return false;
            }
        }
        return true;
    }

    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        // Em produção, tokenizar no front-end (não envie PAN ao servidor).
        $card = array(
            'number' => sanitize_text_field($_POST['wc_62pay_cc_number'] ?? ''),
            'expiry' => sanitize_text_field($_POST['wc_62pay_cc_expiry'] ?? ''),
            'cvc' => sanitize_text_field($_POST['wc_62pay_cc_cvc'] ?? ''),
            'holder' => sanitize_text_field($_POST['wc_62pay_cc_holder'] ?? ''),
        );

        try {
            $customer = $order->get_customer_id();
            // validar se já foi cadastrado no 62pay
            $_pay_customer = get_user_meta($customer, '_wc_62pay_customer', true);

            if (!$_pay_customer) {
                // criar cliente no 62pay

                update_user_meta($customer, '_wc_62pay_customer', $_pay_customer['id']);
            }

            // processa daoos do pagamento
            $order->save_meta_data('_wc_62pay_payemnt_id', '');


            $client = $this->get_client();
            $charge = $client->create_charge($order, 'credit_card', array('card' => $card, 'soft_descriptor' => $this->soft_descriptor));

            if ($charge['status'] === 'approved') {
                $order->payment_complete($charge['id']);
                $order->add_order_note('Pagamento aprovado (cartão). ID: ' . $charge['id']);
                \WC()->cart->empty_cart();
                return array('result' => 'success', 'redirect' => $this->get_return_url($order));
            }

            if ($charge['status'] === 'pending') {
                $order->update_status('on-hold', 'Pagamento pendente (cartão).');
                return array('result' => 'success', 'redirect' => $this->get_return_url($order));
            }

            throw new \Exception('Transação recusada.');
        } catch (\Exception $e) {
            wc_add_notice('Falha ao processar pagamento: ' . $e->getMessage(), 'error');
            return array('result' => 'failure');
        }
    }
}
