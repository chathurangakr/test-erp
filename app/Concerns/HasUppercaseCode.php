<?php

namespace App\Concerns;

/**
 * Normalises a model's `code` attribute to uppercase.
 *
 * Applied on the model rather than in a controller so the rule holds everywhere the model
 * is written — seeders, factories, console commands and the future REST API included.
 */
trait HasUppercaseCode
{
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null ? null : mb_strtoupper(trim($value));
    }
}
