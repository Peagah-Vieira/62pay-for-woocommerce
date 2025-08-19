<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use Sixtytwopay\Responses\InvoiceResponse;

final class InvoicePixExtractor
{
    /**
     * @param InvoiceResponse $invoice
     * @return array|null
     */
    public static function firstPixPaymentOrNull(InvoiceResponse $invoice): ?array
    {
        $payments = $invoice->payments();
        if (!is_array($payments) || count($payments) === 0) {
            return null;
        }

        foreach ($payments as $payment) {
            $method = null;
            if (is_object($payment) && method_exists($payment, 'paymentMethod')) {
                $method = $payment->paymentMethod();
            } elseif (is_array($payment) && isset($payment['payment_method'])) {
                $method = $payment['payment_method'];
            }

            if (strtoupper((string)$method) !== 'PIX') {
                continue;
            }

            $paymentId = self::get($payment, 'id');

            $status = self::get($payment, 'status');
            $amount = self::getInt($payment, 'amount');

            $payable = self::getPayable($payment);

            $qrBase64 = self::get($payable, 'qr_code_base64');
            $copyPaste = self::get($payable, 'copy_paste');
            $expiresAt = self::get($payable, 'expires_at');

            return [
                'payment_id' => (string)$paymentId,
                'status' => $status ? (string)$status : null,
                'amount' => is_int($amount) ? $amount : null,
                'copy_paste' => $copyPaste ? (string)$copyPaste : null,
                'qr_base64' => $qrBase64 ? (string)$qrBase64 : null,
                'expires_at' => $expiresAt ? (string)$expiresAt : null,
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
