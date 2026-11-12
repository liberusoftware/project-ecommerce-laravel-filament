<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\Team;
use App\Models\User;
use App\Support\StoreAccess;
use App\Support\StorefrontTheme;
use App\Support\StorefrontThemes;
use App\Support\TeamPermission;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Liberu\Ecommerce\Contracts\Modules\Abilities;
use Spatie\Permission\PermissionRegistrar;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(StorefrontTheme::class, fn ($app) => new StorefrontTheme($app['view']->getFinder(), base_path('themes')));
        $this->app->singleton(StorefrontThemes::class, fn () => new StorefrontThemes(base_path('themes')));
        $this->app->bind(StripeClient::class, fn () => new StripeClient(config('services.stripe.secret')));
    }

    public function boot(): void
    {
        // Platform permissions are named `{affix}_{subject}`, compound models with `::`
        // (`view_any_product::category`): the names the policies check and the seeder ships.
        FilamentShield::buildPermissionKeyUsing(
            fn (string $affix, string $subject): string => Str::snake($affix).'_'.str_replace('_', '::', Str::snake($subject)),
        );

        // Every view, so a theme can name the store wherever it overrides one.
        View::composer('*', function ($view) {
            $store = Store::current();
            $view->with('currentStore', $store)->with('storeName', $store?->name ?? config('app.name'));
        });

        $this->defineModuleAbilities();

        app(PermissionRegistrar::class)
            ->setPermissionClass(Permission::class)
            ->setRoleClass(Role::class);

        // Password::default() was already the rule everywhere (PasswordValidationRules,
        // SetUserPassword) but nothing ever configured it, so it resolved to Laravel's
        // stock min:8 with no complexity. These accounts hold order history and saved
        // payment methods; set the defaults once here and every caller inherits them —
        // registration, password reset, password update, and social account setup.
        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers()->symbols();

            // uncompromised() is a HaveIBeenPwned k-anonymity lookup: an outbound HTTPS
            // call to api.pwnedpasswords.com on the registration request. Worth it —
            // credential stuffing against reused breached passwords is the actual threat
            // to a storefront, and complexity rules alone happily accept "P@ssw0rd1234".
            // Production only, because the closure is what makes that affordable:
            // it defers to validation time, so the suite (APP_ENV=testing) and an
            // air-gapped/local deploy never touch the network. Laravel fails OPEN on a
            // request error, so an HIBP outage degrades to the complexity rules above
            // rather than blocking every signup.
            //
            // environment('production'), not isProduction(): ServiceProvider::$app is
            // typed as the Foundation\Application *contract*, which declares
            // environment() but not isProduction() — the latter only exists on the
            // concrete class. It resolves at runtime either way, but only this one is
            // sound against the declared type. isProduction() just delegates here.
            return $this->app->environment('production') ? $rule->uncompromised() : $rule;
        });
    }

    /** What modules ask instead of naming a role. Null when the store does not exist, so never the storefront's. */
    private function defineModuleAbilities(): void
    {
        $abilities = [
            Abilities::MANAGE_STORE => [TeamPermission::STORE_MANAGE, null],
            Abilities::MANAGE_CATALOGUE => [TeamPermission::CATALOGUE_MANAGE, 'create_product'],
            Abilities::MANAGE_ORDERS => [TeamPermission::ORDERS_MANAGE, 'update_order'],
        ];

        foreach ($abilities as $ability => [$permission, $platform]) {
            Gate::define($ability, function (User $user, int $storeId) use ($permission, $platform) {
                $store = Store::find($storeId);

                return $store !== null && StoreAccess::allows($user, $permission, $store, $platform);
            });
        }

        Gate::define(Abilities::MANAGE_MODULES, function (User $user, int $teamId) {
            $team = Team::find($teamId);

            return $team !== null && StoreAccess::allows($user, TeamPermission::STORE_MANAGE, $team);
        });

        Gate::define('manageModules', fn (User $user) => StoreAccess::platform($user, 'manage_modules'));
    }
}
