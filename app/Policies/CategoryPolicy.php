<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('categories.view');
    }

    public function view(User $actor, Category $category): bool
    {
        return $actor->can('categories.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('categories.create');
    }

    public function update(User $actor, Category $category): bool
    {
        return $actor->can('categories.update');
    }

    /**
     * A category that products are filed under stays, so those products keep
     * reading correctly. Switch it off instead.
     */
    public function delete(User $actor, Category $category): Response
    {
        if (! $actor->can('categories.delete')) {
            return Response::deny('You may not delete categories.');
        }

        $products = $this->productCount($category);

        if ($products > 0) {
            return Response::deny(
                "This category holds {$products} ".($products === 1 ? 'product' : 'products')
                    .'. Move them first, or switch the category off instead.'
            );
        }

        return Response::allow();
    }

    public function updateStatus(User $actor, Category $category): bool
    {
        return $actor->can('categories.status');
    }

    /**
     * How many products sit under this category.
     *
     * The list screen loads the figure with `withCount`, so a page of rows
     * asks nothing extra. Only a single-record route falls back to a query.
     */
    protected function productCount(Category $category): int
    {
        return (int) ($category->products_count ?? $category->products()->count());
    }
}
