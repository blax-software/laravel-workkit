<?php

namespace Blax\Workkit\Traits;

trait HasMetaTranslation
{
    use HasMeta;

    protected array $__localizedFallbackGuard = [];

    public function getMeta(): object
    {
        $r = (object) $this->meta;
        $r->i18n ??= (object) [];

        if (is_array($r->i18n)) {
            $r->i18n = (object) $r->i18n;
        }

        foreach (config('app.i18n.supporting') as $lang => $longlang) {
            if ($lang == 'en') {
                continue;
            }

            if (!isset($r->i18n->{$lang})) {
                $r->i18n->{$lang} = (object) [];
            } elseif (is_array($r->i18n->{$lang})) {
                // Normalize any existing array locale bucket to object
                $r->i18n->{$lang} = (object) $r->i18n->{$lang};
            }
        }

        return $r;
    }

    public function getLocalized($key, string|null $locale = null, bool $allowAttrFallback = true)
    {
        $locale = $locale ?: request()->get('locale') ?: app()->getLocale() ?: 'en';

        $meta = $this->getMeta();
        $i18n = $meta->i18n ?? null;

        $get = function ($container, $prop) {
            if ($container === null) {
                return null;
            }
            if (is_array($container)) {
                return $container[$prop] ?? null;
            }
            if (is_object($container)) {
                return $container->{$prop} ?? null;
            }
            return null;
        };

        // Build recursive fallback chain from config
        $fallbackMap = (array) config('app.i18n.fallback', []);
        $chain = [];
        $visited = [];

        $expand = function ($loc) use (&$expand, &$chain, &$visited, $fallbackMap) {
            if (!$loc || isset($visited[$loc])) {
                return;
            }
            $visited[$loc] = true;
            $chain[] = $loc;
            if (!isset($fallbackMap[$loc])) {
                return;
            }
            $targets = (array) $fallbackMap[$loc];
            foreach ($targets as $t) {
                $expand($t);
            }
        };

        $expand($locale);

        // Always ensure 'en' ends up as last resort if not already included
        if (!in_array('en', $chain, true)) {
            $expand('en');
        }

        $value = null;
        foreach ($chain as $loc) {
            // Prefer normalized meta object path
            $candidate = $get($get($i18n, $loc), $key);
            if ($candidate === null || $candidate === '') {
                // Try raw meta structure if different
                $candidate = $get($get($get($this->meta ?? null, 'i18n'), $loc), $key);
            }
            if ($candidate !== null && $candidate !== '') {
                $value = $candidate;
                break;
            }
        }

        // Optional model attribute fallback (raw attribute array only to avoid recursion)
        if (($value === null || $value === '') && $allowAttrFallback) {
            $attr = $this->attributes[$key] ?? null;
            if ($attr !== null && $attr !== '') {
                $value = $attr;
            }
        }

        // Apply model casts (e.g., json) when present so localized values respect attribute casting
        if ($value !== null && $value !== '') {
            try {
                $casts = method_exists($this, 'getCasts') ? $this->getCasts() : (property_exists($this, 'casts') ? (array) $this->casts : []);
                $cast = $casts[$key] ?? null;
                $castStr = is_string($cast) ? strtolower($cast) : '';

                $isJsonLike = (function ($t) {
                    if (!$t) return false;
                    $t = strtolower($t);
                    return $t === 'array' || $t === 'object' || $t === 'collection' || str_contains($t, 'json');
                })($castStr);

                if ($isJsonLike) {
                    if (method_exists($this, 'hasCast') && method_exists($this, 'castAttribute') && $this->hasCast($key)) {
                        // Let Eloquent do the casting for consistency
                        $value = $this->castAttribute($key, $value);
                    } else {
                        // Minimal manual handling for json/array/object casts
                        if (is_string($value)) {
                            $assoc = ($castStr === 'array' || str_contains($castStr, 'json'));
                            $decoded = json_decode($value, $assoc);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $value = $decoded;
                            }
                        } elseif (is_object($value) && ($castStr === 'array' || str_contains($castStr, 'json'))) {
                            $value = json_decode(json_encode($value), true);
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Swallow casting errors to avoid breaking localization
            }
        }

        return $value;
    }

    public function setLocalized($key, $value, string|null $locale = null, bool $save = false)
    {
        $locale = $locale ?: request()->get('locale') ?: app()->getLocale() ?: 'en';

        $meta = $this->getMeta();
        $i18n = (object) ($meta->i18n ?? []);

        if (
            $locale == 'en'
            && array_key_exists($key, $this->attributes)
        ) {
            $this->attributes[$key] = $value;
        }

        if (!isset($i18n->{$locale})) {
            $i18n->{$locale} = (object) [];
        } elseif (is_array($i18n->{$locale})) {
            // Normalize existing array to object before property assignment
            $i18n->{$locale} = (object) $i18n->{$locale};
        }

        $i18n->{$locale}->{$key} = $value;

        $meta->i18n = $i18n;
        $this->meta = (object) $meta;

        if ($save) {
            $this->update(['meta' => $this->meta]);
        }

        return $this;
    }

    public function wipeTranslation($locale = 'all')
    {
        $meta = $this->getMeta();
        $i18n = (object) ($meta->i18n ?? []);

        if ($locale === 'all') {
            // Wipe all translations
            $i18n = (object) [];
        } elseif (isset($i18n->{$locale})) {
            // Wipe specific locale
            $i18n->{$locale} = (object) [];
        }

        $meta->i18n = $i18n;
        $this->meta = (object) $meta;

        $this->update(['meta' => $this->meta]);

        return $this;
    }

    /**
     * Returns all supported languages that are still an empty object
     * */
    public function getMissingTranslationLanguagesAttribute()
    {
        $meta = $this->getMeta();
        $i18n = (object) ($meta->i18n ?? []);

        $missing = [];

        foreach (config('app.i18n.supporting') as $lang => $longlang) {
            if ($lang == 'en') {
                continue;
            }

            $container = $i18n->{$lang} ?? null;

            if ($container === null) {
                $missing[] = $lang;
            } elseif (is_array($container) && count($container) === 0) {
                $missing[] = $lang;
            } elseif (is_object($container) && count(get_object_vars($container)) === 0) {
                $missing[] = $lang;
            }
        }

        return $missing;
    }

    public final function getMissingTranslationKeysAttribute()
    {
        // assume static variable $translatables, where keys of translatable fillables are listed
        $missing = [];
        $translatables = property_exists($this, 'translatables') ? (array) $this->translatables : [];
        $supported_langs = array_keys(config('app.i18n.supporting', []));

        // get translatables from meta i18n as well
        $meta = $this->getMeta();
        $i18n_keys = array_keys((array) (((object) ($meta->i18n ?? []))?->en ?? []));
        $translatables = array_unique(array_merge($translatables, $i18n_keys));


        foreach ($translatables as $key) {
            foreach ($supported_langs as $lang) {
                if ($lang == 'en') {
                    continue;
                }

                $value = $this->getLocalized($key, $lang, false);

                if (
                    $value == null
                    || $value == ''
                    || $value == $this->getLocalized($key, 'en', false)
                ) {
                    $missing[$key][] = $lang;
                }
            }
        }

        return $missing;
    }

    public final function getMissingKeyLanguagesAttribute()
    {
        $missing = $this->missing_translation_keys;
        $result = [];

        foreach ($missing as $key => $langs) {
            foreach ($langs as $lang) {
                $result[$lang][] = $key;
            }
        }

        return $result;
    }

    // Generic dynamic attribute fallback to localization (replaces specific title accessor)
    public function __get($key)
    {
        $value = parent::__get($key);

        if (($value === null || $value === '')
            && !in_array($key, ['meta', 'i18n'])
            && empty($this->__localizedFallbackGuard[$key])
        ) {
            $this->__localizedFallbackGuard[$key] = true;
            try {
                $localized = $this->getLocalized($key, null, false);
            } finally {
                unset($this->__localizedFallbackGuard[$key]);
            }
            if ($localized !== null && $localized !== '') {
                return $localized;
            }
        }

        return $value;
    }

    public function scopeWhereI18n($query, $key, $value, $locale = null)
    {
        if ($locale) {
            $locale = $locale ?: app()->getLocale() ?: 'en';
            return $query->where("meta->i18n->{$locale}->{$key}", $value);
        } else {
            return $query->where(function ($q) use ($key, $value) {
                foreach (array_keys(config('app.i18n.supporting', [])) as $locale) {
                    $q->orWhere("meta->i18n->{$locale}->{$key}", $value);
                }
            });
        }
    }

    public function scopeOrWhereI18n($query, $key, $value, $locale = null)
    {
        if ($locale) {
            $locale = $locale ?: app()->getLocale() ?: 'en';
            return $query->orWhere("meta->i18n->{$locale}->{$key}", $value);
        } else {
            return $query->orWhere(function ($q) use ($key, $value) {
                foreach (array_keys(config('app.i18n.supporting', [])) as $locale) {
                    $q->orWhere("meta->i18n->{$locale}->{$key}", $value);
                }
            });
        }
    }
}
