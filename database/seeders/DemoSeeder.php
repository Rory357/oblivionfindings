<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@demo.test'],
            ['name' => 'Demo Admin', 'password' => Hash::make('password'), 'role' => 'admin']
        );

        User::updateOrCreate(
            ['email' => 'manager@demo.test'],
            ['name' => 'Demo Manager', 'password' => Hash::make('password'), 'role' => 'provider_manager']
        );

        $workers = User::factory()->count(6)->create([
            'role' => 'support_worker',
            'password' => Hash::make('password'),
        ]);

        $clients = Client::factory()->count(10)->create([
            'status' => 'active',
        ]);

        foreach ($clients as $client) {
            $client->supportWorkers()->sync(
                $workers->random(rand(1, 3))->pluck('id')->all()
            );
        }
    }
}
