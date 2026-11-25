<?php

namespace Database\Seeders;

use App\Enums\LocationType;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Unit;
use App\Services\AuditService;
use Illuminate\Database\Seeder;

class MasterDataSeeder extends Seeder
{
    /**
     * The master rows the app cannot start without.
     *
     * Units, because a product must be counted in something, and the walk-in
     * customer, because a counter sale must still name a buyer.
     *
     * Every row is matched on its code, so running the seeder twice changes
     * nothing. Categories are left out on purpose: what a shop sells differs
     * from one business to the next, and an empty list is the honest start.
     */
    public function run(): void
    {
        app(AuditService::class)->withoutAuditing(fn () => $this->seed());
    }

    protected function seed(): void
    {
        $units = [
            'pcs' => 'Pieces',
            'box' => 'Box',
            'pack' => 'Pack',
            'set' => 'Set',
            'kg' => 'Kilogram',
            'm' => 'Metre',
        ];

        foreach ($units as $code => $name) {
            Unit::firstOrCreate(['code' => $code], [
                'name' => $name,
                // Sold by measure, so 2.5 m and 0.75 kg may be typed.
                'allows_decimals' => in_array($code, HardwareUnitSeeder::MEASURED, true),
                'is_active' => true,
            ]);
        }

        // The flag is what the app looks for, never the name, so an admin may
        // rename this row to suit the business.
        static::walkInCustomer();
    }

    /**
     * The customer every counter sale is recorded against, made once.
     *
     * Public because `data:reset` puts it back without the units. Written
     * with forceCreate: `is_walk_in` is not fillable, so a plain create
     * outside db:seed would quietly drop the flag.
     */
    /**
     * The one location every document posts at, when none is marked
     * (phase 31.2). Put back by `data:reset`, as the walk-in customer is: the
     * ledger cannot post without it. The client renames it to the real store.
     */
    public static function defaultLocation(): void
    {
        if (! Location::where('is_default', true)->exists()) {
            Location::forceCreate([
                'name' => 'Main Store',
                'type' => LocationType::Store->value,
                'description' => 'Where goods are sold over the counter.',
                'is_default' => true,
                'is_active' => true,
            ]);
        }
    }

    public static function walkInCustomer(): void
    {
        if (! Customer::where('is_walk_in', true)->exists()) {
            Customer::forceCreate([
                'code' => 'WALK-IN',
                'name' => 'Walk-in Customer',
                'is_walk_in' => true,
                'is_active' => true,
            ]);
        }
    }
}
