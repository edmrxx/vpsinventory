<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AuditService;
use App\Support\PermissionRegistry;
use Illuminate\Database\Seeder;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the one default administrator and give it the Admin role.
     *
     * `firstOrCreate`, never `updateOrCreate`. This seeder runs again on
     * every deploy that calls `db:seed`, and an update would quietly put
     * the password back to the default and switch a disabled account on —
     * handing the factory password to anyone who had read the README.
     */
    public function run(): void
    {
        app(AuditService::class)->withoutAuditing(fn () => $this->seed());
    }

    protected function seed(): void
    {
        $admin = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'System Administrator',
                'email' => null,
                'password' => 'admin123',
                'is_active' => true,
            ]
        );

        // Only on the first run, for the same reason as `firstOrCreate`
        // above. A re-seed would otherwise hand Admin back to an account
        // somebody had deliberately demoted, and strip whatever role it
        // had been given instead.
        if ($admin->wasRecentlyCreated) {
            $admin->syncRoles([PermissionRegistry::adminRole()]);
        }
    }
}
