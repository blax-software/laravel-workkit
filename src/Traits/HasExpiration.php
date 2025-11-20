<?php

namespace Blax\Workkit\Traits;

trait HasExpiration
{
    public static function bootWillExpire()
    {
        static::addGlobalScope('willExpire', function ($builder) {
            $builder->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
        });
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function extendByHours(int $hours, bool $expire_if_null = false): void
    {
        if ($this->expires_at === null && !$expire_if_null) {
            // Do not add expiration if it does not expire
            return;
        } elseif ($this->expires_at->isPast()) {
            $this->expires_at = now()->addHours($hours);
        } else {
            $this->expires_at = $this->expires_at->addHours($hours);
        }

        $this->save();
    }
}
