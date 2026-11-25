<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_trails', function (Blueprint $table) {
            $table->id();

            // Who. The id is kept for linking, but the name, username and
            // role are copied in as text so the record still reads correctly
            // after the account is renamed or deleted.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('username')->nullable();
            $table->string('role')->nullable();

            // What.
            $table->string('module')->index();
            $table->string('action')->index();
            $table->text('description');

            // Which record it touched, when there is one.
            $table->nullableMorphs('subject');

            // The changed fields, for an update. Secrets are masked before
            // they are written.
            $table->json('properties')->nullable();

            // Where from.
            $table->string('ip_address', 45)->nullable();
            $table->string('browser')->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_trails');
    }
};
