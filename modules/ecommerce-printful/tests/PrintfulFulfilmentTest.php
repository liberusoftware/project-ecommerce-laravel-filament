<?php

namespace Liberu\Ecommerce\Printful\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Liberu\Ecommerce\Contracts\Fulfilment\FulfilmentLine;
use Liberu\Ecommerce\Contracts\Fulfilment\FulfilmentRequest;
use Liberu\Ecommerce\Contracts\Fulfilment\Recipient;
use Liberu\Ecommerce\Contracts\Fulfilment\SupplierUnavailable;
use Liberu\Ecommerce\Printful\PrintfulFulfilment;
use Tests\TestCase;

/** Printful's order, tracking and error behaviour, from the responses its API documents. */
class PrintfulFulfilmentTest extends TestCase
{
    private const SETTINGS = ['api_token' => 'pf_test', 'store_id' => '55'];

    private function request(): FulfilmentRequest
    {
        return new FulfilmentRequest(
            'order-9-printful',
            1,
            new Recipient('Ada Lovelace', 'ada@example.com', '1 High Street', 'Leeds', 'West Yorkshire', 'LS1 1AA', 'GB'),
            [new FulfilmentLine(1, 2, 25.0, 'TEE-M', '4001'), new FulfilmentLine(1, 1, 25.0, 'TEE-L')],
            'Happy birthday',
        );
    }

    public function test_it_sends_printfuls_order_with_each_variants_id_and_a_retry_safe_reference(): void
    {
        Http::fake(['api.printful.com/*' => Http::response(['result' => ['id' => 777]])]);

        $result = (new PrintfulFulfilment)->submit($this->request(), self::SETTINGS);

        $this->assertTrue($result->accepted);
        $this->assertSame('777', $result->supplierReference);
        Http::assertSent(function (Request $request) {
            $items = collect($request['items'])->keyBy(fn ($i) => $i['sync_variant_id'] ?? $i['external_variant_id']);

            return $request->url() === 'https://api.printful.com/orders?confirm=1&update_existing=1'
                && $request->hasHeader('Authorization', 'Bearer pf_test')
                && $request->hasHeader('X-PF-Store-Id', '55')
                && $request['external_id'] === 'order-9-printful'
                && $items[4001]['quantity'] === 2
                && ! isset($items[4001]['external_variant_id'])
                && $items['TEE-L']['quantity'] === 1
                && $request['recipient']['name'] === 'Ada Lovelace'
                && $request['recipient']['city'] === 'Leeds'
                && $request['recipient']['zip'] === 'LS1 1AA'
                && $request['recipient']['country_code'] === 'GB'
                && $request['recipient']['address1'] === '1 High Street'
                && $request['gift']['message'] === 'Happy birthday';
        });
    }

    public function test_a_refusal_is_a_rejection_with_printfuls_reason(): void
    {
        Http::fake(['api.printful.com/*' => Http::response(['error' => ['message' => 'Invalid address']], 400)]);

        $result = (new PrintfulFulfilment)->submit($this->request(), self::SETTINGS);

        $this->assertFalse($result->accepted);
        $this->assertSame('Invalid address', $result->rejectionReason);
    }

    public function test_an_outage_a_rate_limit_and_a_timeout_are_not_rejections(): void
    {
        foreach ([fn () => Http::response('down', 503), fn () => Http::response('slow down', 429), fn () => throw new ConnectionException('timed out')] as $response) {
            Http::fake(['api.printful.com/*' => $response]);

            try {
                (new PrintfulFulfilment)->submit($this->request(), self::SETTINGS);
                $this->fail('expected SupplierUnavailable');
            } catch (SupplierUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_tracking_comes_from_the_first_shipment_with_a_number(): void
    {
        Http::fake(['api.printful.com/orders/777' => Http::sequence()
            ->push(['result' => ['shipments' => []]])
            ->push(['result' => ['shipments' => [['carrier' => 'FEDEX', 'tracking_number' => '1Z9', 'tracking_url' => 'https://track.test/1Z9']]]])]);
        $provider = new PrintfulFulfilment;

        $this->assertNull($provider->track('777', self::SETTINGS));

        $update = $provider->track('777', self::SETTINGS);
        $this->assertSame(['1Z9', 'FEDEX', 'https://track.test/1Z9'], [$update->trackingNumber, $update->carrier, $update->trackingUrl]);
    }

    public function test_availability_reads_the_sync_variant_by_sku_and_a_refusal_is_not_an_answer(): void
    {
        Http::fake(['api.printful.com/sync/variant/*' => Http::sequence()->push(['result' => ['sync_variant' => ['id' => 1]]])->push('nope', 404)]);
        $provider = new PrintfulFulfilment;

        $this->assertSame(['sync_variant' => ['id' => 1]], $provider->availability('TEE-M', 1, self::SETTINGS));

        $this->expectException(SupplierUnavailable::class);
        $provider->availability('GONE', 1, self::SETTINGS);
    }
}
