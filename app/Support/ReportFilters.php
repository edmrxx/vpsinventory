<?php

namespace App\Support;

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * What the Reports screen is asked about (phase 34): a period, and
 * optionally one cashier and one payment method.
 *
 * Read once, here, for the screen and for the spreadsheet alike, so the
 * file always covers exactly what the screen showed — the same "never two
 * copies" rule as a document's `detail()`.
 *
 * Who may pick another cashier is decided here, on the server: somebody
 * without `dashboard.view_all_staff` sees everybody's figures together, as
 * Reports always showed them, or their own — never a colleague's alone.
 */
final class ReportFilters
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $staffId = null,
        public readonly ?int $paymentMethodId = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
        ]);

        $user = $request->user();
        $staff = isset($validated['staff_id']) ? (int) $validated['staff_id'] : null;

        if ($staff !== null && $staff !== $user->id && ! self::seesEveryCashier($user)) {
            throw ValidationException::withMessages([
                'staff_id' => 'You may narrow the report to your own documents only.',
            ]);
        }

        return new self(
            isset($validated['from']) ? Carbon::parse($validated['from']) : Carbon::today()->startOfYear(),
            isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today(),
            $staff,
            isset($validated['payment_method_id']) ? (int) $validated['payment_method_id'] : null,
        );
    }

    public static function seesEveryCashier(User $user): bool
    {
        return $user->can('dashboard.view_all_staff');
    }

    /** The array `InventoryMetrics` narrows by. */
    public function metrics(): array
    {
        return [
            'staff_id' => $this->staffId,
            'payment_method_id' => $this->paymentMethodId,
        ];
    }

    /**
     * Whether the purchase blocks belong in this report. A purchase records
     * no payment method, so narrowed to one they are left out rather than
     * shown unfiltered beside filtered sales.
     */
    public function coversPurchases(): bool
    {
        return $this->paymentMethodId === null;
    }

    /** The address's query, for the page and the spreadsheet link. */
    public function query(): array
    {
        return array_filter([
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'staff_id' => $this->staffId,
            'payment_method_id' => $this->paymentMethodId,
        ], fn ($value) => $value !== null);
    }

    public function periodLabel(): string
    {
        return $this->from->format('d M Y').' to '.$this->to->format('d M Y');
    }

    public function staffLabel(): string
    {
        if ($this->staffId === null) {
            return 'Everyone';
        }

        $user = User::query()->find($this->staffId, ['name', 'username']);

        return $user ? $user->name.' (@'.$user->username.')' : 'Unknown user';
    }

    public function paymentLabel(): string
    {
        if ($this->paymentMethodId === null) {
            return 'Any method';
        }

        return (string) (PaymentMethod::query()->whereKey($this->paymentMethodId)->value('name') ?? 'Unknown method');
    }

    /**
     * The people the Cashier filter offers.
     *
     * @return list<array{value:int,label:string}>
     */
    public static function staffOptions(User $user): array
    {
        if (! self::seesEveryCashier($user)) {
            return [['value' => $user->id, 'label' => $user->name.' (me)']];
        }

        return StaffOptions::all();
    }

    /**
     * Every payment method, switched-off ones too: they still have history.
     * Cash first, as the till shows it.
     *
     * @return list<array{value:int,label:string}>
     */
    public static function methodOptions(): array
    {
        return PaymentMethod::query()
            ->orderByRaw("CASE WHEN kind = 'cash' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])
            ->map(fn (PaymentMethod $method) => [
                'value' => $method->id,
                'label' => $method->name.($method->is_active ? '' : ' (switched off)'),
            ])
            ->all();
    }
}
