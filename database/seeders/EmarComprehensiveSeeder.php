<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationDashboardAlert;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationPrnEffectiveness;
use App\Models\MedicationReview;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Comprehensive seeder for the entire eMAR system.
 *
 * Creates realistic demo data including round templates, daily rounds,
 * administration history, PRN effectiveness reviews, prescriber orders,
 * medication reviews, competency assessments, handovers, and alerts.
 */
class EmarComprehensiveSeeder extends Seeder
{
    public function run(): void
    {
        $clients = Client::all();
        $staff = User::all();

        if ($clients->isEmpty() || $staff->isEmpty()) {
            $this->command->warn('No clients or staff found. Run other seeders first.');

            return;
        }

        // 1. Round Templates (4 daily rounds)
        $templates = [
            ['name' => 'Morning Round', 'scheduled_time' => '08:00', 'window_minutes' => 60, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7]],
            ['name' => 'Midday Round', 'scheduled_time' => '12:00', 'window_minutes' => 60, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7]],
            ['name' => 'Evening Round', 'scheduled_time' => '18:00', 'window_minutes' => 60, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7]],
            ['name' => 'Night Round', 'scheduled_time' => '22:00', 'window_minutes' => 60, 'days_of_week' => [1, 2, 3, 4, 5, 6, 7]],
        ];

        foreach ($templates as $t) {
            MedicationRoundTemplate::firstOrCreate(['name' => $t['name']], array_merge($t, ['active' => true]));
        }

        $this->command->info('Round templates seeded.');

        // 2. Generate 7 days of rounds (past week)
        $allTemplates = MedicationRoundTemplate::all();

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);

            foreach ($allTemplates as $template) {
                $isPast = $i > 0;
                $startedBy = $isPast ? $staff->random()->id : null;
                $completedBy = $isPast ? $staff->random()->id : null;

                MedicationRound::firstOrCreate(
                    ['round_template_id' => $template->id, 'round_date' => $date->toDateString()],
                    [
                        'name' => $template->name,
                        'round_type' => 'scheduled',
                        'scheduled_time' => $template->scheduled_time,
                        'window_minutes' => $template->window_minutes,
                        'status' => $isPast ? 'completed' : 'pending',
                        'assigned_to' => $staff->random()->id,
                        'started_by' => $startedBy,
                        'started_at' => $isPast ? $date->copy()->setTimeFromTimeString($template->scheduled_time)->addMinutes(rand(0, 15)) : null,
                        'completed_by' => $completedBy,
                        'completed_at' => $isPast ? $date->copy()->setTimeFromTimeString($template->scheduled_time)->addMinutes(rand(20, 50)) : null,
                    ]
                );
            }
        }

        $this->command->info('Medication rounds seeded (7 days).');

        // 3. Administration history (7 days, mix of given/refused/missed)
        $statuses = ['given', 'given', 'given', 'given', 'given', 'given', 'given', 'refused', 'missed', 'given'];

        foreach ($clients as $client) {
            $meds = $client->medications()->active()->get();

            foreach ($meds as $med) {
                for ($i = 6; $i >= 1; $i--) {
                    $date = now()->subDays($i);
                    $doseTimes = $med->dose_times ?? ($med->is_prn ? [] : ['08:00']);

                    foreach ($doseTimes as $time) {
                        $status = $statuses[array_rand($statuses)];

                        ClientMedicationAdministration::firstOrCreate(
                            ['client_medication_id' => $med->id, 'scheduled_for' => $date->copy()->setTimeFromTimeString($time)],
                            [
                                'client_id' => $client->id,
                                'administered_by' => $staff->random()->id,
                                'administered_at' => $status === 'given' ? $date->copy()->setTimeFromTimeString($time)->addMinutes(rand(0, 30)) : null,
                                'status' => $status,
                                'dose_given' => $status === 'given' ? $med->dosage : null,
                                'reason' => $status !== 'given' ? 'Demo: '.$status : null,
                                'witnessed_by' => $med->witness_required ? $staff->random()->id : null,
                            ]
                        );
                    }
                }
            }
        }

        $this->command->info('Administration history seeded (7 days).');

        // 4. PRN effectiveness reviews (for PRN administrations)
        $prnAdmins = ClientMedicationAdministration::whereHas('medication', fn ($q) => $q->where('is_prn', true))
            ->where('status', 'given')
            ->limit(10)
            ->get();

        foreach ($prnAdmins as $admin) {
            MedicationPrnEffectiveness::firstOrCreate(
                ['client_medication_administration_id' => $admin->id],
                [
                    'client_id' => $admin->client_id,
                    'client_medication_id' => $admin->client_medication_id,
                    'effectiveness' => ['effective', 'partially_effective', 'not_effective'][rand(0, 2)],
                    'review_minutes_after' => rand(15, 60),
                    'observations' => 'Client responded to PRN medication.',
                    'reviewed_by' => $staff->random()->id,
                    'reviewed_at' => $admin->administered_at?->addMinutes(rand(30, 90)),
                ]
            );
        }

        $this->command->info('PRN effectiveness reviews seeded.');

        // 5. Prescriber orders (3 recent)
        foreach ($clients->take(3) as $client) {
            MedicationPrescriberOrder::firstOrCreate(
                ['client_id' => $client->id, 'medication_name' => 'Amoxicillin 500mg'],
                [
                    'order_type' => ['written', 'verbal', 'telephone'][rand(0, 2)],
                    'prescriber_name' => 'Dr. Smith',
                    'prescriber_registration' => 'NZMC'.rand(10000, 99999),
                    'dose' => '500mg',
                    'route' => 'oral',
                    'frequency' => 'Three times daily',
                    'instructions' => 'Take with food. Complete full course.',
                    'status' => 'active',
                    'order_date' => now()->subDays(rand(1, 14)),
                ]
            );
        }

        $this->command->info('Prescriber orders seeded.');

        // 6. Medication reviews (mix of scheduled and completed)
        foreach ($clients->take(5) as $client) {
            MedicationReview::firstOrCreate(
                ['client_id' => $client->id, 'review_type' => 'routine'],
                [
                    'status' => ['scheduled', 'completed', 'overdue'][rand(0, 2)],
                    'scheduled_date' => now()->addDays(rand(-10, 30)),
                    'reviewer_name' => 'Dr. Johnson',
                    'reviewer_role' => 'GP',
                ]
            );
        }

        $this->command->info('Medication reviews seeded.');

        // 7. Competency assessments (some current, some expiring)
        foreach ($staff->take(8) as $s) {
            MedicationCompetencyAssessment::firstOrCreate(
                ['user_id' => $s->id, 'assessment_type' => 'full'],
                [
                    'status' => 'passed',
                    'assessment_date' => now()->subMonths(rand(1, 11)),
                    'expiry_date' => now()->addDays(rand(-10, 365)),
                    'five_rights' => true,
                    'safety_checks' => true,
                    'documentation' => true,
                    'controlled_drugs' => (bool) rand(0, 1),
                    'prn_assessment' => true,
                    'error_reporting' => true,
                    'allergy_awareness' => true,
                    'can_administer_unsupervised' => true,
                    'can_witness_controlled' => (bool) rand(0, 1),
                    'assessor_id' => $staff->random()->id,
                ]
            );
        }

        $this->command->info('Competency assessments seeded.');

        // 8. (removed) Handover demo rows previously seeded the dead MedicationHandover
        //    model. Shift handovers are the canonical ShiftHandover records (FK'd to
        //    rostering shifts/staff) and are seeded via the rostering/handover path.

        // 9. Dashboard alerts
        $firstClient = $clients->first();
        $firstMed = $firstClient?->medications()->first();

        if ($firstClient && $firstMed) {
            MedicationDashboardAlert::firstOrCreate(
                ['alert_type' => 'stock_low', 'client_id' => $firstClient->id],
                [
                    'client_medication_id' => $firstMed->id,
                    'severity' => 'warning',
                    'message' => 'Paracetamol stock is below reorder level.',
                    'status' => 'active',
                ]
            );
        }

        $this->command->info('Dashboard alerts seeded.');
        $this->command->info('eMAR comprehensive demo data seeded successfully.');
    }
}
