<?php

namespace App\Livewire\Concerns;

trait ParsesIds
{
    /**
     * A positive whole number from the URL or a form, or null.
     */
    protected static function idFrom(string|int|null $value): ?int
    {
        $value = trim((string) $value);

        return preg_match('/^[1-9]\d{0,9}$/', $value) ? (int) $value : null;
    }
}
