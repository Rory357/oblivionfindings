<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Models\WorkplaceInjurySourceVersion;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Detached injury facts. A recovery record is never a medical approval or Workforce decision. */
final class InjuryWorkspaceService
{
    private const TYPE_LABELS = ['strain' => 'Muscle strain', 'laceration' => 'Laceration', 'fracture' => 'Fracture',
        'burn' => 'Burn', 'contusion' => 'Contusion / bruise', 'concussion' => 'Concussion', 'repetitive_strain' => 'Repetitive strain',
        'chemical_exposure' => 'Chemical exposure', 'biological_exposure' => 'Biological exposure', 'needle_stick' => 'Needle-stick',
        'slip_trip_fall' => 'Slip / trip / fall', 'manual_handling' => 'Manual handling', 'psychological' => 'Psychological',
        'illness' => 'Work illness', 'other' => 'Other'];
    private const TREATMENT_LABELS = ['none' => 'None required', 'first_aid' => 'First aid', 'gp_visit' => 'GP visit',
        'hospital' => 'Hospital', 'emergency_department' => 'Emergency department', 'hospitalisation' => 'Hospitalisation',
        'specialist' => 'Specialist', 'ongoing' => 'Ongoing treatment'];
    private const HISTORY_FIELDS = ['user_id', 'site_id', 'related_incident_id', 'injury_date', 'injury_type', 'body_part_affected',
        'severity', 'description', 'immediate_treatment', 'medical_treatment_type', 'worksafe_notifiable', 'acc_claim_lodged',
        'acc_claim_number', 'lost_time_days', 'expected_return_date', 'actual_return_date', 'status', 'notes', 'coordinator_user_id',
        'next_review_date', 'assessment_date', 'assessor_name', 'assessor_type', 'capacity_status', 'assessment_summary', 'restrictions',
        'recommendations', 'next_assessment_date', 'plan_start_date', 'plan_end_date', 'medical_clearance_notes', 'medical_clearance_date',
        'medical_clearance_provider', 'goals', 'stages', 'workplace_modifications', 'worker_agreement_notes', 'worker_agreed',
        'worker_agreed_at', 'review_notes', 'start_date', 'end_date', 'modified_duties_description', 'accommodations', 'hours_per_day'];

    public function __construct(private readonly InjuryAccessService $access,
        private readonly InjuryConnectionsService $connections, private readonly HrCurrentStaffService $staff) {}

    public function detail(User $viewer, int $injuryId): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($injuryId): array {
            $row = $reads->query(WorkplaceInjury::query())->select('id')->findOrFail($injuryId);
            $row = $this->access->assertInjury($row, $current, $reads, $sites);
            $factual = $this->access->project($row, $current, $reads, $sites);
            $sourceProof = $this->access->factualSourceProof($row, $current, $reads, $sites);
            $sourceIntegrity = match ($sourceProof['attribution_state']) {
                'recorded' => 'valid', 'legacy_unknown' => 'unknown', default => 'repair_required',
            };
            $verified = $sourceIntegrity !== 'repair_required';
            if (! $verified) {
                foreach (InjuryAccessService::PRIVATE_INJURY_FIELDS as $field) {
                    $factual[$field] = null;
                }
            }
            $medical = $factual['medical_access'] === 'readable';
            $manage = $current->canDo('hazards.manage');
            $open = $row->status !== 'closed';
            $site = $reads->query(Site::query())->whereKey($row->site_id)->first(['id', 'name']);
            $connections = $this->connections->inContext($row, $current, $reads, $sites);
            $detail = [...$factual, 'source_integrity' => $sourceIntegrity, 'injury_type_label' => self::TYPE_LABELS[$row->injury_type] ?? $row->injury_type,
                'site' => $site ? ['id' => (int) $site->id, 'name' => $site->name] : null,
                'immediate_treatment' => null, 'medical_treatment_type' => null, 'medical_treatment_label' => null,
                'acc_claim_number' => null, 'notes' => null, 'related_incident' => $connections['incident']['access'] === 'readable'
                    ? ['id' => $connections['incident']['id'], 'label' => $connections['incident']['reference'],
                        'title' => $connections['incident']['title']] : null,
                'rtw_plans' => null, 'capacity_assessments' => null, 'attachments' => null, 'audits' => null, 'history' => null,
                'recovery_integrity' => $verified ? ($medical ? 'valid' : 'unknown') : 'repair_required',
                'history_integrity' => $verified ? ($medical ? 'valid' : 'unknown') : 'repair_required',
                'evidence_integrity' => $verified ? ($medical ? 'valid' : 'unknown') : 'repair_required',
                'coordination' => ['coordinator' => $this->identity($row->coordinator_snapshot),
                    'attribution_state' => $row->coordinator_user_id === null ? 'unassigned'
                        : ($this->identity($row->coordinator_snapshot) ? 'recorded' : 'unknown'),
                    'next_review_date' => $row->next_review_date?->toDateString()],
                'connections' => $connections,
                'scheduling_impact' => ['state' => 'pending_interface', 'outcome' => 'unknown',
                    'source_revision' => (int) $row->source_revision, 'evaluated_at' => null, 'evaluated_interval' => null,
                    'affected_duties' => null, 'coordinator' => null, 'receipt' => null],
                'claim_delivery' => ['internally_recorded' => (bool) $row->acc_claim_lodged,
                    'external_receipt_state' => 'unconfirmed', 'lodged_at' => null, 'acknowledged_at' => null],
                'created_at' => $row->created_at?->toIso8601String(), 'updated_at' => $row->updated_at?->toIso8601String(),
                'can' => ['manage' => $manage, 'create' => $current->canDo('hazards.create') || $manage, 'update' => $manage && $open, 'transition' => $manage && $open, 'assign' => $manage,
                    'review' => $manage && $medical && $open, 'recovery' => $manage && $medical && $open,
                    'upload_evidence' => $manage && $medical && $open, 'remove_evidence' => $manage && $medical && $open,
                    'read_evidence' => $medical, 'record_medical_clearance' => $manage && $medical && $open,
                    'approve_restrictions' => false, 'declare_fit' => false],
                'commands' => ['expected_version' => (int) $row->source_revision,
                    'transitions' => $manage ? (WorkplaceInjurySourceService::TRANSITIONS[$row->status] ?? []) : []]];
            if ($medical && $verified) {
                $this->access->assertMedicalSource($row, $current, $reads, $sites);
                foreach (InjuryAccessService::PRIVATE_INJURY_FIELDS as $field) {
                    $detail[$field] = $row->getAttribute($field);
                }
                $detail['medical_treatment_label'] = self::TREATMENT_LABELS[$row->medical_treatment_type] ?? null;
                $versions = $this->history($row, $reads, $sites);
                $detail['history'] = $versions['items'];
                $detail['history_integrity'] = $versions['integrity'];
                $detail['rtw_plans'] = $this->plans($row, $current, $reads, $sites, $detail['recovery_integrity']);
                $detail['capacity_assessments'] = $this->assessments($row, $current, $reads, $sites, $versions['versions'], $detail['recovery_integrity']);
                $detail['attachments'] = $this->attachments($row, $current, $reads, $sites, $detail['evidence_integrity']);
                $detail['audits'] = $this->audits($row, $reads, $versions);
            }
            if (! $verified) {
                foreach (['manage', 'update', 'transition', 'assign', 'review', 'recovery', 'upload_evidence',
                    'remove_evidence', 'read_evidence', 'record_medical_clearance'] as $command) {
                    $detail['can'][$command] = false;
                }
                $detail['commands']['transitions'] = [];
            }
            // Child conflicts do not grant correction authority or invent a safe recovery state.
            if ($detail['recovery_integrity'] === 'repair_required') {
                $detail['can']['recovery'] = false;
                $detail['can']['record_medical_clearance'] = false;
            }
            return $detail;
        });
    }

    /** Capture-only entry has its own permission boundary and never needs register read access. */
    public function captureOptions(User $viewer, ?int $siteId = null, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($siteId, $filters): array {
            $this->access->assertOperation($current, 'create');
            if ($siteId !== null) {
                abort_unless(in_array($siteId, $sites, true), 404);
            }
            $selected = $siteId === null ? $sites : [$siteId];
            $directory = $this->staffPage($reads, $selected, $filters);
            return ['sites' => $reads->query(Site::query())->whereIn('id', $selected)->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($site): array => ['id' => (int) $site->id, 'name' => $site->name])->all(),
                'workers' => $directory['items'], 'worker_picker' => $directory['pagination'],
                'incidents' => $this->connections->incidentOptionsInContext($current, $reads, $sites, $siteId),
                'injury_types' => self::TYPE_LABELS, 'treatments' => self::TREATMENT_LABELS,
                'medical_access' => $current->canDo('hr.wellbeing.view') ? 'readable' : 'restricted',
                'can' => ['create' => true, 'record_reported_details' => true, 'view_register' => $current->canDo('hazards.view') || $current->canDo('hr.wellbeing.view')]];
        });
    }

    public function recoveryOptions(User $viewer, int $injuryId, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($injuryId, $filters): array {
            $row = $reads->query(WorkplaceInjury::query())->select('id')->findOrFail($injuryId);
            $row = $this->access->assertInjury($row, $current, $reads, $sites, 'recovery');
            $this->access->assertMedicalSource($row, $current, $reads, $sites, 'recovery');
            $directory = $this->staffPage($reads, [(int) $row->site_id], $filters);
            return ['injury_id' => (int) $row->id, 'expected_version' => (int) $row->source_revision,
                'assessors' => $directory['items'], 'assessor_picker' => $directory['pagination'],
                'documents' => $this->connections->documentOptionsInContext($row, $current, $reads, $sites)];
        });
    }

    public function commandOptions(User $viewer, int $injuryId, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($injuryId, $filters): array {
            $identity = $reads->query(WorkplaceInjury::query())->select('id')->findOrFail($injuryId);
            $row = $this->access->assertInjury($identity, $current, $reads, $sites, 'update');
            $proof = $this->access->factualSourceProof($row, $current, $reads, $sites, 'update');
            if ($proof['attribution_state'] === 'repair_required') {
                throw new \LogicException('The injury source requires integrity repair before command options are confirmed.');
            }
            $selectedSite = $filters['site_id'] ?? (int) $row->site_id;
            abort_unless((is_int($selectedSite) || (is_string($selectedSite) && ctype_digit($selectedSite)))
                && (int) $selectedSite > 0 && in_array((int) $selectedSite, $sites, true), 404);
            $selectedSite = (int) $selectedSite;
            $directory = $this->staffPage($reads, [$selectedSite], $filters);
            $coordinators = [];
            foreach ($directory['items'] as $candidate) {
                try {
                    $person = $this->access->currentStaff($candidate['id'], $selectedSite, $current, $reads, $sites, true);
                    $coordinators[] = ['id' => (int) $person->id, 'name' => $person->name];
                } catch (HttpExceptionInterface $error) {
                    if (! in_array($error->getStatusCode(), [403, 404], true)) {
                        throw $error;
                    }
                }
            }
            return ['injury_id' => (int) $row->id, 'canonical_site_id' => (int) $row->site_id, 'site_id' => $selectedSite,
                'sites' => $reads->query(Site::query())->whereIn('id', $sites)->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                    ->map(fn ($site): array => ['id' => (int) $site->id, 'name' => $site->name])->all(),
                'worker_id' => (int) $row->user_id,
                'expected_version' => (int) $row->source_revision, 'workers' => $directory['items'], 'coordinators' => $coordinators,
                'worker_picker' => $directory['pagination'], 'coordinator_picker' => $directory['pagination'],
                'incidents' => $this->connections->incidentOptionsInContext($current, $reads, $sites, $selectedSite)];
        });
    }

    /** Continuation describes the current staff candidate page, never a medical count or eligible coordinator total. */
    private function staffPage(CurrentAuthorizationReads $reads, array $sites, array $filters): array
    {
        $search = is_string($filters['search'] ?? null) ? trim($filters['search']) : '';
        $page = $filters['page'] ?? 1;
        abort_unless(strlen($search) <= 200 && (is_int($page) || (is_string($page) && ctype_digit($page)))
            && (int) $page >= 1 && (int) $page <= 1000000, 422);
        $page = (int) $page;
        $query = $this->staff->currentUsersQuery()->whereHas('hrEmployeeProfile', function (Builder $profile) use ($sites): void {
            $profile->where(function (Builder $scope) use ($sites): void {
                $scope->whereIn('primary_site_id', $sites);
                foreach ($sites as $site) {
                    $scope->orWhereJsonContains('secondary_site_ids', (int) $site);
                }
            });
        });
        if ($search !== '') {
            $query->where('users.name', 'like', '%'.addcslashes($search, '%_\\').'%');
        }
        $rows = $reads->query($query)->with('hrEmployeeProfile')->orderBy('name')->orderBy('id')->offset(($page - 1) * 100)->limit(101)->get();
        $items = $rows->take(100)->map(function (User $person) use ($sites): array {
            $profile = $person->hrEmployeeProfile;
            $personSites = array_values(array_intersect($sites, [(int) $profile->primary_site_id, ...($profile->secondary_site_ids ?? [])]));
            return ['id' => (int) $person->id, 'name' => $person->name, 'site_ids' => $personSites];
        })->all();
        return ['items' => $items, 'pagination' => ['search' => $search, 'page' => $page, 'per_page' => 100,
            'has_more' => $rows->count() > 100, 'next_page' => $rows->count() > 100 ? $page + 1 : null]];
    }

    private function identity(mixed $snapshot): ?array
    {
        if (! is_array($snapshot) || ! is_string($snapshot['name'] ?? null)) {
            return null;
        }
        return ['id' => isset($snapshot['id']) && is_int($snapshot['id']) ? $snapshot['id'] : null, 'name' => $snapshot['name']];
    }

    private function currentIdentity(?int $id, CurrentAuthorizationReads $reads): ?array
    {
        if ($id === null) {
            return null;
        }
        $user = $reads->query(User::query())->whereKey($id)->first(['id', 'name']);
        return $user ? ['id' => (int) $user->id, 'name' => $user->name] : null;
    }

    private function validChild(WorkplaceInjury $row, $child, User $current, CurrentAuthorizationReads $reads, array $sites,
        ?WorkplaceInjurySourceVersion &$version = null): bool
    {
        $version = null;
        try {
            $this->access->assertChild($row, $child, $current, $reads, $sites, 'view');
            // A zero marker is honest legacy only when retained canonical history confirms it.
            $version = $this->access->currentVersionForSource($row, $child, $reads);
            if ((int) $child->source_schema_version === 0) {
                return $version === null;
            }
            return (int) $child->source_schema_version === 1
                && data_get($child->worker_snapshot, 'id') === (int) $row->user_id && $version !== null;
        } catch (\LogicException) {
            return false;
        } catch (HttpExceptionInterface $error) {
            if ($error->getStatusCode() !== 404) {
                throw $error;
            }
            return false;
        }
    }

    private function plans(WorkplaceInjury $row, User $current, CurrentAuthorizationReads $reads, array $sites, string &$integrity): array
    {
        $result = [];
        foreach ($reads->query(ReturnToWorkPlan::query())->where('workplace_injury_id', $row->id)->orderByDesc('created_at')->orderByDesc('id')->get() as $plan) {
            if (! $this->validChild($row, $plan, $current, $reads, $sites, $planVersion)) {
                $integrity = 'repair_required';
                continue;
            }
            $duties = [];
            foreach ($reads->query(ModifiedDuty::query())->where('return_to_work_plan_id', $plan->id)->orderBy('id')->get() as $duty) {
                if (! $this->validChild($row, $duty, $current, $reads, $sites, $dutyVersion)) {
                    $integrity = 'repair_required';
                    continue;
                }
                $duties[] = ['id' => (int) $duty->id, 'source_version' => (int) $duty->source_revision, 'source_version_id' => $dutyVersion ? (int) $dutyVersion->id : null, 'status' => $duty->status,
                    'start_date' => $duty->start_date?->toIso8601String(), 'end_date' => $duty->end_date?->toIso8601String(),
                    'modified_duties_description' => $duty->modified_duties_description, 'restrictions' => $duty->restrictions,
                    'accommodations' => $duty->accommodations, 'hours_per_day' => $duty->hours_per_day === null ? null : (float) $duty->hours_per_day,
                    'notes' => $duty->notes, 'user' => $this->currentIdentity((int) $duty->user_id, $reads),
                    'recorded_worker' => $this->identity($duty->worker_snapshot), 'recorder' => $this->identity($duty->recorder_snapshot),
                    'attribution_state' => (int) $duty->source_schema_version === 0 ? 'legacy_unknown' : 'recorded',
                    'approval_state' => 'unknown', 'effectiveness_state' => 'pending_workforce_interface'];
            }
            $result[] = ['id' => (int) $plan->id, 'source_version' => (int) $plan->source_revision, 'source_version_id' => $planVersion ? (int) $planVersion->id : null, 'status' => $plan->status,
                'plan_start_date' => $plan->plan_start_date?->toIso8601String(), 'plan_end_date' => $plan->plan_end_date?->toIso8601String(),
                'goals' => $plan->goals ?? [], 'stages' => $plan->stages ?? [], 'medical_clearance_notes' => $plan->medical_clearance_notes,
                'medical_clearance_provider' => $plan->medical_clearance_provider, 'medical_clearance_date' => $plan->medical_clearance_date?->toIso8601String(),
                'next_review_date' => $plan->next_review_date?->toIso8601String(), 'review_notes' => $plan->review_notes,
                'workplace_modifications' => $plan->workplace_modifications, 'worker_agreement_notes' => $plan->worker_agreement_notes,
                'worker_agreed' => (bool) $plan->worker_agreed, 'worker_agreed_at' => $plan->worker_agreed_at?->toIso8601String(),
                'worker' => $this->currentIdentity((int) $plan->worker_id, $reads), 'manager' => $this->currentIdentity($plan->manager_id, $reads),
                'recorded_worker' => $this->identity($plan->worker_snapshot), 'recorder' => $this->identity($plan->recorder_snapshot),
                'attribution_state' => (int) $plan->source_schema_version === 0 ? 'legacy_unknown' : 'recorded',
                'medical_certification_state' => 'unverified', 'approval_state' => 'unknown', 'modified_duties' => $duties];
        }
        return $result;
    }

    private function assessments(WorkplaceInjury $row, User $current, CurrentAuthorizationReads $reads, array $sites, array $versions, string &$integrity): array
    {
        $items = [];
        foreach ($reads->query(WorkCapacityAssessment::query())->where('workplace_injury_id', $row->id)->orderByDesc('assessment_date')->orderByDesc('id')->get() as $assessment) {
            if (! $this->validChild($row, $assessment, $current, $reads, $sites, $assessmentVersion)) {
                $integrity = 'repair_required';
                continue;
            }
            $typed = (int) $assessment->source_schema_version === 1;
            $document = ['access' => 'absent', 'id' => null, 'title' => null, 'url' => null, 'availability' => 'unknown'];
            foreach ($versions as $version) {
                if ($version->source_type === 'capacity_assessment' && (int) $version->source_id === (int) $assessment->id) {
                    $documentId = data_get($version->content, 'command_facts.hr_document_id');
                    if (is_int($documentId) && $documentId > 0) {
                        $document = $this->connections->documentInContext($row, $documentId, $current, $reads, $sites);
                    }
                    break;
                }
            }
            $items[] = ['id' => (int) $assessment->id, 'source_version' => (int) $assessment->source_revision, 'source_version_id' => $assessmentVersion ? (int) $assessmentVersion->id : null,
                'assessment_date' => $assessment->assessment_date?->toIso8601String(), 'assessor_name' => $assessment->assessor_name,
                'assessor_type' => $assessment->assessor_type, 'capacity_status' => $assessment->capacity_status,
                'restrictions' => $assessment->restrictions, 'recommendations' => $assessment->recommendations,
                'next_assessment_date' => $assessment->next_assessment_date?->toIso8601String(), 'assessment_summary' => $assessment->assessment_summary,
                'worker' => $this->currentIdentity((int) $row->user_id, $reads),
                'recorded_worker' => $typed ? $this->identity($assessment->worker_snapshot) : null,
                'assessor' => $typed && ($assessment->assessor_user_id === null
                    || data_get($assessment->assessor_snapshot, 'id') === (int) $assessment->assessor_user_id)
                    ? $this->identity($assessment->assessor_snapshot) : null,
                'assessor_identity_kind' => ! $typed ? 'unknown' : ($assessment->assessor_user_id === null ? 'reported_external' : 'recorded_internal'),
                'recorder' => $typed && data_get($assessment->recorder_snapshot, 'id') === (int) $assessment->recorded_by
                    ? $this->identity($assessment->recorder_snapshot) : null,
                'attribution_state' => $typed ? $assessment->attribution_state : 'legacy_unknown',
                'medical_certification_state' => 'unverified', 'hr_document' => $document];
        }
        return $items;
    }

    private function attachments(WorkplaceInjury $row, User $current, CurrentAuthorizationReads $reads, array $sites, string &$integrity): array
    {
        $result = [];
        foreach ($reads->query(WorkplaceInjuryAttachment::query())->where('workplace_injury_id', $row->id)->orderByDesc('created_at')->orderByDesc('id')->get() as $item) {
            $proof = $this->access->evidenceProof($row, $item, $current, $reads, $sites);
            if ($proof['attribution_state'] === 'repair_required') {
                $integrity = 'repair_required';
                continue;
            }
            $result[] = ['id' => (int) $item->id, 'original_name' => $item->original_name, 'mime' => $item->mime, 'kind' => $item->kind,
                'notes' => $item->notes, 'alt_text' => $item->alt_text, 'size' => (int) $item->size, 'is_image' => $item->isImage(),
                'uploaded_by' => $this->currentIdentity($item->uploaded_by, $reads)['name'] ?? null,
                'created_at' => $item->created_at?->toIso8601String(), 'availability' => $proof['available'] ? 'available' : 'unavailable',
                'attribution_state' => $proof['attribution_state'], 'source_version_id' => $proof['source_version_id'],
                'source_revision' => $proof['source_revision'],
                'url' => $proof['available'] && Route::has('health-safety.injuries.attachments.download')
                    ? '/health-safety/injuries/'.$row->id.'/attachments/'.$item->id.'/download' : null];
        }
        return $result;
    }

    private function history(WorkplaceInjury $row, CurrentAuthorizationReads $reads, array $sites): array
    {
        $items = [];
        $validVersions = [];
        $auditVersions = [];
        $siteCorrected = false;
        $integrity = 'valid';
        foreach ($reads->query(WorkplaceInjurySourceVersion::query())->where('workplace_injury_id', $row->id)->orderByDesc('aggregate_revision')->get() as $version) {
            if ((int) $version->worker_id < 1 || (int) $version->site_id < 1
                || (int) $version->aggregate_revision > (int) $row->source_revision
                || ! is_array($version->content) || ! in_array($version->content['scope'] ?? null, ['factual', 'medical'], true)
                || ! hash_equals($version->content_digest, RestraintCommandValues::digest($version->content))
                || ! $this->versionTuple($row, $version, $reads)) {
                $integrity = 'repair_required';
                continue;
            }
            $before = data_get($version->content, 'before');
            $after = data_get($version->content, 'after');
            $beforeSite = $version->source_type === 'injury' && is_array($before)
                ? (int) ($before['site_id'] ?? 0) : (int) $version->site_id;
            $siteCorrected = $siteCorrected || (int) $version->site_id !== (int) $row->site_id
                || ($before !== null && $beforeSite !== (int) $version->site_id);
            $afterAllowed = in_array((int) $version->site_id, $sites, true);
            $beforeAllowed = $before === null || in_array($beforeSite, $sites, true);
            if ($afterAllowed) {
                $validVersions[] = $version;
            }
            if ($afterAllowed && $beforeAllowed) {
                $auditVersions[(int) $version->id] = $version;
            }
            $items[] = ['source_revision' => (int) $version->aggregate_revision, 'source_type' => $version->source_type,
                'command' => $version->command, 'content_scope' => $version->content['scope'],
                'recorded_at' => $version->recorded_at?->toIso8601String(),
                'access' => $afterAllowed && $beforeAllowed ? 'readable' : 'restricted',
                'source_context' => $afterAllowed ? ['worker_id' => (int) $version->worker_id, 'site_id' => (int) $version->site_id] : null,
                'recorder' => $afterAllowed && $beforeAllowed ? $this->identity($version->recorder_snapshot) : null,
                'attribution_state' => (int) $version->source_schema_version === 0 ? 'legacy_unknown' : 'recorded',
                'before_access' => $before === null ? 'absent' : ($beforeAllowed ? 'readable' : 'restricted'),
                'after_access' => $afterAllowed ? 'readable' : 'restricted',
                'before' => $beforeAllowed && is_array($before) ? array_intersect_key($before, array_flip(self::HISTORY_FIELDS)) : null,
                'after' => $afterAllowed && is_array($after) ? array_intersect_key($after, array_flip(self::HISTORY_FIELDS)) : null];
        }
        return ['items' => $items, 'versions' => $validVersions, 'audit_versions' => $auditVersions,
            'site_corrected' => $siteCorrected, 'integrity' => $integrity];
    }

    private function versionTuple(WorkplaceInjury $row, WorkplaceInjurySourceVersion $version, CurrentAuthorizationReads $reads): bool
    {
        $after = $version->content['after'] ?? null;
        if (! is_array($after) || (int) ($after['id'] ?? 0) !== (int) $version->source_id) {
            return false;
        }
        $before = $version->content['before'] ?? null;
        if ($before !== null && (! is_array($before) || (int) ($before['id'] ?? 0) !== (int) $version->source_id
            || ($version->source_type === 'injury' && ((int) ($before['user_id'] ?? 0) < 1 || (int) ($before['site_id'] ?? 0) < 1))
            || (in_array($version->source_type, ['rtw_plan', 'capacity_assessment', 'attachment'], true)
                && (int) ($before['workplace_injury_id'] ?? 0) !== (int) $row->id)
            || ($version->source_type === 'rtw_plan' && (int) ($before['worker_id'] ?? 0) !== (int) $version->worker_id)
            || ($version->source_type === 'modified_duty' && ((int) ($before['user_id'] ?? 0) !== (int) $version->worker_id
                || (int) ($before['return_to_work_plan_id'] ?? 0) !== (int) ($after['return_to_work_plan_id'] ?? 0))))) {
            return false;
        }
        return match ($version->source_type) {
            'injury' => (int) $version->source_id === (int) $row->id
                && (int) ($after['user_id'] ?? 0) === (int) $version->worker_id && (int) ($after['site_id'] ?? 0) === (int) $version->site_id,
            'rtw_plan' => (int) ($after['workplace_injury_id'] ?? 0) === (int) $row->id
                && (int) ($after['worker_id'] ?? 0) === (int) $version->worker_id,
            'capacity_assessment' => (int) ($after['workplace_injury_id'] ?? 0) === (int) $row->id
                && ((int) ($after['source_schema_version'] ?? 0) === 0 || (int) ($after['worker_id'] ?? 0) === (int) $version->worker_id),
            'modified_duty' => (int) ($after['user_id'] ?? 0) === (int) $version->worker_id
                && $reads->query(ReturnToWorkPlan::withTrashed())->whereKey($after['return_to_work_plan_id'] ?? 0)
                    ->where('workplace_injury_id', $row->id)->where('worker_id', $version->worker_id)->exists(),
            'attachment' => (int) ($after['workplace_injury_id'] ?? 0) === (int) $row->id,
            default => false,
        };
    }

    private function audits(WorkplaceInjury $row, CurrentAuthorizationReads $reads, array $history): array
    {
        $items = [];
        foreach ($reads->query(AuditLog::query())->where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)
            ->latest()->orderByDesc('id')->limit(50)->get() as $audit) {
            $meta = is_array($audit->meta) ? $audit->meta : [];
            $versionId = $meta['source_version_id'] ?? null;
            $version = is_int($versionId) ? ($history['audit_versions'][$versionId] ?? null) : null;
            $result = $meta['result'] ?? null;
            $governed = $version !== null && $version->idempotency_key !== null
                && (int) $version->recorded_by === (int) $audit->user_id
                && $audit->action === 'health_safety.injury.'.$version->command
                && ($meta['actor_id'] ?? null) === (int) $version->recorded_by
                && ($meta['idempotency_key'] ?? null) === $version->idempotency_key
                && is_array($result) && ($result['accepted'] ?? null) === true
                && ($result['injury_id'] ?? null) === (int) $row->id
                && ($result['actor_id'] ?? null) === (int) $version->recorded_by
                && ($result['source_type'] ?? null) === $version->source_type
                && ($result['source_id'] ?? null) === (int) $version->source_id
                && ($result['command'] ?? null) === $version->command
                && ($result['source_version_id'] ?? null) === (int) $version->id
                && ($result['version'] ?? null) === (int) $version->aggregate_revision
                && ($result['idempotency_key'] ?? null) === $version->idempotency_key;
            // A legacy audit has no provable former Site. Never assign it the corrected current source context.
            $legacy = ! array_key_exists('source_version_id', $meta) && ! $history['site_corrected'] && $history['integrity'] === 'valid';
            $readable = $governed || $legacy;
            $items[] = ['id' => (int) $audit->id, 'action' => $audit->action, 'at' => $audit->created_at?->toIso8601String(),
                'access' => $readable ? 'readable' : 'restricted',
                'attribution_state' => $governed ? 'recorded' : 'unknown',
                'fields' => $readable ? array_values(array_intersect(is_array($meta['fields'] ?? null) ? $meta['fields'] : [], self::HISTORY_FIELDS)) : [],
                'actor' => $readable ? ($this->currentIdentity($audit->user_id, $reads)['name'] ?? null) : null];
        }
        return $items;
    }
}