<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The controls every list screen shares: rows per page (25 / 50 / 100 / All), sorting by a
 * column, and a From / To period on the list's own date. Each screen states which columns it can
 * be sorted by and which date the period applies to; anything else in the request is ignored, so
 * a URL can never sort by an arbitrary column. Screen-specific filters (status, customer, search)
 * stay in the controller.
 *
 * "All" shows every matching record — the whole history unless a period is chosen — on one page,
 * up to config('lists.all_limit'); beyond that it pages on.
 */
final class ListQuery
{
    /** @var array<string, string|Closure(Builder, string): void> */
    private array $sorts = [];

    private string $defaultSort = '';

    private string $defaultDirection = 'desc';

    private ?string $dateColumn = null;

    private function __construct(private readonly Request $request) {}

    public static function from(Request $request): self
    {
        return new self($request);
    }

    /**
     * The columns a user may sort by: key => column name, or a closure that orders the query.
     *
     * @param  array<string, string|Closure(Builder, string): void>  $sorts
     */
    public function sortable(array $sorts, string $default, string $direction = 'desc'): self
    {
        $this->sorts = $sorts;
        $this->defaultSort = $default;
        $this->defaultDirection = $direction;

        return $this;
    }

    /** The date column the From / To period filters on. */
    public function period(string $column): self
    {
        $this->dateColumn = $column;

        return $this;
    }

    /**
     * Applies the period and the sort, then pages the result with the chosen page size.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public function paginate(Builder $query): LengthAwarePaginator
    {
        $this->applyPeriod($query);
        $this->applySort($query);

        $perPage = $this->perPage();

        if ($perPage === 'all') {
            $perPage = max(1, min((clone $query)->toBase()->getCountForPagination(), (int) config('lists.all_limit')));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * What the screen shows back in its controls.
     *
     * @return array{per_page: int|string, sort: string, direction: string, from: string|null, to: string|null}
     */
    public function filters(): array
    {
        return [
            'per_page' => $this->perPage(),
            'sort' => $this->sort(),
            'direction' => $this->direction(),
            'from' => $this->date('from'),
            'to' => $this->date('to'),
        ];
    }

    /** @return int|'all' */
    private function perPage(): int|string
    {
        $value = (string) $this->request->query('per_page', '');

        if ($value === 'all') {
            return 'all';
        }

        $options = config('lists.per_page_options');

        return in_array((int) $value, $options, true) ? (int) $value : (int) config('lists.default_per_page');
    }

    private function sort(): string
    {
        $sort = (string) $this->request->query('sort', '');

        return array_key_exists($sort, $this->sorts) ? $sort : $this->defaultSort;
    }

    private function direction(): string
    {
        $direction = (string) $this->request->query('direction', '');

        if (! array_key_exists((string) $this->request->query('sort', ''), $this->sorts)) {
            return $this->defaultDirection;
        }

        return in_array($direction, ['asc', 'desc'], true) ? $direction : $this->defaultDirection;
    }

    private function date(string $key): ?string
    {
        if ($this->dateColumn === null) {
            return null;
        }

        $value = (string) $this->request->query($key, '');

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function applyPeriod(Builder $query): void
    {
        if ($this->dateColumn === null) {
            return;
        }

        $column = $query->qualifyColumn($this->dateColumn);
        $from = $this->date('from');
        $to = $this->date('to');

        if ($from !== null) {
            $query->whereOnOrAfter($column, $from);
        }

        if ($to !== null) {
            $query->whereOnOrBefore($column, $to);
        }
    }

    private function applySort(Builder $query): void
    {
        $sort = $this->sort();
        $direction = $this->direction();
        $order = $this->sorts[$sort] ?? null;

        if ($order instanceof Closure) {
            $order($query, $direction);
        } elseif (is_string($order)) {
            $query->orderBy($query->qualifyColumn($order), $direction);
        }

        // A steady order inside equal values, newest first, so paging never repeats a row.
        $query->orderBy($query->getModel()->getQualifiedKeyName(), $direction === 'asc' && $order !== null ? 'asc' : 'desc');
    }
}
