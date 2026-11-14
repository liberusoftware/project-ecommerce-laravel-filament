<?php

namespace Liberu\Ecommerce\Printful;

use Liberu\Ecommerce\Contracts\Fulfilment\ChecksAvailability;
use Liberu\Ecommerce\Contracts\Fulfilment\FulfilmentLine;
use Liberu\Ecommerce\Contracts\Fulfilment\FulfilmentProvider;
use Liberu\Ecommerce\Contracts\Fulfilment\FulfilmentRequest;
use Liberu\Ecommerce\Contracts\Fulfilment\FulfilmentResult;
use Liberu\Ecommerce\Contracts\Fulfilment\SupplierUnavailable;
use Liberu\Ecommerce\Contracts\Fulfilment\TrackingUpdate;

class PrintfulFulfilment implements ChecksAvailability, FulfilmentProvider
{
    public function supplierKey(): string
    {
        return 'printful';
    }

    public function moduleKey(): string
    {
        return PrintfulDefinition::KEY;
    }

    public function submit(FulfilmentRequest $request, array $settings): FulfilmentResult
    {
        // external_id plus update_existing is what makes a retry safe at Printful's end.
        $response = PrintfulApi::post($settings, '/orders?confirm=1&update_existing=1', $this->payload($request));

        return $response->successful()
            ? FulfilmentResult::accepted((string) $response->json('result.id'), (array) $response->json('result'))
            : FulfilmentResult::rejected((string) ($response->json('error.message') ?? $response->json('result') ?? "Printful refused the order ({$response->status()})."), (array) $response->json());
    }

    public function track(string $supplierReference, array $settings): ?TrackingUpdate
    {
        $response = PrintfulApi::get($settings, '/orders/'.rawurlencode($supplierReference));

        if (! $response->successful()) {
            throw new SupplierUnavailable("Printful could not report tracking ({$response->status()}).");
        }

        $shipment = collect($response->json('result.shipments', []))->first(fn ($s) => filled($s['tracking_number'] ?? null));

        return $shipment === null ? null : new TrackingUpdate((string) $shipment['tracking_number'], $shipment['carrier'] ?? null, $shipment['tracking_url'] ?? null);
    }

    /** Printful has no stock endpoint; a sync variant's state, by its external id, is the nearest. */
    public function availability(string $sku, int $quantity, array $settings): array
    {
        $response = PrintfulApi::get($settings, '/sync/variant/@'.rawurlencode($sku));

        if (! $response->successful()) {
            throw new SupplierUnavailable("Printful could not check availability ({$response->status()}).");
        }

        return (array) $response->json('result');
    }

    /** @return array<string, mixed> */
    private function payload(FulfilmentRequest $request): array
    {
        $to = $request->recipient;

        return array_filter([
            'external_id' => $request->reference,
            'shipping' => 'STANDARD',
            'recipient' => array_filter([
                'name' => $to->name,
                'email' => $to->email,
                'address1' => $to->address,
                'city' => $to->city,
                'state_code' => $to->state,
                'country_code' => $to->countryCode,
                'zip' => $to->postalCode,
            ], fn ($value) => filled($value)),
            'items' => array_map(fn (FulfilmentLine $line) => array_filter([
                // A variant mapped to a Printful sync variant goes by that id, otherwise by SKU.
                'sync_variant_id' => filled($line->supplierVariantId) ? (int) $line->supplierVariantId : null,
                'external_variant_id' => blank($line->supplierVariantId) ? $line->sku : null,
                'quantity' => $line->quantity,
                'retail_price' => number_format($line->unitPrice, 2, '.', ''),
            ], fn ($value) => filled($value)), $request->lines),
            'gift' => filled($request->giftMessage) ? ['message' => $request->giftMessage] : null,
        ], fn ($value) => $value !== null);
    }
}
