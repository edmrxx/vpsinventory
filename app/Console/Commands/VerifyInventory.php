<?php

namespace App\Console\Commands;

use App\Services\InventoryLedger;
use App\Support\CustomerCredit;
use App\Support\OrderReceiving;
use App\Support\SupplierAccount;
use App\Support\Quantity;
use Illuminate\Console\Command;

/**
 * Check the one rule the whole ledger rests on.
 *
 * What a product says it holds must equal what its batches add up to. If the
 * two ever drift apart, every valuation and every cost of sale after that
 * point is wrong, so this is worth running after a restore, a manual edit of
 * the database, or any upgrade that touched stock.
 *
 * It checks customer balances the same way (phase 19): what each sale still
 * owes must equal what went on account, less what returns took off it, less
 * what payments that still stand put towards it. A customer's balance is
 * the sum of those, so if one is wrong, every statement and the aging
 * report are wrong with it.
 */
class VerifyInventory extends Command
{
    protected $signature = 'inventory:verify';

    protected $description = 'Check that every product\'s stock matches the batches behind it at every location, every sale\'s balance matches its payments, and every order line matches its deliveries';

    public function handle(InventoryLedger $ledger): int
    {
        $stock = $this->checkStock($ledger);
        $locations = $this->checkLocations($ledger);
        $balances = $this->checkBalances();
        $orders = $this->checkOrders();
        $payables = $this->checkPayables();

        return $stock && $locations && $balances && $orders && $payables ? self::SUCCESS : self::FAILURE;
    }

    /** Each location matches its batches, and a product's locations add up to its total (phase 31.2). */
    protected function checkLocations(InventoryLedger $ledger): bool
    {
        $wrong = $ledger->reconcileLocations();

        if ($wrong === []) {
            $this->components->info('Every location matches the batches sitting there, and adds up to each product\'s total.');

            return true;
        }

        $this->components->error(count($wrong).' location figure(s) disagree with the batches behind them.');

        $this->table(
            ['SKU', 'Location', 'Recorded', 'Should be', 'Checked'],
            array_map(fn (array $row) => [$row['sku'], $row['location'], $row['recorded'], $row['expected'], $row['what']], $wrong)
        );

        $this->components->warn(
            'Stock only moves through a posted document. A difference here means '
                .'something wrote to the table directly.'
        );

        return false;
    }

    protected function checkStock(InventoryLedger $ledger): bool
    {
        $wrong = $ledger->reconcile();

        if ($wrong === []) {
            $this->components->info('Every product matches the batches behind it.');

            return true;
        }

        $this->components->error(
            count($wrong).' product(s) have come apart from their batches.'
        );

        $this->table(
            ['SKU', 'On hand', 'In batches', 'Difference'],
            array_map(fn (array $row) => [
                $row['sku'],
                $row['on_hand'],
                $row['layers'],
                Quantity::format(Quantity::diff($row['on_hand'], $row['layers'])),
            ], $wrong)
        );

        $this->components->warn(
            'Stock only moves through a posted document. A difference here means '
                .'something wrote to the table directly.'
        );

        return false;
    }

    /** Every bill's balance matches what went on account, its returns and its payments (phase 24). */
    protected function checkPayables(): bool
    {
        $wrong = SupplierAccount::reconcile();

        if ($wrong === []) {
            $this->components->info('Every bill\'s balance matches what went on account, its returns and its supplier payments.');

            return true;
        }

        $this->components->error(count($wrong).' bill(s) owe a figure their documents do not add up to.');

        $this->table(
            ['Bill', 'Balance due', 'Should be'],
            array_map(fn (array $row) => [$row['reference'], $row['balance_due'], $row['expected']], $wrong)
        );

        $this->components->warn(
            'A bill\'s balance only changes through a delivery, a purchase return, a void or a supplier payment. '
                .'A difference here means something wrote to the table directly.'
        );

        return false;
    }

    /** Every order line has received exactly what its posted deliveries brought (phase 23). */
    protected function checkOrders(): bool
    {
        $wrong = OrderReceiving::reconcile();

        if ($wrong === []) {
            $this->components->info('Every purchase order line matches the deliveries posted against it.');

            return true;
        }

        $this->components->error(count($wrong).' purchase order line(s) disagree with their deliveries.');

        $this->table(
            ['Order', 'SKU', 'Received', 'Delivered'],
            array_map(fn (array $row) => [$row['order'], $row['sku'], $row['received'], $row['delivered']], $wrong)
        );

        $this->components->warn(
            'What an order received only changes when a delivery against it is posted or voided. '
                .'A difference here means something wrote to the table directly.'
        );

        return false;
    }

    protected function checkBalances(): bool
    {
        $wrong = CustomerCredit::reconcile();

        if ($wrong === []) {
            $this->components->info('Every sale\'s balance matches what went on account, its returns and its payments.');

            return true;
        }

        $this->components->error(count($wrong).' sale(s) owe a figure their documents do not add up to.');

        $this->table(
            ['Sale', 'Balance due', 'Should be'],
            array_map(fn (array $row) => [$row['reference'], $row['balance_due'], $row['expected']], $wrong)
        );

        $this->components->warn(
            'A balance only changes through a sale, a return, a void or a customer payment. '
                .'A difference here means something wrote to the table directly.'
        );

        return false;
    }
}
