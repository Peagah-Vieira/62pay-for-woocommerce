<?php
declare(strict_types=1);

namespace WC62Pay\Support;

use Sixtytwopay\Inputs\Customer\CustomerUpdateInput;
use WC_Order;

final class CustomerUpdateMapper
{
    public static function map(WC_Order $order, ?string $forcedDoc = null): CustomerUpdateInput
    {
        $doc = MapHelpers::onlyDigits($forcedDoc ?: (string)$order->get_meta('_wc_62pay_pix_document_number'));
        if ($doc === '') $doc = MapHelpers::onlyDigits((string)$order->get_meta('_billing_cpf'));
        if ($doc === '') $doc = MapHelpers::onlyDigits((string)$order->get_meta('_billing_cnpj'));

        $type = null;
        if ($doc !== '') {
            $len = strlen($doc);
            $type = $len === 11 ? 'NATURAL' : ($len === 14 ? 'LEGAL' : null);
        }

        return CustomerUpdateInput::fromArray([
            'document_number' => $doc ?: null,
            'type' => $type,
        ]);
    }
}
