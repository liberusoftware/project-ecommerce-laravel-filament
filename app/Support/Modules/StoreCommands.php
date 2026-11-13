<?php

namespace App\Support\Modules;

use App\Models\Store;
use Illuminate\Support\Facades\Artisan;

/**
 * Runs config('team-modules.store_commands') for every store. An entry is a command, run hourly, or a
 * command => 'hourly'|'daily'. A command whose module is off is skipped.
 */
final class StoreCommands
{
    public const FREQUENCIES = ['hourly', 'daily'];

    /** @return array<string, string> command => frequency */
    public static function configured(): array
    {
        $commands = [];

        foreach ((array) config('team-modules.store_commands') as $key => $value) {
            [$command, $frequency] = is_int($key) ? [$value, 'hourly'] : [$key, $value];
            $commands[$command] = in_array($frequency, self::FREQUENCIES, true) ? $frequency : 'hourly';
        }

        return $commands;
    }

    /** @return list<string> the commands that ran; all of them, or those of one frequency */
    public function run(?string $frequency = null): array
    {
        $available = Artisan::all();
        $ran = [];

        foreach (self::configured() as $command => $every) {
            if (($frequency !== null && $every !== $frequency) || ! array_key_exists($command, $available)) {
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
