<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture of the product.
 *
 * The column holds a path inside the `public` disk, never the picture
 * itself. A database row stays small and a backup stays quick; the files
 * sit in `storage/app/public/products` and are served through the storage
 * link. The other side of that bargain is that a database export does NOT
 * carry the photos — the folder has to be backed up as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
