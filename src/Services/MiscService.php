<?php

namespace Blax\Workkit\Services;

use App\Services\IncompleteJsonService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MiscService
{
    public static function asPaginated(
        $paginated,
        $resource_class,
        array $meta = []
    ) {
        $data = $paginated->toArray();

        $payload = [
            'data' => $resource_class::collection($paginated),
            'meta' => [
                'from' => @$data['from'],
                'to' => @$data['to'],
                'total' => @$data['total'],
                'last_page' => @$data['last_page'],
                'current_page' => @$data['current_page'],
                'options' => (object) request('options'),
            ],
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
