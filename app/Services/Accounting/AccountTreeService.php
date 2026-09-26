<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Support\DecimalMath;
use Illuminate\Support\Collection;

/**
 * Turns flat account totals into the chart's own hierarchy, with subtotals.
 *
 * Both financial statements present accounts the way the chart organises them — Assets, then
 * Cash and Cash Equivalents, then Cash on Hand and Bank — so the grouping lives here once
 * rather than in each statement.
 *
 * **A parent is counted exactly once.** Its subtotal is its own direct postings plus its
 * children's subtotals. Group headers normally refuse posting and so contribute nothing of
 * their own, but the arithmetic does not depend on that: if a parent ever becomes postable,
 * its own balance is added alongside its children rather than being lost or double-counted.
 * See docs/ACCOUNTING_RULES.md §24.
 */
class AccountTreeService
{
    public function __construct(private readonly AccountingBalanceService $balances) {}

    /**
     * The accounts of one or more types, nested, with presented subtotals.
     *
     * Branches that moved nothing at all are dropped: a statement listing forty untouched
     * accounts hides the handful that matter. A branch is kept when it or any descendant has
     * a non-zero balance.
     *
     * @param  list<AccountType>  $types
     * @param  Collection<int, array{debit: string, credit: string, net: string}>  $totals
     * @return array{rows: list<array<string, mixed>>, total: string}
     */
    public function build(array $types, Collection $totals, bool $includeEmpty = false): array
    {
        $accounts = Account::query()
            ->whereIn('account_type', array_map(fn (AccountType $type): string => $type->value, $types))
            ->orderBy('code')
            ->get();

        $childrenByParent = $accounts->groupBy('parent_id');

        // A root here is an account whose parent is absent from this type selection — so a
        // liability sitting under an asset header, were that ever configured, still appears.
        $selectedIds = $accounts->pluck('id')->all();
        $roots = $accounts->filter(
            fn (Account $account): bool => $account->parent_id === null || ! in_array($account->parent_id, $selectedIds, true),
        );

        $rows = [];
        $total = DecimalMath::zero();

        foreach ($roots as $root) {
            $node = $this->node($root, $childrenByParent, $totals, 0, $includeEmpty);

            if ($node === null) {
                continue;
            }

            $rows[] = $node;
            $total = DecimalMath::add($total, $node['amount']);
        }

        return [
            'rows' => $rows,
            'total' => DecimalMath::cost($total),
        ];
    }

    /**
     * One account and everything beneath it.
     *
     * @param  Collection<int|null, Collection<int, Account>>  $childrenByParent
     * @param  Collection<int, array{debit: string, credit: string, net: string}>  $totals
     * @return array<string, mixed>|null
     */
    private function node(
        Account $account,
        Collection $childrenByParent,
        Collection $totals,
        int $depth,
        bool $includeEmpty,
    ): ?array {
        $ownNet = $totals[$account->id]['net'] ?? DecimalMath::cost('0');
        $ownAmount = $this->balances->presentationAmount($account->account_type, $ownNet);

        $children = [];
        $childrenTotal = DecimalMath::zero();

        foreach ($childrenByParent->get($account->id, collect()) as $child) {
            $node = $this->node($child, $childrenByParent, $totals, $depth + 1, $includeEmpty);

            if ($node === null) {
                continue;
            }

            $children[] = $node;
            $childrenTotal = DecimalMath::add($childrenTotal, $node['amount']);
        }

        // Own postings PLUS children — never one standing in for the other.
        $amount = DecimalMath::cost(DecimalMath::add($ownAmount, $childrenTotal));

        $moved = ! DecimalMath::isZero($ownAmount) || $children !== [];

        if (! $moved && ! $includeEmpty) {
            return null;
        }

        return [
            'account_id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->account_type->value,
            'depth' => $depth,
            'is_group' => $children !== [],
            // What this account itself holds, before its children.
            'own_amount' => $ownAmount,
            // This account and everything beneath it.
            'amount' => $amount,
            'children' => $children,
        ];
    }
}
