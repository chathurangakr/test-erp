<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The next code in a master's sequence: PREFIX-001, PREFIX-002, …
 *
 * The number is one more than the highest number already used with that prefix, whatever its
 * padding (SUP-27, SUP-0028 and SUP-029 all count as 27, 28 and 29). The rows are read under lock,
 * so inside a transaction two people adding at the same moment cannot get the same code; the
 * unique index on `code` is the last line of defence. Codes without the prefix are ignored.
 */
final class SequentialCode
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function next(string $model, string $prefix, int $digits): string
    {
        $prefix = mb_strtoupper(trim($prefix)).'-';
        $pattern = '/^'.preg_quote($prefix, '/').'(\d+)$/';

        $highest = $model::query()
            ->where('code', 'like', $prefix.'%')
            ->lockForUpdate()
            ->pluck('code')
            ->map(fn (string $code): int => preg_match($pattern, $code, $match) ? (int) $match[1] : 0)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($highest + 1), $digits, '0', STR_PAD_LEFT);
    }
}
