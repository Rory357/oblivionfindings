<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\MarScheduleService;
use Illuminate\Database\Seeder;

/**
 * Demo data for the redesigned /emar/rounds page.
 *
 * Reproduces the design prototype's catalog so the page renders the full
 * experience — timeline donuts, board cards, the Resident × Round chart, the
 * guided modal and the audit timeline — instead of empty states.
 *
 * The 8 residents live in a DEDICATED service context ("Rounds Demo (eMAR)")
 * so the all-site round templates pick up exactly these residents' doses (and
 * not unrelated seed meds), keeping the demo clean and matching the prototype.
 *
 * Date-relative + idempotent: every run targets TODAY and upserts, so it is
 * safe to re-run (and to run daily) on any database state.
 *
 * Run standalone:  php artisan db:seed --class=Database\\Seeders\\MedicationRoundsDemoSeeder
 */
class MedicationRoundsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $schedule = app(MarScheduleService::class);
        $roundDate = today()->toDateString();
        // Compute each dose's UTC instant exactly the way GuidedRoundService::items()
        // does (dateFromInput → setTimeFromTimeString → utc) so seeded administrations
        // key-match their doses and render as recorded rather than "due".
        $slotUtc = fn (string $time) => $schedule->dateFromInput($roundDate)->setTimeFromTimeString($time)->utc();

        $staff = User::query()->where('role', 'support_worker')->first() ?? User::query()->first();

        // Dedicated service context so templates isolate exactly these residents.
        $context = ServiceContext::query()->firstOrCreate(
            ['name' => 'Rounds Demo (eMAR)'],
            ['type' => 'residential', 'is_active' => true],
        );

        // ── Sites (3) ────────────────────────────────────────────────────────
        $siteDefs = [
            'Rata House' => ['suburb' => 'Mount Eden', 'postcode' => '1024'],
            'Kauri Lodge' => ['suburb' => 'Grey Lynn', 'postcode' => '1021'],
            'Kowhai Villa' => ['suburb' => 'Devonport', 'postcode' => '0624'],
        ];
        $sites = [];
        foreach ($siteDefs as $name => $extra) {
            $sites[$name] = Site::query()->firstOrCreate(
                ['name' => $name],
                [
                    'address_line_1' => '1 '.$name.' Way',
                    'suburb' => $extra['suburb'],
                    'city' => 'Auckland',
                    'region' => 'Auckland',
                    'postcode' => $extra['postcode'],
                    'country' => 'New Zealand',
                    'is_active' => true,
                ],
            );
        }

        // ── Residents (8) across the 3 sites, all in the demo context ─────────
        $clientDefs = [
            ['nhi' => 'RND0001', 'first' => 'Margaret', 'last' => 'Hill', 'site' => 'Rata House'],
            ['nhi' => 'RND0002', 'first' => 'David', 'last' => 'Tanaka', 'site' => 'Rata House'],
            ['nhi' => 'RND0003', 'first' => 'Aroha', 'last' => 'Ngata', 'site' => 'Kauri Lodge'],
            ['nhi' => 'RND0004', 'first' => 'Samuel', 'last' => 'Reid', 'site' => 'Kauri Lodge'],
            ['nhi' => 'RND0005', 'first' => 'Grace', 'last' => 'Patel', 'site' => 'Rata House'],
            ['nhi' => 'RND0006', 'first' => 'Tane', 'last' => 'Walker', 'site' => 'Kowhai Villa'],
            ['nhi' => 'RND0007', 'first' => 'Elsie', 'last' => 'Brown', 'site' => 'Kowhai Villa'],
            ['nhi' => 'RND0008', 'first' => 'Joseph', 'last' => 'Mago', 'site' => 'Rata House'],
        ];
        $clients = [];
        foreach ($clientDefs as $i => $c) {
            // Key on nhi_hash (queryable) — email/phone/nhi_number are encrypted casts
            // and can't be matched by updateOrCreate, which would create duplicates.
            $clients[$c['nhi']] = Client::query()->updateOrCreate(
                ['nhi_hash' => Client::nhiHash($c['nhi'])],
                [
                    'nhi_number' => $c['nhi'],
                    'site_id' => $sites[$c['site']]->id,
                    'service_context_id' => $context->id,
                    'first_name' => $c['first'],
                    'last_name' => $c['last'],
                    'preferred_name' => $c['first'],
                    'gender' => ['female', 'male'][$i % 2],
                    'status' => 'active',
                    'email' => strtolower($c['first'].'.'.$c['last']).'@rounds-demo.test',
                    'city' => 'Auckland',
                ],
            );
        }

        // ── Medications per resident — dose_times land each dose in a round ────
        // Times used: 08:00 (Morning), 12:30 (Midday), 16:00 (Afternoon),
        // 18:00 (Evening), 21:00 (Night).
        $medDefs = [
            'RND0001' => [
                ['name' => 'Metformin', 'dosage' => '1 g', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['08:00', '18:00'], 'instructions' => 'With or after food.'],
                ['name' => 'Paracetamol', 'dosage' => '1 g', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['12:30'], 'instructions' => 'Max 4 g in 24h.'],
            ],
            'RND0002' => [
                ['name' => 'Amlodipine', 'dosage' => '5 mg', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['08:00', '18:00']],
                ['name' => 'Metformin', 'dosage' => '1 g', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['12:30'], 'instructions' => 'With lunch.'],
            ],
            'RND0003' => [
                ['name' => 'Insulin Lantus', 'dosage' => '12 units', 'route' => 'SC', 'form' => 'Injection', 'times' => ['08:00', '21:00'], 'high_risk' => true, 'instructions' => 'Rotate site. Record blood glucose first.'],
                ['name' => 'Paracetamol', 'dosage' => '1 g', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['16:00']],
            ],
            'RND0004' => [
                ['name' => 'Warfarin', 'dosage' => '3 mg', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['08:00', '18:00'], 'high_risk' => true, 'instructions' => 'Dose per INR yellow book — confirm today\'s dose.'],
                ['name' => 'Digoxin', 'dosage' => '125 mcg', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['12:30'], 'high_risk' => true, 'instructions' => 'Check apical pulse — withhold if under 60 bpm.'],
            ],
            'RND0005' => [
                ['name' => 'Sertraline', 'dosage' => '50 mg', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['08:00', '18:00']],
                ['name' => 'Paracetamol', 'dosage' => '1 g', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['12:30']],
            ],
            'RND0006' => [
                ['name' => 'Donepezil', 'dosage' => '10 mg', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['08:00', '21:00']],
                ['name' => 'Furosemide', 'dosage' => '20 mg', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['16:00'], 'instructions' => 'Give early — diuretic.'],
            ],
            'RND0007' => [
                ['name' => 'Furosemide', 'dosage' => '40 mg', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['08:00', '21:00']],
                ['name' => 'Senna', 'dosage' => '2 tablets', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['16:00']],
            ],
            'RND0008' => [
                ['name' => 'Paracetamol', 'dosage' => '500 mg', 'route' => 'PO', 'form' => 'Tablet', 'times' => ['08:00', '18:00']],
                ['name' => 'Oxycodone', 'dosage' => '5 mg', 'route' => 'PO', 'form' => 'Liquid', 'times' => ['12:30'], 'controlled_drug' => true, 'witness_required' => true, 'instructions' => 'Controlled drug — second signature required.'],
            ],
        ];

        $meds = [];
        foreach ($medDefs as $nhi => $list) {
            foreach ($list as $m) {
                $meds[$nhi][$m['name']] = ClientMedication::query()->updateOrCreate(
                    ['client_id' => $clients[$nhi]->id, 'name' => $m['name']],
                    [
                        'created_by' => $staff?->id,
                        'dosage' => $m['dosage'],
                        'route' => $m['route'],
                        'form' => $m['form'],
                        'frequency' => count($m['times']) > 1 ? 'Twice daily' : 'Once daily',
                        'dose_times' => $m['times'],
                        'is_prn' => false,
                        'controlled_drug' => $m['controlled_drug'] ?? false,
                        'high_risk' => $m['high_risk'] ?? false,
                        'witness_required' => $m['witness_required'] ?? false,
                        'instructions' => $m['instructions'] ?? null,
                        'start_date' => today()->subMonths(2)->toDateString(),
                        'end_date' => null,
                        'active' => true,
                        'state' => 'active',
                        'approval_status' => 'verified',
                    ],
                );
            }
        }

        // ── Round templates (5, all-site, scoped to the demo context) ─────────
        $templateDefs = [
            'morning' => ['name' => 'Morning Round', 'time' => '08:00', 'window' => 60],
            'midday' => ['name' => 'Midday Round', 'time' => '12:30', 'window' => 45],
            'afternoon' => ['name' => 'Afternoon Round', 'time' => '16:00', 'window' => 60],
            'evening' => ['name' => 'Evening Round', 'time' => '18:00', 'window' => 45],
            'night' => ['name' => 'Night Round', 'time' => '21:00', 'window' => 60],
        ];
        $assignedKeys = ['morning', 'midday', 'evening'];
        $rounds = [];
        foreach ($templateDefs as $key => $t) {
            $template = MedicationRoundTemplate::query()->updateOrCreate(
                ['name' => $t['name'], 'service_context_id' => $context->id],
                [
                    'site_id' => null,
                    'scheduled_time' => $t['time'],
                    'window_minutes' => $t['window'],
                    'days_of_week' => [],
                    'active' => true,
                    'default_assigned_to' => in_array($key, $assignedKeys, true) ? $staff?->id : null,
                ],
            );

            $rounds[$key] = MedicationRound::query()->firstOrCreate(
                ['round_template_id' => $template->id, 'round_date' => $roundDate],
                [
                    'name' => $t['name'],
                    'service_context_id' => $context->id,
                    'site_id' => null,
                    'round_type' => 'scheduled',
                    'scheduled_time' => $t['time'],
                    'window_minutes' => $t['window'],
                    'status' => 'pending',
                    'assigned_to' => in_array($key, $assignedKeys, true) ? $staff?->id : null,
                    'total_medications' => $template->applicableMedicationCountForDate(today()),
                ],
            );
        }

        // ── Administrations → Morning partial, Midday in-progress, rest pending
        $recipe = [
            ['morning', '08:00', 'RND0001', 'Metformin', 'given'],
            ['morning', '08:00', 'RND0002', 'Amlodipine', 'given'],
            ['morning', '08:00', 'RND0003', 'Insulin Lantus', 'given', ['bg' => 7.2]],
            ['morning', '08:00', 'RND0004', 'Warfarin', 'given'],
            ['morning', '08:00', 'RND0005', 'Sertraline', 'refused', ['reason' => 'Declined — felt nauseous', 'code' => 'declined']],
            ['morning', '08:00', 'RND0006', 'Donepezil', 'given'],
            ['morning', '08:00', 'RND0007', 'Furosemide', 'given'],
            ['morning', '08:00', 'RND0008', 'Paracetamol', 'given'],
            ['midday', '12:30', 'RND0001', 'Paracetamol', 'given'],
            ['midday', '12:30', 'RND0002', 'Metformin', 'given'],
        ];

        if ($staff) {
            foreach ($recipe as $entry) {
                [$roundKey, $time, $nhi, $medName, $status] = $entry;
                $extra = $entry[5] ?? [];
                $med = $meds[$nhi][$medName] ?? null;
                $round = $rounds[$roundKey] ?? null;
                if (! $med || ! $round) {
                    continue;
                }

                ClientMedicationAdministration::query()->firstOrCreate(
                    [
                        'client_medication_id' => $med->id,
                        'medication_round_id' => $round->id,
                        'scheduled_for' => $slotUtc($time),
                    ],
                    [
                        'client_id' => $clients[$nhi]->id,
                        'service_context_id' => $context->id,
                        'administered_by' => $staff->id,
                        'administered_at' => $slotUtc($time)->copy()->addMinutes(6),
                        'status' => $status,
                        'dose_given' => $status === 'given' ? $med->dosage : null,
                        'reason' => $extra['reason'] ?? null,
                        'reason_code' => $extra['code'] ?? null,
                        'blood_glucose_level' => $extra['bg'] ?? null,
                        'notes' => 'Seeded demo round administration.',
                    ],
                );
            }
        }

        // ── Refresh counters + set the demo status story ──────────────────────
        foreach ($rounds as $round) {
            $round->updateCounts();
        }
        $rounds['morning']->update([
            'status' => 'partial',
            'started_at' => $slotUtc('08:00'),
            'started_by' => $staff?->id,
        ]);
        $rounds['midday']->update([
            'status' => 'in_progress',
            'started_at' => $slotUtc('12:30'),
            'started_by' => $staff?->id,
        ]);
    }
}
