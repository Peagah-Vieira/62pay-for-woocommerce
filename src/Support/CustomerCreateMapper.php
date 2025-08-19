<?php

declare(strict_types=1);

namespace Woo62Pay\Support;

use Sixtytwopay\Inputs\Customer\CustomerCreateInput;
use WC_Order;

final class CustomerCreateMapper
{
    public static function map(WC_Order $order): CustomerCreateInput
    {
        $doc = (string)get_post_meta($order->get_id(), '_billing_cpf', true);
        if (!$doc) {
            $doc = (string)get_post_meta($order->get_id(), '_billing_cnpj', true);
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

        $neighborhood = (string)get_post_meta($order->get_id(), '_billing_neighborhood', true);
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

        return CustomerCreateInput::make($data);
    }
}
