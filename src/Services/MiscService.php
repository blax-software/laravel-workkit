<?php

namespace Blax\Workkit\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Grab-bag of small utilities used across Blax host apps.
 *
 * Response-envelope helpers (`response`, `apiResponse`, `apiMeta`,
 * `apiItem`, `apiCollection`, `apiPaginated`, `asPaginated`,
 * `paginationMeta`, `availableLanguages`) have moved to
 * {@see ResponseService} — they remain here as thin shims so existing
 * callers continue to work without changes. Prefer calling
 * {@see ResponseService} directly in new code.
 */
class MiscService
{
    /* ──────────────────────────────────────────────────────────────────────
     * Response envelope (delegates to ResponseService)
     * ────────────────────────────────────────────────────────────────────── */

    /**
     * Build a raw `{ data, meta }` envelope. See {@see ResponseService::response()}.
     */
    public static function response(mixed $data = null, array $meta = []): array
    {
        return ResponseService::response($data, $meta);
    }

    /**
     * Available content languages for the running app.
     * See {@see ResponseService::availableLanguages()}.
     *
     * @return array<int, string>
     */
    public static function availableLanguages(): array
    {
        return ResponseService::availableLanguages();
    }

    /**
     * Standard meta block (`url`, `locale`, `languages` + extras).
     * See {@see ResponseService::apiMeta()}.
     */
    public static function apiMeta(array $extra = []): array
    {
        return ResponseService::apiMeta($extra);
    }

    /**
     * Single-item envelope. See {@see ResponseService::apiItem()}.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resource_class
     */
    public static function apiItem(mixed $item, ?string $resource_class = null, array $extraMeta = []): array
    {
        return ResponseService::apiItem($item, $resource_class, $extraMeta);
    }

    /**
     * Plain envelope with the standard meta block.
     * See {@see ResponseService::apiResponse()}.
     */
    public static function apiResponse(mixed $data = null, array $extraMeta = []): array
    {
        return ResponseService::apiResponse($data, $extraMeta);
    }

    /**
     * Non-paginated collection envelope.
     * See {@see ResponseService::apiCollection()}.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource_class
     */
    public static function apiCollection(iterable $items, string $resource_class, array $extraMeta = []): array
    {
        return ResponseService::apiCollection($items, $resource_class, $extraMeta);
    }

    /**
     * Paginated envelope. See {@see ResponseService::apiPaginated()}.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource_class
     */
    public static function apiPaginated(mixed $paginated, string $resource_class, array $extraMeta = []): array
    {
        return ResponseService::apiPaginated($paginated, $resource_class, $extraMeta);
    }

    /**
     * Legacy paginated meta block.
     * See {@see ResponseService::paginationMeta()}.
     */
    public static function paginationMeta(mixed $paginated, array $options = [], array $meta = []): array
    {
        return ResponseService::paginationMeta($paginated, $options, $meta);
    }

    /**
     * Legacy paginated envelope.
     * See {@see ResponseService::asPaginated()}.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resource_class
     */
    public static function asPaginated(
        mixed $paginated,
        string $resource_class,
        array $meta = [],
        ?array $options = null,
    ): array {
        return ResponseService::asPaginated($paginated, $resource_class, $meta, $options);
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Misc utilities
     * ────────────────────────────────────────────────────────────────────── */

    /**
     * Resolve a controller payload to a normalized options array.
     *
     * Supported payload shapes:
     *  - `['options' => [...]]`
     *  - `[...]` (already flat)
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function resolveOptions(array $payload, array $defaults = []): array
    {
        $options = is_array($payload['options'] ?? null)
            ? $payload['options']
            : $payload;

        return array_merge($defaults, $options);
    }

    /**
     * Read an option value using exact / snake_case / camelCase fallback.
     *
     * @param  array<string, mixed>  $options
     */
    public static function option(array $options, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $options)) {
            return $options[$key];
        }

        $snake = str($key)->snake()->toString();
        if (array_key_exists($snake, $options)) {
            return $options[$snake];
        }

        $camel = str($key)->camel()->toString();
        if (array_key_exists($camel, $options)) {
            return $options[$camel];
        }

        return $default;
    }

    /**
     * Format a byte count as a human-readable string (B, KB, MB, …).
     */
    public static function bytesToHuman(int|float $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * AES-128-ECB encrypt — deterministic (same input → same output).
     * Use only where determinism is required; prefer Laravel's `Crypt`
     * facade for general-purpose encryption.
     */
    public static function deterministicEncrypt(string $data): string
    {
        return base64_encode(openssl_encrypt($data, 'AES-128-ECB', config('app.key'), OPENSSL_RAW_DATA));
    }

    /**
     * Inverse of {@see deterministicEncrypt()}.
     */
    public static function deterministicDecrypt(string $encrypted): string|false
    {
        return openssl_decrypt(base64_decode($encrypted), 'AES-128-ECB', config('app.key'), OPENSSL_RAW_DATA);
    }

    /**
     * Time a callable and log its duration at debug level. Returns the
     * callable's return value (or null when no callable is given).
     */
    public static function logExecutionTime(string $logtext, ?callable $callable = null): mixed
    {
        $start = microtime(true);

        if (! $callable) {
            return null;
        }

        $result = $callable();
        $end = microtime(true);

        Log::debug($logtext, [
            'execution_time' => $end - $start,
        ]);

        return $result;
    }

    /**
     * Look up geolocation/ISP info for an IP via ipapi.co.
     * Cached per-request via `once()` and per-IP via flexible cache.
     *
     * @return array<string, mixed>|null
     */
    public static function getIpInformation(string $ip): ?array
    {
        return once(function () use ($ip) {
            return cache()->flexible('ipapi-' . $ip, [60 * 60 * 24 * 2, 60 * 60 * 24 * 7], function () use ($ip) {
                $response = Http::get("https://ipapi.co/{$ip}/json/");

                if ($response->failed()) {
                    return null;
                }

                return $response->json();
            });
        });
    }

    /**
     * Map a German/native country name to its ISO 3166-1 alpha-2 code.
     */
    public static function countryToCode(string $country_long): ?string
    {
        return match (str()->lower($country_long)) {
            'deutschland' => 'de',
            'österreich' => 'at',
            'schweiz' => 'ch',
            'spanien' => 'es',
            'luxemburg' => 'lu',
            'estland' => 'ee',
            'belgien' => 'be',
            default => null,
        };
    }

    /**
     * Map an ISO 3166-1 alpha-2 code to a localized country name.
     * Supports `de`, `es`, `uk` and falls through to English.
     */
    public static function codeToCountry(string $country_code, ?string $locale = null): ?string
    {
        $country_code = str()->lower($country_code);
        $locale ??= app()->getLocale();

        if ($locale === 'de') {
            return match ($country_code) {
                'de' => 'Deutschland',
                'at' => 'Österreich',
                'ch' => 'Schweiz',
                'es' => 'Spanien',
                'lu' => 'Luxemburg',
                'ee' => 'Estland',
                'be' => 'Belgien',
                default => null,
            };
        }

        if ($locale === 'es') {
            return match ($country_code) {
                'de' => 'Alemania',
                'at' => 'Austria',
                'ch' => 'Suiza',
                'es' => 'España',
                'lu' => 'Luxemburgo',
                'ee' => 'Estonia',
                'be' => 'Bélgica',
                default => null,
            };
        }

        if ($locale === 'uk') {
            return match ($country_code) {
                'de' => 'Німеччина',
                'at' => 'Австрія',
                'ch' => 'Швейцарія',
                'es' => 'Іспанія',
                'lu' => 'Люксембург',
                'ee' => 'Естонія',
                'be' => 'Бельгія',
                default => null,
            };
        }

        return match ($country_code) {
            'de' => 'Germany',
            'at' => 'Austria',
            'ch' => 'Switzerland',
            'es' => 'Spain',
            'lu' => 'Luxembourg',
            'ee' => 'Estonia',
            'be' => 'Belgium',
            default => null,
        };
    }

    /**
     * Parse partial/streaming JSON best-effort. Delegates to
     * {@see IncompleteJsonService}.
     */
    public static function parseIncompleteJson(string $json, bool $associative = true): array|object|null
    {
        return (new IncompleteJsonService())->parse($json, $associative);
    }
}
