<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use GuzzleHttp\Exception\GuzzleException;
use Sixtytwopay\Exceptions\ApiException;
use Sixtytwopay\Inputs\Customer\CustomerUpdateInput;
use Sixtytwopay\Responses\CustomerResponse;
use WC_Order;

final class CustomerResolver
{
    private const META_KEY = '_wc_62pay_customer';

    /**
     * @param WC_Order $order
     * @return string
     */
    private static function resolveOrderDocument(WC_Order $order): string
    {
        $doc = (string)$order->get_meta('wc_62pay_document_number');

        if ($doc === '') $doc = (string)$order->get_meta('_billing_cpf');
        if ($doc === '') $doc = (string)$order->get_meta('_billing_cnpj');

        return MapHelpers::onlyDigits($doc);
    }

    private static function docType(?string $doc): ?string
    {
        $len = strlen((string)$doc);
        return $len === 11 ? 'NATURAL' : ($len === 14 ? 'LEGAL' : null);
    }

    /**
     * @param string $customerId
     * @param WC_Order $order
     * @return void
     */
    private static function tryUpdateDocument(string $customerId, WC_Order $order): void
    {
        $doc = self::resolveOrderDocument($order);
        if ($doc === '') return;

        $type = self::docType($doc) ?? 'NATURAL';

        $payload = CustomerUpdateInput::fromArray([
            'document_number' => $doc,
            'type' => $type,
        ]);

        if (!array_key_exists('document_number', $payload->toPayload())) return;

        try {
            \wc_62pay_client()
                ->customer()
                ->update($customerId, $payload);
        } catch (\Throwable $e) {
            wc_get_logger()->warning(
                sprintf('[62Pay] Falha ao atualizar document_number do cliente %s: %s', $customerId, $e->getMessage()),
                ['source' => 'wc-62pay', 'order_id' => (int)$order->get_id()]
            );
        }
    }

    /**
     * @param WC_Order $order
     * @return string
     * @throws ApiException
     * @throws GuzzleException
     */
    public static function ensureId(WC_Order $order): string
    {
        return self::ensure($order)->id();
    }

    /**
     * Garante cliente e **sincroniza o document_number** via update quando aplicável.
     *
     * @throws ApiException|GuzzleException|\RuntimeException
     */
    public static function ensure(WC_Order $order): CustomerResponse
    {
        $logger = wc_get_logger();
        $service = \wc_62pay_client()->customer();

        $savedId = '';
        $userId = (int)$order->get_customer_id();

        if ($userId > 0) {
            $savedId = (string)get_user_meta($userId, self::META_KEY, true);
        }
        if ($savedId === '') {
            $savedId = (string)get_post_meta($order->get_id(), self::META_KEY, true);
        }

        if ($savedId !== '') {
            try {
                $customer = $service->get($savedId);
                self::persistIds($order, $customer->id());

                self::tryUpdateDocument($customer->id(), $order);

                return $customer;
            } catch (\Throwable $e) {
                $logger->warning(
                    sprintf('[62Pay] Customer ID salvo inválido (%s): %s', $savedId, $e->getMessage()),
                    ['source' => 'wc-62pay']
                );
            }
        }

        $input = CustomerCreateMapper::map($order);
        $customer = $service->create($input);

        if ($customer->id() === '') {
            throw new \RuntimeException('Resposta inválida ao criar cliente no 62Pay.');
        }

        self::persistIds($order, $customer->id());

        self::tryUpdateDocument($customer->id(), $order);

        return $customer;
    }

    private static function persistIds(WC_Order $order, string $customerId): void
    {
        $userId = (int)$order->get_customer_id();
        if ($userId > 0) {
            update_user_meta($userId, self::META_KEY, $customerId);
        }
        update_post_meta($order->get_id(), self::META_KEY, $customerId);
    }
}
