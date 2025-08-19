<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use WC_Order;

/**
 * Persiste dados do pagamento PIX (payable) no pedido e opcionalmente salva a imagem do QR.
 */
final class InvoicePixPersister
{
    // Metas padrão (ajuste se preferir outros nomes)
    public const META_PAYMENT_ID = '_wc_62pay_pix_payment_id';
    public const META_STATUS = '_wc_62pay_pix_status';
    public const META_AMOUNT = '_wc_62pay_pix_amount';
    public const META_COPY_PASTE = '_wc_62pay_pix_copy_paste';
    public const META_QR_BASE64 = '_wc_62pay_pix_qr_base64';
    public const META_QR_PNG_URL = '_wc_62pay_pix_qr_png_url';
    public const META_EXPIRES_AT = '_wc_62pay_pix_expires_at';

    /**
     * Persiste metas e (se $savePng === true) grava o QR como PNG em uploads/62pay/.
     *
     * @return array{qr_png_url:?string} Informações úteis de retorno
     */
    public static function persist(WC_Order $order, array $pixData, bool $savePng = true): array
    {
        $orderId = (int)$order->get_id();
        $qrPngUrl = null;

        // Metas básicas
        if (!empty($pixData['payment_id'])) {
            update_post_meta($orderId, self::META_PAYMENT_ID, (string)$pixData['payment_id']);
        }
        if (isset($pixData['status'])) {
            update_post_meta($orderId, self::META_STATUS, (string)$pixData['status']);
        }
        if (isset($pixData['amount'])) {
            update_post_meta($orderId, self::META_AMOUNT, (int)$pixData['amount']);
        }
        if (isset($pixData['copy_paste'])) {
            update_post_meta($orderId, self::META_COPY_PASTE, (string)$pixData['copy_paste']);
        }
        if (isset($pixData['expires_at'])) {
            update_post_meta($orderId, self::META_EXPIRES_AT, (string)$pixData['expires_at']);
        }

        // Salva base64 no meta (útil para API/integrações internas)
        if (!empty($pixData['qr_base64'])) {
            update_post_meta($orderId, self::META_QR_BASE64, (string)$pixData['qr_base64']);
        }

        // (Opcional) Salvar PNG em uploads para servir como arquivo estático
        if ($savePng && !empty($pixData['qr_base64'])) {
            $qrPngUrl = self::saveBase64Png($pixData['qr_base64'], $orderId);
            if ($qrPngUrl) {
                update_post_meta($orderId, self::META_QR_PNG_URL, $qrPngUrl);
            }
        }

        return ['qr_png_url' => $qrPngUrl];
    }

    /**
     * Decodifica base64 e salva como PNG em uploads/62pay/pix-ORDERID.png.
     */
    private static function saveBase64Png(string $base64, int $orderId): ?string
    {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            wc_get_logger()->warning('[62Pay] Falha ao obter upload dir: ' . $upload['error'], ['source' => 'wc-62pay']);
            return null;
        }

        $dir = trailingslashit($upload['basedir']) . '62pay/';
        $url = trailingslashit($upload['baseurl']) . '62pay/';

        if (!wp_mkdir_p($dir)) {
            wc_get_logger()->warning('[62Pay] Não foi possível criar diretório: ' . $dir, ['source' => 'wc-62pay']);
            return null;
        }

        // Remove prefixo data URI se vier (ex.: "data:image/png;base64,....")
        if (strpos($base64, 'base64,') !== false) {
            $base64 = substr($base64, strpos($base64, 'base64,') + 7);
        }

        $binary = base64_decode($base64, true);
        if ($binary === false) {
            wc_get_logger()->warning('[62Pay] QR base64 inválido.', ['source' => 'wc-62pay']);
            return null;
        }

        $filename = sprintf('pix-%d.png', $orderId);
        $fullpath = $dir . $filename;

        $bytes = file_put_contents($fullpath, $binary);
        if ($bytes === false) {
            wc_get_logger()->warning('[62Pay] Falha ao salvar QR PNG: ' . $fullpath, ['source' => 'wc-62pay']);
            return null;
        }

        return $url . $filename;
    }
}
