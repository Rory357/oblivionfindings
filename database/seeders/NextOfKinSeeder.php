<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\NextOfKin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class NextOfKinSeeder extends Seeder
{
    public function run(): void
    {
        $nokRole = Role::where('name', 'next_of_kin')->first();
        $password = Hash::make('password');

        // Get some clients to link NOK to
        $clients = Client::where('status', 'active')->limit(10)->get();

        foreach ($clients as $client) {
            // Create 1-2 NOK per client
            $nokCount = rand(1, 2);
            
            for ($i = 0; $i < $nokCount; $i++) {
                $firstName = fake()->firstName();
                $lastName = fake()->lastName();
                
                // Create user account for NOK
                $user = User::create([
                    'name' => "{$firstName} {$lastName}",
                    'email' => strtolower("{$firstName}.{$lastName}" . rand(1, 999) . '@example.com'),
                    'password' => $password,
                    'approved_at' => now(),
                    'email_verified_at' => now(),
                ]);

                // Assign next_of_kin role
                if ($nokRole) {
                    $user->roles()->attach($nokRole->id);
                }

                // Create NOK record
                NextOfKin::create([
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                    'relationship' => fake()->randomElement([
                        'Parent',
                        'Spouse',
                        'Sibling',
                        'Child',
                        'Guardian',
                        'Partner',
                        'Other',
                    ]),
                    'is_primary_contact' => $i === 0,
                    'is_emergency_contact' => true,
                    'phone' => fake()->phoneNumber(),
                    'alternate_phone' => fake()->optional(0.5)->phoneNumber(),
                    'address' => fake()->address(),
                    'can_view_medical' => fake()->boolean(70),
                    'can_view_medications' => fake()->boolean(60),
                    'can_view_incidents' => fake()->boolean(50),
                    'can_receive_updates' => true,
                ]);
            }
        }

        $this->command->info('Created Next of Kin records for ' . $clients->count() . ' clients.');
    }
}
