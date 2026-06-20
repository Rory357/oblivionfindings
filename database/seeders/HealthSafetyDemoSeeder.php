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
            try {
                $this->command->info('Seeding Fleet Incidents...');
                $this->seedFleetIncidents($assetIds, $userIds, $now);
            } catch (\Throwable $e) {
                $this->command->warn('Fleet Incidents skipped: ' . $e->getMessage());
            }
        } else {
            $this->command->info('Fleet Incidents already seeded, skipping.');
        }

        // ── 6. Worker Participation ─────────────────────────────────
        if (!DB::table('hs_committee_meetings')->exists()) {
            $this->command->info('Seeding Worker Participation (meetings + consultations)...');
            $this->seedWorkerParticipation($siteIds, $userIds, $now);
        } else {
            $this->command->info('Worker Participation already seeded, skipping.');
        }

        // ── 7. Governance backbone (investigations + corrective actions) ──
        if (!DB::table('hs_corrective_actions')->where('description', 'like', '%' . self::DEMO_MARKER . '%')->exists()) {
            $this->command->info('Seeding Governance backbone (investigations + corrective actions)...');
            $this->seedGovernanceBacklog($userIds, $now);
        } else {
            $this->command->info('Governance backbone already seeded, skipping.');
        }

        // ── 8. Lone Worker Safety (sessions + check-ins + legacy alerts) ──
        if (! DB::table('lone_worker_sessions')->where('activity_description', 'like', '%' . self::DEMO_MARKER . '%')->exists()) {
            $this->command->info('Seeding Lone Worker sessions...');
            $this->seedLoneWorkers($userIds, $siteIds, $clientIds, $now);
        } else {
            $this->command->info('Lone Worker sessions already seeded, skipping.');
        }

        // ── 9. Safe Work Procedures (controlled SWMS document library) ──
        if (! DB::table('safe_work_procedures')->where('purpose', 'like', '%'.self::DEMO_MARKER.'%')->exists()) {
            $this->command->info('Seeding Safe Work Procedures...');
            $this->seedSafeWorkProcedures($siteIds, $userIds, $now);
        } else {
            $this->command->info('Safe Work Procedures already seeded, skipping.');
        }

        $this->command->info('Health & Safety demo data seeded successfully.');
    }

    /* ==================================================================
     *  Safe Work Procedures — controlled SWMS document library demo data
     * ================================================================*/

    private function seedSafeWorkProcedures(array $siteIds, array $userIds, Carbon $now): void
    {
        $owner = fn () => $userIds[array_rand($userIds)];
        $someSites = fn () => count($siteIds) > 1 ? array_slice($siteIds, 0, 2) : $siteIds;

        // [reference, title, category, status, reviewOffsetDays|null, versions, roles, ppe, hazards, steps, sites?]
        $rows = [
            ['SWP-001', 'Safe Manual Handling & Client Transfers', 'manual_handling', 'approved', 165, 3,
                ['support_worker', 'team_lead'], ['Gloves', 'Hoist sling', 'Slide sheet'], ['Musculoskeletal injury', 'Slips, trips & falls'],
                [['Assess the client and the environment before any transfer.', 'Never lift more than you safely can — use the hoist.'], ['Position the hoist and check the sling rating.', 'Confirm the sling is the correct size and undamaged.'], ['Complete the transfer with a second worker where the care plan requires it.', 'Stop if the client shows distress.']]],
            ['SWP-002', 'Responding to Behaviours of Concern (PBS)', 'challenging_behaviour', 'approved', -12, 2,
                ['support_worker', 'team_lead'], ['None'], ['Aggression / challenging behaviour', 'Working alone'],
                [['Use the client\'s positive behaviour support plan first.', 'Keep yourself and others at a safe distance.'], ['De-escalate using known triggers and calming strategies.', 'Do not restrain unless trained and it is a last resort.']]],
            ['SWP-003', 'Lone & Community Working', 'lone_working', 'under_review', null, 1,
                ['support_worker'], ['Mobile phone', 'Hi-vis vest'], ['Working alone', 'Slips, trips & falls'],
                [['Log your visit and expected return in the lone-worker system.', 'Check in at the agreed interval.']]],
            ['SWP-004', 'Medication Administration & eMAR', 'medication', 'approved', 20, 2,
                ['support_worker', 'team_lead'], ['Gloves'], ['Medication error'],
                [['Confirm the six rights before administering.', 'Witness controlled drugs with a second signatory.'], ['Record administration on the eMAR immediately.', 'Report any error or omission without delay.']]],
            ['SWP-005', 'Infection Prevention & Control', 'infection_control', 'approved', 240, 1,
                ['support_worker'], ['Gloves', 'Apron', 'Face mask'], ['Exposure to bodily fluids'],
                [['Perform hand hygiene before and after every contact.', 'Use PPE appropriate to the task.'], ['Dispose of clinical waste in the correct stream.', null]]],
            ['SWP-006', 'Fire Evacuation & Assembly', 'fire_safety', 'approved', 300, 1,
                ['support_worker', 'team_lead'], ['None'], ['Burns / scalds'],
                [['On discovering a fire, raise the alarm and call 111.', 'Do not tackle the fire unless it is safe to do so.'], ['Evacuate clients via the nearest safe exit to the assembly point.', 'Account for everyone and report to the warden.']]],
            ['SWP-007', 'Epilepsy & Seizure Response', 'emergency_procedures', 'draft', null, 1,
                ['support_worker'], ['Gloves'], ['Slips, trips & falls'],
                [['Protect the person from injury and time the seizure.', 'Do not put anything in their mouth.'], ['Administer rescue medication only as prescribed.', 'Call 111 if the seizure lasts longer than 5 minutes.']]],
            ['SWP-008', 'Hoist & Mobility Equipment Use', 'equipment_use', 'approved', 90, 1,
                ['support_worker', 'team_lead'], ['Hoist sling'], ['Musculoskeletal injury'],
                [['Inspect the hoist and sling before every use.', 'Check the LOLER inspection is in date.'], ['Operate within the safe working load.', null]]],
            ['SWP-009', 'Personal & Intimate Care', 'personal_care', 'draft', null, 1,
                ['support_worker'], ['Gloves', 'Apron'], ['Exposure to bodily fluids'],
                [['Maintain the client\'s dignity and consent throughout.', 'Follow the personal care plan.']]],
        ];

        foreach ($rows as $r) {
            [$ref, $title, $category, $status, $reviewOffset, $versionCount, $roles, $ppe, $hazards, $stepPairs] = $r;
            $approved = $status === 'approved';
            $ownerId = $owner();
            $steps = [];
            foreach ($stepPairs as $i => $pair) {
                $steps[] = ['step_number' => $i + 1, 'description' => $pair[0], 'safety_notes' => $pair[1] ?? ''];
            }

            $procedureId = DB::table('safe_work_procedures')->insertGetId([
                'title' => $title,
                'reference_number' => $ref,
                'category' => $category,
                'purpose' => 'Keep workers and clients safe during '.strtolower($title).'. '.self::DEMO_MARKER,
                'scope' => 'All supported-living settings where this activity is carried out.',
                'hazards_addressed' => json_encode($hazards),
                'ppe_required' => json_encode($ppe),
                'steps' => json_encode($steps),
                'emergency_procedures' => json_encode(['Call 111 for any life-threatening emergency.', 'Notify the on-call coordinator and record an incident.']),
                'status' => $status,
                'current_version' => $versionCount,
                'approved_by' => $approved ? $owner() : null,
                'approved_at' => $approved ? $now->copy()->subDays(30) : null,
                'owner_id' => $ownerId,
                'review_date' => $reviewOffset !== null ? $now->copy()->addDays($reviewOffset)->toDateString() : null,
                'review_frequency_months' => 12,
                'applicable_roles' => json_encode($roles),
                'applicable_sites' => json_encode($someSites()),
                'related_training' => json_encode([]),
                'created_by' => $ownerId,
                'updated_by' => $ownerId,
                'created_at' => $now->copy()->subDays(120),
                'updated_at' => $now,
            ]);

            for ($v = 1; $v <= $versionCount; $v++) {
                DB::table('safe_work_procedure_versions')->insert([
                    'safe_work_procedure_id' => $procedureId,
                    'version_number' => $v,
                    'content_snapshot' => json_encode(['title' => $title, 'version' => $v]),
                    'change_summary' => $v === 1 ? 'Initial version' : 'Reviewed and updated for v'.$v.'.',
                    'changed_by' => $ownerId,
                    'created_at' => $now->copy()->subDays(120 - $v * 20),
                    'updated_at' => $now->copy()->subDays(120 - $v * 20),
                ]);
            }
        }
    }

    /* ==================================================================
     *  Lone Worker Safety — live monitoring register demo data
     * ================================================================*/
    private function seedLoneWorkers(array $userIds, array $siteIds, array $clientIds, Carbon $now): void
    {
        // NZ field locations (Bay of Plenty / Waikato) for the last-known-location map.
        $coords = [
            ['Tauranga community visit', -37.6878, 176.1651],
            ['Hamilton home support', -37.7870, 175.2793],
            ['Rotorua site lock-up', -38.1368, 176.2497],
            ['Mount Maunganui welfare check', -37.6406, 176.1849],
        ];

        $mk = function (string $status, int $startedMinsAgo, int $expectedMins, ?int $lastCheckinMinsAgo, int $interval, array $coord, ?Carbon $endedAt = null, ?Carbon $emergencyAt = null) use ($userIds, $siteIds, $clientIds, $now): array {
            $started = $now->copy()->subMinutes($startedMinsAgo);

            return [
                'user_id' => $userIds[array_rand($userIds)],
                'site_id' => $siteIds[array_rand($siteIds)],
                'client_id' => (! empty($clientIds) && rand(0, 1) === 1) ? $clientIds[array_rand($clientIds)] : null,
                'shift_id' => null,
                'started_at' => $started,
                'expected_end_at' => $started->copy()->addMinutes($expectedMins),
                'ended_at' => $endedAt,
                'location' => $coord[0],
                'location_lat' => $coord[1],
                'location_lng' => $coord[2],
                'activity_description' => $coord[0] . ' ' . self::DEMO_MARKER,
                'check_in_interval_minutes' => $interval,
                'last_check_in_at' => $lastCheckinMinsAgo !== null ? $now->copy()->subMinutes($lastCheckinMinsAgo) : $started,
                'status' => $status,
                'emergency_triggered_at' => $emergencyAt,
                'emergency_notes' => $emergencyAt ? ('No response to welfare call ' . self::DEMO_MARKER) : null,
                'created_by' => $userIds[array_rand($userIds)],
                'updated_by' => $userIds[array_rand($userIds)],
                'created_at' => $started,
                'updated_at' => $now,
            ];
        };

        $rows = [
            $mk('active', 95, 240, 12, 30, $coords[0]),
            $mk('active', 50, 180, 8, 60, $coords[1]),
            $mk('overdue', 200, 180, 75, 30, $coords[2]),
            $mk('emergency', 40, 180, 35, 30, $coords[3], null, $now->copy()->subMinutes(9)),
            $mk('completed', 1500, 240, 1260, 60, $coords[0], $now->copy()->subMinutes(1260)),
            $mk('completed', 1600, 240, 1380, 60, $coords[1], $now->copy()->subMinutes(1370)),
        ];

        foreach ($rows as $row) {
            $sessionId = DB::table('lone_worker_sessions')->insertGetId($row);

            $checkIns = [[
                'lone_worker_session_id' => $sessionId,
                'checked_in_at' => $row['started_at'],
                'location_lat' => $row['location_lat'],
                'location_lng' => $row['location_lng'],
                'status' => 'ok',
                'notes' => 'Arrived on site ' . self::DEMO_MARKER,
                'created_at' => $row['started_at'],
                'updated_at' => $row['started_at'],
            ]];

            if (in_array($row['status'], ['active', 'completed'], true)) {
                $mid = $row['started_at']->copy()->addMinutes(30);
                $checkIns[] = [
                    'lone_worker_session_id' => $sessionId,
                    'checked_in_at' => $mid,
                    'location_lat' => $row['location_lat'],
                    'location_lng' => $row['location_lng'],
                    'status' => 'ok',
                    'notes' => null,
                    'created_at' => $mid,
                    'updated_at' => $mid,
                ];
            }
            if ($row['status'] === 'emergency') {
                $checkIns[] = [
                    'lone_worker_session_id' => $sessionId,
                    'checked_in_at' => $row['emergency_triggered_at'],
                    'location_lat' => $row['location_lat'],
                    'location_lng' => $row['location_lng'],
                    'status' => 'emergency',
                    'notes' => 'Emergency raised ' . self::DEMO_MARKER,
                    'created_at' => $row['emergency_triggered_at'],
                    'updated_at' => $row['emergency_triggered_at'],
                ];
            }
            DB::table('lone_worker_check_ins')->insert($checkIns);

            if ($row['status'] === 'overdue') {
                DB::table('lone_worker_alerts')->insert([
                    'lone_worker_session_id' => $sessionId,
                    'alert_type' => 'overdue_check_in',
                    'triggered_at' => $now->copy()->subMinutes(45),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if ($row['status'] === 'emergency') {
                DB::table('lone_worker_alerts')->insert([
                    'lone_worker_session_id' => $sessionId,
                    'alert_type' => 'emergency',
                    'triggered_at' => $row['emergency_triggered_at'],
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
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

            // Canonical injury lifecycle: reported → under_treatment → return_to_work → recovered → closed.
            $ageDays = $injuryDate->diffInDays($now);
            $status = match (true) {
                $ageDays > 90 => 'closed',
                $ageDays > 60 => 'recovered',
                $ageDays > 30 => 'return_to_work',
                $ageDays > 14 => 'under_treatment',
                default => 'reported',
            };

            $expectedReturn = $lostTimeDays > 0
                ? $injuryDate->copy()->addDays($lostTimeDays + rand(0, 5))->toDateString()
                : null;

            $actualReturn = (in_array($status, ['closed', 'recovered'], true) && $expectedReturn)
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

    /* ==================================================================
     *  6. WORKER PARTICIPATION (committees, reps, meetings, consultations)
     * ================================================================== */

    private function seedWorkerParticipation(array $siteIds, array $userIds, Carbon $now): void
    {
        // Pick some staff as H&S reps
        $repUserIds = array_slice($userIds, 0, min(4, count($userIds)));

        // ── H&S Representatives (skip if exist) ──
        if (DB::table('hs_representatives')->count() > 0) {
            $this->command->info('  -> H&S representatives already exist, skipping.');
        } else {
        $reps = [];
        $methods = ['elected', 'appointed', 'volunteered'];
        foreach ($repUserIds as $i => $userId) {
            $electedAt = $now->copy()->subMonths(rand(3, 12));
            $reps[] = [
                'user_id'                      => $userId,
                'site_id'                      => $siteIds[$i % count($siteIds)],
                'work_group'                   => ['Day Shift', 'Night Shift', 'Community Support', 'Kitchen/Facilities'][$i % 4],
                'election_method'              => $methods[array_rand($methods)],
                'elected_at'                   => $electedAt->toDateString(),
                'term_expires_at'              => $electedAt->copy()->addYears(2)->toDateString(),
                'status'                       => 'active',
                'training_days_completed'      => rand(0, 5),
                'initial_training_completed_at' => rand(0, 1) ? $electedAt->copy()->addDays(rand(14, 60))->toDateString() : null,
                'notes'                        => null,
                'created_by'                   => $userIds[array_rand($userIds)],
                'created_at'                   => $electedAt,
                'updated_at'                   => $electedAt,
            ];
        }
        DB::table('hs_representatives')->insert($reps);
        $this->command->info('  -> ' . count($reps) . ' H&S representatives created.');
        } // end reps skip check

        // ── Committees (skip if exist) ──
        $committeeIds = DB::table('hs_committees')->pluck('id')->toArray();
        if (count($committeeIds) > 0) {
            $this->command->info('  -> Committees already exist (' . count($committeeIds) . '), skipping creation.');
        } else {
        $committees = [];
        $committeeNames = ['Health & Safety Committee - Kauri House', 'Health & Safety Committee - Harbour Respite'];
        foreach ($committeeNames as $i => $name) {
            $established = $now->copy()->subMonths(rand(6, 18));
            $members = array_map(fn ($uid) => [
                'user_id' => $uid,
                'role'    => $uid === $repUserIds[0] ? 'chair' : ($uid === $repUserIds[1] ? 'secretary' : (rand(0, 1) ? 'worker_rep' : 'employer_rep')),
                'joined_at' => $established->toDateString(),
            ], array_slice($userIds, 0, min(6, count($userIds))));

            $committees[] = [
                'name'               => $name . ' ' . self::DEMO_MARKER,
                'site_id'            => $siteIds[$i % count($siteIds)],
                'meeting_frequency'  => 'monthly',
                'terms_of_reference' => 'Review workplace hazards, investigate incidents, recommend improvements, monitor H&S compliance, ensure worker engagement per HSWA 2015.',
                'established_at'     => $established->toDateString(),
                'status'             => 'active',
                'members'            => json_encode($members),
                'created_by'         => $userIds[0],
                'created_at'         => $established,
                'updated_at'         => $established,
            ];
        }
        DB::table('hs_committees')->insert($committees);
        $committeeIds = DB::table('hs_committees')->pluck('id')->toArray();
        $this->command->info('  -> ' . count($committees) . ' committees created.');
        } // end committees skip check

        // ── Committee Meetings ──
        $meetings = [];
        $agendaTemplates = [
            [['title' => 'Review of open hazards', 'notes' => 'Walk through current hazard register'], ['title' => 'Incident review', 'notes' => 'Review incidents since last meeting'], ['title' => 'Training update', 'notes' => 'Staff training compliance status']],
            [['title' => 'Emergency drill debrief', 'notes' => 'Discuss outcomes from recent fire drill'], ['title' => 'PPE audit results', 'notes' => 'Review PPE condition and replacements needed'], ['title' => 'Policy review', 'notes' => 'Manual handling procedure update']],
            [['title' => 'Workplace inspection findings', 'notes' => 'Monthly inspection walk-through results'], ['title' => 'Worker feedback', 'notes' => 'Concerns raised by staff'], ['title' => 'Action items follow-up', 'notes' => 'Review previous meeting actions']],
        ];

        foreach ($committeeIds as $cId) {
            // 3-4 meetings per committee over last 6 months
            for ($m = 0; $m < rand(3, 4); $m++) {
                $scheduledAt = $now->copy()->subMonths($m + 1)->addDays(rand(1, 10));
                $isCompleted = $scheduledAt->lt($now);
                $agenda = $agendaTemplates[array_rand($agendaTemplates)];
                $attendeeList = array_slice($userIds, 0, rand(4, 8));

                $actionItems = $isCompleted ? [
                    ['description' => 'Replace worn floor mats in B-wing corridor', 'assigned_to' => $userIds[array_rand($userIds)], 'due_date' => $scheduledAt->copy()->addDays(14)->toDateString(), 'status' => rand(0, 1) ? 'completed' : 'open'],
                    ['description' => 'Schedule refresher training for manual handling', 'assigned_to' => $userIds[array_rand($userIds)], 'due_date' => $scheduledAt->copy()->addDays(30)->toDateString(), 'status' => 'open'],
                ] : null;

                $meetings[] = [
                    'hs_committee_id'        => $cId,
                    'scheduled_at'           => $scheduledAt,
                    'started_at'             => $isCompleted ? $scheduledAt : null,
                    'ended_at'               => $isCompleted ? $scheduledAt->copy()->addMinutes(rand(45, 90)) : null,
                    'location'               => ['Kauri House - Meeting Room', 'Harbour Respite - Staff Room', 'Main Office - Board Room'][rand(0, 2)],
                    'status'                 => $isCompleted ? 'completed' : 'scheduled',
                    'attendees'              => json_encode($attendeeList),
                    'confirmed_attendees'    => $isCompleted ? json_encode(array_map(fn ($uid) => ['user_id' => $uid, 'confirmed' => (bool) rand(0, 1), 'confirmed_at' => $scheduledAt->toIso8601String()], $attendeeList)) : null,
                    'agenda_items'           => json_encode($agenda),
                    'minutes'                => $isCompleted ? "Meeting commenced at {$scheduledAt->format('g:i A')}. All agenda items were discussed. Key concerns raised about slip hazards in wet weather. Action items assigned. Meeting closed." : null,
                    'action_items'           => $isCompleted ? json_encode($actionItems) : null,
                    'safety_concerns_raised' => $isCompleted ? 'Wet weather creating slip hazards at main entrance. Night shift staffing concerns affecting lone worker safety.' : null,
                    'recorded_by'            => $isCompleted ? $userIds[array_rand($userIds)] : null,
                    'created_by'             => $userIds[0],
                    'created_at'             => $scheduledAt->copy()->subDays(7),
                    'updated_at'             => $isCompleted ? $scheduledAt : $scheduledAt->copy()->subDays(7),
                ];
            }
        }
        // One future scheduled meeting
        $futureMeeting = $now->copy()->addDays(rand(7, 21));
        $meetings[] = [
            'hs_committee_id'        => $committeeIds[0],
            'scheduled_at'           => $futureMeeting,
            'started_at'             => null,
            'ended_at'               => null,
            'location'               => 'Kauri House - Meeting Room',
            'status'                 => 'scheduled',
            'attendees'              => json_encode(array_slice($userIds, 0, 6)),
            'confirmed_attendees'    => null,
            'agenda_items'           => json_encode($agendaTemplates[0]),
            'minutes'                => null,
            'action_items'           => null,
            'safety_concerns_raised' => null,
            'recorded_by'            => null,
            'created_by'             => $userIds[0],
            'created_at'             => $now,
            'updated_at'             => $now,
        ];
        DB::table('hs_committee_meetings')->insert($meetings);
        $this->command->info('  -> ' . count($meetings) . ' committee meetings created.');

        // ── Consultations ──
        $consultations = [];
        $consultationData = [
            ['title' => 'Manual Handling Procedure Update', 'type' => 'procedure_change', 'desc' => 'Consultation with staff on proposed changes to manual handling procedures following incident review. New two-person lift policy for residents over 80kg.', 'status' => 'closed', 'feedback' => 'Staff generally supportive. Concerns about time impact on shift schedules. Suggested additional training sessions.', 'outcome' => 'Procedure updated and approved. Training sessions scheduled for all care staff over next 4 weeks.', 'changes' => 'Two-person lift threshold lowered from 100kg to 80kg. New hoist training mandatory. Shift handover updated to include mobility status.'],
            ['title' => 'New Chemical Storage Proposal', 'type' => 'hazard_identified', 'desc' => 'Consulting workers on relocating chemical storage from laundry to dedicated locked cupboard in utility room. HSNO compliance requirement.', 'status' => 'actioned', 'feedback' => 'Laundry staff agree current location is unsuitable. Suggested lockable wall cabinet with SDS holder.', 'outcome' => 'Wall cabinet ordered and installation scheduled. SDS folder to be mounted alongside.', 'changes' => null],
            ['title' => 'Night Shift Lone Worker Policy', 'type' => 'policy_change', 'desc' => 'Proposed new check-in procedure for lone workers on night shift. Includes 30-minute check-in intervals and duress alarm trial.', 'status' => 'feedback_received', 'feedback' => 'Night staff prefer 60-minute intervals as 30 is too frequent. Support duress alarm trial. Want training on the app.', 'outcome' => null, 'changes' => null],
            ['title' => 'New Hoist Equipment Assessment', 'type' => 'equipment_change', 'desc' => 'Consulting care staff on proposed replacement of ceiling hoists in bedrooms 4-8. New model includes electronic controls and weight display.', 'status' => 'open', 'feedback' => null, 'outcome' => null, 'changes' => null],
        ];

        foreach ($consultationData as $i => $cd) {
            $createdAt = $now->copy()->subMonths(4 - $i)->addDays(rand(1, 15));
            $consultations[] = [
                'title'                    => $cd['title'],
                'consultation_type'        => $cd['type'],
                'description'              => $cd['desc'] . ' ' . self::DEMO_MARKER,
                'site_id'                  => $siteIds[$i % count($siteIds)],
                'initiated_by'             => $userIds[array_rand($userIds)],
                'consultation_date'        => $createdAt->toDateString(),
                'workers_consulted'        => json_encode(array_slice($userIds, 0, rand(3, 8))),
                'worker_feedback_summary'  => $cd['feedback'],
                'outcome'                  => $cd['outcome'],
                'changes_made'             => $cd['changes'],
                'status'                   => $cd['status'],
                'created_by'               => $userIds[0],
                'updated_by'               => $cd['status'] !== 'open' ? $userIds[array_rand($userIds)] : null,
                'created_at'               => $createdAt,
                'updated_at'               => $cd['status'] !== 'open' ? $createdAt->copy()->addDays(rand(7, 30)) : $createdAt,
            ];
        }
        DB::table('hs_consultations')->insert($consultations);
        $this->command->info('  -> ' . count($consultations) . ' consultations created.');
    }

    /* ==================================================================
     *  7. GOVERNANCE BACKBONE  (investigations + corrective actions)
     *
     *  Powers the Overview "Overdue corrective actions" and the Lagging
     *  "Open investigations" worklists, which read HsCorrectiveAction::overdue()
     *  and HsInvestigation::active() linked to the HsEvent backbone. Links to
     *  events that already exist (the demo server has them) rather than creating
     *  morph-sourced events; skips cleanly if none are present.
     * ================================================================*/
    private function seedGovernanceBacklog(array $userIds, Carbon $now): void
    {
        $events = DB::table('hs_events')
            ->orderByRaw('site_id IS NULL') // prefer events that carry a site
            ->orderByDesc('occurred_at')
            ->limit(15)
            ->get(['id', 'site_id', 'client_id', 'staff_id', 'organization_id', 'reference_number'])
            ->values();

        if ($events->isEmpty()) {
            $this->command->warn('  -> No HsEvent backbone rows to attach to — skipping investigations + corrective actions.');
            return;
        }

        $year = $now->year;

        // ── Investigations (all active; #1 and #4 past-due → flagged overdue) ──
        $investigationConfigs = [
            ['type' => 'standard', 'status' => 'in_progress',       'methodology' => '5_whys',   'targetOffset' => -2, 'startedDaysAgo' => 6],
            ['type' => 'full',     'status' => 'under_review',      'methodology' => 'icam',     'targetOffset' => 4,  'startedDaysAgo' => 11],
            ['type' => 'standard', 'status' => 'findings_recorded', 'methodology' => 'fishbone', 'targetOffset' => 9,  'startedDaysAgo' => 4],
            ['type' => 'standard', 'status' => 'in_progress',       'methodology' => '5_whys',   'targetOffset' => -6, 'startedDaysAgo' => 9],
        ];

        $investigationRows = [];
        foreach ($investigationConfigs as $i => $c) {
            $event = $events[$i % $events->count()];
            $startedAt = $now->copy()->subDays($c['startedDaysAgo']);
            $investigationRows[] = [
                'hs_event_id'            => $event->id,
                'organization_id'        => $event->organization_id,
                'reference_number'       => "INV-{$year}-" . str_pad(9001 + $i, 4, '0', STR_PAD_LEFT),
                'investigation_type'     => $c['type'],
                'status'                 => $c['status'],
                'methodology'            => $c['methodology'],
                'lead_investigator_id'   => $userIds[array_rand($userIds)],
                'team_member_ids'        => json_encode(array_slice($userIds, 0, min(3, count($userIds)))),
                'started_at'             => $startedAt,
                'target_completion_date' => $now->copy()->addDays($c['targetOffset'])->toDateString(),
                'findings_summary'       => 'Investigation underway — interim findings being collated. ' . self::DEMO_MARKER,
                'created_by'             => $userIds[0],
                'created_at'             => $startedAt,
                'updated_at'             => $now,
            ];
        }
        DB::table('hs_investigations')->insert($investigationRows);

        // ── Corrective actions (4 overdue + 2 upcoming) ──
        $caConfigs = [
            ['title' => 'Replace worn stair tread — main stairwell',  'priority' => 'high',   'status' => 'open',        'dueOffset' => -2],
            ['title' => 'Update lone-worker check-in procedure',      'priority' => 'high',   'status' => 'in_progress', 'dueOffset' => -5],
            ['title' => 'Service ceiling hoist (room 4)',             'priority' => 'medium', 'status' => 'open',        'dueOffset' => -1],
            ['title' => 'Re-train staff on medication double-check',  'priority' => 'high',   'status' => 'open',        'dueOffset' => -8],
            ['title' => 'Install anti-slip matting at main entrance', 'priority' => 'medium', 'status' => 'in_progress', 'dueOffset' => 6],
            ['title' => 'Review chemical storage SDS folder',         'priority' => 'low',    'status' => 'open',        'dueOffset' => 14],
        ];

        $caRows = [];
        foreach ($caConfigs as $i => $c) {
            $event = $events[$i % $events->count()];
            $assignedAt = $now->copy()->subDays(rand(7, 30));
            $caRows[] = [
                'hs_event_id'         => $event->id,
                'organization_id'     => $event->organization_id,
                'reference_number'    => "CA-{$year}-" . str_pad(9001 + $i, 4, '0', STR_PAD_LEFT),
                'action_type'         => 'corrective',
                'priority'            => $c['priority'],
                'title'               => $c['title'],
                'description'         => 'Corrective action arising from event ' . ($event->reference_number ?: ('#' . $event->id)) . '. ' . self::DEMO_MARKER,
                'status'              => $c['status'],
                'assigned_to_user_id' => $userIds[array_rand($userIds)],
                'assigned_by_user_id' => $userIds[0],
                'assigned_at'         => $assignedAt,
                'due_date'            => $now->copy()->addDays($c['dueOffset'])->toDateString(),
                'created_by'          => $userIds[0],
                'created_at'          => $assignedAt,
                'updated_at'          => $now,
            ];
        }
        DB::table('hs_corrective_actions')->insert($caRows);

        $this->command->info('  -> ' . count($investigationRows) . ' investigations + ' . count($caRows) . ' corrective actions created.');
    }
}
