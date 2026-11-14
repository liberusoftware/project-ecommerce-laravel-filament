<?php

namespace Liberu\Ecommerce\Printful;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Liberu\Ecommerce\Contracts\Fulfilment\SupplierUnavailable;

final class PrintfulApi
{
    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws SupplierUnavailable
     */
    public static function get(array $settings, string $path, array $query = []): Response
    {
        return self::call(fn () => self::client($settings)->get(self::url($path), $query));
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws SupplierUnavailable
     */
    public static function post(array $settings, string $path, array $body): Response
    {
        return self::call(fn () => self::client($settings)->post(self::url($path), $body));
    }

    /** No connection, a timeout, a 5xx or a 429 is no answer at all; the core retries. */
    private static function call(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new SupplierUnavailable('Could not reach Printful: '.$e->getMessage());
        }

        if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
            throw new SupplierUnavailable("Printful answered {$response->status()}.");
        }

        return $response;
    }

    private static function url(string $path): string
    {
        return rtrim((string) config('printful.url'), '/').$path;
    }

    /** @param  array<string, mixed>  $settings */
    private static function client(array $settings): PendingRequest
    {
        $request = Http::withToken((string) $settings['api_token'])->acceptJson()->connectTimeout(5)->timeout(20);

        return filled($settings['store_id'] ?? null) ? $request->withHeaders(['X-PF-Store-Id' => (string) $settings['store_id']]) : $request;
    }
}
