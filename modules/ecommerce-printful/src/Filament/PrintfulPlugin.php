<?php

namespace Liberu\Ecommerce\Printful\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

class PrintfulPlugin implements Plugin
{
    public function getId(): string
    {
        return 'ecommerce-printful';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([PrintfulImport::class]);
    }

    public function boot(Panel $panel): void {}
}
