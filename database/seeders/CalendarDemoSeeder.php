<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Shift;
use Illuminate\Database\Seeder;

class CalendarDemoSeeder extends Seeder
{
    public function run(): void
    {
        $userId = 1;
        $clients = Client::take(6)->get();

        if ($clients->isEmpty()) {
            $this->command->warn('No clients found — skipping calendar demo seed.');
            return;
        }

        $now = now();
        $locations = ['Kauri House', 'Harbour Respite', 'Main Site', 'Rimu Lodge', 'Totara Place'];

        $shifts = [
            // Yesterday (completed)
            ['client_idx' => 0, 'day' => -1, 'start_h' => 7,  'end_h' => 11, 'status' => 'completed', 'loc' => 0],
            ['client_idx' => 1, 'day' => -1, 'start_h' => 12, 'end_h' => 16, 'status' => 'completed', 'loc' => 1],
            ['client_idx' => 2, 'day' => -1, 'start_h' => 17, 'end_h' => 21, 'status' => 'completed', 'loc' => 2],

            // Today
            ['client_idx' => 3, 'day' => 0, 'start_h' => 7,  'end_h' => 11, 'status' => 'completed', 'loc' => 0],
            ['client_idx' => 0, 'day' => 0, 'start_h' => 12, 'end_h' => 16, 'status' => 'in_progress', 'loc' => 1],
            ['client_idx' => 4, 'day' => 0, 'start_h' => 17, 'end_h' => 21, 'status' => 'scheduled', 'loc' => 3],

            // Tomorrow
            ['client_idx' => 1, 'day' => 1, 'start_h' => 6,  'end_h' => 10, 'status' => 'scheduled', 'loc' => 0],
            ['client_idx' => 2, 'day' => 1, 'start_h' => 11, 'end_h' => 15, 'status' => 'scheduled', 'loc' => 2],
            ['client_idx' => 5, 'day' => 1, 'start_h' => 16, 'end_h' => 20, 'status' => 'scheduled', 'loc' => 4],

            // Day +2
            ['client_idx' => 3, 'day' => 2, 'start_h' => 8,  'end_h' => 16, 'status' => 'scheduled', 'loc' => 1],

            // Day +3
            ['client_idx' => 0, 'day' => 3, 'start_h' => 9,  'end_h' => 13, 'status' => 'scheduled', 'loc' => 2],
            ['client_idx' => 4, 'day' => 3, 'start_h' => 14, 'end_h' => 18, 'status' => 'scheduled', 'loc' => 0],

            // Day +4
            ['client_idx' => 1, 'day' => 4, 'start_h' => 7,  'end_h' => 11, 'status' => 'scheduled', 'loc' => 3],
            ['client_idx' => 5, 'day' => 4, 'start_h' => 12, 'end_h' => 17, 'status' => 'scheduled', 'loc' => 4],

            // Day +5
            ['client_idx' => 2, 'day' => 5, 'start_h' => 8,  'end_h' => 12, 'status' => 'scheduled', 'loc' => 1],
            ['client_idx' => 3, 'day' => 5, 'start_h' => 13, 'end_h' => 17, 'status' => 'scheduled', 'loc' => 0],
        ];

        $created = 0;
        foreach ($shifts as $s) {
            $clientIdx = $s['client_idx'] % $clients->count();
            $client = $clients[$clientIdx];
            $day = $now->copy()->addDays($s['day']);

            Shift::create([
                'user_id' => $userId,
                'client_id' => $client->id,
                'starts_at' => $day->copy()->setTime($s['start_h'], 0),
                'ends_at' => $day->copy()->setTime($s['end_h'], 0),
                'status' => $s['status'],
                'location' => $locations[$s['loc']],
                'notes' => 'Seeded for calendar demo',
            ]);
            $created++;
        }

        $this->command->info("Created {$created} demo shifts for user {$userId}.");
    }
}
