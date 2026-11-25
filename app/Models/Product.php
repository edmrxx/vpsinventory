<?php

namespace App\Models;

use App\Casts\QuantityCast;
use App\Concerns\Auditable;
use App\Support\ProductPhoto;
use App\Support\Quantity;
use App\Support\Reservations;
use App\Support\UnitConversion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of the product master.
 *
 * `on_hand` is deliberately outside `$fillable`. Stock only ever moves
 * through a posted document, inside the posting service, so no form and no
 * API payload can reach it.
 */
class Product extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'sku', 'barcode', 'name', 'brand', 'model', 'description', 'photo_path',
        'category_id', 'unit_id', 'preferred_supplier_id',
        'cost', 'selling_price', 'reorder_level', 'is_active',
    ];

    /**
     * The columns a picker reads to search by brand, model or barcode
     * (phase 30). Every product list a form or the till offers selects them
     * and passes `searchWords()` along, so a scan finds the same product on
     * every screen.
     */
    public const SEARCH_COLUMNS = ['brand', 'model', 'barcode'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'reorder_level' => QuantityCast::class,
            'on_hand' => QuantityCast::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Where a browser loads the photo from, or null when there is none.
     *
     * The column holds a path on the `public` disk; the address is worked
     * out here so no screen and no payload has to know that.
     */
    public function photoUrl(): ?string
    {
        return ProductPhoto::url($this->photo_path);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The supplier this is usually bought from (phase 30). A suggestion:
     * a new purchase request fills it in, and deleting the supplier only
     * clears it.
     */
    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    /**
     * The brand, the model and the barcode as one string, for a picker to
     * search beside the label: typing "Boysen" or scanning 4800016… finds
     * the product although neither is in "SKU — name".
     */
    public function searchWords(): string
    {
        return trim(implode(' ', array_filter([$this->brand, $this->model, $this->barcode])));
    }

    /**
     * The other units this is bought and sold in: a box of 100, a roll of
     * 75 m. Smallest first, so a picker reads pcs, pack, box.
     */
    public function otherUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('factor')->orderBy('id');
    }

    /**
     * What it has at each location (phase 31.2). The rows add up to
     * `on_hand`; only the ledger writes either.
     */
    public function locationStocks(): HasMany
    {
        return $this->hasMany(LocationStock::class);
    }

    /** Every batch this product has ever taken in. */
    public function layers(): HasMany
    {
        return $this->hasMany(InventoryLayer::class);
    }

    /** The stock card: every time this product moved. */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Every purchase order line that orders this product. */
    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** Every purchase request line that asks for this product. */
    public function purchaseRequestLines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class);
    }

    /**
     * Active products, as the selling forms pick them: with the price a line
     * fills in, what is on hand, every unit the product is sold in, and —
     * only for somebody allowed to see a margin — the cost, for the
     * below-cost warning.
     *
     * Shared by the sale, quotation and sales order forms, so all three offer
     * exactly the same.
     *
     * `on_hand` here is what **may be sold** — on hand less what is reserved
     * for customer orders (phase 29) — because that is what the forms warn
     * against; `shelf` is what is physically there. `$exceptOrder` leaves
     * one order's own reservation free, for the sale that fulfils it.
     *
     * Given a location (phase 31.4) every figure is that location's: what is
     * there, what orders hold there, what may be sold there. Without one —
     * the quotation form, which moves nothing — they are the product's
     * totals.
     *
     * @return list<array<string, mixed>>
     */
    public static function sellingOptions(bool $withCost = false, ?int $exceptOrder = null, ?int $locationId = null): array
    {
        $held = Reservations::reserved(null, $exceptOrder, locationId: $locationId);
        // One grouped read of the location's figures, whatever the list holds.
        $there = $locationId ? Reservations::atLocation(null, $locationId) : null;
        $shelf = fn (self $p) => $there === null ? $p->on_hand : Quantity::fromMilli($there[$p->id] ?? 0);

        return static::query()
            ->with(UnitConversion::WITH)
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id', 'sku', 'name', 'cost', 'selling_price', 'on_hand', 'unit_id', ...self::SEARCH_COLUMNS])
            ->map(fn (self $p) => array_merge([
                'value' => $p->id,
                'label' => "{$p->sku} — {$p->name}",
                'keywords' => $p->searchWords(),
                'barcode' => $p->barcode,
                'sku' => $p->sku,
                'price' => $p->selling_price,
                'on_hand' => Reservations::available($shelf($p), $held[$p->id] ?? 0),
                'shelf' => $shelf($p),
                'reserved' => Quantity::fromMilli($held[$p->id] ?? 0),
                'unit' => $p->unit?->code,
                // Whether the quantity box takes 2.5 or whole numbers only.
                'decimals' => (bool) $p->unit?->allows_decimals,
                // Its own unit and any others it is sold in, each with its
                // price — and its cost only for somebody allowed to see it.
                'units' => UnitConversion::options($p, $withCost),
            ], $withCost ? ['cost' => $p->cost] : []))
            ->all();
    }

    /** Every sales order line that orders this product (phase 29). */
    public function salesOrderLines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    /** Every pending payment line the kiosk sent with this product (CR-2). */
    public function pendingPaymentLines(): HasMany
    {
        return $this->hasMany(PendingPaymentLine::class);
    }

    /** Every count sheet line that lists this product (phase 32). */
    public function stockCountLines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    /** Every loss report line that names this product (phase 33). */
    public function lossReportLines(): HasMany
    {
        return $this->hasMany(LossReportLine::class);
    }

    /** Every quotation line that offers this product (phase 28). */
    public function quotationLines(): HasMany
    {
        return $this->hasMany(QuotationLine::class);
    }

    /**
     * Active products, as the buying forms pick them: with the cost a line
     * fills in, and every unit the product is bought in.
     *
     * Shared by the purchase form and the purchase request form, so the two
     * cannot offer different products or price a box differently.
     *
     * @return list<array{value:int,label:string,keywords:string,barcode:?string,sku:string,preferred_supplier_id:?int,cost:string,unit:?string,decimals:bool,units:list<array<string,mixed>>}>
     */
    public static function buyingOptions(): array
    {
        return static::query()
            ->with(UnitConversion::WITH)
            ->where('is_active', true)
            ->orderBy('sku')
            ->get(['id', 'sku', 'name', 'cost', 'selling_price', 'unit_id', 'preferred_supplier_id', ...self::SEARCH_COLUMNS])
            ->map(fn (self $p) => [
                'value' => $p->id,
                'label' => "{$p->sku} — {$p->name}",
                'keywords' => $p->searchWords(),
                'barcode' => $p->barcode,
                'sku' => $p->sku,
                // The usual supplier, which a new purchase request fills in.
                'preferred_supplier_id' => $p->preferred_supplier_id,
                'cost' => $p->cost,
                'unit' => $p->unit?->code,
                // Whether the quantity box takes 2.5 or whole numbers only.
                'decimals' => (bool) $p->unit?->allows_decimals,
                // Its own unit and any others it is bought in, each with
                // the cost the line fills in: a box of 100 at 100 x cost.
                'units' => UnitConversion::options($p, withCost: true),
            ])
            ->all();
    }

    /**
     * Make the other units exactly the rows the product form sent.
     *
     * Matched by unit: a row that stays is updated in place, one that goes
     * is deleted, a new one is added. Documents already posted copied the
     * factor onto their own lines, so nothing here changes them.
     *
     * One trail row names the change, so a box price edited on the form
     * reads as an edit of this product.
     *
     * @param  list<array{unit_id:int|string, factor:int|float|string, selling_price?:int|float|string|null}>  $rows
     */
    public function syncOtherUnits(array $rows): void
    {
        $before = $this->otherUnitsForTrail();
        $existing = $this->otherUnits()->get()->keyBy('unit_id');
        $sent = [];

        foreach ($rows as $row) {
            $unitId = (int) $row['unit_id'];
            $sent[] = $unitId;

            $values = [
                'factor' => $row['factor'],
                'selling_price' => isset($row['selling_price']) && $row['selling_price'] !== ''
                    ? round((float) $row['selling_price'], 2)
                    : null,
            ];

            if ($other = $existing->get($unitId)) {
                $other->update($values);

                continue;
            }

            $this->otherUnits()->create(['unit_id' => $unitId, ...$values]);
        }

        $this->otherUnits()->whereNotIn('unit_id', $sent)->delete();

        $this->unsetRelation('otherUnits');
        $after = $this->otherUnitsForTrail();

        if ($before !== $after) {
            $this->auditRelationChange(['other units' => ['from' => $before, 'to' => $after]]);
        }
    }

    /**
     * The other units as a person reads them: "box = 100 pcs at 450.00".
     *
     * @return list<string>
     */
    protected function otherUnitsForTrail(): array
    {
        $own = $this->unit()->value('code');

        return $this->otherUnits()
            ->with('unit:id,code')
            ->get()
            ->map(fn (ProductUnit $other) => "{$other->unit?->code} = ".Quantity::format($other->factor)." {$own}"
                .($other->selling_price !== null ? ' at '.number_format((float) $other->selling_price, 2) : ''))
            ->all();
    }

    /** The SKU is what people quote, so the trail names the row by it. */
    public function auditLabel(): string
    {
        return (string) $this->sku;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Products at or below their reorder level.
     *
     * A reorder level of zero means the product is not watched, so it never
     * reaches the low-stock list.
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('reorder_level', '>', 0)
            ->whereColumn('on_hand', '<=', 'reorder_level');
    }

    /** True when this product is at or below its reorder level. */
    public function isLowStock(): bool
    {
        return $this->reorder_level > 0 && $this->on_hand <= $this->reorder_level;
    }
}
