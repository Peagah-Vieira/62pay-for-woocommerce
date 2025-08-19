<?php

declare(strict_types=1);

namespace WC62Pay\Admin;

if (!defined('ABSPATH')) {
    exit;
}

final class SettingsPage
{
    public const SLUG = 'wc-62pay-settings';

    public static function boot(): void
    {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_notices', [self::class, 'maybe_show_missing_key_notice']);
    }

    public static function register_settings(): void
    {
        register_setting('wc_62pay_options', 'wc_62pay_api_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ]);

        register_setting('wc_62pay_options', 'wc_62pay_live_mode', [
            'type' => 'string', // 'yes' | 'no'
            'sanitize_callback' => function ($v) {
                return $v === 'yes' ? 'yes' : 'no';
            },
            'default' => 'no',
        ]);

        add_settings_section(
            'wc_62pay_main',
            __('Credenciais da 62Pay', 'wc-62pay'),
            function () {
                echo '<p>' . esc_html__('Defina o ambiente e a chave da API para conectar sua loja à 62Pay.', 'wc-62pay') . '</p>';
            },
            self::SLUG
        );

        add_settings_field(
            'wc_62pay_live_mode',
            __('Ambiente', 'wc-62pay'),
            [self::class, 'field_env'],
            self::SLUG,
            'wc_62pay_main'
        );

        add_settings_field(
            'wc_62pay_api_key',
            __('API Key', 'wc-62pay'),
            [self::class, 'field_api_key'],
            self::SLUG,
            'wc_62pay_main'
        );
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('62Pay', 'wc-62pay'),
            __('62Pay', 'wc-62pay'),
            'manage_woocommerce',
            self::SLUG,
            [self::class, 'render_page'],
            56
        );
    }

    public static function field_env(): void
    {
        $val = get_option('wc_62pay_live_mode', 'no');
        ?>
        <label>
            <select name="wc_62pay_live_mode">
                <option value="no" <?php selected($val, 'no'); ?>><?php esc_html_e('Sandbox', 'wc-62pay'); ?></option>
                <option value="yes" <?php selected($val, 'yes'); ?>><?php esc_html_e('Production', 'wc-62pay'); ?></option>
            </select>
        </label>
        <p class="description">
            <?php esc_html_e('Escolha o ambiente: Sandbox para testes, Production para transações reais.', 'wc-62pay'); ?>
        </p>
        <?php
    }

    public static function field_api_key(): void
    {
        $val = get_option('wc_62pay_api_key', '');
        ?>
        <input type="text" name="wc_62pay_api_key" value="<?php echo esc_attr($val); ?>" class="regular-text"
               placeholder="sk_live_..."/>
        <p class="description">
            <?php esc_html_e('Cole aqui a API Key correspondente ao ambiente selecionado.', 'wc-62pay'); ?>
        </p>
        <?php
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Sem permissão.', 'wc-62pay'));
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Configurações 62Pay', 'wc-62pay'); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('wc_62pay_options');
                do_settings_sections(self::SLUG);
                submit_button(__('Salvar alterações', 'wc-62pay'));
                ?>
            </form>
        </div>
        <?php
    }

    public static function maybe_show_missing_key_notice(): void
    {
        if (!current_user_can('manage_woocommerce')) return;

        $screen = get_current_screen();
        if (!$screen || !str_contains((string)$screen->id, 'woocommerce')) return;

        $apiKey = (string)get_option('wc_62pay_api_key', '');
        if ($apiKey !== '') return;

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('62Pay: defina a API Key em WooCommerce → 62Pay para ativar os pagamentos.', 'wc-62pay');
        echo '</p></div>';
    }
}
