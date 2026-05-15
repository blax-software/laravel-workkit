<?php

namespace Blax\Workkit\Services;

use Illuminate\Contracts\Pagination\Paginator;

/**
 * API response envelope builder.
 *
 * Every method here produces the workkit response shape `{ data, meta }`.
 * The richer helpers (`apiItem`, `apiCollection`, `apiPaginated`,
 * `apiResponse`) auto-fill a standard meta block via {@see apiMeta()},
 * which carries the request `url`, the active `locale`, and the list of
 * available `languages`. Pagination metadata is merged on top by
 * {@see apiPaginated()}.
 *
 * This service is the canonical home for these helpers. The matching
 * methods on {@see MiscService} remain as thin shims for backward
 * compatibility — existing callers do not need to change.
 *
 * Lifecycle in a controller:
 *
 *   return response()->json(ResponseService::apiPaginated($q->paginate(), BookResource::class));
 *   return response()->json(ResponseService::apiItem($book, BookResource::class));
 *   return response()->json(ResponseService::apiResponse(['token' => $token]), 201);
 */
class ResponseService
{
    /**
     * Build a raw `{ data, meta }` envelope with whatever meta you pass.
     *
     * Lowest-level primitive — every other method here ultimately produces
     * this same shape. Prefer the higher-level helpers
     * (`apiResponse`, `apiItem`, `apiCollection`, `apiPaginated`) which
     * auto-fill the standard meta block.
     */
    public static function response(mixed $data = null, array $meta = []): array
    {
        return [
            'data' => $data,
            'meta' => $meta,
        ];
    }

    /**
     * Available content languages for the running app.
     *
     * Resolution order:
     *   1. `config('languages.languages')` — Blax convention, list of
     *      `{ code, ... }` records.
     *   2. `config('app.available_locales')` — plain array of codes.
     *   3. Fallback to `[app()->getLocale()]`.
     *
     * @return array<int, string>
     */
    public static function availableLanguages(): array
    {
        $configured = config('languages.languages');
        if (is_array($configured) && $configured) {
            return collect($configured)
                ->map(fn ($l) => is_array($l) ? ($l['code'] ?? $l['lang'] ?? null) : $l)
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
     * Standard meta block: `url`, `locale`, `languages`, plus any extras.
     *
     * Pagination keys are merged in by {@see apiPaginated()};
     * {@see apiItem()} and {@see apiCollection()} skip them.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
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
     * Single-item envelope. Use for any `show`-style endpoint.
     *
     * Pass the resource class to wrap the model in a `JsonResource`, or
     * omit it to serialize the value directly (this is also the form to
     * use for arbitrary payloads — login responses, action acks, etc.).
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     * @return array{data: mixed, meta: array<string, mixed>}
     */
    public static function apiItem(mixed $item, ?string $resourceClass = null, array $extraMeta = []): array
    {
        return self::response(
            $resourceClass !== null ? $resourceClass::make($item) : $item,
            self::apiMeta($extraMeta),
        );
    }

    /**
     * Plain envelope with the standard meta block.
     *
     * Alias of `apiItem($data, null, $extraMeta)` kept for callers whose
     * intent is "arbitrary payload" rather than "model" — login responses,
     * acknowledgements, etc.
     *
     * @param  array<string, mixed>  $extraMeta
     * @return array{data: mixed, meta: array<string, mixed>}
     */
    public static function apiResponse(mixed $data = null, array $extraMeta = []): array
    {
        return self::apiItem($data, null, $extraMeta);
    }

    /**
     * Non-paginated collection envelope.
     *
     * Reserve this for genuinely tiny fixed lists (an enum, a child
     * collection embedded in a parent show response). Most list endpoints
     * should use {@see apiPaginated()}.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     * @return array{data: mixed, meta: array<string, mixed>}
     */
    public static function apiCollection(iterable $items, string $resourceClass, array $extraMeta = []): array
    {
        $count = is_countable($items) ? count($items) : null;

        return self::response(
            $resourceClass::collection($items),
            self::apiMeta(array_merge(['total' => $count], $extraMeta)),
        );
    }

    /**
     * Paginated envelope. Use for any `index`-style endpoint.
     *
     * Wire shape:
     *
     *   {
     *     "data": [...resource collection...],
     *     "meta": {
     *       "url", "locale", "languages",
     *       "current_page", "per_page", "from", "to",
     *       "total", "total_pages", "last_page", "has_more"
     *     }
     *   }
     *
     * `last_page` is exposed alongside `total_pages` as an alias so
     * consumers written against Laravel's native paginator key continue
     * to work without a migration.
     *
     * @param  \Illuminate\Contracts\Pagination\Paginator|mixed  $paginated
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     * @return array{data: mixed, meta: array<string, mixed>}
     */
    public static function apiPaginated(mixed $paginated, string $resourceClass, array $extraMeta = []): array
    {
        $arr = method_exists($paginated, 'toArray') ? $paginated->toArray() : [];
        $current = $arr['current_page'] ?? 1;
        $last = $arr['last_page'] ?? null;

        return self::response(
            $resourceClass::collection($paginated),
            self::apiMeta(array_merge([
                'current_page' => $current,
                'per_page' => $arr['per_page'] ?? null,
                'from' => $arr['from'] ?? null,
                'to' => $arr['to'] ?? null,
                'total' => $arr['total'] ?? null,
                'total_pages' => $last,
                'last_page' => $last,
                'has_more' => $last !== null && $current < $last,
            ], $extraMeta)),
        );
    }

    /**
     * Legacy paginated meta block: slimmer than {@see apiMeta()}, no
     * `url`/`locale`/`languages`, includes a passthrough `options` object
     * reflecting the request's filter/sort state.
     *
     * Retained for backward compatibility — new code should use
     * {@see apiPaginated()}.
     *
     * @param  \Illuminate\Contracts\Pagination\Paginator|mixed  $paginated
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function paginationMeta(mixed $paginated, array $options = [], array $meta = []): array
    {
        $data = method_exists($paginated, 'toArray') ? $paginated->toArray() : [];

        $base = [
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'total' => $data['total'] ?? null,
            'last_page' => $data['last_page'] ?? null,
            'current_page' => $data['current_page'] ?? null,
            'options' => (object) $options,
        ];

        return $meta ? array_merge($base, $meta) : $base;
    }

    /**
     * Legacy paginated envelope. Same intent as {@see apiPaginated()} but
     * with the older meta shape — reads `options` off the current request
     * when none are supplied.
     *
     * Retained for backward compatibility — new code should use
     * {@see apiPaginated()}.
     *
     * @param  \Illuminate\Contracts\Pagination\Paginator|mixed  $paginated
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $options
     * @return array{data: mixed, meta: array<string, mixed>}
     */
    public static function asPaginated(
        mixed $paginated,
        string $resourceClass,
        array $meta = [],
        ?array $options = null,
    ): array {
        $resolvedOptions = $options ?? (is_array(request('options')) ? request('options') : []);

        $payload = self::response(
            $resourceClass::collection($paginated),
            self::paginationMeta($paginated, $resolvedOptions),
        );

        if ($meta) {
            $payload['meta'] = array_merge($payload['meta'], $meta);
        }

        return $payload;
    }
}
