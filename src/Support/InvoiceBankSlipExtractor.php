<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use Sixtytwopay\Responses\InvoiceResponse;

/**
 * Utilitário para extrair o primeiro pagamento BANK_SLIP (Boleto) da invoice.
 */
final class InvoiceBankSlipExtractor
{
    /**
     * Retorna um array normalizado com os dados do primeiro pagamento BANK_SLIP ou null.
     *
     * [
     *   'payment_id'            => string,
     *   'status'                => string|null,
     *   'amount'                => int|null,         // em centavos
     *   'identification_field'  => string|null,      // linha digitável
     *   'bank_slip_number'      => string|null,
     *   'barcode'               => string|null,
     *   'bank_slip_url'         => string|null,      // URL do PDF do boleto
     * ]
     */
    public static function firstBankSlipPaymentOrNull(InvoiceResponse $invoice): ?array
    {
        $payments = $invoice->payments();
        if (!is_array($payments) || count($payments) === 0) {
            return null;
        }

        foreach ($payments as $payment) {
            // detectar método
            $method = null;
            if (is_object($payment) && method_exists($payment, 'paymentMethod')) {
                $method = $payment->paymentMethod();
            } elseif (is_array($payment) && isset($payment['payment_method'])) {
                $method = $payment['payment_method'];
            }

            $method = strtoupper((string)$method);
            if ($method !== 'BANK_SLIP' && $method !== 'BOLETO') {
                continue;
            }

            $paymentId = self::get($payment, 'id');
            $status = self::get($payment, 'status');
            $amount = self::getInt($payment, 'amount');

            $payable = self::getPayable($payment);

            $identificationField = self::get($payable, 'identification_field'); // linha digitável
            $bankSlipNumber = self::get($payable, 'bank_slip_number');
            $barcode = self::get($payable, 'barcode');
            $bankSlipUrl = self::get($payable, 'bank_slip_url');

            return [
                'payment_id' => (string)$paymentId,
                'status' => $status ? (string)$status : null,
                'amount' => is_int($amount) ? $amount : null,
                'identification_field' => $identificationField ? (string)$identificationField : null,
                'bank_slip_number' => $bankSlipNumber ? (string)$bankSlipNumber : null,
                'barcode' => $barcode ? (string)$barcode : null,
                'bank_slip_url' => $bankSlipUrl ? (string)$bankSlipUrl : null,
            ];
        }

        return null;
    }

    private static function get($objOrArr, string $key)
    {
        if (is_object($objOrArr)) {
            $camel = self::toCamel($key);
            if (method_exists($objOrArr, $camel)) {
                return $objOrArr->{$camel}();
            }
            if (property_exists($objOrArr, $key)) {
                return $objOrArr->{$key};
            }
        } elseif (is_array($objOrArr)) {
            return $objOrArr[$key] ?? null;
        }
        return null;
    }

    private static function getInt($objOrArr, string $key): ?int
    {
        $v = self::get($objOrArr, $key);
        return is_numeric($v) ? (int)$v : null;
    }

    private static function getPayable($payment)
    {
        if (is_object($payment)) {
            if (method_exists($payment, 'payable')) {
                return $payment->payable();
            }
            if (property_exists($payment, 'payable')) {
                return $payment->payable;
            }
        } elseif (is_array($payment)) {
            return $payment['payable'] ?? null;
        }
        return null;
    }

    private static function toCamel(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $snake)));
    }
}
