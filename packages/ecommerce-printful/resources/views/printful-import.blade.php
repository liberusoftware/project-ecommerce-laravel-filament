<x-filament-panels::page>
    <div class="space-y-6">
        <div class="bg-gray-50 dark:bg-gray-900/20 border border-gray-200 dark:border-gray-700 rounded-xl p-4 text-sm text-gray-700 dark:text-gray-300">
            Load your Printful store's products, then import one. Leave <strong>Import onto</strong> empty to create a new
            made-to-order product, or choose an existing product to map Printful's variants onto it by colour and size.
            Re-importing updates in place. <code>php artisan printful:import-products</code> imports the whole store.
        </div>

        <div class="flex flex-wrap items-end gap-4">
            @if (count($this->stores()) > 1)
                <label class="text-sm">
                    <span class="block mb-1 font-medium text-gray-700 dark:text-gray-300">Store</span>
                    <select wire:model.live="storeId" class="rounded-lg border-gray-300 text-sm dark:bg-gray-800">
                        <option value="">Choose…</option>
                        @foreach ($this->stores() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            <x-filament::button wire:click="loadProducts" wire:loading.attr="disabled" wire:target="loadProducts">
                <span wire:loading.remove wire:target="loadProducts">Browse Products</span>
                <span wire:loading wire:target="loadProducts">Loading…</span>
            </x-filament::button>
        </div>

        @if ($hasLoaded)
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                        Printful Sync Products
                        <span class="ml-2 text-sm font-normal text-gray-500 dark:text-gray-400">({{ count($syncProducts) }})</span>
                    </h3>
                </div>

                @if (empty($syncProducts))
                    <div class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        No sync products found in your Printful store. Add products in the Printful dashboard first.
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-700 dark:text-gray-300">
                            <thead class="text-xs uppercase bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="px-6 py-3">Printful Product</th>
                                    <th class="px-6 py-3">Sync ID</th>
                                    <th class="px-6 py-3">Variants</th>
                                    <th class="px-6 py-3">Import onto</th>
                                    <th class="px-6 py-3 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($syncProducts as $product)
                                    <tr wire:key="pf-{{ $product['id'] }}">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-3">
                                                @if (! empty($product['thumbnail_url']))
                                                    <img src="{{ $product['thumbnail_url'] }}" alt="" class="w-10 h-10 rounded object-cover">
                                                @endif
                                                <span class="font-medium">{{ $product['name'] ?? 'Unnamed' }}</span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 font-mono text-xs">{{ $product['id'] }}</td>
                                        <td class="px-6 py-4">{{ $product['variants'] ?? '—' }}</td>
                                        <td class="px-6 py-4">
                                            <select wire:model="onto.{{ $product['id'] }}" class="w-full rounded-lg border-gray-300 text-sm dark:bg-gray-800" @disabled(! $storeId)>
                                                <option value="">New product</option>
                                                @foreach ($this->localProducts() as $id => $name)
                                                    <option value="{{ $id }}">{{ $name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <x-filament::button size="sm" wire:click="importProduct({{ $product['id'] }})" wire:loading.attr="disabled">Import</x-filament::button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
