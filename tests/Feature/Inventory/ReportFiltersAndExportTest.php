<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditTrail;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AdjustmentPoster;
use App\Support\Spreadsheet;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Tests\TestCase;
use ZipArchive;

/**
 * Phase 34: the Reports screen narrowed by cashier and payment method, and
 * the same report as a spreadsheet.
 */
class ReportFiltersAndExportTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Customer $customer;

    protected Supplier $supplier;

    protected PaymentMethod $cash;

    protected PaymentMethod $gcash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();

        $this->product = $this->unaudited(fn () => Product::factory()->create(['sku' => 'CEM-40', 'name' => 'Cement 40 kg']));
        $this->customer = $this->unaudited(fn () => Customer::factory()->walkIn()->create());
        $this->supplier = $this->unaudited(fn () => Supplier::factory()->create());
        $this->cash = PaymentMethod::where('name', 'Cash')->firstOrFail();
        $this->gcash = PaymentMethod::where('name', 'GCash')->firstOrFail();

        app(AdjustmentPoster::class)->post('increase', [
            ['product_id' => $this->product->id, 'quantity' => 100, 'unit_cost' => 200],
        ]);
    }

    protected function admin(): User
    {
        return $this->unaudited(fn () => User::factory()->admin()->create());
    }

    protected function staff(): User
    {
        return $this->unaudited(fn () => User::factory()->withRole('Staff')->create());
    }

    /** One sale of `$quantity` at 500, paid as given. */
    protected function sell(User $by, int $quantity, array $payments, ?Customer $customer = null): Sale
    {
        $this->actingAs($by)->post('/sales', [
            'customer_id' => ($customer ?? $this->customer)->id,
            'sale_date' => Carbon::today()->toDateString(),
            'discount' => 0,
            'lines' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_price' => '500.00']],
            'payments' => $payments,
        ])->assertSessionHasNoErrors();

        return Sale::latest('id')->firstOrFail();
    }

    protected function cash(string $amount): array
    {
        return ['payment_method_id' => $this->cash->id, 'amount' => $amount];
    }

    protected function gcash(string $amount): array
    {
        return ['payment_method_id' => $this->gcash->id, 'amount' => $amount, 'reference' => '1234 5678 9012'];
    }

    protected function buy(User $by): void
    {
        $this->actingAs($by)->post('/purchases', [
            'supplier_id' => $this->supplier->id,
            'purchase_date' => Carbon::today()->toDateString(),
            'supplier_reference' => 'INV-'.Str::random(6),
            'lines' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => '210.00']],
        ])->assertSessionHasNoErrors();
    }

    /* ------------------------------------------------------------ Cashier */

    public function test_the_cashier_filter_narrows_every_block_to_their_documents(): void
    {
        $admin = $this->admin();
        $ana = $this->staff();
        $ben = $this->staff();

        $this->sell($ana, 2, [$this->cash('1000')]);
        $this->sell($ben, 3, [$this->cash('1500')]);
        $this->buy($ben);

        $this->actingAs($admin)
            ->get('/reports?staff_id='.$ana->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.staff_id', $ana->id)
                ->where('sales.net', '1000.00')
                ->where('sales.documents', 1)
                ->where('sales.by_product.total.quantity', 2)
                ->where('sales.by_customer.total.amount', '1000.00')
                // Ana bought nothing; Ben's delivery is not hers.
                ->where('purchases.documents', 0)
                ->where('collections.total', 1000)
                ->where('trend.series.0.data', fn ($data) => collect($data)->sum() == 1000)
                // The stock value is "now" and no filter narrows it.
                ->where('inventory', null));

        $this->actingAs($admin)
            ->get('/reports?staff_id='.$ben->id)
            ->assertInertia(fn ($page) => $page
                ->where('sales.net', '1500.00')
                ->where('purchases.net', '2100.00'));
    }

    public function test_staff_may_narrow_to_themselves_but_not_to_a_colleague(): void
    {
        $ana = $this->staff();
        $ben = $this->staff();

        $this->sell($ana, 2, [$this->cash('1000')]);
        $this->sell($ben, 3, [$this->cash('1500')]);

        // Everybody together, as Reports always showed them.
        $this->actingAs($ana)->get('/reports')
            ->assertInertia(fn ($page) => $page
                ->where('sales.net', '2500.00')
                ->has('options.staff', 1)
                ->where('options.staff.0.value', $ana->id));

        $this->actingAs($ana)->get('/reports?staff_id='.$ana->id)
            ->assertInertia(fn ($page) => $page->where('sales.net', '1000.00'));

        $this->actingAs($ana)->get('/reports?staff_id='.$ben->id)
            ->assertSessionHasErrors(['staff_id' => 'You may narrow the report to your own documents only.']);

        $this->actingAs($ana)->get('/reports/export?staff_id='.$ben->id)
            ->assertSessionHasErrors('staff_id');
    }

    /* ----------------------------------------------------- Payment method */

    public function test_a_split_sale_counts_whole_under_either_method(): void
    {
        $admin = $this->admin();

        // 1,000: 400 cash and 600 GCash. Then 1,500 in cash alone.
        $this->sell($admin, 2, [$this->cash('400'), $this->gcash('600')]);
        $this->sell($admin, 3, [$this->cash('1500')]);

        $this->actingAs($admin)
            ->get('/reports?payment_method_id='.$this->gcash->id)
            ->assertInertia(fn ($page) => $page
                ->where('filters.payment_label', 'GCash')
                ->where('sales.documents', 1)
                ->where('sales.net', '1000.00')
                // Only GCash's own money came in by GCash.
                ->where('collections.total', 600)
                ->has('collections.methods', 1)
                // A purchase records no method: left out, not shown unfiltered.
                ->where('purchases', null)
                ->has('trend.series', 1));

        $this->actingAs($admin)
            ->get('/reports?payment_method_id='.$this->cash->id)
            ->assertInertia(fn ($page) => $page
                ->where('sales.documents', 2)
                ->where('sales.net', '2500.00')
                ->where('collections.total', 1900));
    }

    public function test_a_return_goes_with_the_sale_it_undoes(): void
    {
        $admin = $this->admin();

        $paidByGcash = $this->sell($admin, 2, [$this->gcash('1000')]);
        $this->sell($admin, 3, [$this->cash('1500')]);

        // Refunded in cash, as every return is until stage 6, but it undoes
        // a GCash sale, so it counts there.
        $this->actingAs($admin)->post('/sales-returns', [
            'sale_id' => $paidByGcash->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Damaged bag',
            'lines' => [['sale_line_id' => $paidByGcash->lines->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->get('/reports?payment_method_id='.$this->gcash->id)
            ->assertInertia(fn ($page) => $page
                ->where('sales.return_documents', 1)
                ->where('sales.returns', '500.00')
                ->where('sales.net', '500.00')
                ->where('sales.by_product.total.quantity', 1));

        $this->actingAs($admin)
            ->get('/reports?payment_method_id='.$this->cash->id)
            ->assertInertia(fn ($page) => $page
                ->where('sales.return_documents', 0)
                ->where('sales.net', '1500.00'));
    }

    public function test_an_unknown_method_or_cashier_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports?payment_method_id=999&staff_id=999')
            ->assertSessionHasErrors(['payment_method_id', 'staff_id']);
    }

    public function test_a_switched_off_method_is_still_offered(): void
    {
        $this->gcash->forceFill(['is_active' => false])->save();

        $this->actingAs($this->admin())->get('/reports')
            ->assertInertia(fn ($page) => $page
                ->where('options.methods', fn ($methods) => collect($methods)
                    ->contains('label', 'GCash (switched off)')));
    }

    /* -------------------------------------------------------- Spreadsheet */

    public function test_the_spreadsheet_holds_the_summary_and_every_document(): void
    {
        $admin = $this->admin();
        $this->sell($admin, 2, [$this->cash('400'), $this->gcash('600')]);
        $this->sell($admin, 3, [$this->cash('1500')]);
        $this->buy($admin);

        $book = $this->download($admin, '/reports/export');

        $this->assertSame(
            ['Summary', 'Sales', 'Sales returns', 'Sales by product', 'Sales by customer',
                'Purchases', 'Purchase returns', 'Purchases by supplier', 'Purchases by product'],
            array_keys($book),
        );

        $summary = $this->flatten($book['Summary']);
        $this->assertContains('Net sales', $summary);
        $this->assertContains('2500', $summary);
        $this->assertContains('Gross profit', $summary);

        // One row per sale, under a header, with a total that is the screen's.
        $sales = $book['Sales'];
        $header = $sales[0];
        $this->assertSame(['Date', 'Reference', 'Customer', 'Cashier', 'Subtotal', 'Discount', 'Mobilization', 'Total'], array_slice($header, 0, 8));
        $this->assertContains('GCash', $header);
        $this->assertContains('Cost', $header);
        $this->assertCount(4, $sales); // header, two sales, total
        $total = end($sales);
        $this->assertSame('Total', $total[0]);
        $this->assertEquals(2500, $total[array_search('Total', $header, true)]);
        $this->assertEquals(600, $total[array_search('GCash', $header, true)]);
        $this->assertEquals(1900, $total[array_search('Cash', $header, true)]);

        // A date is a real date, not text.
        $this->assertSame((string) Spreadsheet::serial(Carbon::today()->toDateString()), (string) $sales[1][0]);

        $this->assertEquals(2100, end($book['Purchases'])[6]);
    }

    public function test_the_spreadsheet_follows_the_filters(): void
    {
        $admin = $this->admin();
        $this->sell($admin, 2, [$this->gcash('1000')]);
        $this->sell($admin, 3, [$this->cash('1500')]);
        $this->buy($admin);

        $book = $this->download($admin, '/reports/export?payment_method_id='.$this->gcash->id);

        $this->assertArrayNotHasKey('Purchases', $book);
        $this->assertCount(3, $book['Sales']);
        $this->assertContains('GCash', $this->flatten($book['Summary']));
    }

    public function test_staff_never_receive_the_cost_in_the_file(): void
    {
        $staff = $this->staff();
        $this->sell($staff, 2, [$this->cash('1000')]);

        $this->actingAs($staff)->post('/sales-returns', [
            'sale_id' => Sale::latest('id')->first()->id,
            'return_date' => Carbon::today()->toDateString(),
            'reason' => 'Damaged bag',
            'lines' => [['sale_line_id' => Sale::latest('id')->first()->lines->first()->id, 'quantity' => 1]],
        ])->assertSessionHasNoErrors();

        $book = $this->download($staff, '/reports/export');
        // The return is in the file, so its sheet's columns were checked too.
        $this->assertCount(3, $book['Sales returns']);
        $everything = collect($book)->flatMap(fn ($rows) => $this->flatten($rows))->all();

        $this->assertNotContains('Cost', $everything);
        $this->assertNotContains('Gross profit', $everything);
        $this->assertNotContains('Cost of goods sold', $everything);
        // Two bags at 200 each cost 400; one came back. No 200 or 400 cost anywhere.
        $this->assertNotContains('400', $book['Sales'][1]);
    }

    public function test_a_name_typed_as_a_formula_stays_text(): void
    {
        $admin = $this->admin();
        $sly = $this->unaudited(fn () => Customer::factory()->create([
            'name' => '=HYPERLINK("http://evil.test","Click")',
        ]));
        $this->sell($admin, 1, [$this->cash('500')], $sly);

        $raw = $this->rawSheets($admin, '/reports/export');

        foreach ($raw as $xml) {
            $this->assertStringNotContainsString('<f>', $xml);
            $this->assertStringNotContainsString('<f ', $xml);
        }

        $this->assertContains('=HYPERLINK("http://evil.test","Click")', $this->flatten($this->download($admin, '/reports/export')['Sales']));
    }

    public function test_a_control_character_in_a_name_does_not_break_the_file(): void
    {
        $admin = $this->admin();
        $odd = $this->unaudited(fn () => Customer::factory()->create(['name' => "Mang\x07 Jose\x1F Hardware"]));
        $this->sell($admin, 1, [$this->cash('500')], $odd);

        // Every part must parse; the name arrives without the characters.
        $this->assertContains('Mang Jose Hardware', $this->flatten($this->download($admin, '/reports/export')['Sales']));
    }

    public function test_an_export_is_written_to_the_audit_trail_and_leaves_no_file_behind(): void
    {
        $admin = $this->admin();
        $this->download($admin, '/reports/export?payment_method_id='.$this->gcash->id);

        $this->assertTrue(AuditTrail::query()
            ->where('module', 'Reports')
            ->where('description', 'like', 'Exported the report as a spreadsheet:%GCash%')
            ->exists());

        $this->assertSame([], glob(storage_path('app/private/exports/*')) ?: []);
    }

    public function test_the_export_needs_the_reports_permission(): void
    {
        $this->actingAs($this->unaudited(fn () => User::factory()->create()))
            ->get('/reports/export')
            ->assertForbidden();

        $this->forgetSignedInUser();
        auth()->logout();
        $this->get('/reports/export')->assertRedirect('/login');
    }

    /* ---------------------------------------------------------- The writer */

    public function test_the_writer_names_columns_past_z_and_counts_days_like_excel(): void
    {
        $this->assertSame('A', Spreadsheet::column(0));
        $this->assertSame('Z', Spreadsheet::column(25));
        $this->assertSame('AA', Spreadsheet::column(26));
        $this->assertSame('AZ', Spreadsheet::column(51));
        $this->assertSame('BA', Spreadsheet::column(52));

        $this->assertSame(1, Spreadsheet::serial('1899-12-31'));
        $this->assertSame(36526, Spreadsheet::serial('2000-01-01'));
        $this->assertSame(46339, Spreadsheet::serial('2026-11-13'));

        $this->assertSame('1234.5', Spreadsheet::numeral(1234.5));
        $this->assertSame('1900.00', Spreadsheet::numeral('1900.00'));
        $this->assertSame('0', Spreadsheet::numeral(-0.0));
    }

    public function test_the_writer_keeps_sheet_names_legal_and_unique(): void
    {
        $book = new Spreadsheet;
        $book->sheet('Sales: [north]/south?')->row(['a']);
        $book->sheet('Sales: [north]/south?')->row(['b']);
        $book->sheet(str_repeat('x', 40))->row(['c']);

        $zip = new ZipArchive;
        $zip->open($path = $book->save());
        $workbook = new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
        $zip->close();
        @unlink($path);

        $names = array_map(fn ($sheet) => (string) $sheet['name'], iterator_to_array($workbook->sheets->sheet, false));

        $this->assertSame('Sales   north  south', $names[0]);
        $this->assertSame('Sales   north  south (2)', $names[1]);
        $this->assertSame(31, mb_strlen($names[2]));
    }

    /* ---------------------------------------------------------- Reading */

    /**
     * Download the workbook and read every sheet back as rows of cell
     * values. Every XML part is parsed, so a malformed one fails the test.
     *
     * @return array<string, list<list<string>>>
     */
    protected function download(User $user, string $url): array
    {
        $path = $this->save($user, $url);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The download is not a zip.');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            new SimpleXMLElement($zip->getFromIndex($i));
        }

        $workbook = new SimpleXMLElement($zip->getFromName('xl/workbook.xml'));
        $book = [];
        $n = 1;

        foreach ($workbook->sheets->sheet as $sheet) {
            $xml = new SimpleXMLElement($zip->getFromName("xl/worksheets/sheet{$n}.xml"));
            $rows = [];

            foreach ($xml->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    preg_match('/^([A-Z]+)/', (string) $cell['r'], $m);
                    $index = 0;
                    foreach (str_split($m[1]) as $letter) {
                        $index = $index * 26 + (ord($letter) - 64);
                    }
                    $cells[$index - 1] = (string) $cell['t'] === 'inlineStr' ? (string) $cell->is->t : (string) $cell->v;
                }
                $rows[] = $cells === [] ? [] : array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
            }

            $book[(string) $sheet['name']] = $rows;
            $n++;
        }

        $zip->close();
        @unlink($path);

        return $book;
    }

    /** @return list<string> */
    protected function rawSheets(User $user, string $url): array
    {
        $path = $this->save($user, $url);
        $zip = new ZipArchive;
        $zip->open($path);
        $parts = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with($zip->getNameIndex($i), 'xl/worksheets/')) {
                $parts[] = $zip->getFromIndex($i);
            }
        }

        $zip->close();
        @unlink($path);

        return $parts;
    }

    protected function save(User $user, string $url): string
    {
        $response = $this->actingAs($user)->get($url);
        $response->assertOk();
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'book');
        copy($response->baseResponse->getFile()->getPathname(), $path);

        // Send it as the browser would receive it: that is when the server
        // deletes its copy.
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();

        return $path;
    }

    /** @return list<string> */
    protected function flatten(array $rows): array
    {
        return collect($rows)->flatten()->map(fn ($value) => (string) $value)->all();
    }
}
