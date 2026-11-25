<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Services\AuditService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * A starter catalogue for a hardware and electrical supplies shop.
 *
 * For GO Enterprises, after the units and categories:
 *
 *     php artisan db:seed --class=HardwareProductSeeder
 *
 * It calls the unit and category seeders first, so it also works alone.
 *
 * **The prices are estimates**, typical Philippine retail figures written
 * down so the screens have something sensible in them. The owner must check
 * every cost and selling price before the first sale. No brand is named: the
 * shop carries whichever brands it carries.
 *
 * **No stock is added.** `on_hand` stays 0, because stock only ever arrives
 * through a posted document — the opening stock goes in through Adjustments,
 * with the kind set to Opening stock.
 *
 * Matched on the SKU, so running it twice adds nothing, and a price or name
 * changed on the screen is left as it is.
 */
class HardwareProductSeeder extends Seeder
{
    /**
     * SKU => [name, category, unit code, cost, selling price, reorder level].
     *
     * SKUs follow StoreProductRequest: upper case, letters, numbers, dots,
     * dashes and underscores. The prefix names the category.
     */
    public const PRODUCTS = [
        // Cement & Aggregates
        'CEM-PORT-40' => ['Portland Cement 40 kg', 'Cement & Aggregates', 'bag', 235, 265, 50],
        'CEM-SAND' => ['Washed Sand', 'Cement & Aggregates', 'cu.m', 1100, 1400, 5],
        'CEM-GRVL-34' => ['Gravel 3/4"', 'Cement & Aggregates', 'cu.m', 1300, 1650, 5],
        'CEM-CHB-4' => ['Concrete Hollow Block 4"', 'Cement & Aggregates', 'pcs', 12, 16, 500],
        'CEM-CHB-6' => ['Concrete Hollow Block 6"', 'Cement & Aggregates', 'pcs', 17, 22, 300],

        // Steel & Rebar
        'STL-RB-10' => ['Deformed Bar 10 mm x 6 m', 'Steel & Rebar', 'length', 165, 205, 100],
        'STL-RB-12' => ['Deformed Bar 12 mm x 6 m', 'Steel & Rebar', 'length', 240, 295, 100],
        'STL-RB-16' => ['Deformed Bar 16 mm x 6 m', 'Steel & Rebar', 'length', 425, 520, 50],
        'STL-ANG-112' => ['Angle Bar 1-1/2" x 1/4" x 6 m', 'Steel & Rebar', 'length', 680, 820, 10],
        'STL-TW-16' => ['Tie Wire #16', 'Steel & Rebar', 'kg', 75, 95, 25],

        // Lumber & Plywood
        'LUM-PLY-14' => ['Ordinary Plywood 1/4" 4 x 8 ft', 'Lumber & Plywood', 'sheet', 380, 470, 20],
        'LUM-PLY-12' => ['Ordinary Plywood 1/2" 4 x 8 ft', 'Lumber & Plywood', 'sheet', 690, 840, 15],
        'LUM-MPLY-34' => ['Marine Plywood 3/4" 4 x 8 ft', 'Lumber & Plywood', 'sheet', 1450, 1750, 10],
        'LUM-COCO-2X2' => ['Coco Lumber 2 x 2 x 10 ft', 'Lumber & Plywood', 'pcs', 60, 80, 50],
        'LUM-GYP-12' => ['Gypsum Board 1/2" 4 x 8 ft', 'Lumber & Plywood', 'sheet', 420, 520, 20],

        // Roofing & Gutters
        'ROF-GI-26-8' => ['GI Corrugated Sheet Ga. 26 x 8 ft', 'Roofing & Gutters', 'sheet', 320, 395, 30],
        'ROF-PP-26-10' => ['Pre-painted Rib-Type Roofing Ga. 26 x 10 ft', 'Roofing & Gutters', 'sheet', 560, 680, 20],
        'ROF-RIDGE-8' => ['Ridge Roll Ga. 26 x 8 ft', 'Roofing & Gutters', 'length', 240, 300, 10],
        'ROF-TEK-212' => ['Tekscrew with Washer 2-1/2"', 'Roofing & Gutters', 'pcs', 3, 5, 500],

        // Tiles & Flooring
        'TIL-FLR-60' => ['Floor Tile 60 x 60 cm Glazed (4 pcs)', 'Tiles & Flooring', 'box', 520, 640, 20],
        'TIL-WLL-30' => ['Wall Tile 30 x 30 cm (11 pcs)', 'Tiles & Flooring', 'box', 300, 380, 20],
        'TIL-ADH-25' => ['Tile Adhesive 25 kg', 'Tiles & Flooring', 'bag', 280, 345, 20],
        'TIL-GRT-2' => ['Tile Grout 2 kg', 'Tiles & Flooring', 'pack', 65, 85, 20],

        // Nails, Screws & Fasteners
        'NAI-CMN-3' => ['Common Wire Nail 3"', 'Nails, Screws & Fasteners', 'kg', 75, 95, 25],
        'NAI-CMN-4' => ['Common Wire Nail 4"', 'Nails, Screws & Fasteners', 'kg', 75, 95, 25],
        'NAI-CON-2' => ['Concrete Nail 2"', 'Nails, Screws & Fasteners', 'kg', 110, 140, 10],
        'NAI-GYP-1' => ['Gypsum Screw 1" (100 pcs)', 'Nails, Screws & Fasteners', 'pack', 45, 60, 20],
        'NAI-ANC-38' => ['Expansion Anchor Bolt 3/8"', 'Nails, Screws & Fasteners', 'pcs', 12, 18, 100],

        // Door & Cabinet Hardware
        'DOR-LCK-KNOB' => ['Door Knob Lockset, Stainless', 'Door & Cabinet Hardware', 'set', 280, 380, 10],
        'DOR-HNG-4' => ['Butt Hinge 4" x 4", Stainless', 'Door & Cabinet Hardware', 'pair', 95, 135, 20],
        'DOR-PAD-50' => ['Padlock 50 mm', 'Door & Cabinet Hardware', 'pcs', 180, 245, 10],
        'DOR-HDL-96' => ['Cabinet Pull Handle 96 mm', 'Door & Cabinet Hardware', 'pcs', 35, 55, 30],

        // Adhesives & Sealants
        'ADH-EPX-SET' => ['Epoxy Adhesive A + B', 'Adhesives & Sealants', 'set', 140, 185, 10],
        'ADH-CTC-1L' => ['Contact Cement 1 L', 'Adhesives & Sealants', 'can', 230, 290, 10],
        'ADH-SIL-CLR' => ['Silicone Sealant Clear 300 ml', 'Adhesives & Sealants', 'tube', 120, 160, 20],
        'ADH-WPF-1G' => ['Waterproofing Coating', 'Adhesives & Sealants', 'gal', 550, 690, 5],

        // Hand Tools
        'HTL-HAM-16' => ['Claw Hammer 16 oz', 'Hand Tools', 'pcs', 180, 250, 5],
        'HTL-TAPE-5' => ['Measuring Tape 5 m', 'Hand Tools', 'pcs', 95, 140, 10],
        'HTL-PLR-8' => ['Combination Pliers 8"', 'Hand Tools', 'pcs', 150, 210, 5],
        'HTL-SDR-6' => ['Screwdriver Set (6 pcs)', 'Hand Tools', 'set', 190, 260, 5],
        'HTL-TRWL' => ['Concrete Trowel', 'Hand Tools', 'pcs', 85, 120, 5],

        // Power Tools & Accessories
        'PTL-DRL-13' => ['Electric Drill 13 mm', 'Power Tools & Accessories', 'unit', 1650, 2150, 2],
        'PTL-GRD-4' => ['Angle Grinder 4"', 'Power Tools & Accessories', 'unit', 1450, 1890, 2],
        'PTL-CDS-4' => ['Cutting Disc 4" (Metal)', 'Power Tools & Accessories', 'pcs', 25, 38, 50],
        'PTL-DBIT-38' => ['Masonry Drill Bit 3/8"', 'Power Tools & Accessories', 'pcs', 55, 80, 20],

        // Safety & PPE
        'PPE-GLV-CTN' => ['Cotton Work Gloves', 'Safety & PPE', 'pair', 18, 28, 50],
        'PPE-HAT-WHT' => ['Hard Hat (White)', 'Safety & PPE', 'pcs', 140, 195, 5],
        'PPE-GOGGLE' => ['Safety Goggles', 'Safety & PPE', 'pcs', 65, 95, 10],
        'PPE-MSK-N95' => ['N95 Face Mask', 'Safety & PPE', 'pcs', 25, 40, 50],

        // Garden & Outdoor
        'GRD-HOSE-12' => ['Garden Hose 1/2" (per meter)', 'Garden & Outdoor', 'm', 18, 28, 50],
        'GRD-SHV-PT' => ['Shovel, Pointed', 'Garden & Outdoor', 'pcs', 260, 350, 5],
        'GRD-WBRW' => ['Wheelbarrow', 'Garden & Outdoor', 'unit', 1900, 2450, 2],

        // Paints & Coatings
        'PNT-LTX-WHT-G' => ['Latex Paint White', 'Paints & Coatings', 'gal', 520, 650, 10],
        'PNT-QDE-WHT-G' => ['Quick-Dry Enamel White', 'Paints & Coatings', 'gal', 640, 790, 10],
        'PNT-QDE-BLK-Q' => ['Quick-Dry Enamel Black', 'Paints & Coatings', 'qt', 210, 265, 10],
        'PNT-PRM-RED-G' => ['Red Oxide Primer', 'Paints & Coatings', 'gal', 470, 590, 5],
        'PNT-THN-G' => ['Paint Thinner', 'Paints & Coatings', 'gal', 260, 330, 5],

        // Painting Supplies
        'PSU-BRS-2' => ['Paint Brush 2"', 'Painting Supplies', 'pcs', 35, 50, 20],
        'PSU-ROL-7' => ['Paint Roller 7"', 'Painting Supplies', 'pcs', 120, 165, 10],
        'PSU-MSK-1' => ['Masking Tape 1"', 'Painting Supplies', 'roll', 35, 50, 20],
        'PSU-SND-100' => ['Sandpaper #100', 'Painting Supplies', 'pcs', 10, 15, 100],
        'PSU-PTY-1L' => ['Masonry Putty 1 L', 'Painting Supplies', 'can', 160, 210, 5],

        // Plumbing
        'PLB-PVC-12' => ['PVC Pressure Pipe 1/2" x 3 m (Blue)', 'Plumbing', 'length', 95, 125, 20],
        'PLB-PVC-4' => ['PVC Sanitary Pipe 4" x 3 m (Orange)', 'Plumbing', 'length', 380, 470, 10],
        'PLB-ELB-12' => ['PVC Elbow 1/2"', 'Plumbing', 'pcs', 8, 12, 100],
        'PLB-TEE-12' => ['PVC Tee 1/2"', 'Plumbing', 'pcs', 10, 15, 100],
        'PLB-BV-12' => ['Ball Valve 1/2", Brass', 'Plumbing', 'pcs', 145, 195, 10],
        'PLB-TEFLON' => ['Teflon Tape', 'Plumbing', 'roll', 12, 20, 50],

        // Plumbing Fixtures
        'PFX-FCT-SS' => ['Faucet, Stainless', 'Plumbing Fixtures', 'pcs', 260, 350, 5],
        'PFX-WC-2PC' => ['Water Closet, Two-Piece', 'Plumbing Fixtures', 'set', 3200, 4100, 2],
        'PFX-LAV-WH' => ['Lavatory, Wall-Hung', 'Plumbing Fixtures', 'set', 1500, 1950, 2],
        'PFX-SHW-SET' => ['Shower Head with Arm', 'Plumbing Fixtures', 'set', 320, 450, 5],

        // Water Pumps & Tanks
        'PMP-JET-1HP' => ['Jet Pump 1 HP', 'Water Pumps & Tanks', 'unit', 5800, 7200, 1],
        'PMP-PT-24L' => ['Pressure Tank 24 L', 'Water Pumps & Tanks', 'unit', 2300, 2900, 1],
        'PMP-TANK-500' => ['Water Tank 500 L, Plastic', 'Water Pumps & Tanks', 'unit', 4200, 5200, 1],

        // Wires & Cables
        'WIR-THHN-35' => ['THHN Stranded Wire 3.5 mm² (150 m)', 'Wires & Cables', 'box', 2850, 3450, 3],
        'WIR-THHN-55' => ['THHN Stranded Wire 5.5 mm² (150 m)', 'Wires & Cables', 'box', 4500, 5400, 2],
        'WIR-THHN-20-M' => ['THHN Wire 2.0 mm² (per meter)', 'Wires & Cables', 'm', 18, 25, 100],
        'WIR-PDX-14-M' => ['PDX Wire #14 (per meter)', 'Wires & Cables', 'm', 22, 30, 100],
        'WIR-FC-18-M' => ['Flat Cord #18 (per meter)', 'Wires & Cables', 'm', 12, 18, 100],
        'WIR-UTP-C6-M' => ['UTP Cable Cat 6 (per meter)', 'Wires & Cables', 'm', 15, 25, 100],

        // Conduits & Boxes
        'CON-PVC-12' => ['PVC Conduit 1/2" x 3 m', 'Conduits & Boxes', 'length', 45, 60, 50],
        'CON-PVC-34' => ['PVC Conduit 3/4" x 3 m', 'Conduits & Boxes', 'length', 65, 85, 30],
        'CON-UBX' => ['Utility Box, PVC', 'Conduits & Boxes', 'pcs', 12, 18, 100],
        'CON-JBX' => ['Junction Box, PVC', 'Conduits & Boxes', 'pcs', 14, 20, 100],
        'CON-MLD-34' => ['Wire Moulding 3/4" x 8 ft', 'Conduits & Boxes', 'length', 55, 75, 30],

        // Switches & Outlets
        'SWO-SW-1G' => ['Wall Switch, 1-Gang', 'Switches & Outlets', 'pcs', 55, 80, 20],
        'SWO-SW-2G' => ['Wall Switch, 2-Gang', 'Switches & Outlets', 'pcs', 85, 120, 20],
        'SWO-OUT-DPX' => ['Duplex Convenience Outlet', 'Switches & Outlets', 'pcs', 65, 95, 20],
        'SWO-PLT-2G' => ['Wall Plate, 2-Gang', 'Switches & Outlets', 'pcs', 25, 38, 30],
        'SWO-PLUG-HD' => ['Male Plug, Heavy Duty', 'Switches & Outlets', 'pcs', 25, 40, 30],

        // Circuit Protection
        'CBR-PB-20A' => ['Plug-in Circuit Breaker 20 A', 'Circuit Protection', 'pcs', 260, 340, 10],
        'CBR-PB-30A' => ['Plug-in Circuit Breaker 30 A', 'Circuit Protection', 'pcs', 270, 350, 10],
        'CBR-PNL-4' => ['Panel Board, 4 Branches', 'Circuit Protection', 'unit', 650, 850, 2],
        'CBR-SS-30A' => ['Safety Switch 30 A', 'Circuit Protection', 'unit', 480, 620, 2],

        // Lighting
        'LGT-LED-9W' => ['LED Bulb 9 W, Daylight', 'Lighting', 'pcs', 55, 85, 30],
        'LGT-LED-12W' => ['LED Bulb 12 W, Daylight', 'Lighting', 'pcs', 75, 110, 30],
        'LGT-T8-18W' => ['LED Tube T8 18 W', 'Lighting', 'pcs', 120, 170, 20],
        'LGT-DL-6' => ['LED Downlight 6"', 'Lighting', 'pcs', 180, 250, 10],
        'LGT-FL-50W' => ['LED Floodlight 50 W', 'Lighting', 'pcs', 480, 650, 5],
        'LGT-RCPT' => ['Lamp Holder Receptacle', 'Lighting', 'pcs', 18, 28, 30],

        // Fans & Ventilation
        'FAN-EXH-8' => ['Exhaust Fan 8"', 'Fans & Ventilation', 'unit', 650, 850, 2],
        'FAN-CLG-56' => ['Ceiling Fan 56"', 'Fans & Ventilation', 'unit', 1900, 2450, 1],
        'FAN-WALL-16' => ['Wall Fan 16"', 'Fans & Ventilation', 'unit', 1100, 1450, 1],

        // Electrical Accessories
        'EAC-TAPE-BLK' => ['Electrical Tape, Black', 'Electrical Accessories', 'roll', 18, 28, 50],
        'EAC-WNUT' => ['Wire Nut Connector', 'Electrical Accessories', 'pcs', 3, 5, 200],
        'EAC-CTIE-8' => ['Cable Tie 8" (100 pcs)', 'Electrical Accessories', 'pack', 45, 65, 20],
        'EAC-EXT-4G' => ['Extension Cord, 4-Gang 3 m', 'Electrical Accessories', 'pcs', 280, 380, 5],
    ];

    public function run(): void
    {
        $this->call([HardwareUnitSeeder::class, HardwareCategorySeeder::class]);

        $categories = Category::pluck('id', 'name');
        $units = Unit::pluck('id', 'code');

        app(AuditService::class)->withoutAuditing(function () use ($categories, $units) {
            foreach (self::PRODUCTS as $sku => [$name, $category, $unit, $cost, $price, $reorder]) {
                Product::firstOrCreate(['sku' => $sku], [
                    'name' => $name,
                    'category_id' => $categories[$category] ?? throw new RuntimeException("No category [{$category}] for {$sku}."),
                    'unit_id' => $units[$unit] ?? throw new RuntimeException("No unit [{$unit}] for {$sku}."),
                    'cost' => $cost,
                    'selling_price' => $price,
                    'reorder_level' => $reorder,
                    'is_active' => true,
                ]);
            }
        });
    }
}
