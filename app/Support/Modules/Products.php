<?php

namespace App\Support\Modules;

use App\Models\Product;
use App\Services\StoreContext;
use Liberu\Ecommerce\Contracts\Catalog\ProductDirectory;

final class Products implements ProductDirectory
{
    public function names(int $storeId): array
    {
        return StoreContext::writingTo($storeId, fn () => Product::query()->withoutGlobalScope('store')
            ->where('store_id', $storeId)->orderBy('name')->pluck('name', 'id')->all());
    }
}
