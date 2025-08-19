<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use Sixtytwopay\Inputs\Customer\CustomerCreateInput;
use WC_Order;

final class CustomerCreateMapper
{
    public static function map(WC_Order $order): CustomerCreateInput
    {
        // HPOS-safe: leia metas do pedido com $order->get_meta()
        $doc = (string)$order->get_meta('_billing_cpf');
        if ($doc === '') {
            $doc = (string)$order->get_meta('_billing_cnpj');
        }
        $doc = MapHelpers::onlyDigits($doc);

        $type = null;
        if ($doc !== '') {
            $len = strlen($doc);
            $type = $len === 11 ? 'NATURAL' : ($len === 14 ? 'LEGAL' : null);
        }

        $addr1 = trim((string)$order->get_billing_address_1());
        $addressNumber = MapHelpers::extractAddressNumber($addr1);
        $streetNoNumber = MapHelpers::stripAddressNumber($addr1);

        $neighborhood = (string)$order->get_meta('_billing_neighborhood');
        $neighborhood = $neighborhood !== '' ? $neighborhood : null;

        $fullName = $order->get_formatted_billing_full_name();
        $company = $order->get_billing_company();
        $legalName = $company ?: $fullName;

        $data = [
            'type' => $type ?? 'NATURAL',
            'name' => $fullName ?: null,
            'legal_name' => $legalName ?: null,
            'email' => $order->get_billing_email() ?: null,
            'phone' => $order->get_billing_phone() ?: null,
            'document_number' => $doc ?: null,
            'address' => $streetNoNumber ?: null,
            'complement' => $order->get_billing_address_2() ?: null,
            'address_number' => $addressNumber ?: null,
            'postal_code' => MapHelpers::onlyDigits($order->get_billing_postcode()) ?: null,
            'province' => $neighborhood,
            'state' => $order->get_billing_state() ?: null,
            'city' => $order->get_billing_city() ?: null,
            'tags' => ['woocommerce'],
        ];

        // Use o método correto do seu SDK:
        // - se a classe tiver ->fromArray($data), use fromArray
        // - se tiver ::make($data) (como nos seus exemplos), mantenha:
        return CustomerCreateInput::make($data);
        // return CustomerCreateInput::make($data);
    }
}
