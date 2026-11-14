# Modules and the core meet in a separate contracts package

**Status**: accepted, 2026-11-04.

A module distributed on its own cannot require the core, which is a root project with no version a package can constrain against, and it must not import `App\…`. Without something between them, compatibility could only be discovered at runtime.

[`MODULE_DEVELOPMENT.md`](../MODULE_DEVELOPMENT.md) R4 allows a contract package only where a provider is genuinely swappable. Suppliers are: Printful and DropXL share a contract and differ in everything else.

## Decision

`liberusoftware/ecommerce-contracts` holds interfaces and value objects, with no Laravel or Filament dependency. The core and every module require it, so a breaking change is a major version and Composer refuses an incompatible module at `composer require`. The core owns it and implements its host-side interfaces.

Modules register by container tag (`ModuleDefinition`, `FulfilmentProvider`). The core never names a supplier.

Modules describe products and the core writes them, through `ProductImporter`. So no module touches `App\Models\Product`, and store scoping stays in one place.

The package also holds optional interfaces (`ProvidesPanelPlugins`, `ProvidesConfigurationPage`, `ReactsToActivation`), so new optional behaviour can be added as a minor version. The package's README states the versioning rules.

## Options rejected

- **Interfaces in `app/Contracts`:** modules would need the core's `App\` namespace to compile, and could not be tested or versioned alone.
- **`module-ecommerce-dropshipping`'s contracts:** a well-reasoned design, but they come with seven tables and a data model (`tenant_id`, minor units) that this host does not use.
- **Filament types in contracts:** domain interfaces would then need Filament. Plugin methods return plain objects, and the core checks them.

## Consequences

`liberusoftware/ecommerce-contracts` is published from [ecommerce-contracts](https://github.com/liberusoftware/ecommerce-contracts) and installed from Packagist. The tracked `packages/ecommerce-contracts` directory remains the default source copy; Composer resolves the versioned package instead of a path repository.
