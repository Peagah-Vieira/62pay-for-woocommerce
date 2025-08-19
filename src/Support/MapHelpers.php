<?php

declare(strict_types=1);

namespace Woo62Pay\Support;

final class MapHelpers
{
    public static function onlyDigits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string)$value) ?? '';
    }

    /**
     * Extrai o número do endereço (ex.: "Rua tal, 123A apt 45" -> "123A")
     */
    public static function extractAddressNumber(string $addressLine1): ?string
    {
        if (preg_match('/\b(\d+[A-Za-z0-9\-]*)\b/u', $addressLine1, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Remove o número do endereço mantendo apenas a rua/logradouro.
     */
    public static function stripAddressNumber(string $addressLine1): string
    {
        $addr = preg_replace('/\b\d+[A-Za-z0-9\-]*\b/u', '', $addressLine1);
        return trim(preg_replace('/\s{2,}/', ' ', (string)$addr));
    }
}
