<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TableQuery;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The DataTable contract.
 *
 * Every list screen in later phases uses TableQuery the same way, so these
 * tests guard the behaviour once rather than per module.
 */
class DataTableTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->admin()->create(['name' => 'Zed Admin']);
    }

    public function test_it_pages_twenty_five_rows_by_default(): void
    {
        User::factory()->count(30)->create();

        $this->actingAs($this->admin)
            ->get('/users')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', TableQuery::DEFAULT_PER_PAGE)
                ->where('users.total', 31)
                ->where('users.per_page', 25)
                ->where('filters.per_page', 25)
            );
    }

    public function test_the_row_count_can_be_changed(): void
    {
        User::factory()->count(30)->create();

        $this->actingAs($this->admin)
            ->get('/users?per_page=10')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 10)
                ->where('filters.per_page', 10)
            );
    }

    public function test_an_unknown_row_count_falls_back_to_the_default(): void
    {
        User::factory()->count(30)->create();

        // 9999 is not on the list of options, so it must be ignored.
        $this->actingAs($this->admin)
            ->get('/users?per_page=9999')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 25)
                ->where('filters.per_page', 25)
            );
    }

    public function test_the_second_page_holds_the_rest(): void
    {
        User::factory()->count(30)->create();

        $this->actingAs($this->admin)
            ->get('/users?page=2')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 6)
                ->where('users.current_page', 2)
            );
    }

    public function test_the_search_looks_in_several_columns(): void
    {
        User::factory()->create(['name' => 'Maria Santos', 'username' => 'msantos']);
        User::factory()->create(['name' => 'Pedro Reyes', 'username' => 'preyes']);

        // By name.
        $this->actingAs($this->admin)
            ->get('/users?search=Maria')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.username', 'msantos')
                ->where('filters.search', 'Maria')
            );

        // By username.
        $this->actingAs($this->admin)
            ->get('/users?search=preyes')
            ->assertInertia(fn ($page) => $page->has('users.data', 1));
    }

    public function test_the_search_reaches_a_related_column(): void
    {
        User::factory()->withRole('Staff')->create(['username' => 'onstaff']);
        User::factory()->count(3)->create();

        $this->actingAs($this->admin)
            ->get('/users?search=Staff')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.username', 'onstaff')
            );
    }

    public function test_an_empty_search_returns_everything(): void
    {
        User::factory()->count(4)->create();

        $this->actingAs($this->admin)
            ->get('/users?search=%20%20%20')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 5)
                ->where('filters.search', null)
            );
    }

    public function test_sorting_runs_both_ways(): void
    {
        User::factory()->create(['name' => 'Aaron First']);
        User::factory()->create(['name' => 'Mary Middle']);

        $this->actingAs($this->admin)
            ->get('/users?sort=name&direction=asc')
            ->assertInertia(fn ($page) => $page->where('users.data.0.name', 'Aaron First'));

        $this->actingAs($this->admin)
            ->get('/users?sort=name&direction=desc')
            ->assertInertia(fn ($page) => $page->where('users.data.0.name', 'Zed Admin'));
    }

    /**
     * The tiebreaker follows the sort, so newest really means newest.
     *
     * Two rows that share a sorted value are separated by the id. Fixed
     * ascending, a list sorted newest date first still put the earliest
     * row of that date at the top — so on the Sales screen the sale just
     * rung up appeared below the ones before it, on a screen whose whole
     * job is to show what just happened.
     */
    public function test_rows_sharing_a_value_are_broken_newest_first_when_sorting_down(): void
    {
        // Three users made in order, all with the same name, so only the
        // tiebreaker can decide between them.
        $first = User::factory()->create(['name' => 'Same Name', 'username' => 'one']);
        $second = User::factory()->create(['name' => 'Same Name', 'username' => 'two']);
        $third = User::factory()->create(['name' => 'Same Name', 'username' => 'three']);

        // "Zed Admin" sorts above "Same Name", so the three sit below it.
        $this->actingAs($this->admin)
            ->get('/users?sort=name&direction=desc')
            ->assertInertia(fn ($page) => $page
                ->where('users.data.1.username', $third->username)
                ->where('users.data.2.username', $second->username)
                ->where('users.data.3.username', $first->username));

        // And sorting up still reads oldest first.
        $this->actingAs($this->admin)
            ->get('/users?sort=name&direction=asc')
            ->assertInertia(fn ($page) => $page
                ->where('users.data.0.username', $first->username)
                ->where('users.data.2.username', $third->username));
    }

    public function test_a_column_that_is_not_sortable_is_ignored(): void
    {
        User::factory()->create(['name' => 'Aaron First']);

        // `password` is not on the sortable list, so the default sort applies.
        $this->actingAs($this->admin)
            ->get('/users?sort=password&direction=desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'name')
                ->where('users.data.0.name', 'Aaron First')
            );
    }

    public function test_the_search_survives_paging(): void
    {
        User::factory()->count(30)->create(['name' => 'Shared Name']);

        $this->actingAs($this->admin)
            ->get('/users?search=Shared&page=2')
            ->assertInertia(fn ($page) => $page
                ->has('users.data', 5)
                ->where('users.total', 30)
                ->where('filters.search', 'Shared')
            );
    }

    public function test_roles_are_sortable_by_their_counts(): void
    {
        User::factory()->count(3)->withRole('Staff')->create();

        $this->actingAs($this->admin)
            ->get('/roles?sort=users_count&direction=desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('roles.data.0.name', 'Staff')
                ->where('roles.data.0.users_count', 3)
            );
    }
}
