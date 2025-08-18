<?php
namespace WC62Pay\Gateway;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pix extends Base {

    public function __construct() {
        $this->id                 = 'wc_62pay_pix';
        $this->method_title       = __( '62Pay – Pix', 'wc-62pay' );
        $this->method_description = __( 'Cobrança via Pix com QR Code e Copia e Cola.', 'wc-62pay' );
        $this->icon               = apply_filters( 'wc_62pay_pix_icon', '' );
        parent::__construct();

        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        try {
            $client = $this->get_client();
            $charge = $client->create_charge( $order, 'pix', array() );

            if ( ! empty( $charge['pix_qr'] ) ) {
                $order->update_meta_data( '_wc_62pay_pix_qr', $charge['pix_qr'] );
            }
            if ( ! empty( $charge['pix_copia_cola'] ) ) {
                $order->update_meta_data( '_wc_62pay_pix_copia_cola', $charge['pix_copia_cola'] );
            }
            $order->save();

            $order->update_status( 'on-hold', 'Aguardando pagamento Pix.' );

            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        } catch ( \Exception $e ) {
            wc_add_notice( 'Falha ao gerar cobrança Pix: ' . $e->getMessage(), 'error' );
            return array( 'result' => 'failure' );
        }
    }

    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        $qr = $order->get_meta( '_wc_62pay_pix_qr' );
        $copia = $order->get_meta( '_wc_62pay_pix_copia_cola' );
        echo '<h3>Pagamento via Pix</h3>';
        if ( $qr ) {
            echo '<p>Escaneie o QR Code abaixo para pagar:</p>';
            echo '<img style="max-width:260px;height:auto;border:1px solid #eee;padding:8px" src="' . esc_url( $qr ) . '" alt="QR Code Pix" />';
        }
        if ( $copia ) {
            echo '<p><strong>Copia e Cola:</strong><br/><code style="word-break:break-all">' . esc_html( $copia ) . '</code></p>';
        }
        echo '<p>Assim que o pagamento for confirmado, seu pedido será atualizado automaticamente.</p>';
    }
}
