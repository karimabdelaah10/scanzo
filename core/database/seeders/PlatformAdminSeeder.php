<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;

/**
 * The first platform account, for the admin panel.
 *
 * Set ADMIN_EMAIL and ADMIN_PASSWORD in core/.env before running this on
 * anything that is not a local machine. Read through config('scanzo.*')
 * because the entrypoint caches the config, after which env() returns null.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('scanzo.admin.email');

        $admin = Admin::firstOrNew(['email' => $email]);

        if ($admin->exists) {
            $this->command->info("Platform admin {$email} already exists, left alone.");

            return;
        }

        $password = config('scanzo.admin.password');

        $admin->fill([
            'name' => config('scanzo.admin.name'),
            'password' => $password,
            'is_active' => true,
        ])->save();

        $this->command->info("Platform admin created: {$email}");

        if ($password === 'password') {
            $this->command->warn('Using the default password. Change it before this is reachable from anywhere.');
        }
    }
}
