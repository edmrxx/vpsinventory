<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 30: the brand, the model or size, the barcode and the preferred
 * supplier of a product.
 *
 * All four are optional, so every product already on the books keeps
 * working with none of them.
 *
 * - `brand` is indexed: the list sorts and filters on it.
 * - `barcode` is unique, so one scan finds one product. NULLs do not clash,
 *   on MySQL or SQLite, so any number of products may have none.
 * - `preferred_supplier_id` is a suggestion, like a purchase request's
 *   supplier: deleting that supplier only clears it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand', 100)->nullable()->after('name')->index();
            $table->string('model', 100)->nullable()->after('brand');
            $table->string('barcode', 64)->nullable()->after('sku')->unique();
            $table->foreignId('preferred_supplier_id')
                ->nullable()
                ->after('unit_id')
                ->constrained('suppliers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_supplier_id');
            $table->dropUnique(['barcode']);
            $table->dropIndex(['brand']);
            $table->dropColumn(['brand', 'model', 'barcode']);
        });
    }
};
