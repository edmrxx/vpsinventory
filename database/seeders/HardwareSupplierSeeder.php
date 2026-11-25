<?php

namespace Database\Seeders;

use App\Models\Supplier;
use App\Services\AuditService;
use Illuminate\Database\Seeder;

/**
 * SAMPLE suppliers for a hardware and electrical supplies shop, so a
 * delivery can be tried on the Purchases screen before the client enters
 * their real ones.
 *
 *     php artisan db:seed --class=HardwareSupplierSeeder
 *
 * Every business here is **invented**: the e-mail addresses end in `.test`,
 * the phone numbers are 0917 000 01xx placeholders and the TINs are
 * 000-000-0xx-00000, so none of them points at a real company.
 *
 * Six charge VAT and four do not, so both kinds of purchase can be tried:
 * a delivery from a VAT supplier splits out the input VAT, one from a
 * Non-VAT supplier does not (see the VAT bullet in CLAUDE.md).
 *
 * Matched on the code, so running it twice adds nothing, and a supplier
 * edited or switched off on the screen is left as it is.
 *
 * **These are not the client's suppliers.** `php artisan data:reset` takes
 * them away before go-live, with every purchase tried against them.
 */
class HardwareSupplierSeeder extends Seeder
{
    /**
     * Keyed by code. Codes follow StoreSupplierRequest: capitals, numbers,
     * dots, dashes and underscores.
     *
     * @var array<string, array<string, string|bool>>
     */
    public const SUPPLIERS = [
        'CEBU-CEMENT' => [
            'name' => 'Cebu Cement & Aggregates Trading',
            'contact_person' => 'Ramon Villanueva',
            'email' => 'orders@cebu-cement.test',
            'phone' => '0917 000 0101',
            'tin' => '000-000-001-00000',
            'is_vat_registered' => true,
            'address' => 'Mandaue City, Cebu',
            'notes' => 'Cement, hollow blocks, sand and gravel.',
        ],
        'VISAYAS-STEEL' => [
            'name' => 'Visayas Steel Supply Corp.',
            'contact_person' => 'Grace Uy',
            'email' => 'sales@visayas-steel.test',
            'phone' => '0917 000 0102',
            'tin' => '000-000-002-00000',
            'is_vat_registered' => true,
            'address' => 'Talisay City, Cebu',
            'notes' => 'Rebar, angle bars, GI sheets and tie wire.',
        ],
        'METRO-WIRE' => [
            'name' => 'Metro Wire & Cable Distributors',
            'contact_person' => 'Jerome Castillo',
            'email' => 'orders@metro-wire.test',
            'phone' => '0917 000 0103',
            'tin' => '000-000-003-00000',
            'is_vat_registered' => true,
            'address' => 'Valenzuela City, Metro Manila',
            'notes' => 'THHN wire, flat cord and conduit.',
        ],
        'BRIGHTLINE' => [
            'name' => 'Brightline Electrical Supply',
            'contact_person' => 'Anna Lim',
            'email' => 'hello@brightline-electrical.test',
            'phone' => '0917 000 0104',
            'tin' => '000-000-004-00000',
            'is_vat_registered' => true,
            'address' => 'Cebu City, Cebu',
            'notes' => 'Breakers, switches, outlets and LED lights.',
        ],
        'AQUAFLOW' => [
            'name' => 'AquaFlow Plumbing Supply',
            'contact_person' => 'Dennis Tan',
            'email' => 'sales@aquaflow-plumbing.test',
            'phone' => '0917 000 0105',
            'tin' => '000-000-005-00000',
            'is_vat_registered' => true,
            'address' => 'Lapu-Lapu City, Cebu',
            'notes' => 'PVC and PPR pipes, fittings, faucets and valves.',
        ],
        'COLORMAX' => [
            'name' => 'ColorMax Paints Trading',
            'contact_person' => 'Lorna Bautista',
            'email' => 'orders@colormax-paints.test',
            'phone' => '0917 000 0106',
            'tin' => '000-000-006-00000',
            'is_vat_registered' => true,
            'address' => 'Mandaue City, Cebu',
            'notes' => 'Latex and enamel paint, thinner and brushes.',
        ],
        'SANTOS-LUMBER' => [
            'name' => 'Santos Lumber & Plywood',
            'contact_person' => 'Eduardo Santos',
            'email' => 'santoslumber@mail.test',
            'phone' => '0917 000 0107',
            'tin' => '000-000-007-00000',
            'is_vat_registered' => false,
            'address' => 'Consolacion, Cebu',
            'notes' => 'Coco lumber, plywood and marine plywood.',
        ],
        'RJ-NAILS' => [
            'name' => 'RJ Nails & Fasteners',
            'contact_person' => 'Rodel Javier',
            'email' => 'rjnails@mail.test',
            'phone' => '0917 000 0108',
            'tin' => '000-000-008-00000',
            'is_vat_registered' => false,
            'address' => 'Liloan, Cebu',
            'notes' => 'Common nails, screws, bolts and anchors.',
        ],
        'TOOLHUB' => [
            'name' => 'ToolHub Hardware Trading',
            'contact_person' => 'Maricel Ong',
            'email' => 'toolhub@mail.test',
            'phone' => '0917 000 0109',
            'tin' => '000-000-009-00000',
            'is_vat_registered' => false,
            'address' => 'Cebu City, Cebu',
            'notes' => 'Hand tools, power tools and safety gear.',
        ],
        'DELACRUZ-GEN' => [
            'name' => 'Dela Cruz General Merchandise',
            'contact_person' => 'Jun Dela Cruz',
            'email' => 'delacruz.gm@mail.test',
            'phone' => '0917 000 0110',
            'tin' => '000-000-010-00000',
            'is_vat_registered' => false,
            'address' => 'Minglanilla, Cebu',
            'notes' => 'Small hardware, tapes, sealants and adhesives.',
        ],
    ];

    public function run(): void
    {
        // Set-up, not something a person did on a screen; the same as
        // HardwareUnitSeeder.
        app(AuditService::class)->withoutAuditing(function () {
            foreach (self::SUPPLIERS as $code => $row) {
                Supplier::firstOrCreate(['code' => $code], $row + ['is_active' => true]);
            }
        });
    }
}
