<?php

namespace App\Http\Resources\V1;

use App\Support\DecimalMath;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for every v1 API resource — the one place the wire format is decided.
 *
 *   dates        "2026-09-26"                  (calendar dates carry no time or zone)
 *   timestamps   "2026-09-26T16:25:39Z"         (ISO 8601, always UTC)
 *   quantities   "10000.000"                    (string, 3 decimals — never a float)
 *   money        "1025000.000000"               (string, 6 decimals — never a float)
 *
 * Decimals travel as strings because a JSON number is parsed as a binary float by most
 * clients, and a float cannot hold 102.500000 × 10,000 exactly. The mobile app parses them
 * with decimal-safe code. See docs/API_V1.md §7.
 */
abstract class ApiResource extends JsonResource
{
    protected function date(?CarbonInterface $value): ?string
    {
        return $value?->toDateString();
    }

    protected function timestamp(?CarbonInterface $value): ?string
    {
        return $value?->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * A quantity as an exact 3-decimal string.
     */
    protected function quantity(int|string|null $value): ?string
    {
        return $value === null ? null : DecimalMath::quantity(DecimalMath::of($value));
    }

    /**
     * A monetary or cost amount as an exact 6-decimal string.
     */
    protected function money(int|string|null $value): ?string
    {
        return $value === null ? null : DecimalMath::cost(DecimalMath::of($value));
    }

    /**
     * An enum as {"value", "label"} so the app never hard-codes display text.
     *
     * @return array{value: string, label: string}|null
     */
    protected function enum(?BackedEnum $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return [
            'value' => (string) $value->value,
            'label' => method_exists($value, 'label') ? $value->label() : (string) $value->value,
        ];
    }

    /**
     * A related record reduced to the few fields a list needs — never the whole row.
     *
     * @return array{id: int, code: string|null, name: string|null}|null
     */
    protected function ref(mixed $model, string $codeField = 'code', string $nameField = 'name'): ?array
    {
        if ($model === null) {
            return null;
        }

        return [
            'id' => $model->getKey(),
            'code' => $model->{$codeField} ?? null,
            'name' => $model->{$nameField} ?? null,
        ];
    }

    /**
     * A user reference for audit fields — who created, approved, posted or reversed.
     *
     * @return array{id: int, name: string}|null
     */
    protected function userRef(mixed $user): ?array
    {
        return $user === null ? null : ['id' => $user->getKey(), 'name' => $user->name];
    }
}
