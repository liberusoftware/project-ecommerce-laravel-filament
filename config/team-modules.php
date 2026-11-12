<?php

/*
| What a deployment seeds into its modules. Lists nothing in the core; a site names its own, with its
| settings from the environment. See docs/MODULE_ARCHITECTURE.md.
*/

return [
    // Module => settings. Seeding enables it on Settings → Modules, offers it to every team, and
    // configures and enables it for each team once its required settings are present.
    'seed' => [],

    // Commands run once per store, with --store, by the seeder and hourly, such as a catalogue import.
    'store_commands' => [],
];
