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
