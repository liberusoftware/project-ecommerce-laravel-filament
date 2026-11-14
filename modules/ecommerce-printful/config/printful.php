<?php

return [
    'url' => env('PRINTFUL_API_URL', 'https://api.printful.com'),

    // Imported products go in the first category whose keywords match. Without rules they are uncategorised.
    // 'Apparel' => ['keywords' => ['hoodie', 'tee'], 'description' => 'Layers and staples.', 'default' => false],
    'categories' => [],
];
