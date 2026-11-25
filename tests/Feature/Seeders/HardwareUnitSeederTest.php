<?php

namespace Tests\Feature\Seeders;

use App\Http\Requests\Unit\StoreUnitRequest;
use App\Models\Unit;
use Database\Seeders\HardwareUnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class HardwareUnitSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_every_unit_switched_on(): void
    {
        $this->seed(HardwareUnitSeeder::class);

        $this->assertSame(count(HardwareUnitSeeder::UNITS), Unit::active()->count());
        $this->assertSame('Bag (cement, adhesive)', Unit::firstWhere('code', 'bag')->name);
        $this->assertDatabaseCount('audit_trails', 0);
    }

    public function test_every_unit_would_pass_the_form_on_the_units_screen(): void
    {
        // Otherwise a seeded unit could never be saved again from its own
        // edit dialog.
        $rules = (new StoreUnitRequest)->rules();

        foreach (HardwareUnitSeeder::UNITS as $code => $name) {
            $this->assertSame(strtolower($code), $code, "[{$code}] must be lower case, as the form saves it.");

            $validator = Validator::make([
                'code' => $code,
                'name' => $name,
                'allows_decimals' => in_array($code, HardwareUnitSeeder::MEASURED, true),
                'is_active' => true,
            ], $rules);

            $this->assertFalse($validator->fails(), "[{$code}]: ".implode(' ', $validator->errors()->all()));
        }
    }

    public function test_only_the_units_sold_by_measure_allow_decimals(): void
    {
        $this->seed(HardwareUnitSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['m', 'kg', 'cu.m', 'sq.m', 'ltr'],
            Unit::where('allows_decimals', true)->pluck('code')->all(),
        );
        $this->assertFalse(Unit::firstWhere('code', 'pcs')->allows_decimals);
        $this->assertFalse(Unit::firstWhere('code', 'ft')->allows_decimals);

        foreach (HardwareUnitSeeder::MEASURED as $code) {
            $this->assertArrayHasKey($code, HardwareUnitSeeder::UNITS);
        }
    }

    public function test_running_it_twice_keeps_what_was_changed_on_the_screen(): void
    {
        $this->seed(HardwareUnitSeeder::class);

        Unit::firstWhere('code', 'sack')->update(['name' => 'Sako', 'is_active' => false]);

        $this->seed(HardwareUnitSeeder::class);

        $this->assertSame(count(HardwareUnitSeeder::UNITS), Unit::count());
        $this->assertSame('Sako', Unit::firstWhere('code', 'sack')->name);
        $this->assertFalse(Unit::firstWhere('code', 'sack')->is_active);
    }
}
