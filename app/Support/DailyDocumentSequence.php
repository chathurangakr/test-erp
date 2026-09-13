<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Generates daily sequential document identifiers: PREFIX-YYYYMMDD-000001.
 *
 * Shared by the inventory and purchasing reference generators so the concurrency handling
 * exists in exactly one place. Identifiers deliberately encode no business meaning — no
 * supplier, product or document classification — so a document can be re-classified
 * without its permanent reference becoming misleading.
 */
final class DailyDocumentSequence
{
    public const SEQUENCE_LENGTH = 6;

    /**
     * @param  class-string<Model>  $model
     * @param  string  $column  The column holding the reference; supplied internally only.
     */
    public function next(string $prefix, string $model, string $column, ?Carbon $date = null): string
    {
        $stamp = ($date ?? now())->format('Ymd');
        $pattern = "{$prefix}-{$stamp}-";

        // Read the current maximum inside whatever transaction the caller already holds,
        // so two concurrent posts cannot both settle on the same sequence number.
        $latest = $model::query()
            ->where($column, 'like', $pattern.'%')
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        $sequence = $latest === null
            ? 1
            : ((int) substr((string) $latest, -self::SEQUENCE_LENGTH)) + 1;

        return $pattern.str_pad((string) $sequence, self::SEQUENCE_LENGTH, '0', STR_PAD_LEFT);
    }
}
