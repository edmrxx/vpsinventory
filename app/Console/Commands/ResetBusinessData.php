<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Services\AuditService;
use App\Services\DashboardMetrics;
use App\Services\InventoryMetrics;
use App\Support\LossPhoto;
use App\Support\ProductPhoto;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Empty the books, so a business can start entering its own data.
 *
 * Every document, the whole ledger, the seven master lists and the audit
 * trail go. The accounts, the roles and the settings stay, so everybody can
 * still sign in and the company name, logo, VAT switch and document prefixes
 * are as they were. One row is then put back: the walk-in customer, because
 * the till cannot sell without it. Even the standard units stay out; the
 * business enters its own.
 *
 * The tables are named here one by one and never looked up. Laravel 12 lists
 * the tables of every database on a MySQL server, not just this one, and a
 * command that empties tables must never be handed somebody else's.
 * `ResetBusinessDataTest` fails when a migration adds a table that is in
 * neither list, so a new one has to be placed on purpose.
 *
 * The rows are deleted inside one transaction, children before parents, so
 * a failure part-way leaves the data exactly as it was. Only afterwards are
 * the id counters put back to 1: on MySQL that is a schema change, which
 * would commit the transaction early.
 */
class ResetBusinessData extends Command
{
    protected $signature = 'data:reset {--force : Do not ask first}';

    protected $description = 'Empty every document, the stock ledger, the master lists and the audit trail; keep users, roles and settings';

    /** The word typed to agree, so nobody does this by pressing Enter. */
    public const CONFIRMATION = 'RESET';

    /**
     * Emptied, in the order the foreign keys allow: a row goes before the
     * row it points at.
     */
    public const WIPED = [
        // Sales parked at the till. They name customers and products.
        'held_sales',
        // Orders the kiosk sent to the cashier (CR-2). They name sales,
        // customers, products and locations.
        'pending_payment_lines',
        'pending_payments',
        // Sales orders, then quotations: before the sales they became, the
        // quotations orders came from, and the customers.
        'sales_order_lines',
        'sales_orders',
        'quotation_lines',
        'quotations',
        // Money customers paid against invoices. Before the sales they
        // paid, and before the customers.
        'customer_payment_allocations',
        'customer_payments',
        // Money paid to suppliers. Before the bills it paid, and the
        // suppliers.
        'supplier_payment_allocations',
        'supplier_payments',
        'inventory_layer_consumptions',
        'sales_return_lines',
        'sales_returns',
        'purchase_return_lines',
        'purchase_returns',
        'sale_lines',
        'sale_payments',
        'sales',
        'purchase_lines',
        'purchases',
        // Orders placed with suppliers: after the deliveries received
        // against them (phase 23), before the requests they came from.
        'purchase_order_lines',
        'purchase_orders',
        // Suppliers' prices against requests (phase 24.2): before the request
        // lines they price and the suppliers. A request line's award points
        // back at a quote line and is cleared when it goes.
        'supplier_quote_lines',
        'supplier_quotes',
        // Requests to buy. They name products, units and suppliers.
        'purchase_request_lines',
        'purchase_requests',
        // Count sheets (phase 32): before the adjustments their approval
        // posted, the products they list and the locations they count.
        'stock_count_lines',
        'stock_counts',
        // Loss reports (phase 33): before the adjustments their approval
        // posted, the products and the locations. Their photos are files on
        // the private disk, deleted by name after the rows.
        'loss_report_photos',
        'loss_report_lines',
        'loss_reports',
        'inventory_adjustment_lines',
        'inventory_adjustments',
        // Stock moved between locations (phase 31.5): before the movements,
        // batches, locations and products they name.
        'stock_transfer_lines',
        'stock_transfers',
        'inventory_movements',
        'inventory_layers',
        // Each product's stock at each location (phase 31.2): before the
        // products and the locations it names.
        'location_stocks',
        'product_units',
        'products',
        'categories',
        // Departments (phase 24.1): after the requests that name them. The
        // client enters their own, like every other master list.
        'departments',
        // Locations (phase 31.1). One default is put back afterwards, as the
        // walk-in customer is: the ledger cannot post without one (31.2).
        'locations',
        'units',
        'suppliers',
        'customers',
        'document_sequences',
        'audit_trails',
    ];

    /**
     * Left alone. The cache is flushed through the services that own it
     * rather than emptied, so the settings stay cached.
     */
    public const KEPT = [
        'users',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
        'settings',
        // Set-up, like the settings: the ways the shop is paid stay. The
        // till cannot sell without the cash method.
        'payment_methods',
        'personal_access_tokens',
        'password_reset_tokens',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'migrations',
    ];

    public function handle(AuditService $audit): int
    {
        $counts = $this->counts();

        $this->components->warn('Database: '.DB::connection()->getDatabaseName());

        $this->components->twoColumnDetail('<fg=red;options=bold>Will be emptied</>', '<fg=red;options=bold>Rows</>');
        foreach ($counts as $table => $rows) {
            $this->components->twoColumnDetail($table, number_format($rows));
        }

        $this->newLine();
        $this->components->info(sprintf(
            'Kept: %d user account(s), %d role(s), the permissions, the payment methods and every setting, including the logo.',
            DB::table('users')->count(),
            DB::table('roles')->count(),
        ));

        $this->components->info('Put back afterwards: the Walk-in Customer, so the till can sell, and one default location, Main Store, so stock has somewhere to go. The master lists are otherwise empty.');

        $this->components->warn('This cannot be undone. Back up the database and storage/app/public first.');

        if (! $this->option('force')) {
            $answer = $this->ask('Type '.self::CONFIRMATION.' to empty these tables');

            if ($answer !== self::CONFIRMATION) {
                $this->components->warn('Nothing was changed.');

                return self::SUCCESS;
            }
        }

        // Read before the rows go: only the photos a product named are
        // deleted, and only once the rows are safely gone.
        $photoPaths = DB::table('products')->whereNotNull('photo_path')->pluck('photo_path')->all();
        // The evidence on loss reports (phase 33), on the private disk.
        $evidencePaths = DB::table('loss_report_photos')->pluck('path')->all();

        // Rows only. Nobody did any of this from a screen, and the trail is
        // about to be emptied anyway; the one row that matters is below.
        $audit->withoutAuditing(fn () => DB::transaction(function () {
            foreach (self::WIPED as $table) {
                DB::table($table)->delete();
            }
        }));

        $this->restartIds();

        // The one row put back: the walk-in customer the till sells to.
        // Without it a counter sale has no buyer to name, and the protected
        // flag cannot be set again from the screens. The units are not put
        // back — the client enters their own (decided 7 November 2026).
        $audit->withoutAuditing(fn () => MasterDataSeeder::walkInCustomer());

        // And one location, the default, so the opening stock has somewhere
        // to go (phase 31.2). Renamed on the Locations screen to the real
        // store; the warehouse is added there too.
        $audit->withoutAuditing(fn () => MasterDataSeeder::defaultLocation());

        $photos = ProductPhoto::deleteMany($photoPaths);
        $evidence = LossPhoto::deleteMany($evidencePaths);

        // Otherwise the dashboard goes on showing the old totals until the
        // cache runs out.
        app(InventoryMetrics::class)->flush();
        app(DashboardMetrics::class)->flush();

        // The first row of the new trail says what happened to the old one.
        $audit->log(
            AuditAction::Delete,
            'System',
            'All business data was reset from the server command line: documents, stock, master lists and the audit trail.',
            properties: ['rows' => $counts, 'product_photos' => $photos, 'loss_photos' => $evidence],
        );

        $this->components->info(
            'Emptied '.count(self::WIPED).' tables and removed '.$photos.' product photo(s) and '.$evidence.' loss photo(s). '
                .'Document numbers start again at 0001.'
        );

        $this->call('inventory:verify');

        $this->components->bulletList([
            'Rename Main Store to the real store on the Locations screen, and add the warehouse.',
            'Add the units first (a product cannot be saved without one), then the categories, suppliers, customers and products.',
            'Enter the opening stock through Adjustments, with the kind set to Opening stock.',
        ]);

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    protected function counts(): array
    {
        $counts = [];

        foreach (self::WIPED as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Put every emptied table's id counter back to 1, so the first product
     * entered is product 1 and not product 31.
     */
    protected function restartIds(): void
    {
        $connection = DB::connection();

        match ($connection->getDriverName()) {
            'mysql', 'mariadb' => array_map(
                fn (string $table) => $connection->statement(
                    'ALTER TABLE '.$connection->getQueryGrammar()->wrapTable($table).' AUTO_INCREMENT = 1'
                ),
                self::WIPED,
            ),
            // Only tables that have handed out an id appear here.
            'sqlite' => $connection->table('sqlite_sequence')->whereIn('name', self::WIPED)->delete(),
            default => null,
        };
    }
}
