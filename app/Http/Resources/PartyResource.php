<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Models\Supplier;
use App\Support\CustomerCredit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier or a customer. They carry the same fields, so they share a
 * shape rather than having two that drift apart.
 *
 * @mixin Supplier|Customer
 */
class PartyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'phone' => $this->phone,
            'tin' => $this->tin,
            // Suppliers only. A VAT registration on a customer would mean
            // nothing, and this resource serves both.
            'is_vat_registered' => $this->when(
                $this->resource instanceof Supplier,
                fn () => $this->is_vat_registered,
            ),
            'address' => $this->address,
            'is_active' => $this->is_active,
            // Only customers carry these.
            'is_walk_in' => $this->is_walk_in ?? null,
            'credit_limit' => $this->when($this->resource instanceof Customer, fn () => $this->credit_limit),
            'payment_terms_days' => $this->when($this->resource instanceof Customer, fn () => $this->payment_terms_days),
            // Already on the row in a list, so a page asks once.
            'balance' => $this->when($this->resource instanceof Customer, fn () => CustomerCredit::figure(
                (int) ($this->balance_cents ?? CustomerCredit::owed((int) $this->id))
            )),
            'created_at' => $this->created_at?->toIso8601String(),
        ], fn ($value) => $value !== null);
    }
}
