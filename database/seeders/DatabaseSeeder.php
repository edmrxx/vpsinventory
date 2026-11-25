<?php

namespace Database\Seeders;

use App\Services\AuditService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application database.
     *
     * Seeding sets the starting state, so it is not an action anybody took.
     * The audit trail stays quiet while it runs.
     */
    public function run(): void
    {
        app(AuditService::class)->withoutAuditing(function () {
            $this->call([
                RolePermissionSeeder::class,
                AdminUserSeeder::class,
                MasterDataSeeder::class,
            ]);
        });
    }
}
