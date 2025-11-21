<?php

namespace Blax\Workkit\Traits;

trait HasMeta
{
    public function getMeta(): object
    {
        return (object) $this->meta;
    }

    public final function updateMetaKey($key, $value, bool $update = true): self
    {
        $meta = $this->getMeta();
        $meta->{$key} = $value;
        $this->meta = (object) $meta;

        if ($update) {
            $this->update(['meta' => $this->meta]);
        }

        return $this;
    }
}
