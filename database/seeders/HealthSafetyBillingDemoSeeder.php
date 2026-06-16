<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Right-sized worked-hours basis for the Health & Safety frequency rates.
 *
 * LTIFR/TRIFR divide injuries by hours worked, and {@see HsKpiService::totalHoursWorked()}
 * sums `BillingEntry.hours` over `service_date`. Without billed hours the rates show "—"
 * (or, with a sliver of hours, blow up). This seeds ~12 months of billing entries sized to
 * the demo's ACTUAL headcount (~30 staff ≈ ~50k hrs/yr) so the rate is realistic and
 * proportionate — e.g. 1 recordable injury / 50,000 hrs × 1,000,000 ≈ TRIFR 20 — never inflated.
 *
 * Entries mirror how the real {@see BillingService} writes them (no `site_id`; per-site is keyed
 * on `site_name_snapshot`). They read coherently in Finance: weeks older than ~a month are 'paid'
 * (historical revenue), the most recent ~month is 'pending'. Idempotent via the notes marker.
 *
 * Standalone — not run by DatabaseSeeder. Invoke explicitly:
 *   php artisan db:seed --class=HealthSafetyBillingDemoSeeder --force
 */
class HealthSafetyBillingDemoSeeder extends Seeder
{
    private const DEMO_MARKER = '[HS-HOURS-DEMO]';

    /** Trailing-12-month worked-hours target → TRIFR ≈ recordable / 50,000 × 1,000,000. */
    private const TARGET_HOURS = 50000;

    private const WEEKS = 52;

    public function run(): void
    {
        if (DB::table('billing_entries')->where('notes', 'like', '%' . self::DEMO_MARKER . '%')->exists()) {
            $this->command->warn('H&S worked-hours demo already seeded — skipping.');

            return;
        }

        $staff = User::whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['client', 'next_of_kin']))
            ->pluck('name', 'id');
        $staffIds = $staff->keys()->all();
        $clients = Client::get(['id', 'first_name', 'last_name']);
        $sites = Site::get(['id', 'name']);
        $orgId = rescue(fn () => DB::table('organizations')->value('id'), null, false);

        if (empty($staffIds) || $clients->isEmpty()) {
            $this->command->error('Need staff + clients before seeding worked hours. Aborting.');

            return;
        }

        $now = Carbon::now();
        $perWeek = self::TARGET_HOURS / self::WEEKS; // ~962 billable hours/week across the team
        $total = 0.0;
        $count = 0;
        $rows = [];

        for ($w = 0; $w < self::WEEKS; $w++) {
            shuffle($staffIds); // vary who works each week
            $weekTotal = 0.0;

            foreach ($staffIds as $staffId) {
                if ($weekTotal >= $perWeek) {
                    break;
                }

                $hours = min(rand(28, 42), (int) ceil($perWeek - $weekTotal));
                if ($hours <= 0) {
                    break;
                }

                $serviceDate = $now->copy()->subWeeks($w)->subDays(rand(0, 6));
                if ($serviceDate->gt($now)) {
                    $serviceDate = $now->copy();
                }

                $client = $clients->random();
                $site = $sites->isNotEmpty() ? $sites->random() : null;
                $rate = rand(44, 54);
                $ageDays = (int) abs($serviceDate->diffInDays($now));

                $rows[] = [
                    'organization_id' => $orgId,
                    'client_id' => $client->id,
                    'staff_id' => $staffId,
                    'service_date' => $serviceDate->toDateString(),
                    'hours' => $hours,
                    'rate' => $rate,
                    'amount' => round($hours * $rate, 2),
                    'rate_type' => 'standard',
                    'status' => $ageDays > 35 ? 'paid' : 'pending',
                    'site_name_snapshot' => $site?->name,
                    'client_name_snapshot' => trim($client->first_name . ' ' . $client->last_name),
                    'staff_name_snapshot' => $staff[$staffId] ?? null,
                    'notes' => 'Worked-hours demo basis for H&S frequency rates. ' . self::DEMO_MARKER,
                    'created_at' => $serviceDate,
                    'updated_at' => $serviceDate,
                ];

                $weekTotal += $hours;
                $total += $hours;
                $count++;

                if (count($rows) >= 200) {
                    DB::table('billing_entries')->insert($rows);
                    $rows = [];
                }
            }
        }

        if ($rows) {
            DB::table('billing_entries')->insert($rows);
        }

        $this->command->info("Seeded {$count} billing entries · " . round($total) . ' worked-hours over 12 months.');
        $this->command->info('  -> H&S LTIFR/TRIFR now have a real, right-sized denominator (~' . round($total) . ' hrs).');
    }
}
