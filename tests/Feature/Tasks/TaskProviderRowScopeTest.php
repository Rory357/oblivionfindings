<?php

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlledDrugLossReport;
use App\Models\ControlRoomAlert;
use App\Models\DataBreachLog;
use App\Models\DataSubjectRequest;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\FleetIncident;
use App\Models\FleetServiceSchedule;
use App\Models\FleetWorkOrder;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\IncidentFollowup;
use App\Models\MedicationError;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteTask;
use App\Models\RestraintEvent;
use App\Models\SafeguardingActionPlan;
use App\Models\SafeguardingConcern;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteHazard;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Arr;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

/** @param list<string> $keys */
function taskRbacGrant(User $user, array $keys, bool $allowed = true): void
{
    foreach ($keys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => str_replace('.', ' ', $key),
                'group' => explode('.', $key)[0],
            ],
        );
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => $allowed],
        ]);
    }

    $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
}

function taskRbacStaff(Site $site, string $name): User
{
    $user = User::factory()->create([
        'name' => $name,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'TASK-RBAC-'.$user->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user;
}

/**
 * Build one open row for every registered provider at each Site, plus the
 * three product-defined organisation-wide sources.
 *
 * @return array{
 *   actor: User,
 *   siteA: Site,
 *   siteB: Site,
 *   matrix: array<string, array{visible: list<array{id: string, source: string, numeric_id: int, token: string}>, hidden: list<array{id: string, source: string, numeric_id: int, token: string}>}>,
 *   sitePermissions: list<string>,
 *   globalBypasses: list<string>
 * }
 */
function taskRbacMatrix(): array
{
    $siteA = Site::factory()->create([
        'name' => 'TASK-RBAC Site A',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);
    $siteB = Site::factory()->create([
        'name' => 'TASK-RBAC Site B private',
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
    ]);

    $actor = taskRbacStaff($siteA, 'TASK-RBAC Site A worker');
    $staffA = taskRbacStaff($siteA, 'TASK-RBAC Site A colleague');
    $staffB = taskRbacStaff($siteB, 'TASK-RBAC Site B private colleague');

    $sitePermissions = [
        'incidents.viewAny',
        'hazards.view',
        'safeguarding.viewAny',
        'controlRoom.viewAny',
        'fleet.viewAny',
        'assets.viewAny',
        'medications.view',
        'medications.controlled.view',
        'hr.cases.view',
        'checklists.view',
        'shifts.manageAny',
        'respite.tasks.view',
        'clients.viewAssigned',
        'restraints.review',
        'privacy.reportBreaches',
        'privacy.viewRequests',
        'governance.actions.view',
    ];
    taskRbacGrant($actor, $sitePermissions);

    $clientA = Client::factory()->create([
        'site_id' => $siteA->id,
        'first_name' => 'TASKRBACA',
        'last_name' => 'Visible',
        'status' => 'active',
    ]);
    $clientB = Client::factory()->create([
        'site_id' => $siteB->id,
        'first_name' => 'TASKRBACB',
        'last_name' => 'Private',
        'status' => 'active',
    ]);
    $clientA->supportWorkers()->attach($actor->id);
    $clientB->supportWorkers()->attach($actor->id);

    $assetA = Asset::factory()->vehicle()->forSite($siteA)->create([
        'name' => 'TASK-RBAC visible vehicle',
    ]);
    $assetB = Asset::factory()->vehicle()->forSite($siteB)->create([
        'name' => 'TASK-RBAC private vehicle',
    ]);

    $incidentA = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
        'reference_number' => 'INC-91001',
        'client_id' => $clientA->id,
        'site_id' => $siteA->id,
        'reported_by' => $actor->id,
        'investigation_assigned_to' => $actor->id,
        'title' => 'TASK-RBAC visible incident',
        'status' => 'submitted',
    ]));
    $incidentB = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
        'reference_number' => 'INC-92001',
        'client_id' => $clientB->id,
        'site_id' => $siteB->id,
        'reported_by' => $staffB->id,
        'investigation_assigned_to' => $actor->id,
        'title' => 'TASK-RBAC private incident',
        'status' => 'submitted',
    ]));
    $followupA = IncidentFollowup::factory()->create([
        'client_incident_id' => $incidentA->id,
        'assigned_to_user_id' => $actor->id,
        'notes' => 'TASK-RBAC visible incident follow-up',
        'completed_at' => null,
    ]);
    $followupB = IncidentFollowup::factory()->create([
        'client_incident_id' => $incidentB->id,
        'assigned_to_user_id' => $actor->id,
        'notes' => 'TASK-RBAC private incident follow-up',
        'completed_at' => null,
    ]);

    $eventA = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
        'reference_number' => 'HSE-91001',
        'site_id' => $siteA->id,
        'client_id' => $clientA->id,
        'status' => HsEvent::STATUS_OPEN,
        'created_by' => $actor->id,
    ]));
    $eventB = HsEvent::withoutEvents(fn () => HsEvent::factory()->create([
        'reference_number' => 'HSE-92001',
        'site_id' => $siteB->id,
        'client_id' => $clientB->id,
        'status' => HsEvent::STATUS_OPEN,
        'created_by' => $staffB->id,
    ]));
    $investigationA = HsInvestigation::factory()->create([
        'hs_event_id' => $eventA->id,
        'reference_number' => 'HSI-91001',
        'status' => HsInvestigation::STATUS_DRAFT,
        'created_by' => $actor->id,
    ]);
    $investigationB = HsInvestigation::factory()->create([
        'hs_event_id' => $eventB->id,
        'reference_number' => 'HSI-92001',
        'status' => HsInvestigation::STATUS_DRAFT,
        'created_by' => $staffB->id,
    ]);
    $correctiveA = HsCorrectiveAction::factory()->create([
        'hs_event_id' => $eventA->id,
        'reference_number' => 'HCA-91001',
        'title' => 'TASK-RBAC visible corrective action',
        'status' => HsCorrectiveAction::STATUS_OPEN,
        'created_by' => $actor->id,
    ]);
    $correctiveB = HsCorrectiveAction::factory()->create([
        'hs_event_id' => $eventB->id,
        'reference_number' => 'HCA-92001',
        'title' => 'TASK-RBAC private corrective action',
        'status' => HsCorrectiveAction::STATUS_OPEN,
        'created_by' => $staffB->id,
    ]);

    $hazardA = SiteHazard::withoutEvents(fn () => SiteHazard::query()->create([
        'site_id' => $siteA->id,
        'reference_number' => 'HAZ-91001',
        'reported_by_user_id' => $actor->id,
        'hazard_type' => 'other',
        'custom_hazard_type' => 'TASK-RBAC visible hazard',
        'severity' => 'low',
        'likelihood' => 'rare',
        'risk_rating' => 'low',
        'description' => 'TASK-RBAC visible hazard detail',
        'status' => 'open',
        'due_date' => today()->addDay(),
    ]));
    $hazardB = SiteHazard::withoutEvents(fn () => SiteHazard::query()->create([
        'site_id' => $siteB->id,
        'reference_number' => 'HAZ-92001',
        'reported_by_user_id' => $staffB->id,
        'hazard_type' => 'other',
        'custom_hazard_type' => 'TASK-RBAC private hazard',
        'severity' => 'low',
        'likelihood' => 'rare',
        'risk_rating' => 'low',
        'description' => 'TASK-RBAC private hazard detail',
        'status' => 'open',
        'due_date' => today()->addDay(),
    ]));
    $injuryA = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
        'reference_number' => 'INJ-91001',
        'site_id' => $siteA->id,
        'user_id' => $staffA->id,
        'description' => 'TASK-RBAC visible workplace injury',
        'status' => 'reported',
    ]));
    $injuryB = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
        'reference_number' => 'INJ-92001',
        'site_id' => $siteB->id,
        'user_id' => $staffB->id,
        'description' => 'TASK-RBAC private workplace injury',
        'status' => 'reported',
    ]));

    $concernA = SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
        'reference_number' => 'SAF-91001',
        'site_id' => $siteA->id,
        'subject_name' => 'TASK-RBAC visible safeguarding subject',
        'reported_by_user_id' => $actor->id,
        'status' => 'reported',
        'is_sensitive' => false,
    ]));
    $concernB = SafeguardingConcern::withoutEvents(fn () => SafeguardingConcern::factory()->create([
        'reference_number' => 'SAF-92001',
        'site_id' => $siteB->id,
        'subject_name' => 'TASK-RBAC private safeguarding subject',
        'reported_by_user_id' => $staffB->id,
        'status' => 'reported',
        'is_sensitive' => false,
    ]));
    $safeguardingActionA = SafeguardingActionPlan::query()->create([
        'safeguarding_concern_id' => $concernA->id,
        'action_description' => 'TASK-RBAC visible safeguarding action',
        'action_type' => 'protective_measure',
        'assigned_to_user_id' => $actor->id,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
        'priority' => 2,
        'created_by' => $actor->id,
    ]);
    $safeguardingActionB = SafeguardingActionPlan::query()->create([
        'safeguarding_concern_id' => $concernB->id,
        'action_description' => 'TASK-RBAC private safeguarding action',
        'action_type' => 'protective_measure',
        'assigned_to_user_id' => $actor->id,
        'due_date' => now()->addDays(2),
        'status' => 'pending',
        'priority' => 2,
        'created_by' => $staffB->id,
    ]);

    $alertA = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->open()->create([
        'reference_number' => 'ALT-91001',
        'site_id' => $siteA->id,
        'client_id' => $clientA->id,
        'alert_type' => 'TASK-RBAC visible alert',
        'assigned_to_user_id' => $actor->id,
    ]));
    $alertB = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->open()->create([
        'reference_number' => 'ALT-92001',
        'site_id' => $siteB->id,
        'client_id' => $clientB->id,
        'alert_type' => 'TASK-RBAC private alert',
        'assigned_to_user_id' => $actor->id,
    ]));
    $fleetIncidentA = FleetIncident::withoutEvents(fn () => FleetIncident::factory()->create([
        'reference_number' => 'FLT-91001',
        'asset_id' => $assetA->id,
        'description' => 'TASK-RBAC visible fleet incident',
        'status' => 'reported',
    ]));
    $fleetIncidentB = FleetIncident::withoutEvents(fn () => FleetIncident::factory()->create([
        'reference_number' => 'FLT-92001',
        'asset_id' => $assetB->id,
        'description' => 'TASK-RBAC private fleet incident',
        'status' => 'reported',
    ]));
    $workOrderA = FleetWorkOrder::withoutEvents(fn () => FleetWorkOrder::factory()->create([
        'reference_number' => 'WO-91001',
        'asset_id' => $assetA->id,
        'title' => 'TASK-RBAC visible work order',
        'status' => 'open',
        'due_at' => now()->addDay(),
    ]));
    $workOrderB = FleetWorkOrder::withoutEvents(fn () => FleetWorkOrder::factory()->create([
        'reference_number' => 'WO-92001',
        'asset_id' => $assetB->id,
        'title' => 'TASK-RBAC private work order',
        'status' => 'open',
        'due_at' => now()->addDay(),
    ]));
    $scheduleA = FleetServiceSchedule::query()->create([
        'asset_id' => $assetA->id,
        'name' => 'TASK-RBAC visible service schedule',
        'next_due_at' => now()->addDays(2),
        'is_active' => true,
    ]);
    $scheduleB = FleetServiceSchedule::query()->create([
        'asset_id' => $assetB->id,
        'name' => 'TASK-RBAC private service schedule',
        'next_due_at' => now()->addDays(2),
        'is_active' => true,
    ]);

    $medicationA = MedicationError::withoutEvents(fn () => MedicationError::query()->create([
        'reference_number' => 'MED-91001',
        'client_id' => $clientA->id,
        'error_type' => 'wrong_time',
        'severity' => 'minor',
        'description' => 'TASK-RBAC visible medication error',
        'reported_by' => $actor->id,
        'reported_at' => now(),
        'status' => 'reported',
    ]));
    $medicationB = MedicationError::withoutEvents(fn () => MedicationError::query()->create([
        'reference_number' => 'MED-92001',
        'client_id' => $clientB->id,
        'error_type' => 'wrong_time',
        'severity' => 'minor',
        'description' => 'TASK-RBAC private medication error',
        'reported_by' => $staffB->id,
        'reported_at' => now(),
        'status' => 'reported',
    ]));
    $cdLossA = ControlledDrugLossReport::withoutEvents(fn () => ControlledDrugLossReport::query()->create([
        'reference_number' => 'CDL-91001',
        'client_id' => $clientA->id,
        'medication_name' => 'TASK-RBAC visible controlled medicine',
        'quantity_lost' => 1,
        'unit' => 'tablet',
        'circumstances' => 'TASK-RBAC visible circumstances',
        'immediate_action_taken' => 'Stock secured.',
        'discovered_by' => $actor->id,
        'discovered_at' => now(),
        'investigation_status' => 'reported',
    ]));
    $cdLossB = ControlledDrugLossReport::withoutEvents(fn () => ControlledDrugLossReport::query()->create([
        'reference_number' => 'CDL-92001',
        'client_id' => $clientB->id,
        'medication_name' => 'TASK-RBAC private controlled medicine',
        'quantity_lost' => 1,
        'unit' => 'tablet',
        'circumstances' => 'TASK-RBAC private circumstances',
        'immediate_action_taken' => 'Stock secured.',
        'discovered_by' => $staffB->id,
        'discovered_at' => now(),
        'investigation_status' => 'reported',
    ]));

    $hrCaseA = HrCase::factory()->create([
        'case_number' => 'CASE-91001',
        'user_id' => $staffA->id,
        'title' => 'TASK-RBAC visible HR case',
        'status' => 'open',
        'is_confidential' => false,
    ]);
    $hrCaseB = HrCase::factory()->create([
        'case_number' => 'CASE-92001',
        'user_id' => $staffB->id,
        'title' => 'TASK-RBAC private HR case',
        'status' => 'open',
        'is_confidential' => false,
    ]);

    $templateA = SiteChecklistTemplate::query()->create([
        'key' => 'task_rbac_visible',
        'name' => 'TASK-RBAC visible checklist',
        'applicable_to_type' => 'all',
        'frequency' => 'monthly',
        'is_active' => true,
    ]);
    $templateB = SiteChecklistTemplate::query()->create([
        'key' => 'task_rbac_private',
        'name' => 'TASK-RBAC private checklist',
        'applicable_to_type' => 'all',
        'frequency' => 'monthly',
        'is_active' => true,
    ]);
    $assignmentA = SiteChecklistAssignment::query()->create([
        'site_id' => $siteA->id,
        'template_id' => $templateA->id,
        'frequency' => 'monthly',
        'assigned_to_user_id' => $actor->id,
        'start_date' => today(),
        'is_active' => true,
    ]);
    $assignmentB = SiteChecklistAssignment::query()->create([
        'site_id' => $siteB->id,
        'template_id' => $templateB->id,
        'frequency' => 'monthly',
        'assigned_to_user_id' => $actor->id,
        'start_date' => today(),
        'is_active' => true,
    ]);
    $checklistA = SiteChecklistRun::query()->create([
        'assignment_id' => $assignmentA->id,
        'site_id' => $siteA->id,
        'template_id' => $templateA->id,
        'assigned_to_user_id' => $actor->id,
        'scheduled_date' => today(),
        'status' => 'scheduled',
    ]);
    $checklistB = SiteChecklistRun::query()->create([
        'assignment_id' => $assignmentB->id,
        'site_id' => $siteB->id,
        'template_id' => $templateB->id,
        'assigned_to_user_id' => $actor->id,
        'scheduled_date' => today(),
        'status' => 'scheduled',
    ]);

    $shiftA = Shift::factory()->create([
        'site_id' => $siteA->id,
        'client_id' => $clientA->id,
        'user_id' => $staffA->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(5),
        'status' => 'scheduled',
    ]);
    $shiftB = Shift::factory()->create([
        'site_id' => $siteB->id,
        'client_id' => $clientB->id,
        'user_id' => $staffB->id,
        'starts_at' => now()->addHour(),
        'ends_at' => now()->addHours(5),
        'status' => 'scheduled',
    ]);
    $shiftTaskA = ShiftTask::query()->create([
        'shift_id' => $shiftA->id,
        'label' => 'TASK-RBAC visible shift task',
        'scheduled_time' => '10:00',
        'is_completed' => false,
    ]);
    $shiftTaskB = ShiftTask::query()->create([
        'shift_id' => $shiftB->id,
        'label' => 'TASK-RBAC private shift task',
        'scheduled_time' => '10:00',
        'is_completed' => false,
    ]);

    $bookingA = RespiteBooking::factory()->create([
        'client_id' => $clientA->id,
        'location_id' => $siteA->id,
        'status' => 'confirmed',
    ]);
    $bookingB = RespiteBooking::factory()->create([
        'client_id' => $clientB->id,
        'location_id' => $siteB->id,
        'status' => 'confirmed',
    ]);
    $respiteTaskA = RespiteTask::factory()->create([
        'subject_type' => RespiteBooking::class,
        'subject_id' => $bookingA->id,
        'title' => 'TASK-RBAC visible respite task',
        'status' => RespiteTask::STATUS_PENDING,
        'priority' => RespiteTask::PRIORITY_MEDIUM,
        'assigned_to_user_id' => $actor->id,
    ]);
    $respiteTaskB = RespiteTask::factory()->create([
        'subject_type' => RespiteBooking::class,
        'subject_id' => $bookingB->id,
        'title' => 'TASK-RBAC private respite task',
        'status' => RespiteTask::STATUS_PENDING,
        'priority' => RespiteTask::PRIORITY_MEDIUM,
        'assigned_to_user_id' => $actor->id,
    ]);

    $firstAidA = FirstAidRecord::withoutEvents(fn () => FirstAidRecord::factory()->create([
        'reference_number' => 'FA-91001',
        'site_id' => $siteA->id,
        'treated_person_name' => 'TASK-RBAC visible first aid subject',
        'first_aider_id' => $actor->id,
    ]));
    $firstAidB = FirstAidRecord::withoutEvents(fn () => FirstAidRecord::factory()->create([
        'reference_number' => 'FA-92001',
        'site_id' => $siteB->id,
        'treated_person_name' => 'TASK-RBAC private first aid subject',
        'first_aider_id' => $staffB->id,
    ]));
    $firstAidFollowupA = FirstAidFollowup::query()->create([
        'first_aid_record_id' => $firstAidA->id,
        'assigned_to_user_id' => $actor->id,
        'due_at' => now()->addDay(),
        'notes' => 'TASK-RBAC visible first aid follow-up',
        'created_by' => $actor->id,
    ]);
    $firstAidFollowupB = FirstAidFollowup::query()->create([
        'first_aid_record_id' => $firstAidB->id,
        'assigned_to_user_id' => $actor->id,
        'due_at' => now()->addDay(),
        'notes' => 'TASK-RBAC private first aid follow-up',
        'created_by' => $staffB->id,
    ]);
    $restraintA = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create([
        'reference_number' => 'RST-91001',
        'site_id' => $siteA->id,
        'client_id' => $clientA->id,
        'trigger_description' => 'TASK-RBAC visible restraint trigger',
        'reviewed_at' => null,
    ]));
    $restraintB = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create([
        'reference_number' => 'RST-92001',
        'site_id' => $siteB->id,
        'client_id' => $clientB->id,
        'trigger_description' => 'TASK-RBAC private restraint trigger',
        'reviewed_at' => null,
    ]));

    $breach = DataBreachLog::factory()->create([
        'breach_reference' => 'DBR-93001',
        'status' => 'discovered',
    ]);
    $dsr = DataSubjectRequest::factory()->create([
        'reference_number' => 'DSR-93001',
        'request_details' => 'TASK-RBAC explicit global DSR',
        'status' => 'received',
    ]);
    $action = ActionItem::query()->create([
        'action_reference' => 'ACT-93001',
        'source_type' => 'meeting',
        'source_id' => 93001,
        'description' => 'TASK-RBAC explicit global action',
        'assigned_to' => $actor->id,
        'due_date' => today()->addDays(3),
        'status' => 'open',
        'priority' => 'medium',
        'created_by' => $actor->id,
    ]);

    $pair = fn (
        string $source,
        object $visible,
        object $hidden,
        string $visibleToken,
        string $hiddenToken,
        ?string $identitySource = null,
    ): array => [
        'visible' => [[
            'id' => ($identitySource ?? $source).'-'.$visible->id,
            'source' => $identitySource ?? $source,
            'numeric_id' => (int) $visible->id,
            'token' => $visibleToken,
        ]],
        'hidden' => [[
            'id' => ($identitySource ?? $source).'-'.$hidden->id,
            'source' => $identitySource ?? $source,
            'numeric_id' => (int) $hidden->id,
            'token' => $hiddenToken,
        ]],
    ];

    $matrix = [
        'incident' => $pair('incident', $incidentA, $incidentB, 'INC-91001', 'INC-92001'),
        'followup' => $pair('followup', $followupA, $followupB, 'TASK-RBAC visible incident', 'TASK-RBAC private incident'),
        'hs_event' => $pair('hs_event', $eventA, $eventB, 'HSE-91001', 'HSE-92001'),
        'hs_investigation' => $pair('hs_investigation', $investigationA, $investigationB, 'HSI-91001', 'HSI-92001'),
        'corrective_action' => $pair('corrective_action', $correctiveA, $correctiveB, 'HCA-91001', 'HCA-92001'),
        'hazard' => $pair('hazard', $hazardA, $hazardB, 'HAZ-91001', 'HAZ-92001'),
        'injury' => $pair('injury', $injuryA, $injuryB, 'INJ-91001', 'INJ-92001'),
        'safeguarding' => $pair('safeguarding', $concernA, $concernB, 'SAF-91001', 'SAF-92001'),
        'safeguarding_action' => $pair('safeguarding_action', $safeguardingActionA, $safeguardingActionB, 'SAF-91001', 'SAF-92001'),
        'alert' => $pair('alert', $alertA, $alertB, 'ALT-91001', 'ALT-92001'),
        'fleet_incident' => $pair('fleet_incident', $fleetIncidentA, $fleetIncidentB, 'FLT-91001', 'FLT-92001'),
        'fleet_maintenance' => [
            'visible' => [
                ['id' => 'fleet_work_order-'.$workOrderA->id, 'source' => 'fleet_work_order', 'numeric_id' => (int) $workOrderA->id, 'token' => 'WO-91001'],
                ['id' => 'fleet_service_schedule-'.$scheduleA->id, 'source' => 'fleet_service_schedule', 'numeric_id' => (int) $scheduleA->id, 'token' => 'TASK-RBAC visible service schedule'],
            ],
            'hidden' => [
                ['id' => 'fleet_work_order-'.$workOrderB->id, 'source' => 'fleet_work_order', 'numeric_id' => (int) $workOrderB->id, 'token' => 'WO-92001'],
                ['id' => 'fleet_service_schedule-'.$scheduleB->id, 'source' => 'fleet_service_schedule', 'numeric_id' => (int) $scheduleB->id, 'token' => 'TASK-RBAC private service schedule'],
            ],
        ],
        'med_error' => $pair('med_error', $medicationA, $medicationB, 'MED-91001', 'MED-92001'),
        'cd_loss' => $pair('cd_loss', $cdLossA, $cdLossB, 'CDL-91001', 'CDL-92001'),
        'breach' => ['visible' => [['id' => 'breach-'.$breach->id, 'source' => 'breach', 'numeric_id' => (int) $breach->id, 'token' => 'DBR-93001']], 'hidden' => []],
        'dsr' => ['visible' => [['id' => 'dsr-'.$dsr->id, 'source' => 'dsr', 'numeric_id' => (int) $dsr->id, 'token' => 'DSR-93001']], 'hidden' => []],
        'action_item' => ['visible' => [['id' => 'action_item-'.$action->id, 'source' => 'action_item', 'numeric_id' => (int) $action->id, 'token' => 'ACT-93001']], 'hidden' => []],
        'hr_case' => $pair('hr_case', $hrCaseA, $hrCaseB, 'CASE-91001', 'CASE-92001'),
        'checklist_run' => $pair('checklist_run', $checklistA, $checklistB, 'TASK-RBAC visible checklist', 'TASK-RBAC private checklist'),
        'shift_task' => $pair('shift_task', $shiftTaskA, $shiftTaskB, 'TASK-RBAC visible shift task', 'TASK-RBAC private shift task'),
        'respite_task' => $pair('respite_task', $respiteTaskA, $respiteTaskB, 'TASK-RBAC visible respite task', 'TASK-RBAC private respite task'),
        'first_aid_followup' => $pair('first_aid_followup', $firstAidFollowupA, $firstAidFollowupB, 'FA-91001', 'FA-92001'),
        'restraint_review' => $pair('restraint_review', $restraintA, $restraintB, 'RST-91001', 'RST-92001'),
    ];

    return [
        'actor' => $actor,
        'siteA' => $siteA,
        'siteB' => $siteB,
        'matrix' => $matrix,
        'sitePermissions' => $sitePermissions,
        'globalBypasses' => [
            'reports.viewAny',
            'healthSafety.viewAllSites',
            'sites.viewAll',
            'securityDevices.devices.viewAllSites',
            'clinical.accessAllSites',
            'hr.employees.viewAllSites',
            'clients.viewAny',
        ],
    ];
}

/** @return list<array{id: string, source: string, numeric_id: int, token: string}> */
function taskRbacRows(array $matrix, string $visibility): array
{
    return array_values(Arr::flatten(
        array_map(fn (array $provider): array => $provider[$visibility], $matrix),
        1,
    ));
}

function taskRbacFreshRequest(User $user): User
{
    app()->forgetScopedInstances();
    auth()->forgetUser();

    return User::query()->findOrFail($user->id);
}

it('keeps list counts csv detail lookup reports my day and watchers in parity for every provider', function () {
    $fixture = taskRbacMatrix();
    $actor = $fixture['actor'];
    $matrix = $fixture['matrix'];
    $visible = taskRbacRows($matrix, 'visible');
    $hidden = taskRbacRows($matrix, 'hidden');
    $visibleIds = collect($visible)->pluck('id')->sort()->values()->all();
    $hiddenIds = collect($hidden)->pluck('id')->sort()->values()->all();
    $aggregator = new TaskAggregator;

    expect(array_keys($matrix))->toBe(collect(TaskAggregator::defaultProviders())->map->sourceKey()->all());

    foreach (TaskAggregator::defaultProviders() as $provider) {
        $actualIds = collect($provider->authorizedTasks($actor))->pluck('id')->sort()->values()->all();
        $expectedIds = collect($matrix[$provider->sourceKey()]['visible'])->pluck('id')->sort()->values()->all();

        expect($actualIds)->toBe($expectedIds, $provider::class.' returned an incorrectly scoped count.');
    }

    expect(collect($aggregator->itemsFor($actor))->pluck('id')->sort()->values()->all())
        ->toBe($visibleIds)
        ->and(array_intersect($visibleIds, $hiddenIds))->toBe([]);

    $this->actingAs($actor)
        ->get('/tasks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', count($visibleIds))
            ->where('stats.open', count($visibleIds))
            ->where('items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all() === $visibleIds));

    $this->actingAs($actor)
        ->get('/tasks/reports')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.open', count($visibleIds))
            ->where('totals.done', 0)
            ->where('modules', fn ($modules) => collect($modules)->pluck('key')->sort()->values()->all()
                === collect(array_keys($matrix))->sort()->values()->all()));

    $csv = $this->actingAs($actor)->get('/tasks?format=csv')->assertOk()->streamedContent();
    foreach ($visible as $row) {
        expect($csv)->toContain($row['token']);
    }
    foreach ($hidden as $row) {
        expect($csv)->not->toContain($row['token']);
    }

    foreach ($matrix as $source => $rows) {
        $sourceCsv = $this->actingAs($actor)
            ->get('/tasks?format=csv&sources='.$source)
            ->assertOk()
            ->streamedContent();

        foreach ($rows['visible'] as $row) {
            expect($sourceCsv)->toContain($row['token']);
        }
        foreach ($rows['hidden'] as $row) {
            expect($sourceCsv)->not->toContain($row['token']);
        }
    }

    foreach ($visible as $row) {
        $this->actingAs($actor)
            ->getJson('/tasks/detail?'.http_build_query([
                'source' => $row['source'],
                'id' => $row['numeric_id'],
            ]))
            ->assertOk()
            ->assertJsonPath('item.id', $row['id']);

        if (preg_match('/^[A-Z]{2,4}-\d/', $row['token'])) {
            $this->actingAs($actor)
                ->getJson('/tasks/lookup?q='.urlencode($row['token']))
                ->assertOk()
                ->assertJsonPath('match.ref', $row['token']);
        }
    }

    foreach ($hidden as $row) {
        $this->actingAs($actor)
            ->getJson('/tasks/detail?'.http_build_query([
                'source' => $row['source'],
                'id' => $row['numeric_id'],
            ]))
            ->assertNotFound();

        $this->actingAs($actor)
            ->get('/tasks?sources='.urlencode((string) collect($matrix)
                ->search(fn (array $provider) => in_array($row, $provider['hidden'], true))).'&q='.urlencode($row['token']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('pagination.total', 0)
                ->where('items', []));

        if (preg_match('/^[A-Z]{2,4}-\d/', $row['token'])) {
            $this->actingAs($actor)
                ->getJson('/tasks/lookup?q='.urlencode($row['token']))
                ->assertOk()
                ->assertJsonPath('match', null);
        }
    }

    $mine = collect($aggregator->itemsFor($actor))
        ->filter(fn (TaskItem $item): bool => ($item->assignee['id'] ?? null) === $actor->id)
        ->values();
    $expectedMine = $mine->take(8)->pluck('id')->sort()->values()->all();
    $this->actingAs($actor)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('myTasks.total', $mine->count())
            ->where('myTasks.items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all()
                === $expectedMine));

    $visibleIncident = $matrix['incident']['visible'][0];
    $hiddenIncident = $matrix['incident']['hidden'][0];
    TaskWatcher::query()->create([
        'source' => 'incident',
        'item_id' => $visibleIncident['numeric_id'],
        'user_id' => $actor->id,
    ]);
    TaskWatcher::query()->create([
        'source' => 'incident',
        'item_id' => $hiddenIncident['numeric_id'],
        'user_id' => $actor->id,
    ]);

    expect((new TaskAggregator)->visibleWatcherIdsFor('incident', $visibleIncident['numeric_id']))
        ->toBe([$actor->id])
        ->and((new TaskAggregator)->visibleWatcherIdsFor('incident', $hiddenIncident['numeric_id']))
        ->toBe([])
        ->and((new TaskAggregator)->authorizedWatcherIdsFor('incident', $hiddenIncident['numeric_id']))
        ->toBe([])
        ->and(TaskWatcher::query()
            ->where('source', 'incident')
            ->where('item_id', $hiddenIncident['numeric_id'])
            ->where('user_id', $actor->id)
            ->exists())->toBeFalse();
});

it('denies every foreign direct identity and every direct mutation with one generic not found shape', function () {
    $fixture = taskRbacMatrix();
    $actor = $fixture['actor'];
    $hidden = taskRbacRows($fixture['matrix'], 'hidden');

    foreach ($hidden as $row) {
        $this->actingAs($actor)
            ->getJson('/tasks/detail?'.http_build_query([
                'source' => $row['source'],
                'id' => $row['numeric_id'],
            ]))
            ->assertNotFound();
    }

    $incident = $fixture['matrix']['incident']['hidden'][0];
    $this->actingAs($actor)
        ->post("/tasks/incident/{$incident['numeric_id']}/assign", ['assignee_id' => $actor->id])
        ->assertNotFound();
    $this->actingAs($actor)
        ->post("/tasks/incident/{$incident['numeric_id']}/split", ['title' => 'Must remain generic'])
        ->assertNotFound();
    $this->actingAs($actor)
        ->post("/tasks/incident/{$incident['numeric_id']}/watch", ['watching' => true])
        ->assertNotFound();
    $this->actingAs($actor)
        ->post("/tasks/incident/{$incident['numeric_id']}/watch", ['watching' => false])
        ->assertNotFound();
    $this->actingAs($actor)
        ->getJson('/tasks/detail?source=unknown_provider&id='.$incident['numeric_id'])
        ->assertNotFound();

    expect(TaskWatcher::query()
        ->where('source', 'incident')
        ->where('item_id', $incident['numeric_id'])
        ->exists())->toBeFalse();
});

it('applies site revocation reassignment and explicit application wide permissions on the next request', function () {
    $fixture = taskRbacMatrix();
    $actor = $fixture['actor'];
    $matrix = $fixture['matrix'];
    $visibleA = collect(taskRbacRows($matrix, 'visible'))->pluck('id')->sort()->values()->all();
    $visibleB = collect(taskRbacRows($matrix, 'hidden'))->pluck('id')->sort()->values()->all();
    $globalIds = collect(['breach', 'dsr', 'action_item'])
        ->flatMap(fn (string $source) => collect($matrix[$source]['visible'])->pluck('id'))
        ->sort()
        ->values()
        ->all();

    $this->actingAs($actor)
        ->get('/tasks')
        ->assertInertia(fn ($page) => $page->where('pagination.total', count($visibleA)));

    $profile = HrEmployeeProfile::query()->where('user_id', $actor->id)->firstOrFail();
    $profile->update(['is_active' => false, 'end_date' => today()->subDay()]);
    $actor = taskRbacFreshRequest($actor);
    $revokedNavigation = (new TaskAggregator)->navigationBadgeFor($actor);
    $this->actingAs($actor)
        ->get('/tasks')
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', count($globalIds))
            ->where('items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all() === $globalIds)
            ->where('auth.can.tasks.view', $revokedNavigation['view'])
            ->where('auth.can.tasks.badge', $revokedNavigation['badge']));

    $profile->update([
        'primary_site_id' => $fixture['siteB']->id,
        'is_active' => true,
        'end_date' => null,
    ]);
    $actor = taskRbacFreshRequest($actor);
    $expectedAtB = collect([...$visibleB, ...$globalIds])->sort()->values()->all();
    $this->actingAs($actor)
        ->get('/tasks')
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', count($expectedAtB))
            ->where('items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all() === $expectedAtB));

    taskRbacGrant($actor, $fixture['globalBypasses']);
    $actor = taskRbacFreshRequest($actor);
    $allIds = collect([...$visibleA, ...$visibleB])->unique()->sort()->values()->all();
    $this->actingAs($actor)
        ->get('/tasks')
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', count($allIds))
            ->where('items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all() === $allIds));

    taskRbacGrant($actor, $fixture['globalBypasses'], false);
    $actor = taskRbacFreshRequest($actor);
    $this->actingAs($actor)
        ->get('/tasks')
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', count($expectedAtB))
            ->where('items', fn ($items) => collect($items)->pluck('id')->sort()->values()->all() === $expectedAtB));
});

it('requires the three explicit global permissions and scopes the staff lookup independently', function () {
    $fixture = taskRbacMatrix();
    $actor = $fixture['actor'];
    $globalSources = ['breach', 'dsr', 'action_item'];

    $genericAdmin = User::factory()->create([
        'name' => 'TASK-RBAC generic admin field only',
        'role' => 'admin',
        'approved_at' => now(),
    ]);
    expect(collect((new TaskAggregator)->itemsFor($genericAdmin))->pluck('source')->intersect($globalSources))
        ->toBeEmpty();

    foreach ([
        'privacy.reportBreaches' => 'breach',
        'privacy.viewRequests' => 'dsr',
        'governance.actions.view' => 'action_item',
    ] as $permission => $source) {
        taskRbacGrant($actor, [$permission], false);
        $actor = taskRbacFreshRequest($actor);
        expect(collect((new TaskAggregator)->itemsFor($actor))->pluck('source'))->not->toContain($source);

        taskRbacGrant($actor, [$permission], true);
        $actor = taskRbacFreshRequest($actor);
        expect(collect((new TaskAggregator)->itemsFor($actor))->pluck('source'))->toContain($source);
    }

    $this->actingAs($actor)
        ->getJson('/tasks/users?q='.urlencode('TASK-RBAC Site B private colleague'))
        ->assertOk()
        ->assertJsonCount(0, 'users');

    taskRbacGrant($actor, ['reports.viewAny']);
    $actor = taskRbacFreshRequest($actor);
    $this->actingAs($actor)
        ->getJson('/tasks/users?q='.urlencode('TASK-RBAC Site B private colleague'))
        ->assertOk()
        ->assertJsonCount(1, 'users')
        ->assertJsonPath('users.0.name', 'TASK-RBAC Site B private colleague');
});
