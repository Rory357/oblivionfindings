<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HealthSafetyDemoSeeder extends Seeder
{
    /**
     * Marker embedded in descriptions so we can detect prior runs (idempotency).
     */
    private const DEMO_MARKER = '[H&S-DEMO-SEED]';

    public function run(): void
    {
        // ── Pre-flight checks ────────────────────────────────────────
        $clientIds = DB::table('clients')->pluck('id')->toArray();
        $userIds   = DB::table('users')->pluck('id')->toArray();
        $siteIds   = DB::table('sites')->pluck('id')->toArray();
        $assetIds  = DB::table('assets')->pluck('id')->toArray();

        if (empty($clientIds) || empty($userIds) || empty($siteIds)) {
            $this->command->error('Need at least 1 client, 1 user, and 1 site. Seed those tables first.');
            return;
        }

        // ── Idempotency check ────────────────────────────────────────
        $alreadySeeded = DB::table('client_incidents')
            ->where('description', 'like', '%' . self::DEMO_MARKER . '%')
            ->exists();

        if ($alreadySeeded) {
            $this->command->warn('H&S demo data found - seeding remaining tables only.');
        }

        $now = Carbon::now();

        // ── 1. Client Incidents ──────────────────────────────────────
        if (!$alreadySeeded) {
            $this->command->info('Seeding Client Incidents...');
            $this->seedClientIncidents($clientIds, $userIds, $now);
        } else {
            $this->command->info('Client Incidents already seeded, skipping.');
        }

        // ── 2. Site Hazards ──────────────────────────────────────────
        if (!DB::table('site_hazards')->where('description', 'like', '%' . self::DEMO_MARKER . '%')->exists()) {
            $this->command->info('Seeding Site Hazards...');
            $this->seedSiteHazards($siteIds, $userIds, $now);
        } else {
            $this->command->info('Site Hazards already seeded, skipping.');
        }

        // ── 3. Emergency Drills ──────────────────────────────────────
        if (!DB::table('emergency_drills')->where('description', 'like', '%' . self::DEMO_MARKER . '%')->exists()) {
            $this->command->info('Seeding Emergency Drills...');
            $this->seedEmergencyDrills($siteIds, $userIds, $now);
        } else {
            $this->command->info('Emergency Drills already seeded, skipping.');
        }

        // ── 4. Workplace Injuries ────────────────────────────────────
        if (!DB::table('workplace_injuries')->where('description', 'like', '%' . self::DEMO_MARKER . '%')->exists()) {
            $this->command->info('Seeding Workplace Injuries...');
            $this->seedWorkplaceInjuries($userIds, $siteIds, $now);
        } else {
            $this->command->info('Workplace Injuries already seeded, skipping.');
        }

        // ── 5. Fleet Incidents ───────────────────────────────────────
        if (!DB::table('fleet_incidents')->where('description', 'like', '%' . self::DEMO_MARKER . '%')->exists()) {
            $this->command->info('Seeding Fleet Incidents...');
            $this->seedFleetIncidents($assetIds, $userIds, $now);
        } else {
            $this->command->info('Fleet Incidents already seeded, skipping.');
        }

        $this->command->info('Health & Safety demo data seeded successfully.');
    }

    /* ==================================================================
     *  1. CLIENT INCIDENTS  (~8-15 per month for 12 months)
     * ================================================================*/
    private function seedClientIncidents(array $clientIds, array $userIds, Carbon $now): void
    {
        $typeWeights = [
            'fall'         => 40,
            'medication'   => 25,
            'behaviour'    => 15,
            'safeguarding' => 10,
            'near_miss'    => 10,
        ];

        $descriptions = [
            'fall' => [
                'Resident slipped on wet floor in bathroom',
                'Resident fell while transferring from wheelchair to bed',
                'Resident tripped over raised carpet edge in hallway',
                'Resident found on floor beside bed during night check',
                'Resident lost balance walking to dining room',
                'Resident slipped on spilled drink in lounge area',
                'Resident fell while attempting to stand from armchair',
                'Resident tripped on doorway threshold entering room',
            ],
            'medication' => [
                'Medication administered 30 minutes late due to staff handover',
                'Incorrect dosage of paracetamol given - double dose administered',
                'PRN medication given without proper documentation',
                'Blister pack found with missed morning dose',
                'Medication given to wrong resident - names confused',
                'Controlled drug count discrepancy found during shift check',
                'Insulin administered without blood glucose check',
            ],
            'behaviour' => [
                'Resident became verbally aggressive towards staff during personal care',
                'Resident struck another resident in communal area',
                'Resident attempted to leave facility unassisted',
                'Resident displaying escalating agitation and distress',
                'Resident threw objects during episode of confusion',
                'Resident refused care and became physically resistant',
            ],
            'safeguarding' => [
                'Unexplained bruising noted on resident upper arm during shower',
                'Family member raised concern about resident appearing unkempt',
                'Financial irregularity flagged - unusual EFTPOS transactions',
                'Resident reported feeling unsafe with particular staff member',
                'Resident disclosed historical abuse during conversation with carer',
            ],
            'near_miss' => [
                'Staff caught medication error before administration',
                'Wet floor identified and cordoned off before anyone slipped',
                'Wheelchair brake failure noticed during routine check',
                'Hot water temperature found above safe limit during testing',
                'Unsecured cleaning chemicals found in accessible cupboard',
                'Near collision between mobility scooter and pedestrian in corridor',
            ],
        ];

        $nearMissConsequences = [
            'Potential medication overdose',
            'Potential resident fall and hip fracture',
            'Potential scalding injury',
            'Potential poisoning from chemical exposure',
            'Potential collision injury',
        ];

        $severityWeights = ['low' => 50, 'medium' => 35, 'high' => 15];

        $notifiableCount = 0;
        $rows = [];

        for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) {
            $monthStart = $now->copy()->subMonths($monthsAgo)->startOfMonth();
            $monthEnd   = $now->copy()->subMonths($monthsAgo)->endOfMonth();
            if ($monthEnd->isFuture()) {
                $monthEnd = $now->copy();
            }

            $count = rand(8, 15);

            for ($i = 0; $i < $count; $i++) {
                $type     = $this->weightedRandom($typeWeights);
                $severity = $this->weightedRandom($severityWeights);
                $desc     = $descriptions[$type][array_rand($descriptions[$type])];

                $occurredAt = $this->randomDateBetween($monthStart, $monthEnd);
                $ageDays    = $now->diffInDays($occurredAt);

                // Older incidents more likely to be closed
                $statusPool = $this->resolveIncidentStatus($ageDays);
                $status     = $statusPool;

                $reportedBy = $userIds[array_rand($userIds)];
                $reviewedBy = null;
                $reviewedAt = null;
                $closedBy   = null;
                $closedAt   = null;
                $submittedAt = null;

                if (in_array($status, ['submitted', 'reviewed', 'closed'])) {
                    $submittedAt = $occurredAt->copy()->addHours(rand(1, 8));
                }
                if (in_array($status, ['reviewed', 'closed'])) {
                    $reviewedBy = $userIds[array_rand($userIds)];
                    $reviewedAt = $occurredAt->copy()->addDays(rand(1, 5));
                }
                if ($status === 'closed') {
                    $closedBy = $userIds[array_rand($userIds)];
                    $closedAt = $occurredAt->copy()->addDays(rand(5, 30));
                }

                $isNotifiable = false;
                if ($severity === 'high' && $notifiableCount < 3 && rand(1, 4) === 1) {
                    $isNotifiable = true;
                    $notifiableCount++;
                }

                $requiresFollowup = rand(1, 100) <= 30;

                $potentialSeverity    = null;
                $potentialConsequence = null;
                if ($type === 'near_miss') {
                    $potentialSeverity    = $this->weightedRandom(['medium' => 40, 'high' => 60]);
                    $potentialConsequence = $nearMissConsequences[array_rand($nearMissConsequences)];
                }

                $rows[] = [
                    'client_id'            => $clientIds[array_rand($clientIds)],
                    'reported_by'          => $reportedBy,
                    'title'                => ucfirst($type) . ' incident',
                    'type'                 => $type,
                    'severity'             => $severity,
                    'status'               => $status,
                    'occurred_at'          => $occurredAt,
                    'description'          => $desc . ' ' . self::DEMO_MARKER,
                    'is_notifiable'        => $isNotifiable,
                    'requires_followup'    => $requiresFollowup,
                    'submitted_at'         => $submittedAt,
                    'reviewed_by'          => $reviewedBy,
                    'reviewed_at'          => $reviewedAt,
                    'closed_by'            => $closedBy,
                    'closed_at'            => $closedAt,
                    'potential_severity'   => $potentialSeverity,
                    'potential_consequence' => $potentialConsequence,
                    'created_at'           => $occurredAt,
                    'updated_at'           => $closedAt ?? $reviewedAt ?? $submittedAt ?? $occurredAt,
                ];
            }
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('client_incidents')->insert($chunk);
        }

        $this->command->info('  -> ' . count($rows) . ' client incidents created.');
    }

    /* ==================================================================
     *  2. SITE HAZARDS  (~2-4 per month for 12 months)
     * ================================================================*/
    private function seedSiteHazards(array $siteIds, array $userIds, Carbon $now): void
    {
        $hazardTypes = [
            'slip_trip_fall', 'fire_electrical', 'chemical',
            'manual_handling', 'environmental', 'security', 'equipment', 'other',
        ];

        $severityWeights   = ['low' => 30, 'medium' => 40, 'high' => 20, 'critical' => 10];
        $likelihoodOptions = ['rare', 'unlikely', 'possible', 'likely', 'almost_certain'];

        $descriptions = [
            'slip_trip_fall'  => [
                'Loose carpet edge in hallway - trip hazard',
                'Water pooling near laundry entrance after rain',
                'Uneven paving on garden path to rear courtyard',
                'Worn non-slip mat at main entrance',
            ],
            'fire_electrical' => [
                'Fire exit partially blocked by storage boxes',
                'Damaged power cord on floor polisher',
                'Exit sign light not functioning in B-wing corridor',
                'Overloaded power board in staff kitchen',
            ],
            'chemical'        => [
                'Cleaning chemicals stored without MSDS in utility room',
                'Unlocked chemical storage cupboard in laundry',
                'Bleach container without proper labelling',
            ],
            'manual_handling' => [
                'Hoist sling showing signs of fraying',
                'No slide sheets available on second floor',
                'Bed too low for safe patient transfer',
            ],
            'environmental'   => [
                'Temperature in residents lounge exceeding 26 degrees',
                'Mould identified in bathroom ceiling corner',
                'Poor lighting in carpark area at night',
            ],
            'security'        => [
                'External gate latch not self-closing properly',
                'CCTV camera in reception area offline',
                'Visitor sign-in register not being completed consistently',
            ],
            'equipment'       => [
                'Call bell not functioning in Room 14',
                'Wheelchair wheel wobbling - needs replacement',
                'Shower chair rubber feet worn and slipping',
            ],
            'other'           => [
                'Wasps nest forming under eaves near dining room window',
                'Tree branch overhanging walkway after storm',
            ],
        ];

        $riskMatrix = [
            'low'      => ['rare' => 'low', 'unlikely' => 'low', 'possible' => 'low', 'likely' => 'medium', 'almost_certain' => 'medium'],
            'medium'   => ['rare' => 'low', 'unlikely' => 'medium', 'possible' => 'medium', 'likely' => 'high', 'almost_certain' => 'high'],
            'high'     => ['rare' => 'medium', 'unlikely' => 'medium', 'possible' => 'high', 'likely' => 'high', 'almost_certain' => 'extreme'],
            'critical' => ['rare' => 'medium', 'unlikely' => 'high', 'possible' => 'high', 'likely' => 'extreme', 'almost_certain' => 'extreme'],
        ];

        $rows = [];

        for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) {
            $monthStart = $now->copy()->subMonths($monthsAgo)->startOfMonth();
            $monthEnd   = $now->copy()->subMonths($monthsAgo)->endOfMonth();
            if ($monthEnd->isFuture()) {
                $monthEnd = $now->copy();
            }

            $count = rand(2, 4);

            for ($i = 0; $i < $count; $i++) {
                $hazardType = $hazardTypes[array_rand($hazardTypes)];
                $severity   = $this->weightedRandom($severityWeights);
                $likelihood = $likelihoodOptions[array_rand($likelihoodOptions)];
                $riskRating = $riskMatrix[$severity][$likelihood] ?? 'medium';

                $descOptions = $descriptions[$hazardType] ?? $descriptions['other'];
                $desc        = $descOptions[array_rand($descOptions)];

                $createdAt = $this->randomDateBetween($monthStart, $monthEnd);
                $ageDays   = $now->diffInDays($createdAt);

                // Older hazards more likely to be resolved
                if ($ageDays > 180) {
                    $status = $this->weightedRandom(['closed' => 50, 'mitigated' => 30, 'open' => 10, 'in_progress' => 10]);
                } elseif ($ageDays > 60) {
                    $status = $this->weightedRandom(['closed' => 30, 'mitigated' => 25, 'in_progress' => 25, 'open' => 20]);
                } else {
                    $status = $this->weightedRandom(['open' => 40, 'in_progress' => 30, 'mitigated' => 20, 'closed' => 10]);
                }

                $reportedBy = $userIds[array_rand($userIds)];
                $siteId     = $siteIds[array_rand($siteIds)];

                $dueDate = null;
                if (in_array($status, ['open', 'in_progress'])) {
                    // Some overdue, some upcoming
                    $dueDate = rand(0, 1)
                        ? $now->copy()->subDays(rand(1, 30))->toDateString()
                        : $now->copy()->addDays(rand(1, 30))->toDateString();
                }

                $closedAt   = null;
                $closedBy   = null;
                if (in_array($status, ['mitigated', 'closed'])) {
                    $closedAt = $createdAt->copy()->addDays(rand(3, 45));
                    $closedBy = $userIds[array_rand($userIds)];
                }

                static $hazardCounter = 0;
                $hazardCounter++;
                $rows[] = [
                    'site_id'              => $siteId,
                    'reference_number'     => 'HAZ-' . str_pad($hazardCounter + 1000, 5, '0', STR_PAD_LEFT),
                    'hazard_type'          => $hazardType,
                    'severity'             => $severity,
                    'likelihood'           => $likelihood,
                    'risk_rating'          => $riskRating,
                    'description'          => $desc . ' ' . self::DEMO_MARKER,
                    'reported_by_user_id'  => $reportedBy,
                    'assigned_to_user_id'  => $userIds[array_rand($userIds)],
                    'status'               => $status,
                    'due_date'             => $dueDate,
                    'closed_at'            => $closedAt,
                    'closed_by_user_id'    => $closedBy,
                    'created_at'           => $createdAt,
                    'updated_at'           => $closedAt ?? $createdAt,
                ];
            }
        }

        DB::table('site_hazards')->insert($rows);
        $this->command->info('  -> ' . count($rows) . ' site hazards created.');
    }

    /* ==================================================================
     *  3. EMERGENCY DRILLS  (2-3 per site over last 12 months)
     * ================================================================*/
    private function seedEmergencyDrills(array $siteIds, array $userIds, Carbon $now): void
    {
        $drillTypes = [
            'fire_evacuation'    => 60,
            'earthquake'         => 25,
            'medical_emergency'  => 15,
        ];

        $scenarios = [
            'fire_evacuation'   => [
                'Simulated kitchen fire requiring full building evacuation',
                'Fire alarm activated in laundry - full evacuation drill',
                'Night shift fire evacuation scenario - reduced staffing',
            ],
            'earthquake'        => [
                'Earthquake drop, cover, hold drill with post-quake evacuation',
                'Simulated moderate earthquake during meal service',
            ],
            'medical_emergency' => [
                'Simulated cardiac arrest in communal lounge',
                'Choking response drill during lunch service',
                'Anaphylaxis response scenario in medication room',
            ],
        ];

        $rows = [];

        foreach ($siteIds as $siteId) {
            $drillCount = rand(2, 3);

            for ($i = 0; $i < $drillCount; $i++) {
                $drillType = $this->weightedRandom($drillTypes);
                $scenarioOptions = $scenarios[$drillType];
                $scenario  = $scenarioOptions[array_rand($scenarioOptions)];

                // Spread drills across the last 12 months
                $scheduledAt = $now->copy()->subDays(rand(1, 365));

                $isCompleted = $scheduledAt->isPast() && rand(1, 10) <= 8;
                $isFuture    = $scheduledAt->isFuture();

                $status           = $isFuture ? 'scheduled' : ($isCompleted ? 'completed' : 'scheduled');
                $startedAt        = null;
                $completedAt      = null;
                $durationMinutes  = null;
                $evacTime         = null;
                $outcome          = null;
                $participants     = null;
                $residentsEvac    = null;
                $allAreas         = false;
                $assemblyReached  = false;
                $rollCall         = false;
                $conductedBy      = null;
                $observerNotes    = null;
                $improvements     = null;

                if ($status === 'completed') {
                    $startedAt       = $scheduledAt->copy();
                    $durationMinutes = rand(5, 20);
                    $completedAt     = $startedAt->copy()->addMinutes($durationMinutes);
                    $evacTime        = rand(120, 600);
                    $outcome         = rand(1, 3) <= 2 ? 'satisfactory' : 'needs_improvement';
                    $participants    = rand(5, 25);
                    $residentsEvac   = rand(3, 18);
                    $allAreas        = (bool) rand(0, 1);
                    $assemblyReached = true;
                    $rollCall        = (bool) rand(0, 1);
                    $conductedBy     = $userIds[array_rand($userIds)];

                    $observerNotes = $outcome === 'satisfactory'
                        ? 'Staff responded well. All residents accounted for within target time.'
                        : 'Some delays in B-wing evacuation. Staff need refresher on assembly procedures.';

                    $improvements = $outcome === 'needs_improvement'
                        ? 'Review night-shift evacuation roles; update signage in B-wing corridor'
                        : null;
                }

                $title = ucfirst(str_replace('_', ' ', $drillType)) . ' drill - ' . $scheduledAt->format('M Y');

                $rows[] = [
                    'site_id'                 => $siteId,
                    'drill_type'              => $drillType,
                    'title'                   => $title,
                    'description'             => $scenario . ' ' . self::DEMO_MARKER,
                    'scenario_description'    => $scenario,
                    'scheduled_at'            => $scheduledAt,
                    'started_at'              => $startedAt,
                    'completed_at'            => $completedAt,
                    'duration_minutes'        => $durationMinutes,
                    'evacuation_time_seconds' => $evacTime,
                    'status'                  => $status,
                    'outcome'                 => $outcome,
                    'total_participants'      => $participants,
                    'residents_evacuated'     => $residentsEvac,
                    'all_areas_checked'       => $allAreas,
                    'assembly_point_reached'  => $assemblyReached,
                    'roll_call_completed'     => $rollCall,
                    'observer_notes'          => $observerNotes,
                    'improvements_identified' => $improvements,
                    'conducted_by'            => $conductedBy,
                    'created_by'              => $userIds[array_rand($userIds)],
                    'created_at'              => $scheduledAt->copy()->subDays(rand(1, 14)),
                    'updated_at'              => $completedAt ?? $scheduledAt,
                ];
            }
        }

        DB::table('emergency_drills')->insert($rows);
        $this->command->info('  -> ' . count($rows) . ' emergency drills created.');
    }

    /* ==================================================================
     *  4. WORKPLACE INJURIES  (3-5 total over 12 months)
     * ================================================================*/
    private function seedWorkplaceInjuries(array $userIds, array $siteIds, Carbon $now): void
    {
        $injuryTypes = ['musculoskeletal', 'slip_trip_fall', 'manual_handling', 'needle_stick'];

        $bodyParts = [
            'musculoskeletal' => ['lower back', 'shoulder', 'neck', 'wrist'],
            'slip_trip_fall'  => ['knee', 'ankle', 'wrist', 'hip'],
            'manual_handling' => ['lower back', 'shoulder', 'upper back'],
            'needle_stick'    => ['left hand', 'right hand', 'finger'],
        ];

        $descriptions = [
            'musculoskeletal' => [
                'Staff member strained lower back while repositioning resident in bed',
                'Shoulder injury from repetitive overhead reaching in storage area',
                'Wrist strain from prolonged documentation at workstation',
            ],
            'slip_trip_fall'  => [
                'Slipped on wet floor in residents bathroom during morning care',
                'Tripped over equipment cord in corridor',
                'Fell on wet path outside during rubbish collection',
            ],
            'manual_handling' => [
                'Back injury while assisting resident from floor after fall',
                'Shoulder strain from transferring resident using hoist',
                'Upper back pain after manually pushing heavy laundry trolley',
            ],
            'needle_stick'    => [
                'Needle stick injury during blood glucose monitoring',
                'Pricked finger while disposing of insulin needle',
            ],
        ];

        $treatments = [
            'musculoskeletal' => 'Physiotherapy referral and modified duties arranged',
            'slip_trip_fall'  => 'First aid administered on site, GP visit arranged',
            'manual_handling' => 'Ice applied, rest recommended, ACC claim lodged',
            'needle_stick'    => 'Wound cleaned, baseline bloods taken, occupational health referral',
        ];

        $count = rand(3, 5);
        $rows  = [];

        for ($i = 0; $i < $count; $i++) {
            $injuryType    = $injuryTypes[array_rand($injuryTypes)];
            $bodyPartOpts  = $bodyParts[$injuryType];
            $bodyPart      = $bodyPartOpts[array_rand($bodyPartOpts)];
            $descOpts      = $descriptions[$injuryType];
            $desc          = $descOpts[array_rand($descOpts)];
            $severity      = $this->weightedRandom(['minor' => 50, 'moderate' => 35, 'serious' => 15]);

            $injuryDate    = $now->copy()->subDays(rand(5, 360));
            $lostTimeDays  = ($severity !== 'minor' && rand(0, 1)) ? rand(1, 10) : 0;
            $accClaim      = $lostTimeDays > 0 || $severity === 'serious';

            $status = $injuryDate->diffInDays($now) > 60
                ? 'closed'
                : ($injuryDate->diffInDays($now) > 14 ? 'recovering' : 'active');

            $expectedReturn = $lostTimeDays > 0
                ? $injuryDate->copy()->addDays($lostTimeDays + rand(0, 5))->toDateString()
                : null;

            $actualReturn = ($status === 'closed' && $expectedReturn)
                ? Carbon::parse($expectedReturn)->addDays(rand(-2, 3))->toDateString()
                : null;

            $rows[] = [
                'user_id'              => $userIds[array_rand($userIds)],
                'site_id'              => $siteIds[array_rand($siteIds)],
                'injury_date'          => $injuryDate,
                'injury_type'          => $injuryType,
                'body_part_affected'   => $bodyPart,
                'severity'             => $severity,
                'description'          => $desc . ' ' . self::DEMO_MARKER,
                'immediate_treatment'  => $treatments[$injuryType],
                'worksafe_notifiable'  => $severity === 'serious',
                'acc_claim_lodged'     => $accClaim,
                'acc_claim_number'     => $accClaim ? 'ACC-' . rand(100000, 999999) : null,
                'lost_time_days'       => $lostTimeDays,
                'expected_return_date' => $expectedReturn,
                'actual_return_date'   => $actualReturn,
                'status'               => $status,
                'notes'                => null,
                'created_by'           => $userIds[array_rand($userIds)],
                'created_at'           => $injuryDate,
                'updated_at'           => $now,
            ];
        }

        DB::table('workplace_injuries')->insert($rows);
        $this->command->info('  -> ' . count($rows) . ' workplace injuries created.');
    }

    /* ==================================================================
     *  5. FLEET INCIDENTS  (2-4 total over 12 months)
     * ================================================================*/
    private function seedFleetIncidents(array $assetIds, array $userIds, Carbon $now): void
    {
        if (empty($assetIds)) {
            $this->command->warn('  -> No assets found. Skipping fleet incidents.');
            return;
        }

        $incidentTypes = ['collision', 'damage', 'near_miss', 'breakdown'];

        $descriptions = [
            'collision'  => [
                'Minor collision with bollard while reversing in supermarket car park',
                'Low-speed rear-end collision at intersection during client transport',
                'Side-swipe while exiting narrow driveway at residential address',
            ],
            'damage'     => [
                'Windscreen cracked by stone on motorway',
                'Side mirror damaged by passing vehicle while parked on street',
                'Scratch along passenger side from overhanging branch on rural road',
            ],
            'near_miss'  => [
                'Near collision when other vehicle ran red light at intersection',
                'Avoided pedestrian who stepped onto road without looking',
                'Close call with cyclist in shared lane near town centre',
            ],
            'breakdown'  => [
                'Vehicle overheated on route to client - roadside assistance called',
                'Flat tyre on highway during client appointment run',
                'Battery failure in car park - required jump start',
            ],
        ];

        $locations = [
            'Henderson, Auckland',
            'Newmarket, Auckland',
            'Papakura, Auckland',
            'Hamilton CBD',
            'Tauranga, Bay of Plenty',
            'Christchurch Central',
            'Wellington CBD',
        ];

        $count = rand(2, 4);
        $rows  = [];

        for ($i = 0; $i < $count; $i++) {
            $type      = $incidentTypes[array_rand($incidentTypes)];
            $severity  = $this->weightedRandom(['minor' => 60, 'moderate' => 30, 'major' => 10]);
            $descOpts  = $descriptions[$type];
            $desc      = $descOpts[array_rand($descOpts)];
            $occurredAt = $now->copy()->subDays(rand(5, 360));

            $policeNotified = in_array($type, ['collision']) && $severity !== 'minor';
            $resolved       = $occurredAt->diffInDays($now) > 30;

            $rows[] = [
                'asset_id'            => $assetIds[array_rand($assetIds)],
                'reported_by_user_id' => $userIds[array_rand($userIds)],
                'driver_user_id'      => $userIds[array_rand($userIds)],
                'incident_type'       => $type,
                'severity'            => $severity,
                'occurred_at'         => $occurredAt,
                'location'            => $locations[array_rand($locations)],
                'description'         => $desc . ' ' . self::DEMO_MARKER,
                'police_notified'     => $policeNotified,
                'police_reference'    => $policeNotified ? 'POL-' . rand(100000, 999999) : null,
                'insurance_claimed'   => $severity !== 'minor' && rand(0, 1),
                'status'              => $resolved ? 'resolved' : 'open',
                'resolved_at'         => $resolved ? $occurredAt->copy()->addDays(rand(5, 25)) : null,
                'created_at'          => $occurredAt,
                'updated_at'          => $now,
            ];
        }

        DB::table('fleet_incidents')->insert($rows);
        $this->command->info('  -> ' . count($rows) . ' fleet incidents created.');
    }

    /* ==================================================================
     *  Helpers
     * ================================================================*/

    /**
     * Pick a random key from an associative array where values are relative weights.
     */
    private function weightedRandom(array $weights): string
    {
        $total  = array_sum($weights);
        $roll   = rand(1, $total);
        $cumulative = 0;

        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    /**
     * Generate a random Carbon datetime between two bounds.
     */
    private function randomDateBetween(Carbon $start, Carbon $end): Carbon
    {
        $diffSeconds = max(1, $end->diffInSeconds($start));

        return $start->copy()->addSeconds(rand(0, $diffSeconds));
    }

    /**
     * Determine incident status based on age (older = more likely closed).
     */
    private function resolveIncidentStatus(int $ageDays): string
    {
        if ($ageDays > 180) {
            return $this->weightedRandom(['closed' => 60, 'reviewed' => 20, 'submitted' => 15, 'draft' => 5]);
        }

        if ($ageDays > 60) {
            return $this->weightedRandom(['closed' => 40, 'reviewed' => 25, 'submitted' => 25, 'draft' => 10]);
        }

        if ($ageDays > 14) {
            return $this->weightedRandom(['submitted' => 35, 'reviewed' => 30, 'closed' => 20, 'draft' => 15]);
        }

        return $this->weightedRandom(['draft' => 40, 'submitted' => 35, 'reviewed' => 15, 'closed' => 10]);
    }
}
