# 62Pay for WooCommerce (Cartão, Pix, Boleto)

Esqueleto de plugin para integrar **checkout transparente** com a **62Pay**.

## Instalação
1. Envie o `.zip` em *Plugins > Adicionar novo > Enviar plugin*.
2. Ative.
3. Configure em **WooCommerce > Configurações > Pagamentos**:
    - 62Pay – Cartão de Crédito
    - 62Pay – Pix
    - 62Pay – Boleto

## Integração
- Edite `includes/API/Client.php` e implemente as chamadas reais para a 62Pay (`create_charge`, `fetch_charge`, `refund`).
- Endpoint de webhook: `https://SEU-SITE.com/?wc-api=wc_62pay_webhook`
    - Envie `order_id`, `status` e `charge_id`.
    - Valide a assinatura/HMAC no `WebhookHandler` (TODO marcado no código).
- **Cartão**: em produção, use **tokenização no front-end** e evite trafegar PAN no servidor.

## Estados
- Cartão aprovado: `processing` (pago).
- Pix/Boleto: `on-hold` até confirmação via webhook.

## Aviso
Código base de referência. Revise requisitos de **PCI** e **LGPD** antes de produção.


## Compatibilidade HPOS
Este plugin declara compatibilidade com **High-Performance Order Storage (HPOS)** via:
```php
\Automattic\WooCommerce\Utilities\Features::declare_compatibility( 'custom_order_tables', __FILE__, true );
```
A integração usa apenas a camada CRUD (`wc_get_order()`, `$order->update_status()`, etc.), evitando acesso direto a `wp_posts/wp_postmeta`.
# 62pay-for-woocommerce
# 62pay-for-woocommerce
