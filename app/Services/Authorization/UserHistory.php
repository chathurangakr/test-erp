<?php

namespace App\Services\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Does any business record point at this user?
 *
 * Found by reading the database's own foreign keys rather than a hand-written list, so a
 * table added next year that records `approved_by` is covered without anyone remembering to
 * update this class. Tables that merely belong to the user's login — sessions, passkeys, role
 * assignments, password resets — are not history and are ignored.
 *
 * A user with history is deactivated, never deleted: deleting them would either be refused by
 * the database or erase who did what. See docs/AUTHORIZATION_RULES.md §6.
 */
class UserHistory
{
    /** Tables that belong to the account itself rather than to the business. */
    private const ACCOUNT_TABLES = ['sessions', 'passkeys', 'role_user', 'password_reset_tokens'];

    public function hasHistory(User $user): bool
    {
        foreach ($this->references() as [$table, $column]) {
            if (DB::table($table)->where($column, $user->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every [table, column] whose foreign key points at users.id.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function references(): array
    {
        $references = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::ACCOUNT_TABLES, true)) {
                continue;
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if ($foreignKey['foreign_table'] !== 'users') {
                    continue;
                }

                foreach ($foreignKey['columns'] as $column) {
                    $references[] = [$table, $column];
                }
            }
        }

        return $references;
    }
}
