<?php

namespace Liberu\Ecommerce\Contracts\Catalog;

/** The core implements this. Lets a module page offer one store's products without the core's models. */
interface ProductDirectory
{
    /** @return array<int, string> the store's product names by id, ordered by name */
    public function names(int $storeId): array;
}
