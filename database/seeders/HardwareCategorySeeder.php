<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Services\AuditService;
use Illuminate\Database\Seeder;

/**
 * The categories of a hardware and electrical supplies shop.
 *
 * For GO Enterprises, run once after `data:reset`, with the units:
 *
 *     php artisan db:seed --class=HardwareCategorySeeder
 *
 * Not called from DatabaseSeeder, for the same reason as the units: another
 * business sells other things.
 *
 * Matched on the name, so running it twice adds nothing, and a description
 * edited or a category switched off on the screen stays as it was. The
 * description lists what belongs inside, so whoever adds a product can tell
 * "Plumbing" from "Plumbing Fixtures" at a glance.
 */
class HardwareCategorySeeder extends Seeder
{
    /** name => description. Limits follow StoreCategoryRequest: 120 and 255. */
    public const CATEGORIES = [
        // Building materials
        'Cement & Aggregates' => 'Portland cement, sand, gravel, hollow blocks, lime and ready-mix mortar.',
        'Steel & Rebar' => 'Deformed bars, angle bars, flat bars, purlins, tie wire and welded mesh.',
        'Lumber & Plywood' => 'Coco and good lumber, plywood, marine plywood, ceiling and gypsum boards.',
        'Roofing & Gutters' => 'GI and pre-painted roofing sheets, ridge rolls, gutters, flashing and roofing screws.',
        'Tiles & Flooring' => 'Floor and wall tiles, grout, tile trims, tile adhesive and vinyl flooring.',

        // Hardware
        'Nails, Screws & Fasteners' => 'Common and concrete nails, screws, bolts, nuts, washers, anchors and rivets.',
        'Door & Cabinet Hardware' => 'Locksets, door knobs, hinges, handles, padlocks, door closers and drawer slides.',
        'Adhesives & Sealants' => 'Epoxy, contact cement, wood glue, silicone sealant and waterproofing.',
        'Hand Tools' => 'Hammers, pliers, screwdrivers, wrenches, saws, trowels, levels and measuring tapes.',
        'Power Tools & Accessories' => 'Drills, grinders, cutters, jigsaws, drill bits, cutting and grinding discs.',
        'Safety & PPE' => 'Gloves, goggles, hard hats, face masks, safety shoes and harnesses.',
        'Garden & Outdoor' => 'Garden hoses, shovels, rakes, wheelbarrows, sprayers and pots.',

        // Paint
        'Paints & Coatings' => 'Latex and enamel paints, primers, varnish, wood stain, thinner and tints.',
        'Painting Supplies' => 'Paint brushes, rollers, trays, masking tape, sandpaper and putty.',

        // Plumbing
        'Plumbing' => 'PVC and PPR pipes, fittings, elbows, tees, valves, ball valves and teflon tape.',
        'Plumbing Fixtures' => 'Faucets, showers, toilets, lavatories, kitchen sinks and bidets.',
        'Water Pumps & Tanks' => 'Water pumps, pressure tanks, water tanks and water heaters.',

        // Electrical
        'Wires & Cables' => 'THHN and PDX wire, flat and royal cord, coaxial and UTP cable.',
        'Conduits & Boxes' => 'PVC and EMT conduit, fittings, utility and junction boxes, wire moulding.',
        'Switches & Outlets' => 'Wall switches, convenience outlets, plugs, wall plates and wiring devices.',
        'Circuit Protection' => 'Circuit breakers, panel boards, safety switches, fuses and surge protectors.',
        'Lighting' => 'LED bulbs and tubes, downlights, floodlights, lamp holders and light fixtures.',
        'Fans & Ventilation' => 'Exhaust fans, ceiling fans, wall fans and ventilation fittings.',
        'Electrical Accessories' => 'Electrical tape, wire connectors, cable ties, terminal lugs and extension cords.',

        // Catch-all
        'Miscellaneous' => 'Anything that fits no other category.',
    ];

    public function run(): void
    {
        app(AuditService::class)->withoutAuditing(function () {
            foreach (self::CATEGORIES as $name => $description) {
                Category::firstOrCreate(['name' => $name], ['description' => $description, 'is_active' => true]);
            }
        });
    }
}
