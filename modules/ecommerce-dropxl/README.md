# liberusoftware/ecommerce-dropxl

DropXL dropshipping fulfilment and catalogue import for the Liberu ecommerce platform. Install it once in a deployment; each store then activates it with its own DropXL account.

```bash
composer require liberusoftware/ecommerce-dropxl
```

It is a feature module, off until a platform admin enables it on **Settings → Modules**. Then the platform makes it available to teams on **Team integrations**, and the merchant enters their API key and enables it on **Integrations**. Disabling it on **Modules** removes its fulfilment, stock checks and the **Dropshipping → Import from DropXL** page from the next request; the Composer package stays installed.

A product whose `supplier_id` is `dropxl` sends its lines to that store's DropXL account. Imported products are written by the core's `ProductImporter`: matched on DropXL's product id on re-import, and not stock-tracked, because DropXL is asked for availability instead.

Publish `config/dropxl.php` with `--tag=dropxl-config` to change the default API URL, which a store's own setting overrides.

## Testing

The tests extend the host's test case, so they run from a host with the module installed:

```bash
MODULES_ENABLED=ecommerce-dropxl php artisan test modules/ecommerce-dropxl/tests
```

They use the response shapes the integration was built against. Check them against a real account before relying on them.
