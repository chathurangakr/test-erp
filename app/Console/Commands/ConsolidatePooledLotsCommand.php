<?php

namespace App\Console\Commands;

use App\Actions\Inventory\ConsolidatePooledLots;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The one-time move of pooled products' old lot balances into their lot-free stock.
 *
 * Shows what would move and asks before writing. The movement is recorded under the named
 * user, like every other posting. See App\Actions\Inventory\ConsolidatePooledLots.
 */
#[Signature('inventory:consolidate-pooled-lots
    {--user= : Email of the person running the move (recorded as poster)}
    {--dry-run : Only show what would move}
    {--force : Do not ask for confirmation}')]
#[Description('Move pooled products\' old lot balances into lot-free stock (value-neutral, recorded)')]
class ConsolidatePooledLotsCommand extends Command
{
    public function handle(ConsolidatePooledLots $consolidate): int
    {
        $plan = $consolidate->plan();

        if ($plan === []) {
            $this->info('Nothing to move: no pooled product holds stock in a lot.');

            return self::SUCCESS;
        }

        $this->table(
            ['Product', 'Warehouse', 'Lot', 'Quantity'],
            array_map(fn (array $move): array => [
                $move['product']->code,
                $move['warehouse']->code,
                $move['lot']->lot_number,
                $move['quantity'],
            ], $plan),
        );

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $user = User::query()->where('email', (string) $this->option('user'))->where('is_active', true)->first();

        if ($user === null) {
            $this->error('Name an active user with --user=email; the movement is recorded under them.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Move these balances into lot-free stock?')) {
            $this->info('Nothing was written.');

            return self::SUCCESS;
        }

        $transaction = $consolidate->handle($user->id);

        $this->info("Recorded as {$transaction?->reference}. Quantities, values and averages are unchanged.");

        return self::SUCCESS;
    }
}
