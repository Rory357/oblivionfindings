<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => 'password',
                'email_verified_at' => now(),
                'approved_at' => now(),
            ]
        );

        $this->call(DemoSeeder::class);
        $this->call(RbacSeeder::class);
        // NOTE: RoleCatalogSeeder created a large catalogue of job-title roles.
        // We are not using those roles in the system right now, so we no longer seed them.
        // (Keeps Access Control role list clean and prevents accidental assignment.)
        // $this->call(RoleCatalogSeeder::class);
    }
}
