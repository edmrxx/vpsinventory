<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sale put to one side at the till, to be finished later.
 *
 * A customer who goes back for one more bag of cement should not hold up
 * the queue, and should not lose what was already rung up. The cashier
 * holds the counter, serves the next person, and recalls it when they are
 * back.
 *
 * A hold is **not a document**. It takes no reference number, moves no
 * stock and reserves nothing: it is a basket parked on the server. When it
 * is recalled it goes back onto the counter and is sold through
 * `POST /sales` like any other, which weighs every line against the shelf
 * again. That is why it lives in its own table and not as a `held` status
 * on `sales` — a held row there would show up in the Sales list, the
 * day's count and the reports, and the void and return rules would have to
 * learn to step round it.
 *
 * `lines` is JSON rather than a child table on purpose: a hold names a
 * product and a unit without holding a key to them, so it never stops a
 * product or unit from being deleted. A line whose product has gone is
 * dropped when the hold is recalled, and the cashier is told.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('held_sales', function (Blueprint $table) {
            $table->id();
            // Who it is for. A customer deleted meanwhile leaves Walk-in.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            // What the cashier typed to tell holds apart: "Mang Ben, truck".
            $table->string('note', 80)->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            // What it came to when it was held. Shown in the list only: the
            // sale is priced again when it is recalled.
            $table->decimal('total_amount', 15, 2)->default(0);
            // [{product_id, unit_id, quantity, unit_price}], quantity as text.
            $table->json('lines');
            $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('held_sales');
    }
};
