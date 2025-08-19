<?php

declare(strict_types=1);

namespace Woo62Pay\Support;

use GuzzleHttp\Exception\GuzzleException;
use Sixtytwopay\Exceptions\ApiException;
use Sixtytwopay\Responses\InvoiceResponse;
use WC_Order;

/**
 * Resolve/assegura a existência de uma Invoice no 62Pay para um pedido.
 *
 * Estratégia:
 *  1) Lê o ID salvo no meta do pedido; se houver, faz GET para obter o estado atual.
 *  2) Se não existir ID ou o GET falhar, cria uma nova e persiste o ID.
 */
final class InvoiceResolver
{
    /**
     * Meta key para vincular a invoice ao pedido.
     */
    public const META_KEY = '_wc_62pay_invoice';

    /**
     * Atalho que retorna apenas o ID.
     *
     * @throws ApiException|GuzzleException|\RuntimeException
     */
    public static function ensureId(WC_Order $order, string $customerId, array $opts): string
    {
        return self::ensure($order, $customerId, $opts)->id();
    }

    /**
     * Garante uma Invoice e retorna o InvoiceResponse tipado.
     *
     * $opts segue o contrato do InvoiceCreateMapper::fromOrder (ver mapper).
     *
     * @throws ApiException|GuzzleException|\RuntimeException
     */
    public static function ensure(WC_Order $order, string $customerId, array $opts): InvoiceResponse
    {
        $logger = wc_get_logger();
        $service = \wc_62pay_client()->invoice();

        // 1) Tenta reaproveitar a invoice salva no pedido
        $savedId = (string)get_post_meta($order->get_id(), self::META_KEY, true);
        if ($savedId !== '') {
            try {
                $invoice = $service->get($savedId); // -> InvoiceResponse
                // mantém o snapshot
                self::persistId($order, $invoice->id());
                return $invoice;
            } catch (\Throwable $e) {
                // Pode ser 404/cancelada em provedor: loga e parte para criação
                $logger->warning(
                    sprintf('[62Pay] Invoice ID salvo inválido (%s): %s', $savedId, $e->getMessage()),
                    ['source' => 'wc-62pay']
                );
            }
        }

        // 2) Cria nova invoice
        $input = InvoiceCreateMapper::fromOrder($order, $customerId, $opts);
        $invoice = $service->create($input); // -> InvoiceResponse

        if (!$invoice instanceof InvoiceResponse || $invoice->id() === '') {
            throw new \RuntimeException('Resposta inválida ao criar invoice no 62Pay.');
        }

        self::persistId($order, $invoice->id());

        return $invoice;
    }

    /**
     * Persiste o ID da invoice no meta do pedido.
     */
    private static function persistId(WC_Order $order, string $invoiceId): void
    {
        update_post_meta($order->get_id(), self::META_KEY, $invoiceId);
    }
}
