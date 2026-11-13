# liberusoftware/ecommerce-printful

Printful fulfilment and catalogue import for the Liberu ecommerce platform. Install it once in a deployment; each store then activates it with its own Printful account.

```bash
composer require liberusoftware/ecommerce-printful
```

It is a feature module, off until a platform admin enables it on **Settings → Modules**. Then the platform makes it available to teams on **Team integrations**, and the merchant enters their API token and enables it on **Integrations**. Disabling it on **Modules** removes its fulfilment, import command and import page from the next request; the Composer package stays installed.

A product whose `supplier_id` is `printful` then sends its lines to that store's Printful account. Tracking is recorded by the core's `fulfilment:sync-tracking`.

## Importing products

From the admin panel, **Dropshipping → Import from Printful** lists a store's Printful products and imports one, as a new product or onto an existing one. The page is there only while Printful is enabled.

From the console:

```bash
php artisan printful:import-products --store=<id>
```

Products are made to order, at Printful's retail price, with one variant per sync variant and Printful's mockups in the gallery. Re-running updates in place. `--product=<id>` imports one product and `--onto=<local id>` maps it onto an existing one.

Publish `config/printful.php` with `--tag=printful-config` to file imports into categories by keyword.

## Testing

The tests extend the host's test case, so they run from a host with the module installed:

```bash
MODULES_ENABLED=ecommerce-printful php artisan test modules/ecommerce-printful/tests
```

They use recorded response shapes from Printful's documentation. Check against a real account before relying on a new API version.
