<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds enough data for sw1@demo.test to demonstrate the desktop /my-day
 * "What's next" rail end-to-end:
 *  - 4 shift tasks attached to whatever shift sw1 has currently active today
 *  - 2 scheduled (non-PRN) medications for the shift's primary resident with
 *    dose_times that land in the controller's 6-hour visible window
 *
 * Idempotent — re-running just updates existing rows (no duplicates).
 */
class SwOneMyDayDemoSeeder extends Seeder
{
    public function run(): void
    {
        $worker = User::query()->where('email', 'sw1@demo.test')->first();
        if (! $worker) {
            $this->command?->warn('sw1@demo.test not found — nothing to seed.');
            return;
        }

        // Pick the worker's currently-active or next-up shift today (NZ).
        $tz = config('app.worker_timezone', 'Pacific/Auckland');
        $now = Carbon::now($tz);
        $todayStart = $now->copy()->startOfDay()->utc();
        $todayEnd = $now->copy()->endOfDay()->utc();

        $shift = Shift::query()
            ->where('user_id', $worker->id)
            ->whereBetween('starts_at', [$todayStart, $todayEnd])
            ->orderBy('starts_at')
            ->first();

        if (! $shift) {
            $this->command?->warn('sw1 has no shift today — nothing to seed.');
            return;
        }

        $this->seedTasks($shift);
        $this->seedMedications($shift, $now);

        $this->command?->info("Seeded /my-day demo data for sw1@demo.test on shift {$shift->id}.");
    }

    private function seedTasks(Shift $shift): void
    {
        $tasks = [
            ['label' => 'Personal cares — assist with morning routine', 'sort_order' => 10],
            ['label' => 'Prepare breakfast and supervise', 'sort_order' => 20],
            ['label' => 'Community outing — walk to Mt Albert shops', 'sort_order' => 30],
            ['label' => 'Lunch prep + meal support', 'sort_order' => 40],
        ];

        foreach ($tasks as $t) {
            ShiftTask::updateOrCreate(
                ['shift_id' => $shift->id, 'label' => $t['label']],
                [
                    'is_completed' => false,
                    'sort_order' => $t['sort_order'],
                ],
            );
        }
    }

    private function seedMedications(Shift $shift, Carbon $now): void
    {
        $client = Client::query()->find($shift->client_id);
        if (! $client) {
            return;
        }

        // dose_times are NZ wall-clock "HH:MM" strings. Aim for the morning
        // round (overdue when seeded after 09:00) and a midday round (due/
        // upcoming) so the rail shows a mix of statuses.
        $meds = [
            [
                'name' => 'Paracetamol',
                'dosage' => '500 mg',
                'dose_amount' => 500,
                'dose_unit' => 'mg',
                'frequency' => 'Three times daily',
                'frequency_code' => 'tds',
                'dose_times' => ['09:00', '13:00', '18:00'],
                'route' => 'Oral',
                'form' => 'Tablet',
            ],
            [
                'name' => 'Metformin',
                'dosage' => '500 mg',
                'dose_amount' => 500,
                'dose_unit' => 'mg',
                'frequency' => 'Twice daily',
                'frequency_code' => 'bd',
                'dose_times' => ['09:00', '13:00'],
                'route' => 'Oral',
                'form' => 'Tablet',
            ],
        ];

        foreach ($meds as $m) {
            ClientMedication::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'name' => $m['name'],
                ],
                [
                    ...$m,
                    'is_prn' => false,
                    'controlled_drug' => false,
                    'high_risk' => false,
                    'witness_required' => false,
                    'start_date' => $now->copy()->subWeek()->toDateString(),
                    'active' => true,
                    'state' => 'active',
                ],
            );
        }
    }
}
