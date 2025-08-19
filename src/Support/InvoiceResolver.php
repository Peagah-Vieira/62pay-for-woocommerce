<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use GuzzleHttp\Exception\GuzzleException;
use Sixtytwopay\Exceptions\ApiException;
use Sixtytwopay\Responses\InvoiceResponse;
use WC_Order;

final class InvoiceResolver
{
    public const META_KEY = '_wc_62pay_invoice';

    /**
     * @param WC_Order $order
     * @param string $customerId
     * @param array $opts
     * @return string
     * @throws ApiException
     * @throws GuzzleException
     */
    public static function ensureId(WC_Order $order, string $customerId, array $opts): string
    {
        return self::ensure($order, $customerId, $opts)->id();
    }

    /**
     * @param WC_Order $order
     * @param string $customerId
     * @param array $opts
     * @return InvoiceResponse
     * @throws ApiException
     * @throws GuzzleException
     */
    public static function ensure(WC_Order $order, string $customerId, array $opts): InvoiceResponse
    {
        $logger = wc_get_logger();
        $service = \wc_62pay_client()->invoice();

        $savedId = (string)get_post_meta($order->get_id(), self::META_KEY, true);
        if ($savedId !== '') {
            try {
                $invoice = $service->get($savedId);
                self::persistId($order, $invoice->id());
                return $invoice;
            } catch (\Throwable $e) {
                $logger->warning(
                    sprintf('[62Pay] Invoice ID salvo inválido (%s): %s', $savedId, $e->getMessage()),
                    ['source' => 'wc-62pay']
                );
            }
        }

        $input = InvoiceCreateMapper::fromOrder($order, $customerId, $opts);
        $invoice = $service->create($input);

        if (!$invoice instanceof InvoiceResponse || $invoice->id() === '') {
            throw new \RuntimeException('Resposta inválida ao criar invoice no 62Pay.');
        }

        self::persistId($order, $invoice->id());

        return $invoice;
    }

    /**
     * @param WC_Order $order
     * @param string $invoiceId
     * @return void
     */
    private static function persistId(WC_Order $order, string $invoiceId): void
    {
        update_post_meta($order->get_id(), self::META_KEY, $invoiceId);
    }
}
