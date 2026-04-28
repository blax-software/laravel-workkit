<?php

namespace Blax\Workkit\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MiscService
{
    /**
     * Build a standard response payload envelope.
     */
    public static function response(
        mixed $data = null,
        array $meta = []
    ): array {
        return [
            'data' => $data,
            'meta' => $meta,
        ];
    }

    /**
     * Resolve a controller payload to a normalized options array.
     *
     * Supported payload shapes:
     * - ['options' => [...]]
     * - [...] (already flat)
     */
    public static function resolveOptions(
        array $payload,
        array $defaults = []
    ): array {
        $options = is_array($payload['options'] ?? null)
            ? $payload['options']
            : $payload;

        return array_merge($defaults, $options);
    }

    /**
     * Read an option value using camelCase or snake_case fallback.
     */
    public static function option(
        array $options,
        string $key,
        mixed $default = null
    ): mixed {
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
     * Build pagination metadata in a consistent format.
     */
    public static function paginationMeta(
        $paginated,
        array $options = [],
        array $meta = []
    ): array {
        $data = $paginated->toArray();

        $base = [
            'from' => @$data['from'],
            'to' => @$data['to'],
            'total' => @$data['total'],
            'last_page' => @$data['last_page'],
            'current_page' => @$data['current_page'],
            'options' => (object) $options,
        ];

        if ($meta) {
            $base = array_merge($base, $meta);
        }

        return $base;
    }

    public static function asPaginated(
        $paginated,
        $resource_class,
        array $meta = [],
        ?array $options = null
    ) {
        $resolvedOptions = $options;
        if ($resolvedOptions === null) {
            $resolvedOptions = is_array(request('options'))
                ? request('options')
                : [];
        }

        $payload = [
            'data' => $resource_class::collection($paginated),
            'meta' => self::paginationMeta($paginated, $resolvedOptions),
        ];

        if ($meta) {
            $payload['meta'] = array_merge($payload['meta'], $meta);
        }

        return $payload;
    }

    /**
     * Available content languages for the running app.
     *
     * Tries (in order):
     *  1. config('languages.languages') — Blax convention, list of {code, ...}
     *  2. config('app.available_locales') — plain array of codes
     *  3. fall back to [app()->getLocale()]
     *
     * @return array<int, string>
     */
    public static function availableLanguages(): array
    {
        $configured = config('languages.languages');
        if (is_array($configured) && $configured) {
            return collect($configured)
                ->map(fn($l) => is_array($l) ? ($l['code'] ?? $l['lang'] ?? null) : $l)
                ->filter()
                ->values()
                ->toArray();
        }

        $locales = config('app.available_locales');
        if (is_array($locales) && $locales) {
            return array_values($locales);
        }

        return [app()->getLocale()];
    }

    /**
     * Standard meta block for an API response.
     *
     * Every api response in the workkit-shaped envelope carries this block
     * so consumers always know:
     *  - which URL produced the payload (`url`)
     *  - which locale they got back (`locale`)
     *  - which other locales the same resource is available in (`languages`)
     *
     * Pagination keys (current_page, total, total_pages, etc.) are merged in
     * by `apiPaginated()`; `apiItem()` / `apiCollection()` skip them.
     */
    public static function apiMeta(array $extra = []): array
    {
        return array_merge([
            'url' => optional(request())->fullUrl(),
            'locale' => app()->getLocale(),
            'languages' => self::availableLanguages(),
        ], $extra);
    }

    /**
     * Paginated API envelope. Use for any list/index endpoint.
     *
     * Returns:
     *   {
     *     "data": [...resource collection...],
     *     "meta": {
     *       "url", "locale", "languages",
     *       "current_page", "per_page", "from", "to",
     *       "total", "total_pages", "has_more"
     *     }
     *   }
     */
    public static function apiPaginated(
        $paginated,
        string $resource_class,
        array $extraMeta = []
    ): array {
        $arr = method_exists($paginated, 'toArray') ? $paginated->toArray() : [];

        $current = $arr['current_page'] ?? 1;
        $last = $arr['last_page'] ?? null;

        $pagination = [
            'current_page' => $current,
            'per_page' => $arr['per_page'] ?? null,
            'from' => $arr['from'] ?? null,
            'to' => $arr['to'] ?? null,
            'total' => $arr['total'] ?? null,
            'total_pages' => $last,
            'has_more' => ($last !== null) ? ($current < $last) : false,
        ];

        return [
            'data' => $resource_class::collection($paginated),
            'meta' => self::apiMeta(array_merge($pagination, $extraMeta)),
        ];
    }

    /**
     * Single-item API envelope. Use for any show endpoint.
     */
    public static function apiItem(
        $item,
        ?string $resource_class = null,
        array $extraMeta = []
    ): array {
        return [
            'data' => $resource_class ? $resource_class::make($item) : $item,
            'meta' => self::apiMeta($extraMeta),
        ];
    }

    /**
     * Non-paginated collection envelope. Use only when pagination is
     * impractical (tiny fixed list like an enum or a child collection of a
     * parent show response). Most list endpoints should use apiPaginated().
     */
    public static function apiCollection(
        $items,
        string $resource_class,
        array $extraMeta = []
    ): array {
        $count = is_countable($items) ? count($items) : null;

        return [
            'data' => $resource_class::collection($items),
            'meta' => self::apiMeta(array_merge([
                'total' => $count,
            ], $extraMeta)),
        ];
    }

    /**
     * Plain envelope (data + meta) for arbitrary payloads — login responses,
     * action acknowledgements, etc. Always carries the standard apiMeta block.
     */
    public static function apiResponse(
        mixed $data = null,
        array $extraMeta = []
    ): array {
        return [
            'data' => $data,
            'meta' => self::apiMeta($extraMeta),
        ];
    }

    public static function bytesToHuman($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    public static function deterministicEncrypt($data)
    {
        return base64_encode(openssl_encrypt($data, 'AES-128-ECB', config('app.key'), OPENSSL_RAW_DATA));
    }

    public static function deterministicDecrypt($encrypted)
    {
        return openssl_decrypt(base64_decode($encrypted), 'AES-128-ECB', config('app.key'), OPENSSL_RAW_DATA);
    }

    public static function logExecutionTime(
        string $logtext,
        $callable = null
    ) {
        $start = microtime(true);

        if (!$callable) {
            return;
        }

        $result = $callable();
        $end = microtime(true);

        $executionTime = $end - $start;

        Log::debug($logtext, [
            'execution_time' => $executionTime
        ]);

        return $result;
    }

    public static function getIpInformation($ip)
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

    public static function countryToCode($country_long): ?string
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

    public static function codeToCountry($country_code, string|null $locale = null)
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

    public static function parseIncompleteJson(
        string $json,
        bool $associative = true
    ): array|object|null {
        return (new IncompleteJsonService())->parse($json, $associative);
    }
}
