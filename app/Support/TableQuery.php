<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Server-side search, sorting and pagination for the DataTable component.
 *
 * Every list screen uses this, so the query strings, the row limits and the
 * payload shape stay the same across modules.
 *
 *     $users = TableQuery::for(User::query(), $request)
 *         ->searchable(['name', 'username'])
 *         ->sortable(['name', 'username', 'created_at'])
 *         ->defaultSort('name')
 *         ->paginate();
 */
class TableQuery
{
    /** Row counts the user may pick. Anything else falls back to the default. */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    public const DEFAULT_PER_PAGE = 25;

    /** @var list<string> */
    protected array $searchable = [];

    /** @var array<string, string> The sort name the page sends => what the query orders by. */
    protected array $sortable = [];

    protected ?string $defaultSort = null;

    protected string $defaultDirection = 'asc';

    /** @var array<string, mixed> */
    protected array $extra = [];

    public function __construct(
        protected Builder $query,
        protected Request $request,
    ) {}

    public static function for(Builder $query, Request $request): static
    {
        return new static($query, $request);
    }

    /**
     * Columns the search box looks in.
     *
     * Use dot notation for a related column, e.g. `roles.name`.
     *
     * @param  list<string>  $columns
     */
    public function searchable(array $columns): static
    {
        $this->searchable = $columns;

        return $this;
    }

    /**
     * Columns the user may sort by. Anything else is ignored, so the query
     * string cannot reach a column that is not listed.
     *
     * A keyed entry sorts a figure worked out in the query rather than
     * stored: `'balance' => 'balance_cents'` lets the page ask for
     * `sort=balance` and orders by the `balance_cents` the query selects.
     * The page still names only what is listed.
     *
     * @param  array<int|string, string>  $columns
     */
    public function sortable(array $columns): static
    {
        $this->sortable = [];

        foreach ($columns as $name => $column) {
            $this->sortable[is_string($name) ? $name : $column] = $column;
        }

        return $this;
    }

    public function defaultSort(string $column, string $direction = 'asc'): static
    {
        $this->defaultSort = $column;
        $this->defaultDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    /**
     * The search text, trimmed, or null when the box is empty.
     */
    public function search(): ?string
    {
        $search = trim((string) $this->request->query('search', ''));

        return $search === '' ? null : $search;
    }

    /**
     * The column to sort by. Falls back to the default when the query string
     * asks for a column that is not sortable.
     */
    public function sort(): ?string
    {
        $sort = (string) $this->request->query('sort', '');

        return array_key_exists($sort, $this->sortable) ? $sort : $this->defaultSort;
    }

    public function direction(): string
    {
        $requested = (string) $this->request->query('sort', '');
        $direction = strtolower((string) $this->request->query('direction', ''));
        $isExplicit = in_array($direction, ['asc', 'desc'], true);

        // The query string asked for a column that is not sortable, so the
        // sort fell back to the default. Its direction falls back as well,
        // rather than flipping a column the user never picked.
        if ($requested !== '' && ! array_key_exists($requested, $this->sortable)) {
            return $this->defaultDirection;
        }

        if ($isExplicit) {
            return $direction;
        }

        return $this->sort() === $this->defaultSort ? $this->defaultDirection : 'asc';
    }

    public function perPage(): int
    {
        $perPage = (int) $this->request->query('per_page', self::DEFAULT_PER_PAGE);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true)
            ? $perPage
            : self::DEFAULT_PER_PAGE;
    }

    /**
     * Filters this screen adds of its own, such as a date range.
     *
     * They are sent back to the DataTable component inside the same state
     * object, so the component carries them on every search, sort and page
     * change without knowing what they mean.
     *
     * @param  array<string, mixed>  $filters
     */
    public function extra(array $filters): static
    {
        $this->extra = $filters;

        return $this;
    }

    /**
     * The state the DataTable component sends back on every change.
     *
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return array_merge($this->extra, [
            'search' => $this->search(),
            'sort' => $this->sort(),
            'direction' => $this->direction(),
            'per_page' => $this->perPage(),
        ]);
    }

    /**
     * Run the query and return one page of rows.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginate(?int $perPage = null): LengthAwarePaginator
    {
        $this->applySearch();
        $this->applySort();

        return $this->query
            ->paginate($perPage ?? $this->perPage())
            ->withQueryString();
    }

    protected function applySearch(): void
    {
        $search = $this->search();

        if ($search === null || $this->searchable === []) {
            return;
        }

        $this->query->where(function (Builder $query) use ($search) {
            foreach ($this->searchable as $column) {
                if (! str_contains($column, '.')) {
                    $query->orWhere($column, 'like', "%{$search}%");

                    continue;
                }

                // A related column, e.g. "roles.name".
                [$relation, $related] = [
                    implode('.', array_slice(explode('.', $column), 0, -1)),
                    Arr::last(explode('.', $column)),
                ];

                $query->orWhereHas(
                    $relation,
                    fn (Builder $q) => $q->where($related, 'like', "%{$search}%")
                );
            }
        });
    }

    protected function applySort(): void
    {
        $sort = $this->sort();

        if ($sort === null) {
            return;
        }

        $direction = $this->direction();

        $this->query->orderBy($this->sortable[$sort] ?? $sort, $direction);

        // A second, stable column so rows never swap places between pages
        // when the sorted values are equal.
        //
        // It follows the direction asked for. Fixed ascending, a list of
        // documents sorted newest date first still put the earliest of
        // today at the top — so the sale just rung up appeared below the
        // ones before it, on a screen whose whole job is to show what
        // just happened.
        if ($sort !== 'id') {
            $this->query->orderBy('id', $direction);
        }
    }
}
