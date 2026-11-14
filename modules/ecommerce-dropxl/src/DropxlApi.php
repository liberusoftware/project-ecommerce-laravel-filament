<?php

namespace Liberu\Ecommerce\Dropxl;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Liberu\Ecommerce\Contracts\Fulfilment\SupplierUnavailable;

final class DropxlApi
{
    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws SupplierUnavailable
     */
    public static function get(array $settings, string $path, array $query = []): Response
    {
        return self::call(fn () => self::client($settings)->get(self::url($settings, $path), $query));
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws SupplierUnavailable
     */
    public static function post(array $settings, string $path, array $body): Response
    {
        return self::call(fn () => self::client($settings)->post(self::url($settings, $path), $body));
    }

    /** No connection, a timeout, a 5xx, a 408 or a 429 is no answer at all; the core retries. */
    private static function call(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new SupplierUnavailable('Could not reach DropXL: '.$e->getMessage());
        }

        if ($response->serverError() || in_array($response->status(), [408, 429], true)) {
            throw new SupplierUnavailable("DropXL answered {$response->status()}.");
        }

        return $response;
    }

    /** @param  array<string, mixed>  $settings */
    private static function client(array $settings): PendingRequest
    {
        return Http::withToken((string) ($settings['api_key'] ?? ''))->acceptJson()->connectTimeout(5)->timeout(20);
    }

    /** @param  array<string, mixed>  $settings */
    private static function url(array $settings, string $path): string
    {
        return rtrim((string) (($settings['api_url'] ?? null) ?: config('dropxl.url')), '/').$path;
    }
}
