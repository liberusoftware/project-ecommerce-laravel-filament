<?php

namespace App\Support\Modules;

use App\Models\Store;
use Illuminate\Support\Facades\Artisan;

/** Runs config('team-modules.store_commands') for every store. A command whose module is off is skipped. */
final class StoreCommands
{
    /** @return list<string> the commands that ran */
    public function run(): array
    {
        $available = Artisan::all();
        $ran = [];

        foreach ((array) config('team-modules.store_commands') as $command) {
            if (! array_key_exists($command, $available)) {
                continue;
            }

            foreach (Store::query()->orderBy('id')->pluck('id') as $storeId) {
                Artisan::call($command, ['--store' => $storeId]);
            }

            $ran[] = $command;
        }

        return $ran;
    }
}
