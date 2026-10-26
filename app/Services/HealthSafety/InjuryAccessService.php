<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Models\HrDocument;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrDocumentAccessService;
use App\Models\ClientIncident;
use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Models\WorkplaceInjurySourceVersion;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Current source authority; historical injured identity is not current employment or fitness. */
final class InjuryAccessService
{
    public const PERMISSIONS = ['hazards.view', 'hazards.create', 'hazards.manage', 'hr.wellbeing.view',
        'healthSafety.viewAllSites', 'hr.documents.view', 'hr.documents.manage', 'hr.employees.viewAny',
        'hr.employees.viewAllSites', 'incidents.viewAny', 'incidents.viewAssigned', 'incidents.update',
        'incidents.create', 'hr.cases.view', 'clients.viewAny', 'clients.viewAssigned',
        'controlRoom.viewAny', 'controlRoom.alerts.view', 'controlRoom.alerts.manage', 'reports.viewAny',
        'medications.controlled.view', 'clinical.accessAllSites', 'sites.viewAll', 'sites.view'];

    public const FACTUAL_COLUMNS = ['id', 'user_id', 'site_id', 'reference_number', 'source_schema_version', 'source_revision',
        'status', 'severity', 'injury_type', 'body_part_affected', 'description', 'injury_date', 'related_incident_id',
        'worksafe_notifiable', 'acc_claim_lodged', 'lost_time_days', 'expected_return_date', 'actual_return_date',
        'coordinator_user_id', 'coordinator_snapshot', 'next_review_date', 'created_by', 'updated_by', 'created_at', 'updated_at'];

    public const PRIVATE_INJURY_FIELDS = ['immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'notes'];

    public function __construct(private readonly AuthorizationEvidenceLockService $authorization,
        private readonly HrCurrentStaffService $staff, private readonly UserSiteAccessService $sites,
        private readonly IncidentAccessService $incidents, private readonly HrDocumentAccessService $documents) {}

    public function withCurrentViewer(User $viewer, callable $operation): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($viewer, $operation) {
            $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
            abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->exists(), 403);
            $current->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first());
            $sites = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless($sites !== [], 403);
            $result = $operation($current, $reads, $sites);
            $this->detached($result);
            return $result;
        }));
    }

    /** Reuses the active shared read evidence; preparation never grants injury or medical authority. */
    public function withReadContext(HealthSafetyReadContext $context, callable $operation): mixed
    {
        return $context->consumeViewer(self::PERMISSIONS, function ($current, $reads, $sites) use ($operation) {
            $this->assertOperation($current, 'view');
            $result = $operation($current, $reads, $sites);
            $this->detached($result);
            return $result;
        });
    }

    /** Caller-owned transaction/evidence; never opens a second connection or evidence lifetime. */
    public function withCurrentReads(User $viewer, CurrentAuthorizationReads $reads, callable $operation): mixed
    {
        $reads->assertActive();
        if ($viewer->getConnection() !== DB::connection()) {
            throw new LogicException('The injury viewer uses a different current-read connection.');
        }
        $current = $this->authorization->lockForUserWithoutWaiting($viewer, self::PERMISSIONS);
        abort_unless($reads->query($this->staff->currentUsersQuery())->whereKey($current->id)->exists(), 403);
        $current->setRelation('hrEmployeeProfile', $reads->query(HrEmployeeProfile::query())->where('user_id', $current->id)->first());
        $sites = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
        abort_unless($sites !== [], 403);
        $result = $operation($current, $reads, $sites);
        $reads->assertActive();
        $this->detached($result);

        return $result;
    }

    /** Exact new-admission revision levels; this is private source evidence, never restriction approval. */
    public function workRestrictionSourceForCurrentReads(User $viewer, array $expected, CurrentAuthorizationReads $reads): array
    {
        $keys = ['injury_id', 'expected_injury_revision', 'source_type', 'source_id', 'expected_source_revision', 'expected_source_version_id'];
        $this->restrictionKeys($expected, $keys);
        foreach (array_diff($keys, ['source_type']) as $key) {
            $this->restrictionPositive($expected[$key], $key);
        }
        $this->restrictionType($expected['source_type']);

        return $this->withCurrentReads($viewer, $reads, function ($current, $reads, $sites) use ($expected): array {
            $injury = $this->restrictionParent($expected['injury_id'], $current, $reads, $sites);
            if ((int) $injury->source_revision !== $expected['expected_injury_revision']) {
                WorkplaceInjurySourceService::invalid('expected_injury_revision', 'Review the current aggregate injury revision.');
            }
            $source = $this->restrictionSubject($injury, $expected['source_type'], $expected['source_id'], $current, $reads, $sites);
            $version = $this->currentVersionForSource($injury, $source, $reads);
            if (! $version) {
                throw new LogicException('A restriction source requires recorded typed provenance, not unknown legacy evidence.');
            }
            if ((int) $version->aggregate_revision !== $expected['expected_source_revision']
                || (int) $version->id !== $expected['expected_source_version_id']) {
                WorkplaceInjurySourceService::invalid('expected_source_revision', 'Review this exact source revision and immutable version.');
            }
            $this->restrictionVersion($injury, $source, $version, $expected['source_type'], $current, $reads, $sites);
            if ($injury->coordinator_user_id !== null) {
                $this->restrictionPositive((int) $injury->coordinator_user_id, 'coordinator_user_id');
            }

            return ['injury_id' => (int) $injury->id, 'injury_revision' => (int) $injury->source_revision,
                'worker_id' => (int) $injury->user_id, 'source_site_id' => (int) $injury->site_id,
                'source' => ['type' => $expected['source_type'], 'id' => (int) $source->id,
                    'revision' => (int) $version->aggregate_revision, 'version_id' => (int) $version->id],
                'attribution_state' => 'recorded', 'recorded_by' => (int) $version->recorded_by,
                'recorded_at' => $version->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
                'coordinator_user_id' => $injury->coordinator_user_id === null ? null : (int) $injury->coordinator_user_id];
        });
    }

    /** Recover original immutable recording, independently of later lawful aggregate/source bookkeeping. */
    public function assertRecordedWorkRestrictionSource(User $viewer, array $recorded, CurrentAuthorizationReads $reads): void
    {
        $keys = ['injury_id', 'injury_revision', 'worker_id', 'source_site_id', 'source', 'attribution_state',
            'recorded_by', 'recorded_at', 'coordinator_user_id'];
        $this->restrictionKeys($recorded, $keys);
        if (! is_array($recorded['source'])) {
            throw new LogicException('The retained restriction source identity is malformed.');
        }
        $this->restrictionKeys($recorded['source'], ['type', 'id', 'revision', 'version_id']);
        foreach (['injury_id', 'injury_revision', 'worker_id', 'source_site_id', 'recorded_by'] as $key) {
            $this->restrictionPositive($recorded[$key], $key);
        }
        foreach (['id', 'revision', 'version_id'] as $key) {
            $this->restrictionPositive($recorded['source'][$key], $key);
        }
        $this->restrictionType($recorded['source']['type']);
        if ($recorded['coordinator_user_id'] !== null) {
            $this->restrictionPositive($recorded['coordinator_user_id'], 'coordinator_user_id');
        }
        if ($recorded['attribution_state'] !== 'recorded' || ! is_string($recorded['recorded_at'])
            || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/D', $recorded['recorded_at'])) {
            throw new LogicException('Recovery requires exact typed source attribution and recording precision.');
        }
        $this->withCurrentReads($viewer, $reads, function ($current, $reads, $sites) use ($recorded): void {
            $injury = $this->restrictionParent($recorded['injury_id'], $current, $reads, $sites);
            if ((int) $injury->user_id !== $recorded['worker_id'] || (int) $injury->site_id !== $recorded['source_site_id']
                || (int) $injury->source_revision < $recorded['injury_revision']) {
                throw new LogicException('The retained restriction parent, worker or Site provenance changed.');
            }
            $source = $this->restrictionSubject($injury, $recorded['source']['type'], $recorded['source']['id'], $current, $reads, $sites);
            // Validate today's typed row too; its later content must never replace the original version below.
            $latest = $this->currentVersionForSource($injury, $source, $reads);
            if (! $latest) {
                throw new LogicException('The retained source lost its current typed provenance.');
            }
            $this->restrictionVersion($injury, $source, $latest, $recorded['source']['type'], $current, $reads, $sites);
            $original = $reads->query(WorkplaceInjurySourceVersion::query())->whereKey($recorded['source']['version_id'])
                ->where('workplace_injury_id', $injury->id)->where('source_type', $recorded['source']['type'])
                ->where('source_id', $source->id)->where('worker_id', $injury->user_id)->where('site_id', $injury->site_id)
                ->where('source_schema_version', 1)->firstOrFail();
            $this->restrictionVersion($injury, $source, $original, $recorded['source']['type'], $current, $reads, $sites);
            if ((int) $original->aggregate_revision !== $recorded['source']['revision']
                || (int) $original->aggregate_revision > $recorded['injury_revision']
                || (int) $original->recorded_by !== $recorded['recorded_by']
                || $original->recorded_at->utc()->format('Y-m-d\TH:i:s.u\Z') !== $recorded['recorded_at']) {
                throw new LogicException('The exact retained source recording no longer matches.');
            }
            $aggregate = $reads->query(WorkplaceInjurySourceVersion::query())->where('workplace_injury_id', $injury->id)
                ->where('aggregate_revision', $recorded['injury_revision'])->first(['source_schema_version', 'worker_id', 'site_id', 'factual_content', 'factual_digest']);
            $facts = $aggregate?->factual_content;
            if (! $aggregate || (int) $aggregate->source_schema_version !== 1 || (int) $aggregate->worker_id !== $recorded['worker_id']
                || (int) $aggregate->site_id !== $recorded['source_site_id'] || ! is_array($facts) || ! is_string($aggregate->factual_digest)
                || ! hash_equals($aggregate->factual_digest, RestraintCommandValues::digest($facts))
                || ($facts['injury_id'] ?? null) !== $recorded['injury_id'] || ($facts['worker_id'] ?? null) !== $recorded['worker_id']
                || ($facts['site_id'] ?? null) !== $recorded['source_site_id'] || ($facts['aggregate_revision'] ?? null) !== $recorded['injury_revision']
                || ($facts['coordinator']['id'] ?? null) !== $recorded['coordinator_user_id']) {
                throw new LogicException('The original aggregate source context is unavailable or damaged.');
            }
        });
    }

    private function restrictionParent(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): WorkplaceInjury
    {
        $this->assertOperation($current, 'view');
        abort_unless($current->canDo('hr.wellbeing.view'), 403);
        $injury = $reads->query(WorkplaceInjury::query())->whereKey($id)->whereIn('site_id', $sites)->firstOrFail();
        $this->assertMedicalSource($injury, $current, $reads, $sites);
        if ($this->factualSourceProof($injury, $current, $reads, $sites)['attribution_state'] !== 'recorded') {
            throw new LogicException('The injury aggregate has no intact typed factual source proof.');
        }
        $this->currentVersionForSource($injury, $injury, $reads);

        return $injury;
    }

    private function restrictionSubject(WorkplaceInjury $injury, string $type, int $id, User $current, CurrentAuthorizationReads $reads, array $sites): Model
    {
        if ($type === 'injury') {
            abort_unless((int) $injury->id === $id, 404);

            return $injury;
        }
        if ($type === 'modified_duty') {
            // Canonical plan is consumed before its selected duty; no foreign private row is hydrated first.
            $plan = $reads->query(ReturnToWorkPlan::query())->where('workplace_injury_id', $injury->id)->where('worker_id', $injury->user_id)
                ->whereHas('modifiedDuties', fn (Builder $duty) => $duty->whereKey($id)->where('user_id', $injury->user_id))->firstOrFail();
            $this->assertChild($injury, $plan, $current, $reads, $sites, 'view');
            if (! $this->currentVersionForSource($injury, $plan, $reads)) {
                throw new LogicException('A typed duty requires its intact recorded parent plan.');
            }
            $source = $reads->query(ModifiedDuty::query())->whereKey($id)->where('return_to_work_plan_id', $plan->id)->where('user_id', $injury->user_id)->firstOrFail();
        } else {
            $class = $type === 'capacity_assessment' ? WorkCapacityAssessment::class : ReturnToWorkPlan::class;
            $source = $reads->query($class::query())->whereKey($id)->where('workplace_injury_id', $injury->id)->where('worker_id', $injury->user_id)->firstOrFail();
        }
        $this->assertChild($injury, $source, $current, $reads, $sites, 'view');

        return $source;
    }

    private function restrictionVersion(WorkplaceInjury $injury, Model $source, WorkplaceInjurySourceVersion $version, string $type,
        User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        $content = $version->content;
        $after = $content['after'] ?? null;
        if ((int) $version->workplace_injury_id !== (int) $injury->id || $version->source_type !== $type
            || (int) $version->source_id !== (int) $source->id || (int) $version->source_schema_version !== 1
            || (int) $version->worker_id !== (int) $injury->user_id || (int) $version->site_id !== (int) $injury->site_id
            || (int) $version->aggregate_revision < 1 || (int) $version->aggregate_revision > (int) $injury->source_revision
            || (int) $version->recorded_by < 1 || ($version->recorder_snapshot['id'] ?? null) !== (int) $version->recorded_by
            || ! $version->recorded_at || ! is_array($content) || ($content['attribution_state'] ?? null) !== 'recorded'
            || ! is_string($version->content_digest) || ! hash_equals($version->content_digest, RestraintCommandValues::digest($content))
            || ! is_array($after) || (int) ($after['id'] ?? 0) !== (int) $source->id
            || (int) ($after['source_schema_version'] ?? 0) !== 1 || (int) ($after['source_revision'] ?? 0) !== (int) $version->aggregate_revision) {
            throw new LogicException('The retained typed source version is missing, damaged or belongs to another source.');
        }
        $tuple = match ($type) {
            'injury' => [(int) ($after['id'] ?? 0), (int) ($after['user_id'] ?? 0), (int) ($after['site_id'] ?? 0)]
                === [(int) $injury->id, (int) $injury->user_id, (int) $injury->site_id],
            'capacity_assessment', 'rtw_plan' => (int) ($after['workplace_injury_id'] ?? 0) === (int) $injury->id
                && (int) ($after['worker_id'] ?? 0) === (int) $injury->user_id,
            'modified_duty' => (int) ($after['return_to_work_plan_id'] ?? 0) === (int) $source->return_to_work_plan_id
                && (int) ($after['user_id'] ?? 0) === (int) $injury->user_id,
        };
        if (! $tuple) {
            throw new LogicException('The immutable source has a different parent or injured worker.');
        }
        $document = $content['command_facts']['hr_document_id'] ?? null;
        if ($document !== null) {
            $this->restrictionPositive($document, 'hr_document_id');
            $this->restrictionDocument($injury, $document, $current, $reads);
        }
    }

    /** The canonical HR assertion consumes the same injury-authorised read callback. */
    private function restrictionDocument(WorkplaceInjury $injury, int $id, User $current, CurrentAuthorizationReads $reads): void
    {
        $reads->assertActive();
        abort_unless($current->canDo('hr.wellbeing.view'), 403);
        app(HrDocumentAccessService::class)->assertReadableDocumentForCurrentReads(
            $current, $id, (int) $injury->user_id, $reads,
        );
    }

    private function restrictionKeys(array $values, array $keys): void
    {
        if (array_diff(array_keys($values), $keys) || array_diff($keys, array_keys($values))) {
            throw new LogicException('Use only the exact private restriction source contract.');
        }
    }

    private function restrictionPositive(mixed $value, string $field): void
    {
        if (! is_int($value) || $value < 1) {
            WorkplaceInjurySourceService::invalid($field, 'Use a canonical positive integer source identity or revision.');
        }
    }

    private function restrictionType(mixed $type): void
    {
        if (! in_array($type, ['injury', 'capacity_assessment', 'rtw_plan', 'modified_duty'], true)) {
            WorkplaceInjurySourceService::invalid('source_type', 'Use a supported canonical injury source type.');
        }
    }

    private function detached(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->detached($nested);
            }
        } elseif ($value !== null && ! is_scalar($value)) {
            throw new LogicException('Injury authority callbacks may return detached scalar projections only.');
        }
    }

    public function assertOperation(User $current, string $operation): void
    {
        $allowed = match ($operation) {
            'view' => $current->canDo('hazards.view') || $current->canDo('hr.wellbeing.view'),
            'create' => $current->canDo('hazards.create') || $current->canDo('hazards.manage'),
            'update', 'transition', 'assign', 'review', 'recovery', 'evidence' => $current->canDo('hazards.manage'),
            default => false,
        };
        abort_unless($allowed, 403);
    }

    public function readableQuery(User $current, CurrentAuthorizationReads $reads, array $sites): Builder
    {
        $this->assertOperation($current, 'view');
        $query = WorkplaceInjury::query()->whereIn('site_id', $sites);
        if (! $current->canDo('hr.wellbeing.view')) {
            $query->select(self::FACTUAL_COLUMNS);
        }
        return $reads->query($query);
    }

    public function assertInjury(WorkplaceInjury $injury, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $operation = 'view'): WorkplaceInjury
    {
        $this->assertOperation($current, $operation);
        $query = WorkplaceInjury::query();
        if (! $current->canDo('hr.wellbeing.view')) {
            $query->select(self::FACTUAL_COLUMNS);
        }
        $source = $reads->query($query)->whereKey($injury->id)->firstOrFail();
        abort_unless((int) $source->site_id > 0 && in_array((int) $source->site_id, $sites, true) && (int) $source->user_id > 0, 404);
        return $source;
    }

    public function assertMedicalSource(WorkplaceInjury $injury, User $current, CurrentAuthorizationReads $reads, array $sites,
        string $operation = 'view'): void
    {
        $this->assertInjury($injury, $current, $reads, $sites, $operation);
        abort_unless($current->canDo('hr.wellbeing.view'), 403);
    }

    public function canReadMedical(WorkplaceInjury $injury, User $viewer): bool
    {
        try {
            return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($injury) {
                $this->assertMedicalSource($injury, $current, $reads, $sites);
                return true;
            });
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }
            return false;
        }
    }

    public function currentStaff(int $id, int $siteId, User $current, CurrentAuthorizationReads $reads, array $sites,
        bool $coordinator = false): User
    {
        abort_unless(in_array($siteId, $sites, true), 404);
        $candidate = $reads->query($this->staff->currentUsersQuery())->whereKey($id)
            ->whereHas('hrEmployeeProfile', fn (Builder $profile) => $profile->where(fn (Builder $tuple) => $tuple
                ->where('primary_site_id', $siteId)->orWhereJsonContains('secondary_site_ids', $siteId)))->first();
        abort_unless($candidate, 403);
        $candidate = $this->authorization->lockForUserWithoutWaiting($candidate, self::PERMISSIONS);
        if ($coordinator) {
            $this->assertOperation($candidate, 'view');
            $ownSites = $this->sites->accessibleSiteIds($candidate, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            abort_unless(in_array($siteId, $ownSites, true), 403);
        }
        return $candidate;
    }

    public function assertChild(WorkplaceInjury $injury, Model $child, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $operation = 'recovery', bool $includeArchived = false): void
    {
        $injury = $this->assertInjury($injury, $current, $reads, $sites, $operation);
        $this->assertMedicalSource($injury, $current, $reads, $sites, $operation);
        $query = $child->newQuery();
        if ($includeArchived && $child instanceof WorkplaceInjuryAttachment && $operation === 'evidence') {
            $query->withTrashed();
        }
        $child = $reads->query($query)->whereKey($child->id)->firstOrFail();
        if ($child instanceof ModifiedDuty) {
            $plan = $reads->query(ReturnToWorkPlan::query())->whereKey($child->return_to_work_plan_id)->firstOrFail();
            abort_unless((int) $plan->workplace_injury_id === (int) $injury->id
                && (int) $plan->worker_id === (int) $injury->user_id && (int) $child->user_id === (int) $plan->worker_id, 404);
        } elseif ($child instanceof ReturnToWorkPlan) {
            abort_unless((int) $child->workplace_injury_id === (int) $injury->id && (int) $child->worker_id === (int) $injury->user_id, 404);
        } elseif ($child instanceof WorkCapacityAssessment) {
            abort_unless((int) $child->workplace_injury_id === (int) $injury->id
                && ((int) $child->source_schema_version === 0 || (int) $child->worker_id === (int) $injury->user_id), 404);
        } elseif ($child instanceof WorkplaceInjuryAttachment) {
            abort_unless((int) $child->workplace_injury_id === (int) $injury->id, 404);
        } else {
            throw new LogicException('Unsupported injury child source.');
        }
    }

    public function assertIncident(WorkplaceInjury $injury, int $id, User $current, CurrentAuthorizationReads $reads, array $sites, bool $capture = false): void
    {
        if ($capture) {
            $this->assertOperation($current, 'create');
            abort_unless(in_array((int) $injury->site_id, $sites, true), 404);
        } else {
            $this->assertInjury($injury, $current, $reads, $sites, 'update');
            abort_unless(in_array((int) $injury->site_id, $sites, true), 404);
        }
        $incident = $reads->query(ClientIncident::query())->whereKey($id)->firstOrFail();
        abort_unless($this->sites->effectiveClientIncidentSiteId($incident) === (int) $injury->site_id, 404);
        abort_unless($this->incidents->canView($current, $incident, $reads), 403);
    }

    /** Actual HR document grant and exact injured employee source, independently of injury access. */
    public function assertHrDocument(WorkplaceInjury $injury, int $id, User $current, CurrentAuthorizationReads $reads, array $sites,
        string $operation = 'view'): HrDocument
    {
        $injury = $this->assertInjury($injury, $current, $reads, $sites, $operation);
        $this->assertMedicalSource($injury, $current, $reads, $sites, $operation);
        abort_unless($current->canDo('hr.documents.view'), 403);
        $document = $reads->query($this->documents->applyReadableDocumentScope(HrDocument::query(), $current))->whereKey($id)->firstOrFail();
        $profile = $reads->query(HrEmployeeProfile::withTrashed())->whereKey($document->employee_profile_id)->firstOrFail();
        abort_unless((int) $profile->user_id === (int) $injury->user_id, 404);
        return $document;
    }

    /** Safe factual projection used by rows, tasks, search and export. Private facts are never fallback text. */
    public function project(WorkplaceInjury $injury, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $row = $this->assertInjury($injury, $current, $reads, $sites);
        $worker = $reads->query(User::query())->whereKey($row->user_id)->first(['id', 'name']);
        $projection = ['id' => (int) $row->id, 'reference' => $row->reference_number, 'source_version' => (int) $row->source_revision,
            'status' => $row->status, 'severity' => $row->severity, 'injury_type' => $row->injury_type,
            'body_part_affected' => $row->body_part_affected, 'description' => $row->description,
            'injury_date' => $row->injury_date?->toISOString(), 'site_id' => (int) $row->site_id,
            'worker' => $worker?->only(['id', 'name']), 'worksafe_notifiable' => (bool) $row->worksafe_notifiable,
            'acc_claim_lodged' => (bool) $row->acc_claim_lodged, 'lost_time_days' => (int) $row->lost_time_days,
            'expected_return_date' => $row->expected_return_date?->toDateString(), 'actual_return_date' => $row->actual_return_date?->toDateString(),
            'next_review_date' => $row->next_review_date?->toDateString(), 'medical_access' => $current->canDo('hr.wellbeing.view') ? 'readable' : 'restricted'];
        if ($current->canDo('hr.wellbeing.view')) {
            foreach (self::PRIVATE_INJURY_FIELDS as $field) {
                $projection[$field] = $row->getAttribute($field);
            }
        }
        return $projection;
    }


    /** Internal prospective writer snapshot. No raw model, child, file or arbitrary identity snapshot is retained. */
    public static function factualSnapshot(WorkplaceInjury $injury): array
    {
        $facts = array_intersect_key($injury->attributesToArray(), array_flip(self::FACTUAL_COLUMNS));
        foreach (['id', 'user_id', 'site_id', 'source_revision', 'coordinator_user_id', 'coordinator_snapshot'] as $field) {
            unset($facts[$field]);
        }
        $coordinator = $injury->coordinator_user_id === null ? null : [
            'id' => (int) $injury->coordinator_user_id,
            'name' => is_string($injury->coordinator_snapshot['name'] ?? null) ? $injury->coordinator_snapshot['name'] : null,
        ];
        return ['schema_version' => 1, 'injury_id' => (int) $injury->id, 'worker_id' => (int) $injury->user_id,
            'site_id' => (int) $injury->site_id, 'aggregate_revision' => (int) $injury->source_revision,
            'facts' => $facts, 'coordinator' => $coordinator];
    }

    /** Factual-only current aggregate integrity. Never selects or exposes medical version content/child identity. */
    public function factualSourceProof(WorkplaceInjury $injury, User $current, CurrentAuthorizationReads $reads, array $sites,
        string $operation = 'view'): array
    {
        abort_unless(in_array($operation, ['view', 'update', 'transition', 'assign', 'review', 'recovery', 'evidence'], true), 403);
        $this->assertOperation($current, $operation);
        $row = $reads->query(WorkplaceInjury::query())->whereKey($injury->id)->first(self::FACTUAL_COLUMNS);
        if (! $row || ! in_array((int) $row->site_id, $sites, true) || (int) $row->user_id < 1) {
            abort(404);
        }
        $version = $reads->query(WorkplaceInjurySourceVersion::query())->where('workplace_injury_id', $row->id)
            ->orderByDesc('aggregate_revision')->first(['id', 'workplace_injury_id', 'aggregate_revision',
                'source_schema_version', 'worker_id', 'site_id', 'factual_content', 'factual_digest']);
        if ((int) $row->source_schema_version === 0 && (int) $row->source_revision === 0) {
            $typed = $reads->query(WorkplaceInjurySourceVersion::query())->where('workplace_injury_id', $row->id)
                ->where(fn ($proof) => $proof->where('source_schema_version', '<>', 0)->orWhere('aggregate_revision', '<>', 0)
                    ->orWhereNotNull('factual_content')->orWhereNotNull('factual_digest'))->exists();
            if (! $typed) {
                return ['attribution_state' => 'legacy_unknown', 'source_version_id' => null, 'source_revision' => null];
            }
        }
        $state = ['attribution_state' => 'repair_required', 'source_version_id' => null, 'source_revision' => null];
        if ((int) $row->source_schema_version !== 1 || (int) $row->source_revision < 1 || ! $version
            || (int) $version->source_schema_version !== 1 || (int) $version->aggregate_revision !== (int) $row->source_revision
            || (int) $version->worker_id !== (int) $row->user_id || (int) $version->site_id !== (int) $row->site_id
            || ! is_array($version->factual_content) || ! is_string($version->factual_digest)
            || ! preg_match('/^[0-9a-f]{64}$/D', $version->factual_digest)
            || ! hash_equals($version->factual_digest, RestraintCommandValues::digest($version->factual_content))
            || RestraintCommandValues::digest($version->factual_content) !== RestraintCommandValues::digest(self::factualSnapshot($row))) {
            return $state;
        }
        return ['attribution_state' => 'recorded', 'source_version_id' => (int) $version->id, 'source_revision' => (int) $version->aggregate_revision];
    }

    /** Call only within current private source authority; used by bounded history/operational metadata adapters. */
    public function currentVersionForSource(WorkplaceInjury $injury, Model $source, CurrentAuthorizationReads $reads): ?WorkplaceInjurySourceVersion
    {

        $type = match ($source::class) {
            WorkplaceInjury::class => 'injury', WorkCapacityAssessment::class => 'capacity_assessment',
            ReturnToWorkPlan::class => 'rtw_plan', ModifiedDuty::class => 'modified_duty', WorkplaceInjuryAttachment::class => 'attachment',
            default => throw new LogicException('Unsupported versioned injury source.'),
        };
        $version = $reads->query(WorkplaceInjurySourceVersion::query())->where('workplace_injury_id', $injury->id)
            ->where('source_type', $type)->where('source_id', $source->id)->orderByDesc('aggregate_revision')->first();
        if ((int) ($source->source_schema_version ?? 0) === 0) {
            // Exact subject identity across all retained parents; a forged legacy node/reparent cannot hide typed ownership.
            $governed = $reads->query(WorkplaceInjurySourceVersion::query())->where('source_type', $type)->where('source_id', $source->id)
                ->where(fn ($proof) => $proof->where('source_schema_version', '<>', 0)->orWhere('aggregate_revision', '<>', 0)
                    ->orWhereNotNull('factual_content')->orWhereNotNull('factual_digest'))->select('id')->exists();
            if ((int) ($source->source_revision ?? 0) !== 0 || $governed) {
                throw new LogicException('A governed injury source cannot be downgraded to unknown legacy attribution.');
            }
            return null;
        }
        if ((int) $source->source_schema_version !== 1 || (int) $source->source_revision < 1) {
            throw new LogicException('The injury source provenance marker is not supported.');
        }
        $after = $version?->content['after'] ?? null;
        $excluded = ['updated_at', 'updated_by'];
        if ($source instanceof WorkplaceInjury) {
            // Other child commands advance the aggregate; they do not change this injury-level factual version.
            $excluded = [...$excluded, 'source_revision', 'source_schema_version'];
        }
        if (! $version || ! is_array($after) || (int) $version->worker_id !== (int) $injury->user_id
            || (int) $version->site_id !== (int) $injury->site_id || (int) $version->source_schema_version !== 1
            || ! hash_equals($version->content_digest, RestraintCommandValues::digest($version->content))
            || RestraintCommandValues::digest(array_diff_key($after, array_flip($excluded)))
                !== RestraintCommandValues::digest(array_diff_key(($source instanceof WorkplaceInjury && ($version->content['scope'] ?? null) === 'factual')
                    ? array_intersect_key($source->attributesToArray(), array_flip(self::FACTUAL_COLUMNS)) : $source->attributesToArray(), array_flip($excluded)))
            || (! ($source instanceof WorkplaceInjury) && (int) $version->aggregate_revision !== (int) $source->source_revision)) {
            throw new LogicException('The current injury source does not match its retained immutable version.');
        }
        return $version;
    }

    /** Narrow WF source precondition. This grants no operational approval and returns no clinical prose. */
    public function canonicalSourceForViewer(User $viewer, int $injuryId, array $expected): array
    {
        $rules = ['expected_injury_revision' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'source_type' => ['required', 'in:injury,capacity_assessment,rtw_plan,modified_duty'],
            'source_id' => ['required', 'integer', 'min:1'], 'expected_source_revision' => ['required', 'integer', 'min:0'],
            'expected_source_version_id' => ['present', 'nullable', 'integer', 'min:1']];
        if (array_diff(array_keys($expected), array_keys($rules))) {
            WorkplaceInjurySourceService::invalid('source', 'Use only the canonical source preconditions.');
        }
        foreach ($rules as $field => $fieldRules) {
            if (in_array('integer', $fieldRules, true) && is_bool($expected[$field] ?? null)) {
                WorkplaceInjurySourceService::invalid($field, 'Use an integer source precondition, not a boolean.');
            }
        }
        $values = \Illuminate\Support\Facades\Validator::make($expected, $rules)->validate();
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($injuryId, $values) {
            $this->assertOperation($current, 'view');
            abort_unless($current->canDo('hr.wellbeing.view'), 403);
            $injury = WorkplaceInjury::whereKey($injuryId)->lockForUpdate()->firstOrFail();
            $this->assertMedicalSource($injury, $current, $reads, $sites);
            if ((int) $injury->source_revision !== (int) $values['expected_injury_revision']) {
                WorkplaceInjurySourceService::invalid('expected_injury_revision', 'Review the current aggregate injury revision.');
            }
            if ((int) $injury->source_schema_version !== 0 && ((int) $injury->source_schema_version !== 1
                || ! $reads->query(WorkplaceInjurySourceVersion::query())->where('workplace_injury_id', $injury->id)
                    ->where('aggregate_revision', $injury->source_revision)->where('source_schema_version', 1)->exists())) {
                throw new LogicException('The current injury aggregate revision is missing its immutable source proof.');
            }
            if ((int) $injury->source_schema_version === 0 && ((int) $injury->source_revision !== 0
                || $reads->query(WorkplaceInjurySourceVersion::query())->where('workplace_injury_id', $injury->id)->where(fn ($proof) => $proof->where('source_schema_version', '<>', 0)
                    ->orWhere('aggregate_revision', '<>', 0)->orWhereNotNull('factual_content')->orWhereNotNull('factual_digest'))->exists())) {
                throw new LogicException('A governed injury aggregate cannot be downgraded to an unknown legacy source.');
            }
            $class = match ($values['source_type']) {
                'injury' => WorkplaceInjury::class, 'capacity_assessment' => WorkCapacityAssessment::class,
                'rtw_plan' => ReturnToWorkPlan::class, 'modified_duty' => ModifiedDuty::class,
            };
            $source = $class === WorkplaceInjury::class ? $injury : $reads->query($class::query())->whereKey($values['source_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $source->id === (int) $values['source_id'], 404);
            if (! ($source instanceof WorkplaceInjury)) {
                $this->assertChild($injury, $source, $current, $reads, $sites, 'view');
            }
            $version = $this->currentVersionForSource($injury, $source, $reads);
            $revision = $version === null ? 0 : (int) $version->aggregate_revision;
            if ($revision !== (int) $values['expected_source_revision']
                || ($version?->id === null ? null : (int) $version->id) !== (isset($values['expected_source_version_id']) ? (int) $values['expected_source_version_id'] : null)) {
                WorkplaceInjurySourceService::invalid('expected_source_revision', 'Review this exact source revision and immutable version.');
            }
            return ['injury_id' => (int) $injury->id, 'injury_revision' => (int) $injury->source_revision,
                'worker_id' => (int) $injury->user_id, 'source_site_id' => (int) $injury->site_id,
                'source' => ['type' => $values['source_type'], 'id' => (int) $source->id, 'revision' => $revision, 'version_id' => $version ? (int) $version->id : null],
                'attribution_state' => $version ? 'recorded' : 'legacy_unknown',
                'recorded_by' => $version ? (int) $version->recorded_by : null, 'recorded_at' => $version?->recorded_at?->toISOString(),
                'coordinator' => $injury->coordinator_user_id ? ['id' => (int) $injury->coordinator_user_id,
                    'name' => $injury->coordinator_snapshot['name'] ?? null] : null];
        });
    }


    /** Source-owned byte/provenance proof. Safe metadata only; unknown legacy evidence is not upgraded. */
    public function evidenceProof(WorkplaceInjury $injury, WorkplaceInjuryAttachment $attachment, User $current,
        CurrentAuthorizationReads $reads, array $sites): array
    {
        $injury = $this->assertInjury($injury, $current, $reads, $sites);
        $this->assertChild($injury, $attachment, $current, $reads, $sites, 'view');
        $row = $reads->query(WorkplaceInjuryAttachment::query())->whereKey($attachment->id)->firstOrFail();
        $version = $reads->query(WorkplaceInjurySourceVersion::query())->where('workplace_injury_id', $injury->id)
            ->where('source_type', 'attachment')->where('source_id', $row->id)->orderByDesc('aggregate_revision')->first();
        $available = $row->disk === 'private' && is_string($row->path) && str_starts_with($row->path, 'workplace_injury_attachments/')
            && ! str_contains($row->path, '..') && Storage::disk('private')->exists($row->path);
        if ((int) $row->source_schema_version === 0) {
            // Identity-only prior provenance is retained even if a forged legacy node changes the apparent parent.
            $governed = $reads->query(WorkplaceInjurySourceVersion::query())->where('source_type', 'attachment')->where('source_id', $row->id)
                ->where(fn ($proof) => $proof->where('source_schema_version', '<>', 0)->orWhere('aggregate_revision', '<>', 0)
                    ->orWhereNotNull('factual_content')->orWhereNotNull('factual_digest'))->select('id')->exists();
            if ((int) $row->source_revision !== 0 || $governed) {
                return ['attribution_state' => 'repair_required', 'available' => false,
                    'source_version_id' => $version ? (int) $version->id : null,
                    'source_revision' => $version ? (int) $version->aggregate_revision : (int) $row->source_revision];
            }
            return ['attribution_state' => 'legacy_unknown', 'available' => $available, 'source_version_id' => null, 'source_revision' => null];
        }
        if ($version === null) {
            return ['attribution_state' => 'repair_required', 'available' => false, 'source_version_id' => null, 'source_revision' => (int) $row->source_revision];
        }
        $proof = $version->content['command_facts']['file_evidence'] ?? null;
        $after = $version->content['after'] ?? null;
        $excluded = array_flip(['created_at', 'updated_at']);
        $valid = (int) $row->source_schema_version === 1 && (int) $version->aggregate_revision === (int) $row->source_revision
            && $version->command === 'upload_evidence' && (int) $version->source_schema_version === 1
            && (int) $version->worker_id === (int) $injury->user_id && (int) $version->site_id === (int) $injury->site_id
            && hash_equals($version->content_digest, RestraintCommandValues::digest($version->content))
            && is_array($after) && RestraintCommandValues::digest(array_diff_key($after, $excluded))
                === RestraintCommandValues::digest(array_diff_key($row->attributesToArray(), $excluded))
            && is_array($proof) && is_string($proof['sha256'] ?? null) && is_int($proof['size'] ?? null)
            && $proof['size'] === (int) $row->size;
        if ($valid && $available) {
            $bytes = Storage::disk('private')->get($row->path);
            $valid = strlen($bytes) === $proof['size'] && hash_equals($proof['sha256'], hash('sha256', $bytes));
        }
        return ['attribution_state' => $valid ? 'recorded' : 'repair_required', 'available' => $valid && $available,
            'source_version_id' => (int) $version->id, 'source_revision' => (int) $version->aggregate_revision];
    }

    /** Returns source-guarded download facts inside the callback; never a public filesystem path. */
    public function attachmentForDownload(WorkplaceInjury $injury, WorkplaceInjuryAttachment $attachment, User $viewer): array
    {
        return $this->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($injury, $attachment) {
            $this->assertMedicalSource($injury, $current, $reads, $sites);
            $row = $reads->query(WorkplaceInjuryAttachment::query())->whereKey($attachment->id)->firstOrFail();
            $this->assertChild($injury, $row, $current, $reads, $sites, 'view');
            abort_unless($row->disk === 'private' && is_string($row->path) && str_starts_with($row->path, 'workplace_injury_attachments/')
                && ! str_contains($row->path, '..') && Storage::disk('private')->exists($row->path), 404);
            abort_unless($this->evidenceProof($injury, $row, $current, $reads, $sites)['available'], 404);
            return ['disk' => 'private', 'path' => $row->path, 'original_name' => $row->original_name, 'mime' => $row->mime];
        });
    }
}

