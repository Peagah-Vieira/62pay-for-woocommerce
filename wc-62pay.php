<?php
/**
 * Plugin Name: 62Pay for WooCommerce (Cartão, Pix, Boleto)
 * Description: Skeleton do gateway 62Pay com checkout transparente (Cartão, Pix e Boleto) para você integrar sua API.
 * Author: 62Pay
 * Version: 0.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.2
 * Text Domain: wc-62pay
 * Domain Path: /languages
 */

use WC62Pay\API\Client;
use WC62Pay\WebhookHandler;

if (!defined('ABSPATH')) {
    exit;
}

define('WC_62PAY_VERSION', '0.0.1');
define('WC_62PAY_FILE', __FILE__);
define('WC_62PAY_DIR', plugin_dir_path(__FILE__));
define('WC_62PAY_URL', plugin_dir_url(__FILE__));

// ----------------------------------------------------------------------------
// Composer autoload (SDK + your PSR-4 code)
// ----------------------------------------------------------------------------
$autoload = WC_62PAY_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Show admin notice if vendor is missing
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>62Pay for WooCommerce</strong>: a pasta <code>vendor</code> não foi encontrada. Rode <code>composer install</code> dentro do plugin, ou use o zip com vendor incluso.</p></div>';
    });
    // Don’t proceed to avoid fatal errors
    return;
}

// ----------------------------------------------------------------------------
// HPOS (High-Performance Order Storage) compatibility
// ----------------------------------------------------------------------------
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\Features::class)) {
        \Automattic\WooCommerce\Utilities\Features::declare_compatibility('custom_order_tables', WC_62PAY_FILE, true);
    }
});

// ----------------------------------------------------------------------------
// Boot plugin
// ----------------------------------------------------------------------------
add_action('plugins_loaded', function () {

    // WooCommerce required
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>62Pay for WooCommerce</strong> requer WooCommerce ativo.</p></div>';
        });
        return;
    }

    // i18n
    load_plugin_textdomain('wc-62pay', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Register payment gateways (PSR-4 classes under src/)
    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = \WC62Pay\Gateway\CreditCard::class;
        $gateways[] = \WC62Pay\Gateway\Pix::class;
        $gateways[] = \WC62Pay\Gateway\BankSlip::class;
        return $gateways;
    });

    // (Optional) Register Store API / Webhook endpoints
    if (class_exists(WebhookHandler::class)) {
        (new WebhookHandler())->register_routes();
    }
}, 0);

// ----------------------------------------------------------------------------
// Settings shortcut on Plugins list
// ----------------------------------------------------------------------------
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout');
    $links[] = '<a href="' . esc_url($settings_url) . '">' . esc_html__('Configurar', 'wc-62pay') . '</a>';
    return $links;
});

// ----------------------------------------------------------------------------
// Frontend assets (only on Checkout)
// ----------------------------------------------------------------------------
add_action('wp_enqueue_scripts', function () {
    if (is_checkout()) {
        wp_enqueue_script(
            'wc-62pay-checkout',
            WC_62PAY_URL . 'assets/js/checkout.js',
            array('jquery'),
            WC_62PAY_VERSION,
            true
        );

        wp_localize_script('wc-62pay-checkout', 'WC62Pay', array(
            'ajax_url' => admin_url('admin-ajax.php'),
        ));
    }
});

/**
 * Instância única do Client da camada API do plugin.
 *
 * @return Client
 */
function wc_62pay_client(): Client
{
    static $client = null;

    if ($client instanceof Client) {
        return $client;
    }

    $client = Client::fromOptions();
    return $client;
}
