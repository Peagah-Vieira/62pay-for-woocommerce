<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use GuzzleHttp\Exception\GuzzleException;
use Sixtytwopay\Exceptions\ApiException;
use Sixtytwopay\Responses\CustomerResponse;
use WC_Order;

/**
 * Resolve/assegura a existência do cliente no 62Pay e persiste o ID em WP.
 */
final class CustomerResolver
{
    /**
     * Meta key usada no usuário e snapshot no pedido.
     */
    private const META_KEY = '_wc_62pay_customer';

    /**
     * Atalho: garante cliente e retorna apenas o ID.
     *
     * @throws ApiException|GuzzleException|\RuntimeException
     */
    public static function ensureId(WC_Order $order): string
    {
        return self::ensure($order)->id();
    }

    /**
     * Garante cliente no 62Pay e retorna CustomerResponse tipado.
     *
     * Estratégia:
     *  - Reutiliza ID salvo em meta (usuário/pedido) e faz GET para obter dados atuais;
     *  - Se não houver ID ou GET falhar, cria e persiste o novo ID.
     *
     * @throws ApiException|GuzzleException|\RuntimeException
     */
    public static function ensure(WC_Order $order): CustomerResponse
    {
        $logger = wc_get_logger();
        $service = \wc_62pay_client()->customer();

        // 1) Busca ID salvo (usuário → pedido)
        $savedId = '';

        $userId = (int)$order->get_customer_id();

        if ($userId > 0) {
            $savedId = (string)get_user_meta($userId, self::META_KEY, true);
        }

        if ($savedId === '') {
            $savedId = (string)get_post_meta($order->get_id(), self::META_KEY, true);
        }

        // 2) Se houver ID, tenta obter dados canônicos via API
        if ($savedId !== '') {
            try {
                $customer = $service->get($savedId);
                // Garante snapshot no pedido:
                self::persistIds($order, $customer->id());
                return $customer;
            } catch (\Throwable $e) {
                // 404/expirado/qualquer falha: loga e cai para criação
                $logger->warning(
                    sprintf('[62Pay] Customer ID salvo inválido (%s): %s', $savedId, $e->getMessage()),
                    ['source' => 'wc-62pay']
                );
            }
        }

        // 3) Não tinha ID válido → cria no 62Pay
        $input = CustomerCreateMapper::map($order);

        $customer = $service->create($input); // -> CustomerResponse

        if ($customer->id() === '') {
            throw new \RuntimeException('Resposta inválida ao criar cliente no 62Pay.');
        }

        self::persistIds($order, $customer->id());

        return $customer;
    }

    /**
     * Persiste o ID no usuário (quando houver) e sempre no pedido (snapshot).
     */
    private static function persistIds(WC_Order $order, string $customerId): void
    {
        $userId = (int)$order->get_customer_id();
        if ($userId > 0) {
            update_user_meta($userId, self::META_KEY, $customerId);
        }

        update_post_meta($order->get_id(), self::META_KEY, $customerId);
    }
}
