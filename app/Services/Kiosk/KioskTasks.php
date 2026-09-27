<?php

namespace App\Services\Kiosk;

use App\Enums\Permission;
use App\Models\User;

/**
 * The jobs simple mode offers, and what each requires.
 *
 * "Done" on the kiosk saves AND posts, so a job appears only for someone who holds both the
 * create and the post permission for its document — the owner's rule: simple mode is for people
 * allowed to post. Stock is read-only and needs only inventory.view. The routes enforce the same
 * permissions on the server; this list only decides which tiles to draw.
 */
class KioskTasks
{
    /**
     * @return array<string, list<Permission>>
     */
    public static function requirements(): array
    {
        return [
            'receive' => [Permission::PurchasingGrnCreate, Permission::PurchasingGrnPost],
            'dispatch' => [Permission::SalesDeliveryCreate, Permission::SalesDeliveryPost],
            'dehusking' => [Permission::ProductionCreate, Permission::ProductionPost],
            'husk-chipping' => [Permission::ProductionCreate, Permission::ProductionPost],
            'copra' => [Permission::ProductionCreate, Permission::ProductionPost],
            'stock' => [Permission::InventoryView],
        ];
    }

    /**
     * The tasks this user may use, in display order.
     *
     * @return list<string>
     */
    public function availableTo(User $user): array
    {
        return array_keys(array_filter(
            self::requirements(),
            fn (array $permissions): bool => collect($permissions)->every(fn (Permission $p): bool => $user->hasPermission($p)),
        ));
    }
}
