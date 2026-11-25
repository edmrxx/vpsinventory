<?php

namespace Tests\Feature\Seeders;

use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Models\Supplier;
use Database\Seeders\HardwareSupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class HardwareSupplierSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_ten_active_suppliers_of_both_vat_kinds(): void
    {
        $this->seed(HardwareSupplierSeeder::class);

        $this->assertSame(10, Supplier::active()->count());
        $this->assertSame(6, Supplier::where('is_vat_registered', true)->count());
        $this->assertSame(4, Supplier::where('is_vat_registered', false)->count());
        $this->assertDatabaseCount('audit_trails', 0);
    }

    public function test_every_supplier_would_pass_the_form_on_the_suppliers_screen(): void
    {
        // Otherwise a seeded supplier could never be saved again from its
        // own edit dialog.
        $rules = (new StoreSupplierRequest)->rules();

        foreach (HardwareSupplierSeeder::SUPPLIERS as $code => $row) {
            $this->assertStringEndsWith('.test', $row['email'], "[{$code}] must not point at a real address.");

            $validator = Validator::make(['code' => $code, 'is_active' => true] + $row, $rules);

            $this->assertFalse($validator->fails(), "[{$code}]: ".implode(' ', $validator->errors()->all()));
        }
    }

    public function test_running_it_twice_keeps_what_was_changed_on_the_screen(): void
    {
        $this->seed(HardwareSupplierSeeder::class);

        Supplier::firstWhere('code', 'TOOLHUB')->update(['name' => 'ToolHub Cebu', 'is_active' => false]);

        $this->seed(HardwareSupplierSeeder::class);

        $this->assertSame(10, Supplier::count());
        $this->assertSame('ToolHub Cebu', Supplier::firstWhere('code', 'TOOLHUB')->name);
        $this->assertFalse(Supplier::firstWhere('code', 'TOOLHUB')->is_active);
    }
}
