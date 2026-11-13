<?php

/*
| What a deployment seeds into its modules. Lists nothing in the core; a site names its own, with its
| settings from the environment. See docs/MODULE_ARCHITECTURE.md.
*/

return [
    // Module => settings. Seeding enables it on Settings → Modules, offers it to every team, and
    // configures and enables it for each team once its required settings are present.
    'seed' => [],

    // Commands run once per store, with --store, by the seeder and then hourly, such as a catalogue import.
    // List a command, or map it to 'hourly' or 'daily'.
    'store_commands' => [],
];
