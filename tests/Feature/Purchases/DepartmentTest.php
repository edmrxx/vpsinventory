<?php

namespace Tests\Feature\Purchases;

use App\Models\AuditTrail;
use App\Models\Department;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\PurchaseRequestDesk;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 24.1: departments, the department a purchase request is raised
 * for, and printing a request and its Request for Quotation.
 */
class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $staff;

    protected Product $wire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());
        $this->staff = $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
        $this->wire = $this->unaudited(fn () => Product::factory()->create(['sku' => 'WIRE-20']));
    }

    protected function electrical(): Department
    {
        return Department::where('name', 'Electrical')->firstOrFail();
    }

    protected function raise(array $overrides = [], ?User $by = null): PurchaseRequest
    {
        $this->actingAs($by ?? $this->staff)->post('/purchase-requests', array_merge([
            'department_id' => $this->electrical()->id,
            'reason' => 'Wiring for the Lahug job',
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 20, 'unit_cost' => '45.00']],
        ], $overrides))->assertSessionHasNoErrors();

        return PurchaseRequest::latest('id')->firstOrFail();
    }

    /* ------------------------------------------------------ The master list */

    public function test_the_two_departments_the_client_named_arrive_with_the_migration(): void
    {
        $this->assertSame(['Construction', 'Electrical'], Department::orderBy('name')->pluck('name')->all());
    }

    public function test_an_admin_keeps_the_list_and_staff_only_read_it(): void
    {
        $this->actingAs($this->admin)->post('/departments', ['name' => '  Plumbing ', 'is_active' => true])
            ->assertSessionHasNoErrors();
        $plumbing = Department::where('name', 'Plumbing')->firstOrFail();

        $this->actingAs($this->admin)->put("/departments/{$plumbing->id}", ['name' => 'Plumbing works', 'description' => 'Pipes', 'is_active' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame('Plumbing works', $plumbing->fresh()->name);

        $this->actingAs($this->staff)->get('/departments')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Departments/Index')->where('can.create', false));
        $this->actingAs($this->staff)->post('/departments', ['name' => 'Sneaky', 'is_active' => true])->assertForbidden();
        $this->actingAs($this->staff)->delete("/departments/{$plumbing->id}")->assertForbidden();

        $this->assertTrue(AuditTrail::query()->where('module', 'Departments')->where('action', 'create')->exists());
    }

    public function test_the_name_must_be_unique(): void
    {
        $this->actingAs($this->admin)->post('/departments', ['name' => 'Electrical', 'is_active' => true])
            ->assertSessionHasErrors(['name' => 'There is already a department with this name.']);
    }

    public function test_a_department_named_on_a_request_is_switched_off_never_deleted(): void
    {
        $this->raise();
        $electrical = $this->electrical();

        $this->actingAs($this->admin)->delete("/departments/{$electrical->id}")->assertForbidden();
        $this->assertNotNull($electrical->fresh());

        $this->actingAs($this->admin)->patch("/departments/{$electrical->id}/status")->assertRedirect();
        $this->assertFalse($electrical->fresh()->is_active);

        // An unused one goes.
        $spare = $this->unaudited(fn () => Department::factory()->create());
        $this->actingAs($this->admin)->delete("/departments/{$spare->id}")->assertRedirect();
        $this->assertNull($spare->fresh());
    }

    public function test_the_list_carries_each_rows_request_count(): void
    {
        $this->raise();
        $this->raise();

        $this->actingAs($this->admin)->get('/departments')
            ->assertInertia(fn ($page) => $page->where('departments.data', fn ($rows) => collect($rows)
                ->firstWhere('name', 'Electrical')['requests_count'] === 2
                && collect($rows)->firstWhere('name', 'Electrical')['can']['delete'] === false));
    }

    public function test_departments_have_no_detail_screen(): void
    {
        $this->assertFalse(Route::has('departments.show'));
    }

    /* ------------------------------------------------- On a purchase request */

    public function test_a_request_is_raised_for_a_department(): void
    {
        $request = $this->raise();

        $this->assertSame($this->electrical()->id, (int) $request->department_id);

        $this->actingAs($this->staff)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page->where('request.department', 'Electrical'));
    }

    public function test_the_department_is_optional_but_must_be_active(): void
    {
        $this->assertNull($this->raise(['department_id' => null])->department_id);

        $this->electrical()->update(['is_active' => false]);

        $this->actingAs($this->staff)->post('/purchase-requests', [
            'department_id' => $this->electrical()->id,
            'reason' => 'Wiring',
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 1, 'unit_cost' => '0']],
        ])->assertSessionHasErrors(['department_id' => 'Pick a department that is still active, or leave it empty.']);
    }

    public function test_the_form_offers_only_active_departments(): void
    {
        Department::where('name', 'Construction')->update(['is_active' => false]);

        $this->actingAs($this->staff)->get('/purchase-requests/create')
            ->assertInertia(fn ($page) => $page->where('departments', fn ($options) => collect($options)->pluck('label')->all() === ['Electrical']));
    }

    public function test_the_list_filters_by_department(): void
    {
        $this->raise();
        $this->raise(['department_id' => Department::where('name', 'Construction')->value('id')]);

        $this->actingAs($this->admin)->get('/purchase-requests?department_id='.$this->electrical()->id)
            ->assertInertia(fn ($page) => $page
                ->has('requests.data', 1)
                ->where('requests.data.0.department', 'Electrical'));
    }

    public function test_a_double_click_with_another_department_is_not_the_same_request(): void
    {
        $key = (string) Str::uuid();
        $form = [
            'department_id' => $this->electrical()->id,
            'reason' => 'Wiring',
            'idempotency_key' => $key,
            'lines' => [['product_id' => $this->wire->id, 'quantity' => 1, 'unit_cost' => '0']],
        ];

        $this->actingAs($this->staff)->post('/purchase-requests', $form)->assertSessionHasNoErrors();
        $this->actingAs($this->staff)
            ->post('/purchase-requests', ['department_id' => Department::where('name', 'Construction')->value('id')] + $form)
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, PurchaseRequest::count());
    }

    /* ------------------------------------------------------------ Printing */

    public function test_the_rfq_is_offered_only_once_the_request_is_approved(): void
    {
        $request = $this->raise();

        $this->actingAs($this->staff)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page->where('can.printRfq', false));

        app(PurchaseRequestDesk::class)->approve($request, $this->admin);

        $this->actingAs($this->staff)->get("/purchase-requests/{$request->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.printRfq', true)
                ->where('request.decided_by', $this->admin->name));
    }

    /* --------------------------------------------------------- Go-live reset */

    public function test_the_go_live_reset_empties_the_list(): void
    {
        $this->raise();

        $this->artisan('data:reset', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Department::count());
        $this->assertSame(0, PurchaseRequest::count());
    }
}
