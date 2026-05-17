<?php

declare(strict_types=1);

namespace Blax\Workkit\Attributes;

use Attribute;

/**
 * Declares per-method pagination policy for a controller action.
 *
 * `Request::perPage()` reads this attribute via reflection on the resolved
 * route action and produces the page size to hand to `->paginate()`:
 *
 *   #[VariablePaginatable]                              → 25, user can override (1..100)
 *   #[VariablePaginatable(50)]                          → 50, user can override (1..100)
 *   #[VariablePaginatable(10, allowUserOverride: false)] → fixed at 10, no `?per_page=`
 *   #[VariablePaginatable(50, max: 200)]                → 50, user can override (1..200)
 *
 * Without the attribute, `Request::perPage()` falls back to its $fallback
 * argument (15 by default — matches Eloquent's model default).
 *
 * Usage:
 *
 *     #[VariablePaginatable(50)]
 *     public function index(Request $request): array
 *     {
 *         return ResponseService::apiPaginated(
 *             Book::query()->paginate($request->perPage()),
 *             BookResource::class,
 *         );
 *     }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class VariablePaginatable
{
    public function __construct(
        public int $default = 25,
        public bool $allowUserOverride = true,
        public int $max = 100,
    ) {
    }
}
