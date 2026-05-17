<?php

namespace Blax\Workkit\Services;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * API response envelope builder.
 *
 * Every helper here — success or error — produces the same wire shape:
 *
 *   {
 *     "status":  { "code": <int>, "text": <reason phrase> },
 *     "message": <human readable | null>,
 *     "data":    <payload | absent>,        // success only
 *     "error":   <{ type, ... } | absent>,  // error only
 *     "errors":  <{ field: [...] } | absent>, // present for validation errors
 *     "meta":    { url, locale, languages, ...pagination... }
 *   }
 *
 * The split is: `data` carries success payloads, `error` carries failure
 * details, `errors` is the Laravel-compatible field-map alias so
 * `assertJsonValidationErrors([...])` keeps working without re-jiggering tests.
 * Every response also reports its HTTP status as both code and reason phrase
 * inside the body — useful for clients that can't easily inspect headers.
 *
 * Controller lifecycle under {@see \Blax\Workkit\Middleware\ForceJsonResponse}:
 *
 *   200 OK         → return ResponseService::apiItem(...) (plain array)
 *   200 OK list    → return ResponseService::apiPaginated($q->paginate(...), ...)
 *   201 Created    → return ResponseService::apiCreated(...) (JsonResponse)
 *   202 Accepted   → return ResponseService::apiAccepted(...) (JsonResponse)
 *   204 No Content → return ResponseService::apiNoContent()  (JsonResponse)
 *   4xx/5xx        → return ResponseService::apiError(...)   (JsonResponse)
 *   422 validation → return ResponseService::apiValidationError([...]) (JsonResponse)
 *
 * Reach for `response()->json(...)` directly only if you genuinely need a
 * status code or shape not modeled above — in which case prefer to add a
 * helper here so the convention stays uniform.
 */
class ResponseService
{
    /* ─────────────────────────── building blocks ──────────────────────── */

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
     * Standard meta block: `url`, `locale`, `languages`, plus any extras
     * (the per-helper additions like pagination keys land here).
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
     * Internal envelope builder used by every success/error helper. Keeping
     * a single source of truth here means the wire shape can't drift between
     * helpers — a new top-level key only needs to be added in one place.
     *
     * @param  array<string, mixed>  $payload Body keys (data/error/errors)
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    private static function envelope(
        int $statusCode,
        ?string $message,
        array $payload,
        array $extraMeta = [],
    ): array {
        return array_merge([
            'status' => [
                'code' => $statusCode,
                'text' => Response::$statusTexts[$statusCode] ?? 'Unknown',
            ],
            'message' => $message,
        ], $payload, [
            'meta' => self::apiMeta($extraMeta),
        ]);
    }

    /* ─────────────────────────────── success ──────────────────────────── */

    /**
     * Single-item envelope. Use for any `show`-style endpoint or for an
     * arbitrary payload (login receipt, ack, etc. — pass `null` resource).
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     * @return array{status: array{code:int,text:string}, message: ?string, data: mixed, meta: array<string, mixed>}
     */
    public static function apiItem(
        mixed $item,
        ?string $resourceClass = null,
        array $extraMeta = [],
        ?string $message = null,
    ): array {
        return self::envelope(200, $message, [
            'data' => $resourceClass !== null ? $resourceClass::make($item) : $item,
        ], $extraMeta);
    }

    /**
     * Non-paginated collection envelope. Reserve for genuinely tiny fixed
     * lists (an enum, a child collection embedded in a parent response).
     * Most list endpoints should use {@see apiPaginated()}.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    public static function apiCollection(
        iterable $items,
        string $resourceClass,
        array $extraMeta = [],
        ?string $message = null,
    ): array {
        $count = is_countable($items) ? count($items) : null;

        return self::envelope(200, $message, [
            'data' => $resourceClass::collection($items),
        ], array_merge(['total' => $count], $extraMeta));
    }

    /**
     * Paginated envelope. Use for any `index`-style endpoint. The meta block
     * picks up the paginator's `current_page`, `per_page`, `from`, `to`,
     * `total`, `total_pages` / `last_page` (aliased so legacy clients keep
     * working) and a `has_more` boolean.
     *
     * @param  \Illuminate\Contracts\Pagination\Paginator|mixed  $paginated
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    public static function apiPaginated(
        mixed $paginated,
        string $resourceClass,
        array $extraMeta = [],
        ?string $message = null,
    ): array {
        $arr = method_exists($paginated, 'toArray') ? $paginated->toArray() : [];
        $current = $arr['current_page'] ?? 1;
        $last = $arr['last_page'] ?? null;

        return self::envelope(200, $message, [
            'data' => $resourceClass::collection($paginated),
        ], array_merge([
            'current_page' => $current,
            'per_page' => $arr['per_page'] ?? null,
            'from' => $arr['from'] ?? null,
            'to' => $arr['to'] ?? null,
            'total' => $arr['total'] ?? null,
            'total_pages' => $last,
            'last_page' => $last,
            'has_more' => $last !== null && $current < $last,
        ], $extraMeta));
    }

    /**
     * Single-item envelope wrapped in a 201 Created JsonResponse. Use for
     * any `store`-style endpoint.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     */
    public static function apiCreated(
        mixed $item,
        ?string $resourceClass = null,
        array $extraMeta = [],
        ?string $message = null,
    ): JsonResponse {
        return response()->json(
            self::envelope(201, $message, [
                'data' => $resourceClass !== null ? $resourceClass::make($item) : $item,
            ], $extraMeta),
            Response::HTTP_CREATED,
        );
    }

    /**
     * 202 Accepted envelope — for endpoints that queue work and return a
     * receipt rather than the final resource.
     *
     * @param  class-string<\Illuminate\Http\Resources\Json\JsonResource>|null  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     */
    public static function apiAccepted(
        mixed $item = null,
        ?string $resourceClass = null,
        array $extraMeta = [],
        ?string $message = null,
    ): JsonResponse {
        return response()->json(
            self::envelope(202, $message, [
                'data' => $resourceClass !== null ? $resourceClass::make($item) : $item,
            ], $extraMeta),
            Response::HTTP_ACCEPTED,
        );
    }

    /**
     * 204 No Content — carries no body. Useful for `delete`-style endpoints.
     */
    public static function apiNoContent(): JsonResponse
    {
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /* ──────────────────────────────── errors ──────────────────────────── */

    /**
     * Generic error envelope. Accepts either:
     *
     *   - A {@see \Throwable} instance — its class becomes `error.type` and
     *     its message becomes the envelope `message` (both overridable).
     *   - A plain string — used as the envelope `message`. `type` defaults
     *     to `'Error'` unless you pass it explicitly.
     *
     *     return ResponseService::apiError($e, 422, ['book' => ['...']]);
     *     return ResponseService::apiError('Forbidden', 403);
     *     return ResponseService::apiError('Rate limited', 429, type: 'TooManyRequests');
     *
     * @param  array<string, array<int, string>>  $errors Field-keyed
     *         validation-style errors, mirrored into the top-level `errors`
     *         key for {@see \Illuminate\Testing\TestResponse::assertJsonValidationErrors()}.
     * @param  array<string, mixed>  $extraMeta
     */
    public static function apiError(
        Throwable|string $errorOrMessage,
        int $status = 500,
        array $errors = [],
        ?string $type = null,
        ?string $message = null,
        array $extraMeta = [],
    ): JsonResponse {
        if ($errorOrMessage instanceof Throwable) {
            $type ??= class_basename($errorOrMessage);
            $message ??= $errorOrMessage->getMessage();
        } else {
            $message ??= $errorOrMessage;
            $type ??= 'Error';
        }

        $payload = ['error' => ['type' => $type]];
        if (! empty($errors)) {
            $payload['errors'] = $errors;
        }

        return response()->json(
            self::envelope($status, $message, $payload, $extraMeta),
            $status,
        );
    }

    /**
     * 422 Unprocessable Entity wrapper around {@see apiError()} with the
     * field-error map pre-filled. Mirrors the shape Laravel's default
     * ValidationException renderer produces, so `assertJsonValidationErrors`
     * keeps working — but the envelope also carries the unified `status`,
     * `message`, `error` keys for the rest of the body.
     *
     *     return ResponseService::apiValidationError([
     *         'book' => ['No copies of this book are currently available.'],
     *     ]);
     *
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $extraMeta
     */
    public static function apiValidationError(
        array $errors,
        ?string $message = null,
        array $extraMeta = [],
    ): JsonResponse {
        return self::apiError(
            errorOrMessage: $message ?? 'The given data was invalid.',
            status: Response::HTTP_UNPROCESSABLE_ENTITY,
            errors: $errors,
            type: 'ValidationException',
            extraMeta: $extraMeta,
        );
    }
}
