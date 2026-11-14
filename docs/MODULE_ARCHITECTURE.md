# Module architecture

How modules work in this platform, how to build one, and what is still open. Paths are relative to the repository root.

**Installation is not activation.** Composer and a deploy put code in a deployment. Each store then decides whether to use it. Stores never install PHP code, and nothing in the admin panel runs Composer.

| Phase | State |
| --- | --- |
| 1. Make the module manager real | Done ([ADR 0014](./adr/0014-host-module-registration.md), then the manager's own provider from 1.7.0, [ADR 0018](./adr/0018-the-managers-provider-registers-modules.md)) |
| 2. Activation lifecycle | Done per store ([ADR 0015](./adr/0015-per-store-module-activation.md)), then per team ([ADR 0019](./adr/0019-team-module-activation.md)) |
| 3. Stable contracts | Done ([ADR 0016](./adr/0016-ecommerce-contracts-package.md)) |
| 4. Printful and DropXL | Both extracted to packages, installed by the core as feature modules, off by default (§10) |
| 5. Distribution and CI | CI done. The publisher stays, limited to the canonical repository; making it fail safely is open (§11) |
| 6. Themes | Done. See [`THEMES.md`](./THEMES.md) |

Nothing here has been published or pushed. §12 lists what is open.

## 1. Responsibilities

| Who | Does | Where it is recorded |
| --- | --- | --- |
| Deployment operator | Install and remove with Composer | `composer.lock`; `MODULES_ENABLED` and `MODULES_DISABLED` set the defaults, and `MODULES_DISABLED` overrides everything else |
| The core | Gate each store by its team; the manager discovers, validates and registers | `ModuleServiceProvider`, `ModuleManagerServiceProvider` |
| Platform admin | Turn installed modules on and off; offer them to teams; see what loaded and why not | `module_activations`, on **Settings → Modules**, from `module-manager-filament`; `module_availability`, on **Settings → Team integrations**; `php artisan module:list` and `module:status` |
| Team | Enable and configure it; every store of the team inherits it | `team_modules.enabled` and `settings`, on **Integrations** |
| Existing authorization | Decide who may do any of this | `StoreAccess`, Gate abilities |

The lifecycle, in order:

1. **Catalogued:** listed as supported, with its install command.
2. **Installed:** `composer require`, committed lock file, deploy.
3. **Discovered:** the core finds its `module.json`.
4. **Validated:** manifest, Composer metadata, dependencies and versions all check out.
5. **Available to the deployment:** its provider boots.
6. **Available to a store:** a platform admin allows it.
7. **Enabled by that store.**
8. **Configured by that store:** every required setting is present.
9. **Authorized:** the person acting holds the permission.

A store's module is **active** only when steps 5 to 8 all hold. Steps 2 and 5 need a deploy or a restart. Steps 6 to 8 take effect immediately and register nothing.

## 2. Required and optional modules

`config('modules.required')` lists what the core's own classes need, plus the page that turns modules on and off:

| Module | Why it is required |
| --- | --- |
| `module-manager` | Its classes do the discovery and validation |
| `module-manager-filament` | It provides **Settings → Modules**; switched off, nothing could switch modules back on from the panel |
| `tenancy` | `App\Traits\IsTenantModel` uses its `BelongsToTeam` |
| `users-roles` | `App\Models\User` extends its model |

`MODULES_DISABLED` cannot turn these off. They stay on, and `module:validate` fails on the misconfiguration. A required module that cannot load stops the boot with its name and the reason, because the alternative is a class-not-found error later.

Everything else is optional. An optional module that fails is left out, logged and reported, and every other module and store keeps working.

## 3. Registration

The manager's `ModuleManagerServiceProvider` is the only path by which a module's provider is registered, with `modules.isolate_failures` on so that it loads one module at a time. `App\Providers\ModuleServiceProvider` registers what is the host's: team activation and the contracts modules plug into. ADR 0018 has the reasoning.

A module boots when it is required, or its manifest sets `default_enabled`, or `MODULES_ENABLED` names it. `MODULES_DISABLED` overrides the last two.

```bash
php artisan module:list       # what is installed and booted, and why not. --json for scripts
php artisan module:validate   # every installed module, booted or not. Fails the build on any fault
php artisan module:status X   # one module in detail
php artisan module:cache      # validate, then cache; a cached boot skips validation
php artisan module:clear
php artisan module:features
```

Root `composer.json` lists `tenancy` and `users-roles` under `extra.laravel.dont-discover`, because both packages declare `extra.laravel.providers`. `module:validate --strict`, which CI and the image build run, fails on any module that Laravel's package discovery would still register.

A provider that throws in `register()` is isolated. One that throws in `boot()` is not, because Laravel offers no hook for it. The composition test in §9 catches that before a release.

## 4. Building a module

```text
module-ecommerce-acme/                 liberusoftware/ecommerce-acme
├── composer.json                      type: liberu-module
├── module.json
├── src/
│   ├── AcmeServiceProvider.php
│   ├── AcmeDefinition.php             implements ModuleDefinition
│   └── AcmeFulfilment.php             implements FulfilmentProvider
├── config/ routes/ resources/ database/migrations/
└── tests/
```

`composer.json`. Do **not** add `extra.laravel.providers`: installing must boot nothing.

```json
{
    "name": "liberusoftware/ecommerce-acme",
    "type": "liberu-module",
    "version": "1.0.0",
    "require": { "php": "^8.5", "liberusoftware/ecommerce-contracts": "^1.0" },
    "autoload": { "psr-4": { "Liberu\\Ecommerce\\Acme\\": "src/" } },
    "extra": { "liberu": { "name": "ecommerce-acme" } }
}
```

`module.json`. Every key shown is required except `$schema`, `module_type` and `requires.capabilities`:

```json
{
    "name": "ecommerce-acme",
    "display_name": "Acme",
    "description": "Acme fulfilment.",
    "version": "1.0.0",
    "category": "adapter",
    "module_type": "feature",
    "provider": "Liberu\\Ecommerce\\Acme\\AcmeServiceProvider",
    "requires": {
        "php": "^8.5",
        "laravel": "^13.0",
        "packages": { "liberusoftware/ecommerce-contracts": "^1.0" }
    },
    "capabilities": ["ecommerce.fulfilment.acme"],
    "default_enabled": false,
    "features": ["Sends a store's Acme lines to its Acme account"]
}
```

`module_type` is what puts a module on **Settings → Modules**, listed by its `display_name` with its `description` beneath. `feature` is something an administrator switches on and off, such as an integration. Leave it out, or set `infrastructure`, for what the application is built on, such as the module manager, tenancy and users and roles; those are never listed there. Requiring the package is not enough, and its vendor name plays no part.

The rules `module:validate` enforces:

- `name` matches `extra.liberu.name`, and `type` is `liberu-module`.
- `version` matches the version Composer installed.
- `requires.packages` lists exactly the `liberusoftware/*` packages in Composer's `require`.
- `provider` is autoloadable and extends `ServiceProvider`.
- Module names, Composer packages and capabilities are unique across installed modules.
- PHP, Laravel, package and capability constraints are all satisfied.

The provider uses ordinary Laravel, and registers with the core by container tag:

```php
class AcmeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/acme.php', 'acme');
        $this->app->tag(AcmeDefinition::class, ModuleDefinition::class);
        $this->app->tag(AcmeFulfilment::class, FulfilmentProvider::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'acme');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'acme');
    }
}
```

Never import `App\…`. Everything a module needs from the core is in `liberusoftware/ecommerce-contracts`. Its README states the versioning rules.

**Migrations.** Prefix tables with the module's name. **Date each migration after the newest core table it references.** Laravel sorts migrations by filename across every path, so an earlier date fails its foreign key on a fresh MySQL database. SQLite does not notice, which is why CI runs both. Keep migrations backward compatible: rolling back an image does not roll back a schema.

**Modules holding personal data** must join GDPR export and erasure. `FulfilmentRequest` hands over the recipient at submission so that a module need not store it. No contract for joining GDPR exists yet, because no module stores any; add one before the first that does.

## 5. Declaring a module to stores

```php
class AcmeDefinition implements ModuleDefinition
{
    public function key(): string { return 'ecommerce-acme'; }
    public function label(): string { return 'Acme'; }
    public function description(): string { return 'Acme fulfilment.'; }

    public function settings(): array
    {
        return [
            new SettingField('api_token', 'API token', SettingField::SECRET, required: true),
            new SettingField('region', 'Region', SettingField::SELECT, required: true, options: ['eu' => 'Europe']),
        ];
    }

    public function validateSettings(array $settings): array
    {
        return []; // field => message, for what the rules cannot express
    }
}
```

The core stores settings encrypted per team, keeps only declared fields, never redisplays a secret, and treats a blank secret as "keep the current one". Rotating `APP_KEY` needs `APP_PREVIOUS_KEYS`, or stored settings cannot be read.

Reading state, by injecting `ModuleState`:

```php
$state->isActive('ecommerce-acme', $storeId);   // null means the current request's store
$state->settings('ecommerce-acme', $storeId);   // throws ModuleNotActive for a store without it

$state->isActiveForTeam('ecommerce-acme', $teamId);
$state->teamSettings('ecommerce-acme', $teamId); // the same settings every store of the team reads
```

`currentStoreId()` is null in the panel of a team with several stores; the core never picks one. `currentTeamId()` is the storefront's team or the panel's team, and null on the platform. Work that writes store rows, such as importing products, names its store explicitly.

Under the hood this is `App\Support\Modules\TeamModules`. It is scoped, and resolves each store to its team, so nothing crosses requests or jobs under Octane or Horizon.

Optional interfaces on the definition:

| Interface | For |
| --- | --- |
| `ProvidesPanelPlugins` | Adding Filament plugins to `admin` or `app` |
| `ProvidesConfigurationPage` | Configuring on the module's own page instead of the generated form. `configurationUrl()` takes the team id |
| `ReactsToActivation` | Running something when a store turns it on or off |

### Filament

`App\Filament\ModulePlugins` adds plugins to both panels, from `panelPlugins()` or from the manifest's `presentation.filament` for the fleet's presentation packages. The manager's validator allows manifest plugins only on `presentation` modules, so an `adapter` uses `panelPlugins()`.

A module's pages are registered for the whole deployment, so each one must gate itself:

```php
public static function canAccess(): bool
{
    $state = app(ModuleState::class);
    $teamId = $state->currentTeamId();

    return $teamId !== null
        && $state->isActiveForTeam('ecommerce-acme', $teamId)
        && Gate::allows(Abilities::MANAGE_MODULES, $teamId);
}
```

Modules ask `Abilities::MANAGE_MODULES`, taking a team id, or `MANAGE_STORE`, `MANAGE_CATALOGUE` or `MANAGE_ORDERS`, each taking a store id, and never name a role. `strictAuthorization()` makes a module model with no policy fail loudly.

### Routes

```php
Route::middleware(['web', 'store.module:ecommerce-acme'])->get('/acme/status', ...);
```

`store.module` returns 404 for a store without the module active. Leave supplier webhooks out of it, so that in-flight orders still get their updates after a store switches the module off.

### When a module is off

| Situation | Behaviour |
| --- | --- |
| Storefront product whose supplier is inactive | Page renders, and `ProductPresenter` reports it sold out, so themes need no change |
| Checkout contains such a line | Refused before payment, on both checkout paths |
| Queued order when the module has gone inactive | Moves to `supplier_failed`, and staff are notified. Retrying later is safe |
| Module's pages and routes | Hidden and denied; 404 |
| Disabled in one store | Other stores are unaffected, and its settings are kept |
| Removed from `MODULES_ENABLED`, or the package removed | The same as inactive for every store. Data stays |

## 6. Fulfilment

A product's `supplier_id` decides where its lines go, and the request never does.

```php
class AcmeFulfilment implements FulfilmentProvider
{
    public function supplierKey(): string { return 'acme'; }             // the value in products.supplier_id
    public function moduleKey(): string { return 'ecommerce-acme'; }

    public function submit(FulfilmentRequest $request, array $settings): FulfilmentResult { /* … */ }
    public function track(string $supplierReference, array $settings): ?TrackingUpdate { /* … */ }
}
```

The contract a provider must keep:

- **Send `$request->reference` to the supplier and rely on it to dedupe.** A timeout leaves the outcome unknown, and the core sends the same reference again.
- **Set a timeout** on every HTTP call.
- **Return a result only for a real answer.** `FulfilmentResult::rejected()` means the supplier refused, and a person must act.
- **Throw `SupplierUnavailable`** for no connection, a timeout, a 5xx or a 429. The core retries.

The flow:

1. Before charging, `CheckoutService::assertFulfillable()` refuses a line whose supplier is not active for its store.
2. After payment, `Fulfilment\OrderFulfilments` writes one `order_fulfilments` row per supplier, then queues one `DispatchDropshippingOrder` per row.
3. Each job sends only that supplier's lines, with that store's credentials. It retries an outage and cannot overlap itself. A placed row is never sent again.
4. The order moves to `processing` when every supplier has it, and to `supplier_failed` if any refuses. Staff retry from the order page, which resends only what failed.
5. `fulfilment:sync-tracking` runs hourly and records tracking on each row.

**Why `order_fulfilments` exists.** One `supplier_order_reference` per order could not represent two suppliers, and `supplierFor()` responded to a mixed cart by sending it to nobody after taking payment. For a single-supplier order, the existing `orders.supplier_*` columns are still written, so older views keep working.

**Catalogues.** A module implements `SupplierCatalog` to describe products as `ImportedProduct`, and calls the core's `ProductImporter` to write them. Modules never touch `App\Models\Product`, and the importer writes into one named store.

## 7. Installing, upgrading and removing

**Install**

1. `composer require liberusoftware/ecommerce-acme`, and commit the lock file.
2. Merge, let CI pass, build and deploy. The deploy runs its migrations.
3. Enable it on **Settings → Modules**.
4. On **Settings → Team integrations**, offer it. Each team then configures and enables it on **Integrations**.

To develop locally against an unpublished module, add a temporary path repository with `composer config repositories.acme path ../module-ecommerce-acme`, and do not commit it.

**Upgrade**

1. A Dependabot or manual PR updates the lock file, and CI runs the matrix.
2. Build runs `module:validate`. Deploy runs `migrate --isolated`, then restarts Octane and Horizon.

**Remove**

These are separate steps on purpose, and the data stays unless you choose step 3.

1. Withdraw it on **Settings → Team integrations**, which turns it off for every team. The page shows how many teams use it.
2. Disable it on **Settings → Modules**. The code is present and does not boot.
3. Optional and destructive, while the files still exist: `php artisan migrate:reset --path=modules/<name>/database/migrations`, and delete its `team_modules` and `module_availability` rows.
4. `composer remove`, and deploy.

## 8. Why the admin panel does not run Composer

A web process able to write code turns any admin compromise into code execution. The Kubernetes setup runs several replicas from one image, so a change in one pod is not in the others and disappears on restart. And it would put production out of step with the lock file in Git. The Modules page shows the commands instead.

## 9. Testing and CI

| Level | What it proves | Where |
| --- | --- | --- |
| Boot | Real application boots with broken, missing and disabled modules | `tests/Feature/Modules/ModuleBootTest.php`, `OptionalModuleBootTest.php` |
| Acceptance | One module on, in one store and not another, with isolated settings, routes and services, and authorization | `tests/Feature/Modules/StoreModuleLifecycleTest.php` |
| Commerce | Mixed carts, no charge when unfulfillable, retries, tracking, per-store credentials | `tests/Feature/SupplierFulfilmentTest.php`, `DispatchDropshippingOrderTest.php` |
| Composition | Any module, after a real `composer require` | `tests/Composition/InstalledModuleTest.php`, via `.github/scripts/verify-module.sh` |
| Module's own | Its behaviour | The module's `tests/`, run from a host |

`tests/Fixtures/modules/fixture-supplier` is the acceptance fixture: a complete module with settings, a route, a migration, a Filament page and a fulfilment provider. It is never published.

`.github/workflows/modules.yml`:

| Job | Fails when |
| --- | --- |
| `core` | The publisher's repository guard is removed; an unexpected installed module (outside the default Printful and DropXL copies) or a `composer.json` theme is committed; a manifest or theme is invalid; anything optional boots by default |
| `installed-module` | The fixture, Printful or DropXL fails a real install, `module:validate`, the composition test or its own tests |
| `installed-theme` | The fixture theme fails a real install or Vite build, or loses its own classes |
| `mysql` | Module and commerce tests fail on MySQL 8.4 |
| `image` | The built image lacks a required module, or `module:validate` fails in it |

`tests.yml` remains the core-without-optional-modules suite, since it installs none. A new module joins `installed-module` by adding a matrix row.

A new module's own repository keeps the three thin workflows in [`MODULE_DEVELOPMENT.md`](./MODULE_DEVELOPMENT.md) §4, on bare tags.

## 10. Printful and DropXL

**Printful** has its tracked default copy in `modules/ecommerce-printful` and **DropXL** in `modules/ecommerce-dropxl`. Their authoritative repositories are [module-ecommerce-printful](https://github.com/liberusoftware/module-ecommerce-printful) and [module-ecommerce-dropxl](https://github.com/liberusoftware/module-ecommerce-dropxl). Composer installs and updates their versioned packages from Packagist; the tracked copies keep the default module code available on a fresh checkout. Both depend on the contracts package instead of the host application, and the core's `composer.json` requires both. They remain off until enabled on **Settings → Modules**.

DropXL's **Import from DropXL** page is a Filament plugin its definition returns for the `admin` panel. It lists stores through the contracts' `StoreDirectory`, allows those the `ecommerce.catalogue.manage` gate allows, and writes products through `ProductImporter`. Imported products are therefore matched on DropXL's product id and not stock-tracked, where the built-in service matched on slug and copied DropXL's stock count; availability is DropXL's to answer at checkout. Nothing confirms DropXL's API is real, so check it against an account before relying on it.

Printful's **Import from Printful** page is built the same way, listing a store's products through the contracts' `ProductDirectory` to import onto. Disabled, neither registers its provider: no fulfilment, no stock checks, no `printful:import-products`, no import page or menu entry, and neither appears on **Integrations**. The packages stay installed.

**What changes when a site merges this.** The sites are not modified by this work. On their next merge from the core:

| Change | What the site must do |
| --- | --- |
| New `tenancy`, contracts and `dont-discover` entries | Resolve `composer.lock` by re-running Composer |
| Supplier credentials leave `.env` | Enter them per team on **Integrations**. `PRINTFUL_API_TOKEN` and `DROPXL_API_KEY` are no longer used |
| A site using Printful or DropXL | Enable it on **Settings → Modules**, then offer it on **Team integrations** and enable it on **Integrations**. **Until then its products show as sold out** |
| A site that seeds its integrations | List them in `config/team-modules.php`: `seed` maps a module to its settings from the environment, and `TeamModulesSeeder` enables it on **Settings → Modules**, offers it and switches it on for each team whose settings are complete. `store_commands`, such as `printful:import-products`, run once per store from the seeder and then hourly, or daily when mapped to `daily` |
| `services.dropxl` | Moves to `dropxl.url`, still read from `DROPXL_API_URL` |
| `printful:import-products` | Now needs `--store=<id>` on a multi-store deployment |
| `dropshipping.suppliers.printful.categories` | Moves to `printful.categories` |
| Site tests with `supplier_id => 'printful'` | Must activate the module in `setUp`, or checkout rightly refuses them |
| Theme checkouts with a supplier `<select>` | Harmless, as the list is empty and the value is ignored, but worth removing |

## 11. The package publisher

`.github/workflows/sync-packages.yml` copies `modules/*` and `themes/*` from a host into shared repositories, tags them and updates Packagist. It is unchanged, apart from the guard limiting it to `liberusoftware/project-ecommerce-laravel-filament`, which `modules.yml` now protects.

**The problem.** It points the opposite way from independently maintained modules, and products overwrite each other. `module-users-roles` has sync commits from three products. `module-module-manager` took commits from five products on 2026-11-02, moving from `1.4.3` to `v1.6.2`, with `v` tags that skip the release gates that trigger only on bare tags ([ADR 0005](./adr/0005-bare-version-tags.md)). The GitHub API reports `main` unprotected on `module-users-roles`, `module-tenancy` and `module-module-manager`, so its review-PR path does not apply to them.

**What this work did not change.** The publisher still exists, `modules/tenancy` and `modules/users-roles` are still tracked, and the merged publisher has still never run from this repository. The `packages/` directory is outside its paths, so it cannot publish the contracts or Printful. Nothing on this branch touches `modules/**`, so merging it will not trigger a run.

**Decided: the publisher stays, and fails safely.** A run refuses, publishing nothing, when the destination package repository has changes since this repository's last sync to it, whether direct commits or another product's sync.

Independently of that, `ecommerce-contracts`, `ecommerce-printful` and `ecommerce-dropxl` are outside the publisher's paths and are still to be published from their own repositories, on bare tags.

## 12. Open decisions and limits

**Need a decision**

| Decision | Recommendation | Who |
| --- | --- | --- |
| Publish `ecommerce-contracts`, `ecommerce-printful` and `ecommerce-dropxl`, and public or private? | Public on Packagist | You, with the maintainer |
| One package per integration, against the fleet's four ([ADR 0016](./adr/0016-ecommerce-contracts-package.md)) | One. Needs a catalogue entry in `ECOMMERCE.md` for both packages | Upstream maintainer |
| Do the wave `module-ecommerce-*` packages stay the plan? | Pause them. They have their own data model, and `module-ecommerce-dropshipping`'s manifest is invalid | Upstream maintainer |
| Move to manager `1.7.0` | Done on `feat/module-manager-1.7`: its provider isolates per module, and ADR 0018 retires the host's copy | — |
| The checkout's "ship to recipient" option still adds `shipping.drop_shipping_premium` | Kept, so that no price changed in this work. It no longer sends anything to a supplier, so decide whether it should still cost more | You |

**Known limits**

- Supplier APIs have not been called. The tests use response shapes from documentation and memory, and Printful's shipment fields in particular need checking against a real account before going live.
- Tracking is polled hourly. There are no supplier webhooks, tracking is not emailed to customers, and it does not move the order's status.
- A shopper can still add an unavailable supplier product through a direct POST to the cart. Checkout refuses it before payment.
- `printful.categories` is per deployment rather than per store.
- A module's own tests extend the host's test case, so they run from a host. Moving Printful to its own repository needs `liberusoftware/package-testbench` for standalone runs.
- `modules.yml` has not run on GitHub. Each job's commands were run locally, in Docker, against MySQL 8.4 and in a real image build. Site CI status could not be read, because those repositories are private.

## 13. What was found before this work

Kept briefly, because it explains the design. The first assessment is in this file's Git history.

| Finding | Now |
| --- | --- |
| The manager was installed and never registered, so no `module:*` command existed and `MODULES_*` did nothing | Fixed |
| `users-roles` and `tenancy` booted through package discovery, bypassing it | Fixed |
| The image lacked `modules/module-manager` | Fixed, and proved in a real build |
| One invalid installed manifest would stop every boot | Fixed for optional modules |
| Supplier credentials were global to a deployment | Per store |
| A shopper's request could choose the supplier | Removed |
| A cart with two suppliers reached neither, after payment | Fixed |
| An outage was recorded as a supplier's rejection, so the job's retry could not happen | Fixed |
| Nothing wrote tracking | `fulfilment:sync-tracking` |
| `dropship_*` wave tables and `App\Modules` were attempts that never connected to a running host | Neither is in use. See ADR 0011 and §12 |
| The publisher ran in private site repositories, and could have published from them | Guarded; sites get this on their next merge |
