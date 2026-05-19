# Blax Software — Laravel Composer Package Principles

This document is the single source of truth for how every Blax Software
Laravel composer package (open-source or internal) is built. It exists so
that any consumer who installs one of our packages can rely on a
predictable shape, and so that any maintainer who jumps between packages
finds the same patterns.

If you are creating a new package, copy these conventions verbatim. If a
package deviates, the deviation must be justified inline in that package
(`README.md` or service provider docblock) and ideally lifted back into
this document.

---

## 1. Migrations: hybrid auto-load + publishable

Every package ships migrations as **real timestamped `.php` files** living
in `database/migrations/`. They are NOT `.stub` files. The service provider
both auto-loads them AND offers them for publishing.

This gives consumers the best of both worlds:

- **Plug-and-play**: `composer require …` + `php artisan migrate` works on
  a fresh install. No `vendor:publish` step needed for the schema baseline.
- **Future updates**: when the package ships new additive migrations
  (added columns, new tables, indexes, fixups), the consumer just runs
  `composer update && php artisan migrate` — the new migration auto-loads
  from `vendor/` and the migrator picks it up.
- **Escape hatch**: consumers who want to customise the schema (different
  ID types, multi-tenant prefixes, extra columns) can publish the
  migrations and disable auto-load.

### Pattern (canonical — laravel-roles / laravel-shop)

**File layout**

```
database/migrations/
  2025_01_01_000001_create_blax_<package>_tables.php
  2025_01_01_000002_<additive_migration>.php
  2026_04_26_000001_<later_additive_migration>.php
```

Use the package's first-release date as the timestamp prefix for the
baseline (`2025_01_01_000001_…`) so it sorts before anything a consumer
already has. Each subsequent migration gets its own real timestamp.

**Service provider** (`<Package>ServiceProvider.php`)

```php
public function boot(): void
{
    $this->offerPublishing();
    $this->registerMigrations();
    // …
}

/**
 * Auto-load the package's migrations so fresh installs work without
 * publishing. Disabled via `<package>.run_migrations = false` for
 * projects that prefer to publish + manage migrations themselves.
 */
protected function registerMigrations(): void
{
    if (! config('<package>.run_migrations', true)) {
        return;
    }

    $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
}

/**
 * Publishing preserves the SOURCE filename so that any migration
 * already run via auto-load is marked as run for the published copy
 * too — no duplicate execution.
 */
protected function offerPublishing(): void
{
    if (! $this->app->runningInConsole()) {
        return;
    }

    $this->publishes([
        __DIR__ . '/../config/<package>.php' => $this->app->configPath('<package>.php'),
    ], '<package>-config');

    $migrationsPath = __DIR__ . '/../database/migrations';
    $publishMap = [];
    foreach (glob($migrationsPath . '/*.php') as $sourcePath) {
        $publishMap[$sourcePath] = $this->app->databasePath('migrations/' . basename($sourcePath));
    }
    $this->publishes($publishMap, '<package>-migrations');
}
```

**Config key**

```php
// config/<package>.php
return [
    /*
     * Whether the package should auto-run its migrations. See
     * laravel-workkit/PRINCIPLES/laravel-composer-packages.md.
     */
    'run_migrations' => true,
    // …
];
```

### Why the filename-preserving publish is critical

Laravel's `migrations` table records the migration *filename*. If the
published copy has a different filename than the source (e.g. a fresh
`date('Y_m_d_His')` timestamp), Laravel sees it as a brand-new migration
and runs it again, on top of the auto-loaded copy. By copying with
`basename($sourcePath)` we keep the filenames identical, so the migrator
deduplicates correctly.

### Anti-pattern: fresh-timestamp publish

The bug the filename-preserving publish prevents looks like this in a
service provider:

```php
// ❌ Anti-pattern — produces 1050 errors on every consumer
$this->publishes([
    __DIR__ . '/../database/migrations/create_blax_files_table.php.stub'
        => $this->getMigrationFileName('create_blax_files_table.php'),
], 'files-migrations');

protected function getMigrationFileName(string $name): string
{
    $timestamp = date('Y_m_d_His');
    return $this->app->databasePath() . "/migrations/{$timestamp}_{$name}";
}
```

Each `vendor:publish` produces a NEW filename. Combined with auto-load
this guarantees the table gets created twice and the second run dies
with `SQLSTATE[42S01]: 1050 Table 'files' already exists`. The fix is
either (a) `basename($sourcePath)` in the publish map to inherit the
source name, or (b) the `Schema::hasTable()` guards below — preferably
both. Reference fix: [Blax\Files\FilesServiceProvider::offerPublishing()](/home/a6a2f5842/Documents/Repos/laravel-files/src/FilesServiceProvider.php).

### Idempotency requirement

Every migration MUST be safe to run when its tables/columns already
exist. Guard each `Schema::create` with `if (! Schema::hasTable(...))`
and each `Schema::table` column addition with
`if (! Schema::hasColumn(...))`. Reason: in real consumer projects
people *will* end up with both a published copy (with a different
timestamp) and the auto-loaded copy, and we want graceful degradation
instead of fatal errors.

### Workbench schema must mirror the package schema

The workbench `database/migrations/` directory is what your test suite
runs against. It must reflect the SAME schema a consumer would see —
either by:

- **Letting the package auto-load do the work.** Don't reimplement the
  package's own `Schema::create` calls in the workbench. The service
  provider's `loadMigrationsFrom` fires during test boot too, so the
  package's own migrations create the tables in the testbench DB. The
  workbench only needs migrations for tables the *consumer* would
  provide (`users`, host-app fixture tables like `articles`).
- **Or, if the workbench duplicates the package schema for isolation,
  keeping it in lockstep with model changes.** When `Filable` switched
  to `HasUuids`, the workbench `filables` table needed the matching
  `uuid('id')`. Skipping the workbench update means the test suite
  silently rots — 39 tests went red in laravel-files for ~5 weeks
  before anyone noticed, because nothing in CI was screaming about
  the model/schema mismatch.

Pick the first option for new packages. It's less code and
self-consistent: a passing test suite proves the consumer's install
flow works.

### Deviation: laravel-addresses

`laravel-addresses` keeps the original `create_blax_address_tables.php.stub`
as a publish-only stub because some downstream apps already published a
heavily-customised version (UUID PKs, extra columns) and we cannot safely
re-run the baseline against them. *Additive* migrations there still
follow this principle — plain `.php` files, auto-loaded. New packages
should default to the full hybrid (laravel-roles style); only use the
laravel-addresses split if you have an existing customisation problem to
work around.

---

## 2. README structure (open-source packages)

Every Blax Software OSS package README has the **same four mandatory
anchors** and the same final closer. Between them the package author is
free to grow the README to whatever depth the feature surface needs.

### The skeleton

| # | Section | Status |
|---|---|---|
| 1 | OSS banner (linked from laravel-workkit) | **Mandatory** |
| 2 | Title + badges below | **Mandatory** |
| 3 | Emoji feature list of what the package provides | **Mandatory** |
| 4 | Quickstart (install + minimum viable usage) | Suggested |
| 5 | Quick configuration overview of features | Suggested |
| 6 | Anything else (advanced usage, testing, security, credits, license, changelog, etc.) | Free-form |
| 7 | Star History | **Mandatory** |

The order matters — consumers skim top-to-bottom. The four mandatory
items (1, 2, 3, 7) bookend every README and make every Blax repo feel
familiar within two seconds. Between section 3 and 7 the author has
total freedom: a tiny package may go banner → title+badges → features →
quickstart → star history and stop; a large one (see laravel-mail) can
have 15 sections of advanced material in between.

### The canonical skeleton, fleshed out

```markdown
<!-- 1. OSS banner — mandatory, always first, no blank line before the H1 -->
[![Blax Software OSS](https://raw.githubusercontent.com/blax-software/laravel-workkit/master/art/oss-initiative-banner.svg)](https://github.com/blax-software)

<!-- 2. Title + badges — mandatory; title-case, no "Package" suffix -->
# <Title>

<!-- Pick badges that are common in this repo's stack — see "Badges" below -->
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/laravel-10.x--13.x-orange)](https://laravel.com)

<One-sentence description of what the package does — the elevator pitch.>

<!-- 3. Emoji feature list — mandatory -->
## Features

- 🛍️ **Headline feature** — short benefit-oriented line
- 💰 **Next feature** — what it gives the consumer
- 📦 **…** — keep each item one line; emoji + bold short title + benefit
- 🎯 …

<!-- 4. Quickstart — suggested -->
## Quick Start

```bash
composer require blax-software/<repo>
php artisan migrate
```

<The shortest possible "hello world" — get a consumer to a working call
in under 30 seconds. Use real model names from the package.>

<!-- 5. Quick configuration overview — suggested -->
## Configuration

<Brief tour of the most useful config knobs (table-name overrides, model
bindings, the run_migrations flag, env vars). Don't repeat the whole
config file — link to `config/<package>.php` in the repo for the rest.>

<!-- 6. Anything else — free-form. Examples below; pick what's relevant. -->
## Advanced Usage / Requirements / Testing / Documentation / Security / Credits / License / Changelog …

<!-- 7. Star History — mandatory, always last -->
## Star History

<a href="https://www.star-history.com/?repos=blax-software%2F<repo>&type=date&legend=top-left">
 <picture>
   <source media="(prefers-color-scheme: dark)" srcset="https://api.star-history.com/chart?repos=blax-software/<repo>&type=date&theme=dark&legend=top-left" />
   <source media="(prefers-color-scheme: light)" srcset="https://api.star-history.com/chart?repos=blax-software/<repo>&type=date&legend=top-left" />
   <img alt="Star History Chart" src="https://api.star-history.com/chart?repos=blax-software/<repo>&type=date&legend=top-left" />
 </picture>
</a>
```

### Notes on each anchor

1. **OSS banner** — always the very first thing in the file, no blank
   line before the H1. Linked back to the blax-software org. The SVG is
   served from `laravel-workkit/art/oss-initiative-banner.svg` so all
   packages share one source of truth.
2. **Title + badges** — title-case, no package-y suffix ("Laravel Roles",
   not "Laravel Roles Package"). Badges sit directly under the H1 (no
   intervening prose). See "Badges" below for what badges to use.
3. **Emoji feature list** — this is the section consumers skim hardest.
   One bullet per line, format `- <emoji> **<short bold title>** — <one-line
   benefit>`. Lead with the most compelling features. laravel-shop is the
   gold-standard reference. Don't nest sub-bullets; if you need more
   detail, link out to a section further down.
7. **Star History** — the star-history.com embed scoped to this repo,
   always the very last thing. Update the repo slug in all four
   occurrences.

### Badges (anchor 2 detail)

There is no fixed badge set. **Use the badges that are common in this
repo's stack**:

- Laravel composer package → PHP version, Laravel version, License, and
  optionally Packagist version + a Tests CI badge once the workflow
  exists.
- Nuxt / Vue project → Node version, framework version, npm version,
  build status, etc.
- Minecraft plugin → the relevant ecosystem badges (Spigot/Paper version,
  bStats, etc.).

The rule: pick what a visitor from that ecosystem expects to see — not a
fixed prescription. Don't ship a badge that's broken (e.g. a Tests CI
badge pointing at a workflow that doesn't exist yet).

### Forbidden

- A blank line between the OSS banner and the H1.
- "Click here", "More info"-style filler links.
- An "About" section before the features — the one-line description
  above the features list is enough.
- Marketing emojis in section headings. The features list is the only
  place emojis live.

---

## 3. Cross-cutting principles

These apply to **every** Blax composer package, regardless of stack.

### UUIDs or ULIDs for everything

Primary keys are always sortable, non-sequential identifiers — **either
UUIDv4 or ULID**. Integer auto-increments are forbidden in package
schemas. Foreign keys use `foreignUuid(...)` / `foreignUlid(...)`,
polymorphic relations use `uuidMorphs(...)` / `ulidMorphs(...)`. Pick one
style per package and stick with it; don't mix UUIDs and ULIDs in the
same package.

Why: consumer projects in the Blax fleet are UUID/ULID-based (see
[[blax-laravel-conventions]]). A package that returned a `bigint` PK
would force the host to use `morphs()` instead of `uuidMorphs()` to
attach things to it, breaking the host's schema convention.

### Model bindings via config

Every model the package owns is bound in the service provider through a
`<package>.models.*` config key, e.g.:

```php
// config/<package>.php
'models' => [
    'product' => \Blax\Shop\Models\Product::class,
],

// <Package>ServiceProvider::register()
$this->app->bind(
    \Blax\Shop\Models\Product::class,
    fn ($app) => $app->make($app->config['shop.models.product'])
);
```

This lets a consumer extend the package's model (add casts, scopes,
methods) and rebind via config without forking the package. Every
internal reference inside the package must resolve through the container
(`app(Product::class)`, dependency injection, etc.) — never `new
Product()` or `Product::query()` directly.

Reference implementations: [laravel-roles/src/RolesServiceProvider.php:91-100](/home/a6a2f5842/Documents/Repos/laravel-roles/src/RolesServiceProvider.php#L91-L100),
[laravel-addresses/src/AddressesServiceProvider.php:149-165](/home/a6a2f5842/Documents/Repos/laravel-addresses/src/AddressesServiceProvider.php#L149-L165).

### Backward compatibility

Every release of a Blax package must be backward-compatible with the
previous minor version. Consumers must be able to `composer update` and
keep running without code changes.

Concretely:

- **Schema changes are additive only.** New columns, new tables, new
  indexes are fine — but never drop or rename an existing column, never
  rename a table, never narrow a type. If you absolutely must, deprecate
  first and remove only on a major version bump.
- **Public PHP API is stable.** No removing methods, no renaming
  classes, no narrowing parameter types or widening return types in
  surprising ways. Add new methods rather than changing signatures.
- **Config keys never disappear.** New keys are fine and get sensible
  defaults via `mergeConfigFrom`. Existing keys keep working forever —
  if they become obsolete, the package ignores them, doesn't error.
- **Events, traits, contracts** carry the same stability guarantee as
  public methods.

Why: every internal Blax project pins package versions as `dev-master`
(see [[blax-laravel-conventions]]). A breaking change to a package
breaks every project on the next `composer update`. Treat every push to
`master` as a potentially-shipped release.

### Naming: composer name, PHP namespace, README title

These three labels live in different files but tell the same story —
keep them aligned.

- **Composer package name** — `blax-software/laravel-<name>` for Laravel
  composer packages. Universal across the fleet (laravel-roles,
  laravel-shop, laravel-addresses, laravel-files, laravel-mail,
  laravel-websockets, laravel-workkit). For non-Laravel packages drop
  the `laravel-` prefix and use the relevant ecosystem prefix.
- **PHP namespace** — `Blax\<PackageName>` (e.g. `Blax\Shop`,
  `Blax\Roles`, `Blax\Addresses`). The *original* intent was the longer
  `BlaxSoftware\Laravel<PackageName>` form, but in practice all but one
  package settled on the short `Blax\<PackageName>` form, so that's the
  working standard for new packages. The one outlier
  (`BlaxSoftware\LaravelWebSockets`) is grandfathered — don't migrate
  it.
- **README H1 title** — just the nice human-readable name of the
  package. If it's a Laravel package, prefix with `Laravel`. **No
  "Package" suffix.** So: `# Laravel Shop`, `# Laravel Roles`,
  `# Laravel Mail`. Not `# Laravel Shop Package`.

### Money in integer cents — never floats

Monetary columns are stored as **integer cents** (or the equivalent smallest
currency unit). Never `decimal`, never `float`. The package's casts mark
them `'integer'`, the migrations declare them `integer` (or
`unsignedBigInteger` for large totals like Stripe's `amount_capturable`).

Why: float arithmetic is lossy in non-obvious ways (`0.1 + 0.2 !== 0.3`).
A `decimal` column avoids the float problem at the storage layer but
re-introduces it the moment a value leaves the DB into PHP. Integers
sidestep both. The formatting step (cents → "€19.99") happens at the
*presentation* boundary — never in the model, the service, or the DB.

Currency is a separate column (`currency`, ISO 4217), never inferred from
the integer.

Reference: `Blax\Shop\Models\ProductPrice::$casts` has `unit_amount`,
`sale_unit_amount`, and tier `unit_amount`/`flat_amount` all cast as
`'integer'`.

### Atomic conditional UPDATEs over `lockForUpdate` dances

When you need to decrement a counter race-safely (stock, balance, available
seats), prefer a single atomic conditional UPDATE over a transaction +
`lockForUpdate` + check + update.

```php
// ✅ Atomic — one statement, race-safe
$affected = static::whereKey($this->getKey())
    ->where('available_copies', '>=', $quantity)
    ->update(['available_copies' => DB::raw(
        'available_copies - '.(int) $quantity
    )]);

return $affected > 0;

// ❌ Transactional dance — three statements, locks the row, more code
DB::transaction(function () use ($id, $quantity) {
    $row = static::whereKey($id)->lockForUpdate()->first();
    if ($row->available_copies < $quantity) {
        throw new NotAvailableException();
    }
    $row->decrement('available_copies', $quantity);
});
```

The atomic form returns the same race-safety guarantee with no transaction
and no row lock — the database honours the `WHERE` and the `UPDATE`
together. If 0 rows match, you know the constraint was violated and your
caller decides how to translate that into a 422 / exception / fallback.

Why: simpler code, fewer round-trips, no transaction state to manage. The
atomic form also composes better in queue jobs and serverless contexts
where transaction lifetimes are dicey.

Use the transactional form only when you genuinely need multi-row consistency
(e.g. "decrement stock AND insert order line item — both or neither"). In
that case the transaction stays small and only wraps the multi-step work.

### Automatic updates — no user action for migration updates

When a package author ships a new migration, a consumer must be able to
get it by running just:

```bash
composer update
php artisan migrate
```

No `vendor:publish` step. No manual file copying. No "edit your
migration to add this column" instructions in the changelog. The hybrid
migration pattern (section 1) is what makes this work — `loadMigrationsFrom`
picks up new files from `vendor/` automatically, and the additive-only
schema rule above guarantees the new migration won't break existing
data.

This is what separates a "Blax-grade" package from a typical
Laravel-ecosystem package that requires `php artisan
vendor:publish --tag=foo-migrations` after every upgrade.

---

## 4. Subclassable models: every relation declares its foreign key

If your package's model is meant to be subclassed by consumers (a host app's
`Book extends Product`, `Invoice extends Document`, …), **every `hasMany`,
`hasOne`, and `belongsToMany` on that model must declare the foreign key
explicitly**. Don't rely on Eloquent's convention to infer it from the
parent class name.

```php
// ✅ Explicit — survives subclassing
public function stocks(): HasMany
{
    return $this->hasMany(
        config('shop.models.product_stock', ProductStock::class),
        'product_id'
    );
}

// ❌ Convention-driven — breaks the moment a consumer extends
public function stocks(): HasMany
{
    return $this->hasMany(ProductStock::class);
    // When called on Book extends Product, Eloquent guesses `book_id`
    // and the relation either errors (no such column) or silently
    // returns an empty collection.
}
```

This is the most common way a package "appears to support subclassing" but
silently breaks for consumers. Subclassing is the canonical Laravel
extensibility mechanism — far simpler than wrappers, decorators, or
service rebinding — but a single un-prefixed FK on a hasMany ruins it.

The same rule applies to:

- `hasMany` / `hasOne` — pass `'parent_id'` (or whatever the actual column
  is) as the second argument.
- `belongsToMany` — pass the pivot table name and both FK columns
  explicitly, since the pivot name is *also* inferred from the class.
- Polymorphic morphs (`morphMany`, `morphTo`) are safe — they use the
  `*_type` / `*_id` columns directly, not the class name.

Tests for this principle:

- Spin up a bare subclass in a test fixture (`class SubclassedProduct
  extends Product {}`) and assert each relation returns rows. If the FK
  was inferred from the subclass name, the assertion fails on the insert
  or the select.

Reference: [Blax\Shop\Models\Product::attributes(), actions()](/home/a6a2f5842/Documents/Repos/laravel-shop/src/Models/Product.php), [Blax\Shop\Traits\HasStocks::stocks(), allStocks()](/home/a6a2f5842/Documents/Repos/laravel-shop/src/Traits/HasStocks.php), [tests/Feature/Product/ProductSubclassFkTest.php](/home/a6a2f5842/Documents/Repos/laravel-shop/tests/Feature/Product/ProductSubclassFkTest.php) — the regression test built specifically for this rule.

Why: a Blax package's value is amplified by being trivially extensible.
A library that wants to use `laravel-shop` shouldn't model `Book` next
to `Product`; it should `class Book extends Product` and gain stocks /
prices / categories / actions for free. That only works if the relations
keep pointing at `product_id` regardless of the calling subclass.

---

## 5. Domain data lives in tables, policy knobs live in config

Anything that varies **per-record** belongs in a table. Anything that
applies **app-wide** belongs in config. Don't blur the line.

| Belongs in config | Belongs in a table |
|---|---|
| Default loan duration in weeks | The actual due-date of each loan |
| Maximum extensions allowed | This loan's count of extensions used |
| Whether Stripe is enabled | A product's price |
| Cart expiration window | A cart's expiry timestamp |
| Currency code default | An order's actual currency |
| Whether to auto-publish migrations | What columns a table has |

The wrong answer: storing per-product pricing tiers in
`config('shop.loan.pricing')` — every product has to share one ladder,
host apps can't differentiate, and the data is uneditable through the
admin UI. The right answer is a `product_price_tiers` table with one row
per tier, FK to `product_prices`.

The "config-vs-data" smell test: ask "can two records sensibly disagree
about this value?" If yes, it's data. If no, it's config.

Edge case — **defaults that policy can override**: the default loan
duration sits in config (`shop.loan.default_duration_weeks = 2`) but a
specific borrower might have a 4-week limit (data, on the user record). The
loan creation logic reads config as the floor, then lets per-record data
override. Both layers coexist, neither "wins" — config is the policy,
data is the exception.

Reference: [Blax\Shop\Models\ProductPriceTier](/home/a6a2f5842/Documents/Repos/laravel-shop/src/Models/ProductPriceTier.php) — pricing as data;
[config('shop.loan')](/home/a6a2f5842/Documents/Repos/laravel-shop/config/shop.php) — duration / extension policy as config.

---

## 6. Lifecycle traits split fat models

When a model accumulates 200+ lines of methods around one domain concept
(booking lifecycle, loan lifecycle, audit log, soft archival …), extract
that concept into a **domain-named trait** named after the *concept*, not
the model.

```php
// ✅ Concept-named trait — co-located, importable, separately testable
use HasBookingLifecycle, HasLoanLifecycle;

// ❌ Bag-of-traits with model-derived names — fans out infinitely
use ProductPurchaseScopes, ProductPurchaseMethods, ProductPurchaseHelpers;
```

The good trait names describe what they do (booking lifecycle, loan
lifecycle); the bad ones just describe where they came from
(ProductPurchaseScopes). The first style stays useful when another model
needs the same behavior — `HasLoanLifecycle` could attach to a future
`Subscription` model too. The second is impossible to lift out.

Rules of thumb:

- **One concept per trait.** If you can't describe the trait in one
  sentence ("loan extension / return semantics on a purchase row"), it's
  doing too much. Split.
- **Unit-test the trait directly.** If the trait can only be tested via
  the host model's integration paths, the trait has hidden coupling. The
  unit test for a lifecycle trait should be able to spin up a bare model
  + trait and exercise the methods.
- **Co-locate scopes with the methods that use the same domain meta
  keys.** A scope reading `meta->returned_at` belongs next to the method
  that writes `meta.returned_at`.
- **Don't move the host's `protected $casts` or `$fillable`** into the
  trait. Those stay on the model — the trait declares *behavior*, not
  *schema*.

Reference: [Blax\Shop\Traits\HasBookingLifecycle](/home/a6a2f5842/Documents/Repos/laravel-shop/src/Traits/HasBookingLifecycle.php), [Blax\Shop\Traits\HasLoanLifecycle](/home/a6a2f5842/Documents/Repos/laravel-shop/src/Traits/HasLoanLifecycle.php) — extracted from `ProductPurchase` so the model declares its data shape and composes its behavior.

---

## 7. API resource translators decouple internal vocabulary from public contracts

Eloquent column names follow the package's internal vocabulary —
e-commerce in `laravel-shop`'s case (`from`, `until`, `amount_paid`,
`purchasable_*`). Direct serialization leaks that vocabulary into every
host app's API and into every external integration. That's a coupling no
host wants.

**The package ships a base `JsonResource` that translates internal names
to domain-flavored names**, with override hooks for the parts a host
inevitably needs to customize.

```php
// In the package — ships the base translator
class PurchaseResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'item' => $this->resolveItem(),
            'loaned_at' => optional($this->from)->toIso8601String(),  // ← `from` → `loaned_at`
            'due_at' => optional($this->until)->toIso8601String(),    // ← `until` → `due_at`
            'returned_at' => $this->returnedAt(),
            'status' => $this->getDomainStatus(),                     // ← derived
            'accrued_cost' => $this->from ? $this->accruedCost() : null,
        ];
    }

    // Hook for host apps to point at their own nested resource.
    protected function purchasableResource(): ?string { return null; }
}

// In the host app — minimal subclass for domain vocabulary
class LoanResource extends PurchaseResource
{
    public function toArray($request): array
    {
        $payload = parent::toArray($request);
        $payload['book'] = $payload['item'];           // rename per domain
        unset($payload['item']);
        return $payload;
    }

    protected function purchasableResource(): ?string
    {
        return BookResource::class;                    // point at app resource
    }
}
```

Rules:

- **Never serialize the model directly.** A bare `Resource::make($model)`
  with no translation layer ships the package's column names to the
  caller — change those names and every consumer breaks. The translator
  is the contract.
- **The translator name describes the domain output, not the source
  model.** `PurchaseResource` is fine; `ProductPurchaseResource` is fine;
  but if the resource is loan-flavored, name it `LoanResource` and have
  it translate.
- **Hooks for subclasses are explicit methods, not protected attributes
  on the resource.** A `purchasableResource()` method is overridable; a
  `$purchasableResource = …` property is one Eloquent quirk away from
  not working.

Reference: [Blax\Shop\Http\Resources\PurchaseResource](/home/a6a2f5842/Documents/Repos/laravel-shop/src/Http/Resources/PurchaseResource.php) — the package translator. [App\Http\Resources\LoanResource](/home/a6a2f5842/Documents/Repos/moonshiner-library/app/Http/Resources/LoanResource.php) — the moonshiner library's domain subclass.

Why: it's the only practical way to refactor internal column names without
a breaking-change release. The package can rename `until` to `valid_until`
in a major version, and the translator absorbs the rename — consumers
don't notice.

---

## Checklist for a new Blax Laravel package

- [ ] `database/migrations/` contains real `.php` files (no `.stub`),
      timestamped from the package's first-release date.
- [ ] Service provider auto-loads via `loadMigrationsFrom` and offers
      filename-preserving publishing.
- [ ] `config/<package>.php` exposes `run_migrations` (default true).
- [ ] Every `Schema::create` is guarded by `hasTable`, every column
      addition by `hasColumn`.
- [ ] README has the 4 mandatory anchors in order: OSS banner →
      title+badges → emoji feature list → … → Star History.
- [ ] No blank line between the OSS banner and the H1 title.
- [ ] Badges match the repo's stack (no broken badges like a CI badge
      pointing at a missing workflow).
- [ ] `composer require` + `php artisan migrate` is the *complete* install
      flow for the happy path.
- [ ] Composer name is `blax-software/laravel-<name>` (or stack-equivalent
      prefix for non-Laravel packages).
- [ ] PHP namespace is `Blax\<PackageName>`.
- [ ] README H1 is `# Laravel <Name>` (no "Package" suffix) for Laravel
      packages, just `# <Name>` otherwise.
- [ ] All primary keys are UUIDs or ULIDs (never integer auto-increments).
- [ ] Every package-owned model is bound via `<package>.models.*` config
      and resolved through the container, never `new` or static calls
      that bypass binding.
- [ ] The release is backward-compatible: no dropped columns / tables /
      methods / config keys, schema changes are additive only.
- [ ] `composer update` + `php artisan migrate` is the *complete* upgrade
      flow — no `vendor:publish` step required for migration updates.
- [ ] Money columns are integer cents (never `decimal`, never `float`),
      currency is a separate `string(3)` column.
- [ ] Counter-decrement paths use atomic conditional UPDATEs; transactional
      `lockForUpdate` only appears where multi-row consistency demands it.
- [ ] Every `hasMany` / `hasOne` / `belongsToMany` on a model that's
      intended to be subclassable declares its foreign key explicitly.
      A regression test exercises a bare subclass through each relation.
- [ ] Per-record data lives in tables; app-wide policy lives in config.
      Pricing tiers, due dates, statuses, currencies → tables. Default
      durations, expiration windows, feature flags → config.
- [ ] Domain behavior on models lives in concept-named traits (e.g.
      `HasLoanLifecycle`), unit-testable in isolation, never named after
      the host model (no `ProductPurchaseScopes`).
- [ ] The package ships a `JsonResource` translator for each model
      exposed via API, so host apps subclass for domain vocabulary
      without leaking internal column names through the API boundary.
