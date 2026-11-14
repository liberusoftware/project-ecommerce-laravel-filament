<?php

namespace Liberu\Ecommerce\Dropxl\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

class DropxlPlugin implements Plugin
{
    public function getId(): string
    {
        return 'ecommerce-dropxl';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([DropxlImport::class]);
    }

    public function boot(Panel $panel): void {}
}
