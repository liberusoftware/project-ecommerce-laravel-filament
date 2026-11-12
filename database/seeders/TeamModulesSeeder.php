<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Support\Modules\StoreCommands;
use App\Support\Modules\TeamModules;
use Illuminate\Database\Seeder;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\ModuleManager\Exceptions\ModuleActivationRefused;
use Liberu\Foundation\ModuleManager\ModuleManagement;

/** Seeds the modules config('team-modules.seed') names. Does nothing when it names none. */
class TeamModulesSeeder extends Seeder
{
    public function run(ModuleManagement $management, TeamModules $modules, StoreCommands $commands): void
    {
        foreach ((array) config('team-modules.seed') as $module => $settings) {
            try {
                $management->enable($module);
            } catch (ModuleActivationRefused $e) {
                $this->command?->warn("{$module}: {$e->getMessage()}");

                continue;
            }

            if ($modules->definition($module) === null) {
                $this->command?->warn("{$module} is enabled and boots from the next run; seed again to offer and configure it.");

                continue;
            }

            $modules->setAvailable($module, true);
            $settings = array_filter((array) $settings, fn ($value) => filled($value));

            foreach (Team::query()->orderBy('id')->pluck('id') as $teamId) {
                try {
                    $modules->configure($module, $teamId, $settings);
                    $modules->enable($module, $teamId);
                } catch (ValidationException|ModuleActivationRefused) {
                    $this->command?->warn("{$module} is offered to team {$teamId} but needs its settings before it can be enabled.");
                }
            }
        }

        foreach ($commands->run() as $command) {
            $this->command?->info("Ran {$command} for every store.");
        }
    }
}
