<?php

namespace Database\Seeders;

use App\Domain\Governance\Models\BoardMember;
use App\Models\User;
use Illuminate\Database\Seeder;

class BoardMemberSeeder extends Seeder
{
    public function run(): void
    {
        // Get or create a user for board member
        $user = User::firstOrCreate(
            ['email' => 'board@example.com'],
            [
                'name' => 'Board Member',
                'password' => bcrypt('password'),
            ]
        );

        // Create board member record
        BoardMember::firstOrCreate(
            ['user_id' => $user->id],
            [
                'board_role' => 'member',
                'term_start' => now(),
                'term_end' => now()->addYears(3),
                'is_active' => true,
                'is_independent' => true,
            ]
        );

        // Create a few more board members
        $roles = [
            ['name' => 'Sarah Chair', 'email' => 'chair@example.com', 'role' => 'chair'],
            ['name' => 'Mike Treasurer', 'email' => 'treasurer@example.com', 'role' => 'treasurer'],
            ['name' => 'Lisa Secretary', 'email' => 'secretary@example.com', 'role' => 'secretary'],
        ];

        foreach ($roles as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => bcrypt('password'),
                ]
            );

            BoardMember::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'board_role' => $data['role'],
                    'term_start' => now(),
                    'term_end' => now()->addYears(3),
                    'is_active' => true,
                    'is_independent' => true,
                ]
            );
        }
    }
}
