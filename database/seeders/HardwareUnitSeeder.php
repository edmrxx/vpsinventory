<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Services\AuditService;
use Illuminate\Database\Seeder;

/**
 * The units a hardware and electrical supplies shop sells in.
 *
 * For GO Enterprises, run once after `data:reset`:
 *
 *     php artisan db:seed --class=HardwareUnitSeeder
 *
 * Not called from DatabaseSeeder: what a business counts in is its own
 * choice, and another business would want another list.
 *
 * Matched on the code, so running it twice adds nothing, and a unit renamed
 * or switched off on the screen is left as it is. The name carries a few
 * examples, because it is what shows in the unit picker on a product.
 *
 * The units in MEASURED are sold by measure, so they start with "Allows
 * decimals" on: 2.5 m of wire, 0.75 kg of nails. Everything else is counted
 * whole. A foot stays whole — wire is sold by the metre here — and the owner
 * can turn the switch on from the Units screen.
 */
class HardwareUnitSeeder extends Seeder
{
    /** code => name. Codes follow StoreUnitRequest: lower case, at most 20. */
    public const UNITS = [
        // Counted
        'pcs' => 'Pieces',
        'pair' => 'Pair (hinges, gloves)',
        'set' => 'Set (tools, fittings)',
        'doz' => 'Dozen',
        'unit' => 'Unit (pumps, panels, appliances)',
        'lot' => 'Lot',

        // Packed
        'box' => 'Box (screws, wire, tiles)',
        'pack' => 'Pack',
        'bag' => 'Bag (cement, adhesive)',
        'sack' => 'Sack',
        'bundle' => 'Bundle (rebar, conduit)',
        'roll' => 'Roll (wire, tape, mesh)',

        // Long and flat
        'length' => 'Length (pipe, rebar, conduit)',
        'sheet' => 'Sheet (plywood, GI, roofing)',
        'm' => 'Meter (wire, cable, chain)',
        'ft' => 'Foot',

        // Bulk
        'kg' => 'Kilogram (nails, tie wire)',
        'cu.m' => 'Cubic meter (sand, gravel)',
        'sq.m' => 'Square meter',

        // Liquids and paint
        'gal' => 'Gallon (paint)',
        'qt' => 'Quart (paint)',
        'ltr' => 'Liter',
        'pail' => 'Pail',
        'can' => 'Can',
        'tube' => 'Tube (sealant, epoxy)',
        'bottle' => 'Bottle',
    ];

    /** The units whose products may be sold in parts. */
    public const MEASURED = ['m', 'kg', 'cu.m', 'sq.m', 'ltr'];

    public function run(): void
    {
        // Set-up, not something a person did on a screen; the same as
        // MasterDataSeeder.
        app(AuditService::class)->withoutAuditing(function () {
            foreach (self::UNITS as $code => $name) {
                Unit::firstOrCreate(['code' => $code], [
                    'name' => $name,
                    'allows_decimals' => in_array($code, self::MEASURED, true),
                    'is_active' => true,
                ]);
            }
        });
    }
}
