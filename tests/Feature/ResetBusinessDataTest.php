<?php

namespace Tests\Feature;

use App\Console\Commands\ResetBusinessData;
use App\Enums\AuditAction;
use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\Location;
use App\Models\HeldSale;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\SupplierQuoteLine;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryMetrics;
use App\Services\PurchaseRequestDesk;
use App\Support\Settings;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * `data:reset`, which empties the books before a business goes live.
 *
 * The data is built the way a user builds it — through the screens — so
 * every table the command must empty really holds rows, and the foreign
 * keys really stand in the way if the order is wrong.
 */
class ResetBusinessDataTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // data:reset deletes loss photos by name (phase 33): never on the
        // real private disk.
        Storage::fake('local');

        Storage::fake('public');

        $this->seed(RolePermissionSeeder::class);
        $this->admin = $this->unaudited(fn () => User::factory()->admin()->create());

        // Units, categories, products, suppliers and an opening adjustment.
        $this->seed(SampleDataSeeder::class);
    }

    /** One of everything: a delivery, a sale, and a return of each. */
    protected function trade(): void
    {
        $product = Product::firstWhere('sku', 'TSHIRT-BLK');
        $customer = Customer::where('is_walk_in', true)->firstOrFail();

        $this->actingAs($this->admin)->post('/purchases', [
            'supplier_id' => Supplier::first()->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => '300.00']],
        ])->assertSessionHasNoErrors();

        $purchase = Purchase::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post('/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post('/sales-returns', [
            'sale_id' => $sale->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Faulty',
            'lines' => [['sale_line_id' => $sale->lines->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/purchase-returns', [
            'purchase_id' => $purchase->id,
            'return_date' => now()->toDateString(),
            'reason' => 'Damaged on arrival',
            'lines' => [['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        // Sold by the box as well, so product_units holds a row too.
        ProductUnit::create([
            'product_id' => $product->id,
            'unit_id' => Unit::where('id', '!=', $product->unit_id)->firstOrFail()->id,
            'factor' => 10,
        ]);

        $product->update(['photo_path' => 'products/shirt.jpg']);
        Storage::disk('public')->put('products/shirt.jpg', 'a photo');

        // A sale on account and a payment against it, so the two
        // customer payment tables hold rows too.
        $account = Customer::create(['code' => 'CUS-CR', 'name' => 'Account Customer', 'credit_limit' => 10000, 'payment_terms_days' => 30]);

        $this->actingAs($this->admin)->post('/sales', [
            'customer_id' => $account->id,
            'sale_date' => now()->toDateString(),
            'on_account' => '500.00',
            'payments' => [],
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/customer-payments', [
            'customer_id' => $account->id,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => PaymentMethod::cash()->id,
            'amount' => '200.00',
            'allocations' => [['sale_id' => Sale::latest('id')->value('id'), 'amount' => '200.00']],
        ])->assertSessionHasNoErrors();

        // A delivery on account and a payment against it, so the two
        // supplier payment tables hold rows too (phase 24).
        $this->actingAs($this->admin)->post('/purchases', [
            'supplier_id' => Supplier::first()->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'on_account' => true,
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_cost' => '300.00']],
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->post('/supplier-payments', [
            'supplier_id' => Supplier::first()->id,
            'payment_date' => now()->toDateString(),
            'payment_method_id' => PaymentMethod::cash()->id,
            'amount' => '100.00',
            'allocations' => [['purchase_id' => Purchase::latest('id')->value('id'), 'amount' => '100.00']],
        ])->assertSessionHasNoErrors();

        // A purchase request, so both request tables hold rows too.
        $this->actingAs($this->admin)->post('/purchase-requests', [
            'supplier_id' => Supplier::first()->id,
            'reason' => 'Running low before the weekend',
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => '300.00']],
        ])->assertSessionHasNoErrors();

        // Approved, quoted and awarded (phase 24.2), so both quote tables
        // hold rows and a request line points back at a quote line.
        $raised = PurchaseRequest::latest('id')->firstOrFail();
        app(PurchaseRequestDesk::class)->approve($raised, $this->admin);
        $this->actingAs($this->admin)->post("/purchase-requests/{$raised->id}/quotes", [
            'supplier_id' => Supplier::first()->id,
            'prices' => [$raised->lines()->value('id') => '290.00'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post("/purchase-requests/{$raised->id}/award", [
            'choices' => [$raised->lines()->value('id') => SupplierQuoteLine::latest('id')->value('id')],
        ])->assertSessionHasNoErrors();

        // And an order placed with a supplier, so both order tables hold rows,
        // with part of it received: the delivery then points at the order,
        // which the reset has to delete it before.
        $this->actingAs($this->admin)->post('/purchase-orders', [
            'supplier_id' => Supplier::first()->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 20, 'unit_cost' => '300.00']],
        ])->assertSessionHasNoErrors();

        $order = PurchaseOrder::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post('/purchases', [
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'purchase_date' => now()->toDateString(),
            'lines' => [[
                'purchase_order_line_id' => $order->lines()->value('id'),
                'product_id' => $product->id,
                'quantity' => 5,
                'unit_cost' => '300.00',
            ]],
        ])->assertSessionHasNoErrors();

        // A quotation turned into a sale, so both quotation tables hold rows
        // and one points at a sale the reset must delete after it.
        $this->actingAs($this->admin)->post('/quotations', [
            'customer_id' => $customer->id,
            'valid_until' => now()->addDays(15)->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        $quotation = Quotation::latest('id')->firstOrFail();

        $this->actingAs($this->admin)->post('/sales', [
            'quotation_id' => $quotation->id,
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        // A sales order, so both order tables hold rows (phase 29).
        $this->actingAs($this->admin)->post('/sales-orders', [
            'customer_id' => $customer->id,
            'reserve_until' => now()->addDays(7)->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        // A transfer, so both transfer tables hold rows (phase 31.5).
        $this->actingAs($this->admin)->post('/transfers', [
            'from_location_id' => Location::default()->id,
            'to_location_id' => Location::where('name', 'Warehouse')->value('id'),
            'transfer_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        // A count sheet, so both count tables hold rows (phase 32).
        $this->actingAs($this->admin)->post('/stock-counts', [
            'location_id' => Location::default()->id,
        ])->assertSessionHasNoErrors();

        // A loss report with a photo row, so the three loss tables hold rows
        // (phase 33). The file itself is never written: only its row.
        $this->actingAs($this->admin)->post('/loss-reports', [
            'location_id' => Location::default()->id,
            'reason' => 'theft',
            'lines' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();
        \App\Models\LossReport::latest('id')->first()->photos()->create(['path' => 'losses/never-written.jpg']);

        // An order the kiosk sent to the cashier (CR-2), so both pending
        // payment tables hold rows. Sent from the kiosk, which is opt-in.
        $this->admin->givePermissionTo('kiosk.use');
        $this->actingAs($this->admin)->post('/pending-payments', [
            'customer_id' => $customer->id,
            'location_id' => Location::default()->id,
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => '500.00']],
        ])->assertSessionHasNoErrors();

        // A sale parked at the till, so held_sales holds a row too.
        HeldSale::create([
            'customer_id' => Customer::where('is_walk_in', true)->value('id'),
            'note' => 'Back in five minutes',
            'lines' => [['product_id' => $product->id, 'unit_id' => null, 'quantity' => '1', 'unit_price' => '500.00']],
        ]);
    }

    protected function reset(): PendingCommand
    {
        return $this->artisan('data:reset --force');
    }

    /* -------------------------------------------------------- The schema */

    public function test_every_table_is_either_emptied_or_kept_on_purpose(): void
    {
        // A table added later must be placed in one list or the other. Left
        // out, its rows would survive a reset nobody would think to check.
        $tables = Schema::getTableListing(schemaQualified: false);
        $placed = [...ResetBusinessData::WIPED, ...ResetBusinessData::KEPT];

        $this->assertSame([], array_values(array_diff($tables, $placed)), 'Add these to WIPED or KEPT.');
        $this->assertSame([], array_values(array_diff($placed, $tables)), 'These tables no longer exist.');
        $this->assertSame([], array_values(array_intersect(ResetBusinessData::WIPED, ResetBusinessData::KEPT)));
    }

    /* --------------------------------------------------------- Emptying */

    public function test_it_empties_the_books_and_keeps_the_accounts_and_settings(): void
    {
        $this->trade();

        foreach (ResetBusinessData::WIPED as $table) {
            $this->assertGreaterThan(0, DB::table($table)->count(), "[{$table}] should hold rows before the reset.");
        }

        app(Settings::class)->set('company.name', 'GO Enterprises');
        Storage::disk('public')->put('settings/logo.png', 'the logo');

        // In the folder but named by no product here: another copy of the
        // data may well be using it.
        Storage::disk('public')->put('products/not-ours.jpg', 'somebody else');

        $users = User::count();
        $permissions = DB::table('permissions')->count();
        $grants = DB::table('role_has_permissions')->count();

        $this->reset()->assertSuccessful();

        // Only the walk-in customer and one default location come back; even
        // the units stay empty. The trail keeps the one row that says what
        // happened.
        $refilled = ['customers' => 1, 'locations' => 1, 'audit_trails' => 1];

        foreach (ResetBusinessData::WIPED as $table) {
            $this->assertSame($refilled[$table] ?? 0, DB::table($table)->count(), "[{$table}] was not emptied.");
        }

        $walkIn = Customer::sole();
        $this->assertTrue($walkIn->is_walk_in);
        $this->assertSame(1, $walkIn->id);
        $this->assertSame('Walk-in Customer', $walkIn->name);
        $this->assertSame(0, Unit::count());

        // Phase 31.2: the ledger cannot post without a default location, so
        // one is put back for the opening stock; the client renames it.
        $location = Location::sole();
        $this->assertSame('Main Store', $location->name);
        $this->assertTrue($location->is_default);
        $this->assertSame(1, $location->id);

        // Set-up, like the settings: the ways the shop is paid stay, and the
        // till still has its cash method.
        // The four of phase 16 and Cheque (phase 24.5).
        $this->assertSame(5, PaymentMethod::count());
        $this->assertNotNull(PaymentMethod::cash());

        $this->assertSame($users, User::count());
        $this->assertTrue($this->admin->fresh()->hasRole('Admin'));
        $this->assertSame($permissions, DB::table('permissions')->count());
        $this->assertSame($grants, DB::table('role_has_permissions')->count());
        $this->assertSame('GO Enterprises', app(Settings::class)->get('company.name'));

        // A product's own photo goes; the logo is a setting, and stays; and a
        // file no product here named is left alone.
        Storage::disk('public')->assertMissing('products/shirt.jpg');
        Storage::disk('public')->assertExists('settings/logo.png');
        Storage::disk('public')->assertExists('products/not-ours.jpg');
    }

    public function test_the_dashboard_figures_are_not_left_in_the_cache(): void
    {
        $this->trade();

        $metrics = app(InventoryMetrics::class);
        $this->assertNotSame([], $metrics->topSellers());

        $this->reset()->assertSuccessful();

        $this->assertSame([], $metrics->topSellers());
    }

    public function test_numbers_and_ids_start_again_from_one(): void
    {
        $this->trade();

        $this->reset()->assertSuccessful();

        $this->seed(SampleDataSeeder::class);
        $this->assertSame(1, Supplier::orderBy('id')->first()->id);

        $product = Product::orderBy('id')->firstOrFail();
        $this->assertSame(1, Product::min('id'));

        $this->actingAs($this->admin)->post('/purchases', [
            'supplier_id' => Supplier::first()->id,
            'purchase_date' => now()->toDateString(),
            'supplier_reference' => 'INV-FIRST',
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => '100.00']],
        ])->assertSessionHasNoErrors();

        $this->assertStringEndsWith('-0001', Purchase::firstOrFail()->reference);
        $this->assertSame(1, Purchase::firstOrFail()->id);
    }

    public function test_the_reset_is_the_first_row_of_the_new_trail(): void
    {
        $this->trade();

        $this->reset()->assertSuccessful();

        $row = AuditTrail::sole();

        $this->assertSame(AuditAction::Delete, $row->action);
        $this->assertSame('System', $row->module);
        $this->assertSame(1, $row->properties['product_photos']);
        $this->assertGreaterThan(0, $row->properties['rows']['sales']);
    }

    public function test_the_stock_check_passes_afterwards(): void
    {
        $this->trade();

        $this->reset()->assertSuccessful();

        $this->artisan('inventory:verify')->assertSuccessful();
    }

    /* ---------------------------------------------------------- Refusing */

    public function test_nothing_changes_unless_the_word_is_typed(): void
    {
        $this->trade();
        $sales = Sale::count();

        foreach (['', 'yes', 'reset'] as $answer) {
            $this->artisan('data:reset')
                ->expectsQuestion('Type '.ResetBusinessData::CONFIRMATION.' to empty these tables', $answer)
                ->expectsOutputToContain('Nothing was changed.')
                ->assertSuccessful();
        }

        $this->assertSame($sales, Sale::count());
        $this->assertGreaterThan(0, Product::count());
        Storage::disk('public')->assertExists('products/shirt.jpg');
    }

    public function test_typing_the_word_empties_the_books(): void
    {
        $this->trade();

        $this->artisan('data:reset')
            ->expectsQuestion('Type '.ResetBusinessData::CONFIRMATION.' to empty these tables', 'RESET')
            ->assertSuccessful();

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Product::count());
    }
}
