<?php

namespace App\Enums;

/**
 * What kind of thing a fixed asset is — for grouping on screens only.
 */
enum AssetCategory: string
{
    case Vehicle = 'VEHICLE';
    case Machinery = 'MACHINERY';
    case Building = 'BUILDING';
    case Land = 'LAND';
    case Equipment = 'EQUIPMENT';
    case Furniture = 'FURNITURE';
    case Other = 'OTHER';

    /**
     * The display name, in the reader's language. Never save it — see App\Support\RecordText.
     */
    public function label(): string
    {
        return __(match ($this) {
            self::Vehicle => 'Vehicle',
            self::Machinery => 'Machinery',
            self::Building => 'Building',
            self::Land => 'Land',
            self::Equipment => 'Equipment',
            self::Furniture => 'Furniture',
            self::Other => 'Other',
        });
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
