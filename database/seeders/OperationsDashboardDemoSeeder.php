<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Idempotent seeder that produces a coherent snapshot for the
 * Operations Dashboard (/operations) so every section has something
 * to render: sites across regions, today's shifts of every type
 * (with unassigned + a conflict), approved timesheet hours this and
 * last week, pending + overdue timesheets, and a few open incidents.
 *
 * Safe to re-run — it deletes only the data it previously created
 * (tagged via notes / metadata) before re-inserting.
 */
class OperationsDashboardDemoSeeder extends Seeder
{
    private const TAG = 'ops-dashboard-demo';

    public function run(): void
    {
        $admin = User::query()->where('role', 'admin')->first()
            ?? User::query()->first();
        if (! $admin) {
            $this->command->error('No users — run a base seeder first.');
            return;
        }

        $this->resetDemo();

        $sites = $this->ensureSites();
        $clients = $this->ensureClientsForSites($sites, $admin);
        $staff = $this->ensureStaff($admin);
        $serviceContext = ServiceContext::query()->first();
        if (! $serviceContext) {
            $this->command->error('No service context — needs a base seeder.');
            return;
        }

        $this->seedShifts($sites, $clients, $staff, $serviceContext, $admin);
        $this->seedTimesheets($sites, $clients, $staff, $admin);
        $this->seedIncidents($clients, $staff);

        $this->command->info('Operations dashboard demo data seeded.');
    }

    private function resetDemo(): void
    {
        $tag = self::TAG;

        Shift::query()->where('notes', 'like', "%{$tag}%")->delete();
        Timesheet::query()->where('notes', 'like', "%{$tag}%")->delete();
        ClientIncident::query()
            ->where('description', 'like', "%{$tag}%")
            ->delete();
    }

    /**
     * @return \Illuminate\Support\Collection<int, \App\Models\Site>
     */
    private function ensureSites(): \Illuminate\Support\Collection
    {
        $defs = [
            ['name' => 'Hilltop House', 'region' => 'Northland', 'city' => 'Whangārei', 'type' => 'house', 'is_high_needs' => true],
            ['name' => 'Kāpiti Lodge', 'region' => 'Wellington', 'city' => 'Wellington', 'type' => 'house', 'is_high_needs' => false],
            ['name' => 'Riverside Care', 'region' => 'Waikato', 'city' => 'Hamilton', 'type' => 'house', 'is_high_needs' => false],
            ['name' => 'Aroha Respite', 'region' => 'Auckland', 'city' => 'Auckland', 'type' => 'house', 'is_high_needs' => false],
            ['name' => 'Tōtara House', 'region' => 'Canterbury', 'city' => 'Christchurch', 'type' => 'house', 'is_high_needs' => false],
            ['name' => 'Pōneke Community', 'region' => 'Wellington', 'city' => 'Wellington', 'type' => 'house', 'is_high_needs' => false],
        ];

        $tenantId = User::query()->whereNotNull('organization_id')->value('organization_id') ?? 1;

        return collect($defs)->map(function ($d) use ($tenantId) {
            return Site::firstOrCreate(
                ['name' => $d['name']],
                [
                    'tenant_id' => $tenantId,
                    'type' => $d['type'],
                    'address_line_1' => '1 Demo Street',
                    'suburb' => $d['city'],
                    'city' => $d['city'],
                    'region' => $d['region'],
                    'country' => 'New Zealand',
                    'is_active' => true,
                    'is_high_risk' => false,
                    'is_high_needs' => $d['is_high_needs'],
                ]
            );
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Site>  $sites
     * @return \Illuminate\Support\Collection<int, \App\Models\Client>
     */
    private function ensureClientsForSites(\Illuminate\Support\Collection $sites, User $admin): \Illuminate\Support\Collection
    {
        // Use existing clients first; if a site has none, attach 2.
        $clients = Client::query()->limit(60)->get();

        $clients->groupBy(fn ($c) => $c->site_id)->each(function ($group, $siteId) use ($sites) {
            // re-balance: if site_id null, push to first site
            if (! $siteId) {
                $first = $sites->first();
                $group->each(fn ($c) => $c->update(['site_id' => $first->id]));
            }
        });

        // Ensure each site has at least 4 clients by reassigning when underweight.
        $sites->each(function (Site $site) use (&$clients) {
            $assigned = $clients->where('site_id', $site->id);
            $needed = max(0, 4 - $assigned->count());
            if ($needed === 0) return;
            $candidates = $clients->whereNotIn('id', $assigned->pluck('id'))->take($needed);
            $candidates->each(function (Client $c) use ($site) {
                $c->update(['site_id' => $site->id]);
            });
            $clients = Client::query()->limit(60)->get();
        });

        return Client::query()->whereNotNull('site_id')->get();
    }

    private function ensureStaff(User $admin): \Illuminate\Support\Collection
    {
        // Use whatever staff users we have — fall back to the admin alone.
        $staff = User::query()->where('id', '!=', $admin->id)->limit(10)->get();
        if ($staff->isEmpty()) {
            $staff = collect([$admin]);
        }
        return $staff;
    }

    private function seedShifts(
        \Illuminate\Support\Collection $sites,
        \Illuminate\Support\Collection $clients,
        \Illuminate\Support\Collection $staff,
        ServiceContext $serviceContext,
        User $admin,
    ): void {
        $today = Carbon::today();
        $now = Carbon::now();
        $tag = self::TAG;

        // ── Today's shifts: one of each type per site ──────────────
        $sites->each(function (Site $site, int $idx) use ($clients, $staff, $serviceContext, $admin, $today, $now, $tag) {
            // Prefer clients already on this site; fall back to any client so every site has shifts.
            $siteClients = $clients->where('site_id', $site->id)->values();
            if ($siteClients->isEmpty()) {
                $siteClients = $clients->shuffle()->take(4)->values();
            }
            if ($siteClients->isEmpty()) return;

            $picks = [
                // Overnight (last night → this morning) — always completed
                [
                    'start' => $today->copy()->subDay()->setTime(22, 0),
                    'end' => $today->copy()->setTime(6, 0),
                    'type' => 'overnight',
                    'status' => 'completed',
                ],
                // Day shift — half in_progress, half completed, alternating by site idx
                [
                    'start' => $today->copy()->setTime(9, 0),
                    'end' => $today->copy()->setTime(17, 0),
                    'type' => 'day',
                    'status' => $idx % 2 === 0 ? 'in_progress' : 'completed',
                ],
                // Evening shift — scheduled (still in future for some sites)
                [
                    'start' => $today->copy()->setTime(15, 0),
                    'end' => $today->copy()->setTime(23, 0),
                    'type' => 'evening',
                    'status' => $idx % 2 === 0 ? 'in_progress' : 'scheduled',
                ],
            ];

            foreach ($picks as $shiftIdx => $p) {
                $client = $siteClients[$shiftIdx % $siteClients->count()];
                // Hilltop's evening shift goes unassigned (urgent).
                $isUnassigned = $idx === 0 && $shiftIdx === 2;
                $worker = $isUnassigned ? null : $staff->random();

                $status = $isUnassigned ? 'scheduled' : $p['status'];

                Shift::create([
                    'client_id' => $client->id,
                    'site_id' => $site->id,
                    'service_context_id' => $serviceContext->id,
                    'user_id' => $worker?->id,
                    'starts_at' => $p['start'],
                    'ends_at' => $p['end'],
                    'actual_starts_at' => $status !== 'scheduled' ? $p['start']->copy()->addMinutes(rand(0, 5)) : null,
                    'actual_ends_at' => $status === 'completed' ? $p['end']->copy()->subMinutes(rand(0, 5)) : null,
                    'started_by' => $status !== 'scheduled' && $worker ? $worker->id : null,
                    'completed_by' => $status === 'completed' && $worker ? $worker->id : null,
                    'location' => $site->city,
                    'status' => $status,
                    'shift_type' => $p['type'],
                    'created_by' => $admin->id,
                    'notes' => "Demo · {$tag}",
                ]);
            }

            // One short community visit during the day (optional)
            if ($idx < 3) {
                $client = $siteClients->first();
                $worker = $staff->random();
                $start = $today->copy()->setTime(10, 30);
                $end = $today->copy()->setTime(12, 0);
                $status = 'completed';
                Shift::create([
                    'client_id' => $client->id,
                    'site_id' => $site->id,
                    'service_context_id' => $serviceContext->id,
                    'user_id' => $worker->id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'actual_starts_at' => $start->copy()->addMinutes(2),
                    'actual_ends_at' => $end->copy()->subMinutes(1),
                    'started_by' => $worker->id,
                    'completed_by' => $worker->id,
                    'location' => $site->city,
                    'status' => $status,
                    'shift_type' => 'community_visit',
                    'created_by' => $admin->id,
                    'notes' => "Demo community visit · {$tag}",
                ]);
            }
        });

        // ── Future shifts for the next-7-days bar chart ────────────
        for ($d = 1; $d <= 6; $d++) {
            $day = $today->copy()->addDays($d);
            // Vary count per day so the chart has shape
            $count = match ($d) {
                1 => 12, 2 => 14, 3 => 18, 4 => 16, 5 => 10, 6 => 8,
                default => 10,
            };
            for ($i = 0; $i < $count; $i++) {
                $site = $sites->random();
                $clientCandidates = $clients->where('site_id', $site->id);
                if ($clientCandidates->isEmpty()) continue;
                $client = $clientCandidates->random();
                $worker = $staff->random();
                $hour = [7, 9, 11, 14, 15, 17, 22][$i % 7];
                $duration = $hour === 22 ? 8 : ($hour === 14 ? 2 : 8);
                $start = $day->copy()->setTime($hour, 0);
                $end = $start->copy()->addHours($duration);
                Shift::create([
                    'client_id' => $client->id,
                    'site_id' => $site->id,
                    'service_context_id' => $serviceContext->id,
                    'user_id' => ($i % 8 === 7 && $d <= 2) ? null : $worker->id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'location' => $site->city,
                    'status' => 'scheduled',
                    'shift_type' => $hour === 22 ? 'overnight' : ($hour === 14 ? 'community_visit' : ($hour >= 15 ? 'evening' : 'day')),
                    'created_by' => $admin->id,
                    'notes' => "Demo future · {$tag}",
                ]);
            }
        }
    }

    private function seedTimesheets(
        \Illuminate\Support\Collection $sites,
        \Illuminate\Support\Collection $clients,
        \Illuminate\Support\Collection $staff,
        User $admin,
    ): void {
        $tag = self::TAG;
        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek(Carbon::MONDAY);
        $lastWeekStart = $weekStart->copy()->subWeek();

        // Approved timesheets covering this week + last week (drives hours metric)
        $createApproved = function (Carbon $monday, int $daysBack) use ($staff, $clients, $sites, $admin, $tag) {
            for ($d = 0; $d < $daysBack; $d++) {
                $day = $monday->copy()->addDays($d);
                $shifts = $staff->take(6);
                foreach ($shifts as $worker) {
                    $client = $clients->random();
                    $start = $day->copy()->setTime(9, 0);
                    $end = $day->copy()->setTime(17, 0);
                    Timesheet::create([
                        'user_id' => $worker->id,
                        'client_id' => $client->id,
                        'work_date' => $day->toDateString(),
                        'starts_at' => $start,
                        'ends_at' => $end,
                        'break_minutes' => 30,
                        'status' => 'approved',
                        'submitted_at' => $day->copy()->setTime(18, 0),
                        'submitted_by' => $worker->id,
                        'approved_by' => $admin->id,
                        'approved_at' => $day->copy()->setTime(20, 0),
                        'created_by' => $worker->id,
                        'notes' => "Demo approved · {$tag}",
                    ]);
                }
            }
        };
        // Days into this week so far (mon→today, inclusive)
        $daysSoFar = (int) $today->copy()->startOfDay()->diffInDays($weekStart) + 1;
        $createApproved($weekStart, max(1, $daysSoFar));
        $createApproved($lastWeekStart, 7);

        // Pending timesheets — 8 total, 3 overdue
        for ($i = 0; $i < 8; $i++) {
            $worker = $staff->random();
            $client = $clients->random();
            $isOverdue = $i < 3;
            $workDate = $lastWeekStart->copy()->addDays($i % 5);
            $start = $workDate->copy()->setTime(9, 0);
            $end = $workDate->copy()->setTime(17, 0);
            Timesheet::create([
                'user_id' => $worker->id,
                'client_id' => $client->id,
                'work_date' => $workDate->toDateString(),
                'starts_at' => $start,
                'ends_at' => $end,
                'break_minutes' => 30,
                'status' => 'submitted',
                'submitted_at' => $isOverdue
                    ? Carbon::now()->subDays(rand(4, 7))
                    : Carbon::now()->subDays(rand(0, 2)),
                'submitted_by' => $worker->id,
                'created_by' => $worker->id,
                'notes' => "Demo pending · {$tag}",
            ]);
        }
    }

    private function seedIncidents(
        \Illuminate\Support\Collection $clients,
        \Illuminate\Support\Collection $staff,
    ): void {
        $tag = self::TAG;
        $defs = [
            ['type' => 'medication_error', 'severity' => 'medium', 'detail' => 'Medication near-miss · double-check missed at handover'],
            ['type' => 'fall', 'severity' => 'low', 'detail' => 'Minor fall during transfer · no injury'],
            ['type' => 'fall', 'severity' => 'low', 'detail' => 'Slip in bathroom · already on falls register'],
        ];

        foreach ($defs as $i => $d) {
            $client = $clients->random();
            ClientIncident::create([
                'client_id' => $client->id,
                'reported_by' => $staff->random()->id,
                'type' => $d['type'],
                'severity' => $d['severity'],
                'status' => 'submitted',
                'occurred_at' => Carbon::now()->subHours(rand(1, 36)),
                'title' => ucfirst(str_replace('_', ' ', $d['type'])) . ' — demo',
                'description' => $d['detail'] . " · {$tag}",
                'location' => $client->site?->name ?? 'Unknown',
                'requires_followup' => true,
                'submitted_at' => Carbon::now()->subHours(rand(1, 24)),
            ]);
        }
    }
}
