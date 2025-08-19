<?php

declare(strict_types=1);

namespace WC62Pay\Support;

use WC_Order;

final class InvoiceBankSlipPersister
{
    public const META_PAYMENT_ID = '_wc_62pay_bankslip_payment_id';
    public const META_STATUS = '_wc_62pay_bankslip_status';
    public const META_AMOUNT = '_wc_62pay_bankslip_amount';
    public const META_IDENTIFICATION_FIELD = '_wc_62pay_bankslip_identification_field';
    public const META_BANK_SLIP_NUMBER = '_wc_62pay_bankslip_number';
    public const META_BARCODE = '_wc_62pay_bankslip_barcode';
    public const META_BANK_SLIP_URL = '_wc_62pay_bankslip_url';
    public const META_BANK_SLIP_PDF_URL = '_wc_62pay_bankslip_pdf_url';

    /**
     * @param WC_Order $order
     * @param array $bankSlipData
     * @param bool $savePdf
     * @return null[]|string[]
     */
    public static function persist(WC_Order $order, array $bankSlipData, bool $savePdf = true): array
    {
        $orderId = (int)$order->get_id();
        $pdfUrl = null;

        if (!empty($bankSlipData['payment_id'])) {
            update_post_meta($orderId, self::META_PAYMENT_ID, (string)$bankSlipData['payment_id']);
        }
        if (isset($bankSlipData['status'])) {
            update_post_meta($orderId, self::META_STATUS, (string)$bankSlipData['status']);
        }
        if (isset($bankSlipData['amount'])) {
            update_post_meta($orderId, self::META_AMOUNT, (int)$bankSlipData['amount']);
        }
        if (isset($bankSlipData['identification_field'])) {
            update_post_meta($orderId, self::META_IDENTIFICATION_FIELD, (string)$bankSlipData['identification_field']);
        }
        if (isset($bankSlipData['bank_slip_number'])) {
            update_post_meta($orderId, self::META_BANK_SLIP_NUMBER, (string)$bankSlipData['bank_slip_number']);
        }
        if (isset($bankSlipData['barcode'])) {
            update_post_meta($orderId, self::META_BARCODE, (string)$bankSlipData['barcode']);
        }
        if (isset($bankSlipData['bank_slip_url'])) {
            update_post_meta($orderId, self::META_BANK_SLIP_URL, (string)$bankSlipData['bank_slip_url']);
        }

        // (Opcional) baixar o PDF remoto e servir localmente
        if ($savePdf && !empty($bankSlipData['bank_slip_url'])) {
            $pdfUrl = self::downloadPdf((string)$bankSlipData['bank_slip_url'], $orderId);
            if ($pdfUrl) {
                update_post_meta($orderId, self::META_BANK_SLIP_PDF_URL, $pdfUrl);
            }
        }

        return ['pdf_url' => $pdfUrl];
    }

    /**
     * Baixa o PDF remoto e salva em uploads/62pay/boleto-{ORDERID}.pdf
     */
    private static function downloadPdf(string $remoteUrl, int $orderId): ?string
    {
        // Evita bloquear em ambientes sem allow_url_fopen
        $response = wp_remote_get($remoteUrl, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/pdf,*/*',
            ],
        ]);

        if (is_wp_error($response)) {
            wc_get_logger()->warning('[62Pay] Falha ao baixar PDF do boleto: ' . $response->get_error_message(), ['source' => 'wc-62pay', 'order_id' => $orderId]);
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            wc_get_logger()->warning('[62Pay] Resposta inválida ao baixar PDF do boleto. HTTP ' . $code, ['source' => 'wc-62pay', 'order_id' => $orderId]);
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (!$body) {
            wc_get_logger()->warning('[62Pay] Corpo vazio ao baixar PDF do boleto.', ['source' => 'wc-62pay', 'order_id' => $orderId]);
            return null;
        }

        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            wc_get_logger()->warning('[62Pay] Falha ao obter upload dir: ' . $upload['error'], ['source' => 'wc-62pay', 'order_id' => $orderId]);
            return null;
        }

        $dir = trailingslashit($upload['basedir']) . '62pay/';
        $url = trailingslashit($upload['baseurl']) . '62pay/';

        if (!wp_mkdir_p($dir)) {
            wc_get_logger()->warning('[62Pay] Não foi possível criar diretório: ' . $dir, ['source' => 'wc-62pay', 'order_id' => $orderId]);
            return null;
        }

        $filename = sprintf('boleto-%d.pdf', $orderId);
        $fullpath = $dir . $filename;

        $bytes = file_put_contents($fullpath, $body);
        if ($bytes === false) {
            wc_get_logger()->warning('[62Pay] Falha ao salvar PDF do boleto: ' . $fullpath, ['source' => 'wc-62pay', 'order_id' => $orderId]);
            return null;
        }

        // Ajusta o esquema (http/https) conforme a página
        $finalUrl = set_url_scheme($url . $filename, is_ssl() ? 'https' : 'http');

        return $finalUrl;
    }
}
