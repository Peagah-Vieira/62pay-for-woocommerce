<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use Sixtytwopay\Inputs\Invoice\InvoiceCreateInput;
use WC_Order;

final class InvoiceCreateMapper
{
    public static function fromOrder(WC_Order $order, string $customerId, array $opts): InvoiceCreateInput
    {
        $paymentMethod = (string)($opts['payment_method'] ?? '');
        if ($paymentMethod === '') {
            throw new \InvalidArgumentException('payment_method é obrigatório para criar invoice.');
        }

        $amountCents = (int)round($order->get_total() * 100);

        $dueDate = (string)($opts['due_date'] ?? gmdate('Y-m-d'));

        $defaultDesc = sprintf('Pedido #%s – %s', $order->get_order_number(), get_bloginfo('name'));
        $description = (string)($opts['description'] ?? $defaultDesc);

        $installments = isset($opts['installments']) ? (int)$opts['installments'] : null;
        $immutable = isset($opts['immutable']) ? (bool)$opts['immutable'] : null;
        $interestPercent = isset($opts['interest_percent']) ? (int)$opts['interest_percent'] : null;
        $fineType = $opts['fine_type'] ?? null;
        $fineValue = isset($opts['fine_value']) ? (int)$opts['fine_value'] : null;
        $discountType = $opts['discount_type'] ?? null;
        $discountValue = isset($opts['discount_value']) ? (int)$opts['discount_value'] : null;
        $discountDeadline = $opts['discount_deadline'] ?? null;

        $tags = array_values(array_filter(array_merge(
            ['woocommerce', 'order:' . (string)$order->get_id()],
            (array)($opts['extra_tags'] ?? [])
        )));

        return new InvoiceCreateInput(
            customer: $customerId,
            paymentMethod: $paymentMethod,
            amount: $amountCents,
            dueDate: $dueDate,
            description: $description,
            installments: $installments,
            immutable: $immutable,
            interestPercent: $interestPercent,
            fineType: $fineType,
            fineValue: $fineValue,
            discountType: $discountType,
            discountValue: $discountValue,
            discountDeadline: $discountDeadline,
            tags: $tags ?: null
        );
    }
}
