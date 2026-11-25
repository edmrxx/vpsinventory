<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18.1: the payment terms are agreed on each sale.
 *
 * `sales.terms_days` is how many days the customer was given to pay this
 * sale, beside the `due_date` it gave. Null on a sale with nothing on
 * account. The customer's own `payment_terms_days` stays, as the usual
 * terms each sale starts on.
 *
 * A sale already on account gets the days between its date and the due
 * date it was stamped with, so it reads exactly as it was agreed. Its due
 * date does not move.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedSmallInteger('terms_days')->nullable()->after('due_date');
        });

        DB::table('sales')
            ->whereNotNull('due_date')
            ->orderBy('id')
            ->select(['id', 'sale_date', 'due_date'])
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $days = (int) Carbon::parse($row->sale_date)->startOfDay()
                        ->diffInDays(Carbon::parse($row->due_date)->startOfDay(), false);

                    DB::table('sales')->where('id', $row->id)->update(['terms_days' => max($days, 0)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('terms_days');
        });
    }
};
