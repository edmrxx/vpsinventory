<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AuditTrail;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Department;
use App\Models\InventoryAdjustment;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\PendingPayment;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\LossReport;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\DashboardOverview;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Query budgets for the list screens.
 *
 * A screen must not get slower as its table fills. These tests load a page
 * with 5 rows and then with 40, and fail if the query count grew — which is
 * what an N+1 looks like from the outside.
 */
class PerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    /** Count the queries one page visit makes. */
    protected function queriesFor(User $actor, string $uri): int
    {
        // Warm anything cached outside the page itself, so the count is
        // about the page and not about a cold cache.
        $this->actingAs($actor)->get($uri);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($actor)->get($uri)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_users_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->unaudited(fn () => User::factory()->count(4)->withRole('Staff')->create());
        $small = $this->queriesFor($admin, '/users');

        $this->unaudited(fn () => User::factory()->count(35)->withRole('Staff')->create());
        $large = $this->queriesFor($admin, '/users?per_page=50');

        $this->assertSame(
            $small,
            $large,
            "The users list ran {$small} queries for 5 rows and {$large} for 40. "
                .'That is an N+1.'
        );
    }

    public function test_the_roles_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $small = $this->queriesFor($admin, '/roles');

        $this->unaudited(function () {
            foreach (range(1, 20) as $n) {
                Role::create(['name' => "Role {$n}", 'guard_name' => 'web']);
            }
        });

        $large = $this->queriesFor($admin, '/roles?per_page=50');

        $this->assertSame($small, $large, 'The roles list has an N+1.');
    }

    public function test_the_audit_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeTrailRows(5);
        $small = $this->queriesFor($admin, '/audit');

        $this->makeTrailRows(40);
        $large = $this->queriesFor($admin, '/audit?per_page=50');

        $this->assertSame($small, $large, 'The audit list has an N+1.');
    }

    public function test_the_api_user_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('test', ['users.view'])->plainTextToken;

        $this->unaudited(fn () => User::factory()->count(4)->withRole('Staff')->create());

        $this->withToken($token)->getJson('/api/v1/users');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/users')->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->unaudited(fn () => User::factory()->count(35)->withRole('Staff')->create());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($token)->getJson('/api/v1/users?per_page=50')->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($small, $large, 'The API user list has an N+1.');
    }

    public function test_the_products_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        // One category and one unit for every product, so a list screen that
        // forgot to eager load would show up at once.
        $this->unaudited(fn () => Product::factory()->count(5)->create());
        $small = $this->queriesFor($admin, '/products');

        $this->unaudited(fn () => Product::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/products?per_page=50');

        $this->assertSame(
            $small,
            $large,
            "The products list ran {$small} queries for 5 rows and {$large} for 40. "
                .'That is an N+1.'
        );
    }

    public function test_the_categories_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->unaudited(fn () => Category::factory()->count(5)->create());
        $small = $this->queriesFor($admin, '/categories');

        $this->unaudited(fn () => Category::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/categories?per_page=50');

        $this->assertSame($small, $large, 'The categories list has an N+1.');
    }

    /**
     * Phase 24.1. The delete rule reads each row's request count from
     * `withCount`, so neither more departments nor more requests behind
     * them cost a query per row.
     */
    public function test_the_departments_list_does_not_grow_with_its_rows_or_their_requests(): void
    {
        $admin = $this->admin();

        $this->unaudited(fn () => Department::factory()->count(5)->create());
        $small = $this->queriesFor($admin, '/departments');

        $this->unaudited(fn () => Department::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/departments?per_page=50');

        $this->assertSame($small, $large, 'The departments list has an N+1.');
    }

    /** Phase 31.1: the locations list costs the same with 5 rows or 40. */
    public function test_the_locations_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->unaudited(fn () => Location::factory()->count(3)->create());
        $small = $this->queriesFor($admin, '/locations');

        $this->unaudited(fn () => Location::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/locations?per_page=50');

        $this->assertSame($small, $large, 'The locations list has an N+1.');
    }

    public function test_the_units_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->unaudited(fn () => Unit::factory()->count(5)->create());
        $small = $this->queriesFor($admin, '/units');

        $this->unaudited(fn () => Unit::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/units?per_page=50');

        $this->assertSame($small, $large, 'The units list has an N+1.');
    }

    public function test_the_payment_methods_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        // The delete rule reads a count of payments from every row; it must
        // come with the page, not one query per row.
        $this->unaudited(fn () => PaymentMethod::factory()->count(5)->create());
        $small = $this->queriesFor($admin, '/payment-methods');

        $this->unaudited(fn () => PaymentMethod::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/payment-methods?per_page=50');

        $this->assertSame($small, $large, 'The payment methods list has an N+1.');
    }

    public function test_the_suppliers_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->unaudited(fn () => Supplier::factory()->count(5)->create());
        $small = $this->queriesFor($admin, '/suppliers');

        $this->unaudited(fn () => Supplier::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/suppliers?per_page=50');

        $this->assertSame($small, $large, 'The suppliers list has an N+1.');
    }

    public function test_the_customers_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->unaudited(fn () => Customer::factory()->count(5)->create());
        $small = $this->queriesFor($admin, '/customers');

        $this->unaudited(fn () => Customer::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/customers?per_page=50');

        $this->assertSame($small, $large, 'The customers list has an N+1.');
    }

    /**
     * The delete rule on a category asks how many products sit under it. The
     * list screen loads that figure with `withCount`, so the policy answers
     * from the row. Without it, a page of 40 rows ran 40 extra queries.
     */
    public function test_the_category_delete_rule_asks_nothing_per_row(): void
    {
        $admin = $this->admin();

        $this->unaudited(fn () => Product::factory()->count(5)->create());
        $small = $this->queriesFor($admin, '/categories');

        $this->unaudited(fn () => Product::factory()->count(35)->create());
        $large = $this->queriesFor($admin, '/categories?per_page=50');

        $this->assertSame($small, $large, 'The category delete rule queries once per row.');
    }

    public function test_the_purchases_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        // A supplier and several lines on every row, so a list screen that
        // forgot to eager load would show up at once.
        $this->makePurchases(5);
        $small = $this->queriesFor($admin, '/purchases');

        $this->makePurchases(35);
        $large = $this->queriesFor($admin, '/purchases?per_page=50');

        $this->assertSame(
            $small,
            $large,
            "The purchases list ran {$small} queries for 5 rows and {$large} for 40. "
                .'That is an N+1.'
        );
    }

    /**
     * The delete rule on a supplier asks how many purchases name it. The
     * list screen loads that with `withCount`, so the policy answers from
     * the row rather than querying once per row.
     */
    public function test_the_supplier_delete_rule_asks_nothing_per_row(): void
    {
        $admin = $this->admin();

        $this->makePurchases(5);
        $small = $this->queriesFor($admin, '/suppliers');

        $this->makePurchases(35);
        $large = $this->queriesFor($admin, '/suppliers?per_page=50');

        $this->assertSame($small, $large, 'The supplier delete rule queries once per row.');
    }

    /**
     * Build posted purchases, one supplier and one product each.
     */
    protected function makePurchases(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $supplier = Supplier::factory()->create();
                $product = Product::factory()->create();

                $purchase = Purchase::create([
                    'location_id' => Location::default()->id,
                    'reference' => 'PR-TEST-'.Str::random(10),
                    'supplier_id' => $supplier->id,
                    'purchase_date' => now(),
                    'status' => 'posted',
                    'total_amount' => 100,
                ]);

                $purchase->lines()->create([
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 100,
                    'line_total' => 100,
                ]);
            }
        });
    }

    public function test_the_sales_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeSales(5);
        $small = $this->queriesFor($admin, '/sales');

        $this->makeSales(35);
        $large = $this->queriesFor($admin, '/sales?per_page=50');

        $this->assertSame(
            $small,
            $large,
            "The sales list ran {$small} queries for 5 rows and {$large} for 40. That is an N+1."
        );
    }

    /**
     * The delete rule on a customer asks how many sales name it, the same
     * way a supplier's does.
     */
    public function test_the_customer_delete_rule_asks_nothing_per_row(): void
    {
        $admin = $this->admin();

        $this->makeSales(5);
        $small = $this->queriesFor($admin, '/customers');

        $this->makeSales(35);
        $large = $this->queriesFor($admin, '/customers?per_page=50');

        $this->assertSame($small, $large, 'The customer delete rule queries once per row.');
    }

    /**
     * Customer payments, and the customer list's flags. Each customer owes
     * on an invoice past due, so the overdue and over-limit figures are
     * worked out for every row — in the same query, not one per row.
     */
    public function test_the_customer_payments_list_and_the_flags_do_not_grow_with_their_rows(): void
    {
        $admin = $this->admin();

        $this->makeCustomerPayments(5);
        $payments = $this->queriesFor($admin, '/customer-payments');
        $customers = $this->queriesFor($admin, '/customers');
        $aging = $this->queriesFor($admin, '/aging');
        $collectibles = $this->queriesFor($admin, '/collectibles');

        $this->makeCustomerPayments(35);

        $this->assertSame($payments, $this->queriesFor($admin, '/customer-payments?per_page=50'), 'The customer payments list has an N+1.');
        $this->assertSame($customers, $this->queriesFor($admin, '/customers?per_page=50'), 'The customer flags query once per row.');
        $this->assertSame($aging, $this->queriesFor($admin, '/aging'), 'The aging report queries once per customer.');
        $this->assertSame($collectibles, $this->queriesFor($admin, '/collectibles?per_page=50'), 'The collectibles list has an N+1.');
    }

    public function test_the_purchase_requests_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        // A supplier, a requester and a line on every row, and the per-row
        // "may this person answer it" check, which must ask nothing.
        $this->makePurchaseRequests(5);
        $small = $this->queriesFor($admin, '/purchase-requests');

        $this->makePurchaseRequests(35);
        $large = $this->queriesFor($admin, '/purchase-requests?per_page=50');

        $this->assertSame(
            $small,
            $large,
            "The purchase requests list ran {$small} queries for 5 rows and {$large} for 40. That is an N+1."
        );
    }

    public function test_the_purchase_orders_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        // A supplier, a placer, a request and a line on every row.
        $this->makePurchaseOrders(5);
        $small = $this->queriesFor($admin, '/purchase-orders');

        $this->makePurchaseOrders(35);
        $large = $this->queriesFor($admin, '/purchase-orders?per_page=50');

        $this->assertSame(
            $small,
            $large,
            "The purchase orders list ran {$small} queries for 5 rows and {$large} for 40. That is an N+1."
        );
    }

    public function test_the_supplier_payments_and_payables_lists_do_not_grow_with_their_rows(): void
    {
        $admin = $this->admin();

        $this->makeSupplierPayments(5);
        $payments = $this->queriesFor($admin, '/supplier-payments');
        $payables = $this->queriesFor($admin, '/payables');

        $this->makeSupplierPayments(35);

        $this->assertSame($payments, $this->queriesFor($admin, '/supplier-payments?per_page=50'), 'The supplier payments list has an N+1.');
        $this->assertSame($payables, $this->queriesFor($admin, '/payables?per_page=50'), 'The payables list has an N+1.');
    }

    public function test_the_quotations_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        // A customer, a preparer and a line on every row, and the per-row
        // "may this person sell it" check, which must ask nothing.
        $this->makeQuotations(5);
        $small = $this->queriesFor($admin, '/quotations');

        $this->makeQuotations(35);
        $large = $this->queriesFor($admin, '/quotations?per_page=50');

        $this->assertSame($small, $large, "The quotations list ran {$small} queries for 5 rows and {$large} for 40. That is an N+1.");
    }

    public function test_the_sales_orders_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeSalesOrders(5);
        $small = $this->queriesFor($admin, '/sales-orders');
        $stock = $this->queriesFor($admin, '/stock');

        $this->makeSalesOrders(35);

        $this->assertSame($small, $this->queriesFor($admin, '/sales-orders?per_page=50'), 'The sales orders list has an N+1.');
        // What is reserved is one grouped query, whatever the orders hold.
        $this->assertSame($stock, $this->queriesFor($admin, '/stock'), 'The Stock screen asks once per reservation.');
    }

    /**
     * CR-2: the Pending payments list stays flat, and what they hold is one
     * grouped query on the Stock screen, as a sales order's is.
     */
    public function test_the_pending_payments_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makePendingPayments(5);
        $small = $this->queriesFor($admin, '/pending-payments');
        $stock = $this->queriesFor($admin, '/stock');

        $this->makePendingPayments(35);

        $this->assertSame($small, $this->queriesFor($admin, '/pending-payments?per_page=50'), 'The pending payments list has an N+1.');
        $this->assertSame($stock, $this->queriesFor($admin, '/stock'), 'The Stock screen asks once per pending payment.');
    }

    /** Orders waiting at the cashier, each for its own customer, sender and product. */
    protected function makePendingPayments(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $pending = PendingPayment::create([
                    'reference' => 'PP-TEST-'.Str::random(10),
                    'location_id' => Location::default()->id,
                    'customer_id' => Customer::factory()->create()->id,
                    'order_date' => now(),
                    'hold_until' => now(),
                    'status' => 'pending',
                    'subtotal' => 100,
                    'total_amount' => 100,
                    'taken_by' => User::factory()->create()->id,
                ]);

                $pending->lines()->create([
                    'product_id' => Product::factory()->create()->id,
                    'unit_quantity' => 1,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'line_total' => 100,
                ]);
            }
        });
    }

    /** Approved orders, each for its own customer, taker and product. */
    protected function makeSalesOrders(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $order = SalesOrder::create([
                    'reference' => 'SO-TEST-'.Str::random(10),
                    'location_id' => Location::default()->id,
                    'customer_id' => Customer::factory()->create()->id,
                    'order_date' => now(),
                    'reserve_until' => now()->addDays(7),
                    'status' => 'approved',
                    'subtotal' => 100,
                    'total_amount' => 100,
                    'created_by' => User::factory()->create()->id,
                ]);

                $order->lines()->create([
                    'product_id' => Product::factory()->create()->id,
                    'unit_quantity' => 1,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'line_total' => 100,
                ]);
            }
        });
    }

    /** Open quotations, each for its own customer, preparer and product. */
    protected function makeQuotations(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $quotation = Quotation::create([
                    'reference' => 'QT-TEST-'.Str::random(10),
                    'customer_id' => Customer::factory()->create()->id,
                    'quote_date' => now(),
                    'valid_until' => now()->addDays(15),
                    'status' => 'open',
                    'subtotal' => 100,
                    'total_amount' => 100,
                    'prepared_by' => User::factory()->create()->id,
                ]);

                $quotation->lines()->create([
                    'product_id' => Product::factory()->create()->id,
                    'unit_quantity' => 1,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'line_total' => 100,
                ]);
            }
        });
    }

    /** A bill on account, part paid, each from its own supplier. */
    protected function makeSupplierPayments(int $count): void
    {
        $cash = PaymentMethod::where('kind', 'cash')->firstOrFail();

        $this->unaudited(function () use ($count, $cash) {
            for ($i = 0; $i < $count; $i++) {
                $supplier = Supplier::factory()->create(['payment_terms_days' => 30]);

                $bill = Purchase::create([
                    'location_id' => Location::default()->id,
                    'reference' => 'PR-TEST-'.Str::random(10),
                    'supplier_id' => $supplier->id,
                    'purchase_date' => now()->subDays(40),
                    'status' => 'posted',
                    'total_amount' => 100,
                    'on_account' => 100,
                    'balance_due' => 60,
                    'due_date' => now()->subDays(10),
                ]);

                $payment = SupplierPayment::create([
                    'reference' => 'SP-TEST-'.Str::random(10),
                    'supplier_id' => $supplier->id,
                    'payment_date' => now(),
                    'status' => 'posted',
                    'payment_method_id' => $cash->id,
                    'amount' => 40,
                ]);

                $payment->allocations()->create(['purchase_id' => $bill->id, 'amount' => 40]);
            }
        });
    }

    /** Open orders, each from its own request, supplier, placer and product. */
    protected function makePurchaseOrders(int $count): void
    {
        $this->makePurchaseRequests($count);

        $this->unaudited(function () {
            PurchaseRequest::query()->whereDoesntHave('purchaseOrders')->get()->each(function (PurchaseRequest $request) {
                $order = PurchaseOrder::create([
                    'reference' => 'PO-TEST-'.Str::random(10),
                    'supplier_id' => $request->supplier_id,
                    'purchase_request_id' => $request->id,
                    'order_date' => now(),
                    'expected_date' => now()->addDays(3),
                    'status' => 'open',
                    'total_amount' => 100,
                    'ordered_by' => User::factory()->create()->id,
                ]);

                $order->lines()->create([
                    'product_id' => Product::factory()->create()->id,
                    'unit_quantity' => 1,
                    'quantity' => 1,
                    'unit_cost' => 100,
                    'line_total' => 100,
                ]);
            });
        });
    }

    /** Pending requests, each from its own requester, supplier and product. */
    protected function makePurchaseRequests(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $request = PurchaseRequest::create([
                    'reference' => 'PRQ-TEST-'.Str::random(10),
                    'request_date' => now(),
                    'status' => 'pending',
                    'supplier_id' => Supplier::factory()->create()->id,
                    'reason' => 'Running low',
                    'estimated_total' => 100,
                    'requested_by' => User::factory()->create()->id,
                ]);

                $request->lines()->create([
                    'product_id' => Product::factory()->create()->id,
                    'unit_quantity' => 1,
                    'quantity' => 1,
                    'unit_cost' => 100,
                    'line_total' => 100,
                ]);
            }
        });
    }

    /** A customer owing on an invoice past due, and a payment against it. */
    protected function makeCustomerPayments(int $count): void
    {
        $cash = PaymentMethod::where('kind', 'cash')->firstOrFail();

        $this->unaudited(function () use ($count, $cash) {
            for ($i = 0; $i < $count; $i++) {
                $customer = Customer::factory()->create(['credit_limit' => 50, 'payment_terms_days' => 7]);

                $sale = Sale::create([
                    'reference' => 'SI-TEST-'.Str::random(10),
                    'location_id' => Location::default()->id,
                    'customer_id' => $customer->id,
                    'sale_date' => now()->subDays(40),
                    'status' => 'posted',
                    'subtotal' => 100,
                    'discount' => 0,
                    'total_amount' => 100,
                    'total_cost' => 60,
                    'on_account' => 100,
                    'balance_due' => 60,
                    'due_date' => now()->subDays(33),
                ]);

                $payment = CustomerPayment::create([
                    'reference' => 'CP-TEST-'.Str::random(10),
                    'customer_id' => $customer->id,
                    'payment_date' => now(),
                    'status' => 'posted',
                    'payment_method_id' => $cash->id,
                    'amount' => 40,
                ]);

                $payment->allocations()->create(['sale_id' => $sale->id, 'amount' => 40]);
            }
        });
    }

    /** Posted sales, one customer and one product each. */
    protected function makeSales(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $customer = Customer::factory()->create();
                $product = Product::factory()->create();

                $sale = Sale::create([
                    'reference' => 'SI-TEST-'.Str::random(10),
                    'location_id' => Location::default()->id,
                    'customer_id' => $customer->id,
                    'sale_date' => now(),
                    'status' => 'posted',
                    'subtotal' => 100,
                    'discount' => 0,
                    'total_amount' => 100,
                    'total_cost' => 60,
                ]);

                $sale->lines()->create([
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'line_total' => 100,
                    'unit_cost' => 60,
                    'line_cost' => 60,
                ]);
            }
        });
    }

    public function test_the_transfers_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeTransfers(5);
        $small = $this->queriesFor($admin, '/transfers');

        $this->makeTransfers(35);
        $large = $this->queriesFor($admin, '/transfers?per_page=50');

        $this->assertSame($small, $large, 'The transfers list has an N+1.');
    }

    public function test_the_stock_counts_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeCounts(5);
        $small = $this->queriesFor($admin, '/stock-counts');

        $this->makeCounts(35);
        $large = $this->queriesFor($admin, '/stock-counts?per_page=50');

        $this->assertSame($small, $large, 'The stock counts list has an N+1.');
    }

    public function test_the_loss_reports_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeLossReports(5);
        $small = $this->queriesFor($admin, '/loss-reports');

        $this->makeLossReports(35);
        $large = $this->queriesFor($admin, '/loss-reports?per_page=50');

        $this->assertSame($small, $large, 'The loss reports list has an N+1.');
    }

    public function test_the_adjustments_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeAdjustments(5);
        $small = $this->queriesFor($admin, '/adjustments');

        $this->makeAdjustments(35);
        $large = $this->queriesFor($admin, '/adjustments?per_page=50');

        $this->assertSame($small, $large, 'The adjustments list has an N+1.');
    }

    public function test_the_sales_returns_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeSalesReturns(5);
        $small = $this->queriesFor($admin, '/sales-returns');

        $this->makeSalesReturns(35);
        $large = $this->queriesFor($admin, '/sales-returns?per_page=50');

        $this->assertSame($small, $large, 'The sales returns list has an N+1.');
    }

    public function test_the_purchase_returns_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makePurchaseReturns(5);
        $small = $this->queriesFor($admin, '/purchase-returns');

        $this->makePurchaseReturns(35);
        $large = $this->queriesFor($admin, '/purchase-returns?per_page=50');

        $this->assertSame($small, $large, 'The purchase returns list has an N+1.');
    }

    protected function makePurchaseReturns(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $supplier = Supplier::factory()->create();
                $product = Product::factory()->create();

                $purchase = Purchase::create([
                    'location_id' => Location::default()->id,
                    'reference' => 'PR-TEST-'.Str::random(10),
                    'supplier_id' => $supplier->id,
                    'purchase_date' => now(),
                    'status' => 'posted',
                    'total_amount' => 100,
                ]);

                $line = $purchase->lines()->create([
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 100,
                    'line_total' => 100,
                ]);

                $return = PurchaseReturn::create([
                    'reference' => 'PRR-TEST-'.Str::random(10),
                    'purchase_id' => $purchase->id,
                    'return_date' => now(),
                    'status' => 'posted',
                    'reason' => 'Faulty',
                    'total_cost' => 100,
                ]);

                $return->lines()->create([
                    'purchase_return_id' => $return->id,
                    'purchase_line_id' => $line->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 100,
                    'line_cost' => 100,
                ]);
            }
        });
    }

    protected function makeTransfers(int $count): void
    {
        $this->unaudited(function () use ($count) {
            $from = Location::default();
            $to = Location::query()->whereKeyNot($from->id)->first()
                ?? Location::factory()->create(['name' => 'Second place']);

            for ($i = 0; $i < $count; $i++) {
                $product = Product::factory()->create();

                $transfer = StockTransfer::create([
                    'reference' => 'TR-TEST-'.Str::random(10),
                    'from_location_id' => $from->id,
                    'to_location_id' => $to->id,
                    'status' => 'posted',
                    'transfer_date' => now(),
                    'total_cost' => 100,
                ]);

                $transfer->lines()->create([
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 100,
                    'total_cost' => 100,
                ]);
            }
        });
    }

    /** Finished counts: only one may be open at a location. */
    protected function makeCounts(int $count): void
    {
        $this->unaudited(function () use ($count) {
            $at = Location::default();

            for ($i = 0; $i < $count; $i++) {
                $product = Product::factory()->create();

                $stockCount = StockCount::create([
                    'reference' => 'CNT-TEST-'.Str::random(10),
                    'location_id' => $at->id,
                    'status' => 'cancelled',
                    'count_date' => now(),
                    'cutoff_at' => now(),
                ]);

                $stockCount->lines()->create([
                    'product_id' => $product->id,
                    'system_quantity' => 1,
                    'counted_quantity' => 1,
                ]);
            }
        });
    }

    protected function makeLossReports(int $count): void
    {
        $this->unaudited(function () use ($count) {
            $at = Location::default();

            for ($i = 0; $i < $count; $i++) {
                $product = Product::factory()->create();

                $report = LossReport::create([
                    'reference' => 'LR-TEST-'.Str::random(10),
                    'location_id' => $at->id,
                    'reason' => 'damaged',
                    'status' => 'rejected',
                    'report_date' => now(),
                ]);

                $report->lines()->create(['product_id' => $product->id, 'quantity' => 1]);
                $report->photos()->create(['path' => 'losses/'.Str::random(10).'.jpg']);
            }
        });
    }

    protected function makeAdjustments(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $product = Product::factory()->create();

                $adjustment = InventoryAdjustment::create([
                    'reference' => 'ADJ-TEST-'.Str::random(10),
                    'location_id' => Location::default()->id,
                    'kind' => 'increase',
                    'status' => 'posted',
                    'adjustment_date' => now(),
                    'reason' => 'count',
                    'total_cost' => 100,
                ]);

                $adjustment->lines()->create([
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_cost' => 100,
                    'total_cost' => 100,
                ]);
            }
        });
    }

    protected function makeSalesReturns(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $customer = Customer::factory()->create();
                $product = Product::factory()->create();

                $sale = Sale::create([
                    'reference' => 'SI-TEST-'.Str::random(10),
                    'location_id' => Location::default()->id,
                    'customer_id' => $customer->id,
                    'sale_date' => now(),
                    'status' => 'posted',
                    'subtotal' => 100,
                    'discount' => 0,
                    'total_amount' => 100,
                    'total_cost' => 60,
                ]);

                $line = $sale->lines()->create([
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'line_total' => 100,
                    'unit_cost' => 60,
                    'line_cost' => 60,
                ]);

                $return = SalesReturn::create([
                    'reference' => 'SR-TEST-'.Str::random(10),
                    'sale_id' => $sale->id,
                    'return_date' => now(),
                    'status' => 'posted',
                    'reason' => 'Faulty',
                    'total_amount' => 100,
                    'total_cost' => 60,
                ]);

                $return->lines()->create([
                    'sales_return_id' => $return->id,
                    'sale_line_id' => $line->id,
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_refund' => 100,
                    'line_refund' => 100,
                    'line_cost' => 60,
                ]);
            }
        });
    }

    public function test_the_movements_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        // A mix of document types in both fixtures. Loading the document
        // behind each row would cost one query per distinct type, so the
        // mix is what catches it.
        $this->makeMovements(5);
        $small = $this->queriesFor($admin, '/movements');

        $this->makeMovements(35);
        $large = $this->queriesFor($admin, '/movements?per_page=50');

        $this->assertSame(
            $small,
            $large,
            "The movements list ran {$small} queries for 5 rows and {$large} for 40. That is an N+1."
        );
    }

    public function test_the_stock_list_does_not_grow_with_its_rows(): void
    {
        $admin = $this->admin();

        $this->makeStock(5);
        $small = $this->queriesFor($admin, '/stock');

        $this->makeStock(35);
        $large = $this->queriesFor($admin, '/stock?per_page=50');

        $this->assertSame($small, $large, 'The stock list has an N+1.');
    }

    /**
     * Movements against several kinds of document, in the same proportions
     * both times.
     */
    protected function makeMovements(int $count): void
    {
        $this->unaudited(function () use ($count) {
            $sources = [
                Purchase::class,
                Sale::class,
                InventoryAdjustment::class,
                StockTransfer::class,
            ];

            for ($i = 0; $i < $count; $i++) {
                $product = Product::factory()->create();

                InventoryMovement::create([
                    'product_id' => $product->id,
                    'location_id' => Location::default()->id,
                    'source_type' => $sources[$i % count($sources)],
                    'source_id' => $i + 1,
                    'reference' => 'MOV-'.Str::random(8),
                    'type' => 'purchase',
                    'movement_date' => now(),
                    'quantity_in' => 1,
                    'quantity_out' => 0,
                    'balance_after' => 1,
                    'unit_cost' => 100,
                    'total_cost' => 100,
                ]);
            }
        });
    }

    /** Products with a batch behind each, so every row has a value. */
    protected function makeStock(int $count): void
    {
        $this->unaudited(function () use ($count) {
            for ($i = 0; $i < $count; $i++) {
                $product = Product::factory()->create();

                InventoryLayer::create([
                    'product_id' => $product->id,
                    'location_id' => Location::default()->id,
                    'reference' => 'ADJ-'.Str::random(8),
                    'layer_date' => now(),
                    'quantity_received' => 10,
                    'quantity_remaining' => 10,
                    'unit_cost' => 100,
                ]);
            }
        });
    }

    public function test_the_reports_screen_does_not_grow_with_its_documents(): void
    {
        $admin = $this->admin();

        $this->makeSales(5);
        $small = $this->queriesFor($admin, '/reports');

        $this->makeSales(35);
        $large = $this->queriesFor($admin, '/reports?from=2000-01-01');

        $this->assertSame(
            $small,
            $large,
            "The reports screen ran {$small} queries for 5 sales and {$large} for 40. "
                .'The summaries must be added up in SQL, not row by row.'
        );
    }

    /**
     * Narrowed by cashier and payment method (phase 34) the screen asks the
     * same questions with two more conditions — not one more per document.
     * The spreadsheet reads its registers 500 rows at a time, so under 500
     * documents its count stays flat too.
     */
    public function test_the_filtered_reports_and_the_spreadsheet_do_not_grow_with_the_documents(): void
    {
        $admin = $this->admin();
        $cash = PaymentMethod::where('kind', 'cash')->firstOrFail();
        $filtered = '/reports?from=2000-01-01&staff_id='.$admin->id.'&payment_method_id='.$cash->id;
        $export = '/reports/export?from=2000-01-01';

        $this->makeSales(5);
        $smallScreen = $this->queriesFor($admin, $filtered);
        $smallFile = $this->queriesFor($admin, $export);

        $this->makeSales(35);
        $largeScreen = $this->queriesFor($admin, $filtered);
        $largeFile = $this->queriesFor($admin, $export);

        $this->assertSame($smallScreen, $largeScreen, "The filtered reports screen ran {$smallScreen} queries for 5 sales and {$largeScreen} for 40.");
        $this->assertSame($smallFile, $largeFile, "The spreadsheet ran {$smallFile} queries for 5 sales and {$largeFile} for 40.");

        foreach (glob(storage_path('app/private/exports/*.xlsx')) ?: [] as $left) {
            @unlink($left);
        }
    }

    public function test_the_dashboard_figures_do_not_grow_with_the_documents(): void
    {
        $admin = $this->admin();
        $partial = [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (new HandleInertiaRequests)->version(request()),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'cards,trend,topSellers,lowStock',
        ];

        $this->makeSales(5);
        Cache::flush();
        $this->actingAs($admin)->get('/dashboard', $partial);

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/dashboard', $partial)->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->makeSales(35);

        Cache::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/dashboard', $partial)->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $small,
            $large,
            "The dashboard figures ran {$small} queries for 5 sales and {$large} for 40."
        );
    }

    public function test_a_page_of_users_stays_inside_its_budget(): void
    {
        $admin = $this->admin();
        $this->unaudited(fn () => User::factory()->count(30)->withRole('Staff')->create());

        $queries = $this->queriesFor($admin, '/users');

        // Session, the user, the permission cache, the count, the page, the
        // eager-loaded roles, and the admin guard check. A generous ceiling
        // that still catches a real regression.
        $this->assertLessThanOrEqual(
            12,
            $queries,
            "The users list ran {$queries} queries. The budget is 12."
        );
    }

    public function test_the_dashboard_stays_inside_its_budget(): void
    {
        $admin = $this->admin();
        $this->makeTrailRows(50);

        $this->actingAs($admin)->get('/dashboard');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The figures are deferred, so the first paint only has to fetch
        // the user, the settings and the filter bar's own lists.
        $this->assertLessThanOrEqual(
            10,
            $queries,
            "The dashboard ran {$queries} queries for its first paint. The budget is 10."
        );
    }

    /**
     * The hero cards, which is where the real work is.
     *
     * Eight cards over a period, each compared with the period before,
     * three of them carrying a sparkline. That is four aggregates for this
     * period, four for the last, four grouped for the lines and two for the
     * stock — a fixed cost, whatever the number of documents.
     *
     * The budget is here so the next card added is a decision rather than a
     * surprise. It is a ceiling, not a target.
     *
     * 17 since phase 19: the Overdue balances card stopped saying "Not
     * tracked yet" and reads one figure, as of today, with no comparison.
     * Customer payments joined Collections inside its existing query
     * rather than adding two more.
     *
     * 18 since phase 21: the Purchase requests card stopped saying "Not
     * tracked yet" and reads one figure — how many are waiting, their
     * estimate and the oldest — as of now, with no comparison.
     *
     * 21 since phase 24: Net cash from trading subtracts what was paid to
     * suppliers in the period and in the one before (two queries), and the
     * Payables card reads what is owed as of today (one). What went on a
     * supplier's account rides in the purchase queries already run.
     *
     * 22 since phase 24.4: the Orders to approve card reads how many purchase
     * orders wait for approval, their total and the oldest, as of now (one).
     */
    public function test_the_dashboard_cards_cost_the_same_at_any_volume(): void
    {
        $admin = $this->admin();
        $overview = app(DashboardOverview::class);
        $may = ['sales' => true, 'purchases' => true, 'stock' => true, 'cost' => true, 'purchase_requests' => true, 'payables' => true, 'purchase_orders' => true];

        $this->actingAs($admin);
        $this->makeSales(5);

        [$from, $to] = $overview->resolve('year');

        $count = function () use ($overview, $from, $to, $may) {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $overview->cards($from, $to, [], $may);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $small = $count();

        $this->makeSales(35);
        $this->makePurchases(20);
        $this->makePurchaseRequests(10);

        $large = $count();

        $this->assertSame($small, $large, 'The dashboard cards grow with the documents.');

        $this->assertLessThanOrEqual(
            22,
            $large,
            "The cards ran {$large} queries. The budget is 22."
        );
    }

    /* ------------------------------------------------------------- Indexes */

    public function test_the_columns_the_screens_filter_and_sort_on_are_indexed(): void
    {
        $expected = [
            'users' => ['username', 'is_active', 'name'],
            'audit_trails' => ['module', 'action', 'created_at', 'user_id', 'username'],
            'settings' => ['key'],
            'products' => ['sku', 'name', 'on_hand', 'is_active', 'category_id', 'unit_id'],
            'categories' => ['name', 'is_active'],
            'departments' => ['name', 'is_active'],
            'locations' => ['name', 'type', 'is_active'],
            'units' => ['code', 'name', 'is_active'],
            'payment_methods' => ['name', 'kind', 'is_active'],
            // Collections reads payments by sale and adds them by method.
            'sale_payments' => ['sale_id', 'payment_method_id'],
            'suppliers' => ['code', 'name', 'is_active'],
            'customers' => ['code', 'name', 'is_active', 'is_walk_in'],
            // The FIFO pick reads product_id and quantity_remaining; the
            // stock card reads product_id and the date.
            'inventory_layers' => ['product_id', 'quantity_remaining', 'reference'],
            'inventory_movements' => ['product_id', 'type', 'movement_date', 'reference'],
            'inventory_adjustments' => ['reference', 'kind', 'status', 'adjustment_date'],
            'document_sequences' => ['prefix', 'year'],
            'purchases' => ['reference', 'supplier_id', 'purchase_date', 'status', 'purchase_order_id'],
            'sales' => ['reference', 'customer_id', 'sale_date', 'status'],
            'sale_lines' => ['sale_id', 'product_id', 'inventory_movement_id'],
            'purchase_lines' => ['purchase_id', 'product_id', 'inventory_layer_id', 'purchase_order_line_id'],
            'sales_returns' => ['reference', 'sale_id', 'return_date', 'status'],
            // Customer payments: the list filters on these, a statement
            // reads a customer's by date, and a sale reads what paid it.
            'customer_payments' => ['reference', 'customer_id', 'payment_date', 'status'],
            'customer_payment_allocations' => ['customer_payment_id', 'sale_id'],
            // Purchase requests: the list filters and sorts on these, and
            // the dashboard reads the pending ones by whoever raised them.
            'purchase_requests' => ['reference', 'request_date', 'status', 'supplier_id', 'department_id', 'requested_by'],
            'purchase_request_lines' => ['purchase_request_id', 'product_id', 'unit_id'],
            // Purchase orders: the list filters and sorts on these.
            'purchase_orders' => ['reference', 'supplier_id', 'purchase_request_id', 'order_date', 'expected_date', 'status', 'ordered_by'],
            'purchase_order_lines' => ['purchase_order_id', 'product_id', 'unit_id'],
            // Supplier payments and Payables: the lists filter and sort on
            // these, and a bill reads what paid it.
            'supplier_payments' => ['reference', 'supplier_id', 'payment_date', 'status', 'payment_method_id'],
            'supplier_payment_allocations' => ['supplier_payment_id', 'purchase_id'],
            // Quotations: the list filters and sorts on these.
            'quotations' => ['reference', 'customer_id', 'quote_date', 'valid_until', 'status', 'prepared_by', 'sale_id'],
            'quotation_lines' => ['quotation_id', 'product_id', 'unit_id'],
            // Sales orders: the list, and what is reserved (status, date).
            'sales_orders' => ['reference', 'customer_id', 'order_date', 'reserve_until', 'status', 'created_by', 'quotation_id', 'sale_id'],
            // Pending payments (CR-2): the list filters and sorts on these;
            // what they hold is read by status and date.
            'pending_payments' => ['reference', 'location_id', 'customer_id', 'order_date', 'hold_until', 'status', 'taken_by', 'sale_id'],
            'pending_payment_lines' => ['pending_payment_id', 'product_id', 'unit_id'],
            'sales_order_lines' => ['sales_order_id', 'product_id', 'unit_id'],
            'sales_return_lines' => ['sales_return_id', 'sale_line_id', 'product_id'],
            'purchase_returns' => ['reference', 'purchase_id', 'return_date', 'status'],
            'purchase_return_lines' => ['purchase_return_id', 'purchase_line_id', 'product_id'],
            'inventory_adjustment_lines' => ['inventory_adjustment_id', 'product_id'],
            // Transfers (phase 31.5): the list filters and sorts on these.
            'stock_transfers' => ['reference', 'transfer_date', 'status', 'from_location_id', 'to_location_id', 'posted_by'],
            'stock_transfer_lines' => ['stock_transfer_id', 'product_id'],
            // Stock counts (phase 32): the list filters and sorts on these.
            'stock_counts' => ['reference', 'count_date', 'status', 'location_id', 'started_by'],
            'stock_count_lines' => ['stock_count_id', 'product_id'],
            // Loss reports (phase 33): the list filters and sorts on these;
            // what is held is read by product.
            'loss_reports' => ['reference', 'report_date', 'status', 'location_id', 'reason', 'reported_by'],
            'loss_report_lines' => ['loss_report_id', 'product_id'],
            'loss_report_photos' => ['loss_report_id'],
            // The movement screen sorts by id and filters on these.
            'inventory_movements' => ['product_id', 'type', 'movement_date', 'reference'],
        ];

        foreach ($expected as $table => $columns) {
            $indexed = $this->indexedColumns($table);

            foreach ($columns as $column) {
                $this->assertContains(
                    $column,
                    $indexed,
                    "[{$table}.{$column}] is filtered or sorted on, so it needs an index."
                );
            }
        }
    }

    /**
     * Every column covered by an index on a table.
     *
     * A foreign key counts. MySQL, which this app runs on, always builds an
     * index behind a foreign key, so adding a second one on the same column
     * would only waste space. These tests run on SQLite, which does not, so
     * the foreign keys are read separately.
     *
     * @return list<string>
     */
    protected function indexedColumns(string $table): array
    {
        $schema = DB::connection()->getSchemaBuilder();

        $fromIndexes = collect($schema->getIndexes($table))
            ->flatMap(fn (array $index) => $index['columns']);

        $fromForeignKeys = collect($schema->getForeignKeys($table))
            ->flatMap(fn (array $key) => $key['columns']);

        return $fromIndexes->merge($fromForeignKeys)->unique()->values()->all();
    }

    /* ------------------------------------------------------- The API v1 */

    /**
     * Count the queries one API call makes.
     *
     * The same idea as `queriesFor()`, over a token rather than a session.
     * A phone has no Inertia middleware and no shared props, so its budget
     * is counted separately from the screen that shows the same rows.
     */
    protected function apiQueriesFor(string $token, string $uri): int
    {
        $this->withToken($token)->getJson($uri);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->withToken($token)->getJson($uri)->assertOk();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** A token holding every permission, so nothing is skipped by a gate. */
    protected function fullToken(): string
    {
        $admin = $this->admin();

        return $admin->createToken(
            'performance',
            $admin->getAllPermissions()->pluck('name')->all(),
        )->plainTextToken;
    }

    /**
     * Every API list, with five rows and then with forty.
     *
     * One test rather than twelve, because the shape of the check is
     * identical and the failure message names the endpoint that grew. The
     * endpoints are measured in turn, each from its own baseline, so rows
     * left behind by an earlier one cannot hide an N+1 in a later one.
     */
    public function test_no_api_list_grows_with_its_rows(): void
    {
        $token = $this->fullToken();

        $endpoints = [
            'products' => fn (int $n) => Product::factory()->count($n)->create(),
            'categories' => fn (int $n) => Category::factory()->count($n)->create(),
            'units' => fn (int $n) => Unit::factory()->count($n)->create(),
            'suppliers' => fn (int $n) => Supplier::factory()->count($n)->create(),
            'customers' => fn (int $n) => Customer::factory()->count($n)->create(),
            'purchases' => fn (int $n) => $this->makePurchases($n),
            'sales' => fn (int $n) => $this->makeSales($n),
            'sales-returns' => fn (int $n) => $this->makeSalesReturns($n),
            'purchase-returns' => fn (int $n) => $this->makePurchaseReturns($n),
            'adjustments' => fn (int $n) => $this->makeAdjustments($n),
            'movements' => fn (int $n) => $this->makeMovements($n),
            'stock' => fn (int $n) => $this->makeStock($n),
        ];

        $grew = [];

        foreach ($endpoints as $path => $make) {
            $this->unaudited(fn () => $make(5));
            Cache::flush();
            $small = $this->apiQueriesFor($token, "/api/v1/{$path}");

            $this->unaudited(fn () => $make(35));
            Cache::flush();
            $large = $this->apiQueriesFor($token, "/api/v1/{$path}?per_page=50");

            // Proof the rows were made and the endpoint returned them. A
            // count that never grows would make the test above pass for
            // the wrong reason.
            $returned = count(
                $this->withToken($token)->getJson("/api/v1/{$path}?per_page=50")->json('data')
            );

            $this->assertGreaterThanOrEqual(
                40,
                $returned,
                "/api/v1/{$path} returned {$returned} rows, so this check proved nothing."
            );

            if ($small !== $large) {
                $grew[] = "{$path}: {$small} query/queries at 5 rows, {$large} at 40";
            }
        }

        $this->assertSame([], $grew, "These API lists have an N+1:\n  ".implode("\n  ", $grew));
    }

    /**
     * A page of movements over the API, inside a budget.
     *
     * The count is asserted as a ceiling, not an exact figure, so adding a
     * cheap query is allowed and adding a per-row one is not.
     */
    public function test_a_page_of_movements_stays_inside_its_budget(): void
    {
        $token = $this->fullToken();
        $this->makeMovements(40);

        Cache::flush();
        $queries = $this->apiQueriesFor($token, '/api/v1/movements?per_page=50');

        $this->assertLessThanOrEqual(
            10,
            $queries,
            "A page of movements took {$queries} queries. Something is being loaded per row."
        );
    }

    protected function makeTrailRows(int $count): void
    {
        $now = now();

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'user_id' => null,
                'user_name' => 'Someone',
                'username' => 'someone',
                'role' => 'Staff',
                'module' => 'Users',
                'action' => AuditAction::View->value,
                'description' => "Opened something {$i}.",
                'ip_address' => '127.0.0.1',
                'browser' => 'Test client',
                'created_at' => $now->copy()->subMinutes($i),
            ];
        }

        AuditTrail::insert($rows);
    }
}
