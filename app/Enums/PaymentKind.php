<?php

namespace App\Enums;

/**
 * What kind of money a payment method is.
 *
 * Only one kind changes how the till behaves: cash is the one that gives
 * change. A customer handing over ₱2,000 for ₱1,850 gets ₱150 back from the
 * drawer; nobody gives change from GCash. The other kinds group the methods
 * on the dashboard and, later, on the reports.
 *
 * There is no credit kind, on purpose. A payment method is money that came
 * in, and Collections adds them up. What a customer has not paid yet goes on
 * their account instead: see `sales.on_account` and `CustomerCredit`.
 */
enum PaymentKind: string
{
    case Cash = 'cash';
    case EWallet = 'ewallet';
    case Bank = 'bank';
    case Card = 'card';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::EWallet => 'E-wallet',
            self::Bank => 'Bank transfer',
            self::Card => 'Card',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $kind) => ['value' => $kind->value, 'label' => $kind->label()],
            self::cases(),
        );
    }
}
