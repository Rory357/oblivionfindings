<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use App\Models\WorkplaceInjuryCommandReceipt;
use App\Models\WorkplaceInjurySourceVersion;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\References\ReferenceNumberGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Locked H&S source facts only. Recovery status is never approval, clinical fitness or shift cancellation. */
final class WorkplaceInjurySourceService
{
    public const TYPES = ['strain', 'laceration', 'fracture', 'burn', 'contusion', 'concussion', 'repetitive_strain',
        'chemical_exposure', 'biological_exposure', 'needle_stick', 'slip_trip_fall', 'manual_handling', 'psychological', 'illness', 'other'];
    public const TREATMENTS = ['none', 'first_aid', 'gp_visit', 'hospital', 'emergency_department', 'hospitalisation', 'specialist', 'ongoing'];
    public const TRANSITIONS = ['reported' => ['under_treatment', 'closed'], 'under_treatment' => ['return_to_work', 'recovered', 'closed'],
        'return_to_work' => ['recovered', 'closed'], 'recovered' => ['closed'], 'closed' => []];

    public function __construct(private readonly InjuryAccessService $access, private readonly WorkplaceInjuryJourneyService $journey) {}

    /** Missing/new identities and payloads are strict, including nested whitelist rules supplied by recovery. */
    public static function values(array $input, array $rules, bool $create = false): array
    {
        $envelope = ['idempotency_key' => ['required', 'string', 'uuid']];
        if (! $create) {
            $envelope['expected_version'] = ['required', 'integer', 'min:0', 'max:4294967294'];
        }
        $known = [...array_keys($envelope), ...array_filter(array_keys($rules), fn ($field) => ! str_contains($field, '.'))];
        if (array_diff(array_keys($input), $known)) {
            self::invalid('command', 'Only this operation’s source fields may be submitted.');
        }
        foreach ([...$envelope, ...$rules] as $field => $fieldRules) {
            if (is_bool($input[$field] ?? null) && in_array('integer', $fieldRules, true)) {
                self::invalid($field, 'Use an integer identity or version, not a boolean.');
            }
        }
        $values = Validator::make($input, [...$envelope, ...$rules])->validate();
        foreach ([...$envelope, ...$rules] as $field => $fieldRules) {
            if (isset($values[$field]) && in_array('integer', $fieldRules, true)) {
                $values[$field] = (int) $values[$field];
            }
            if (array_key_exists($field, $values) && in_array('boolean', $fieldRules, true)) {
                if (! is_bool($values[$field]) && ! in_array($values[$field], [0, 1], true)) {
                    self::invalid($field, 'Use a typed factual boolean.');
                }
                $values[$field] = (bool) $values[$field];
            }
        }
        $values['idempotency_key'] = strtolower($values['idempotency_key']);
        return $values;
    }

    public static function date(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            self::invalid($field, 'Use a real calendar date in YYYY-MM-DD form.');
        }
        return $value;
    }

    public static function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public static function identity(User $user): array
    {
        return ['id' => (int) $user->id, 'name' => $user->name];
    }

    private function sourceValues(array $input, bool $create): array
    {
        $rules = ['user_id' => [$create ? 'required' : 'sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'site_id' => [$create ? 'required' : 'sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'related_incident_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'injury_date' => [$create ? 'required' : 'sometimes', 'string'],
            'injury_type' => [$create ? 'required' : 'sometimes', 'in:'.implode(',', self::TYPES)],
            'body_part_affected' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'severity' => [$create ? 'required' : 'sometimes', 'in:minor,moderate,serious,critical'],
            'description' => [$create ? 'required' : 'sometimes', 'string', 'max:5000'],
            'immediate_treatment' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'medical_treatment_type' => [$create ? 'required' : 'sometimes', 'nullable', 'in:'.implode(',', self::TREATMENTS)],
            'worksafe_notifiable' => ['sometimes', 'boolean'], 'acc_claim_lodged' => ['sometimes', 'boolean'],
            'acc_claim_number' => ['sometimes', 'nullable', 'string', 'max:50'], 'lost_time_days' => ['sometimes', 'integer', 'min:0'],
            'expected_return_date' => ['sometimes', 'nullable', 'string'], 'actual_return_date' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000']];
        if (! $create) {
            $rules += ['coordinator_user_id' => ['sometimes', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
                'reason' => ['sometimes', 'required', 'string', 'max:2000'], 'next_review_date' => ['sometimes', 'required', 'string']];
        }
        $values = self::values($input, $rules, $create);
        $transfer = array_intersect(['coordinator_user_id', 'reason', 'next_review_date'], array_keys($values));
        if ($transfer !== [] && count($transfer) !== 3) {
            self::invalid('coordinator_user_id', 'A Site correction with an explicit coordinator requires the named coordinator, reason and review date together.');
        }
        if ($transfer !== []) {
            $values['next_review_date'] = self::date($values['next_review_date'], 'next_review_date');
        }
        if (isset($values['injury_date'])) {
            $values['injury_date'] = RestraintCommandValues::instant($values['injury_date'], 'injury_date');
        }
        foreach (['expected_return_date', 'actual_return_date'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = self::date($values[$field], $field);
            }
        }
        if (isset($values['actual_return_date']) && $values['actual_return_date'] > now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString()) {
            self::invalid('actual_return_date', 'An actual return cannot be recorded in the future.');
        }
        return $values;
    }

    public function create(User $actor, array $input): array
    {
        $values = $this->sourceValues($input, true);
        $key = $values['idempotency_key'];
        $payload = array_diff_key($values, array_flip(['idempotency_key']));
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($payload, $key) {
            $this->access->assertOperation($current, 'create');
            abort_unless(in_array($payload['site_id'], $sites, true), 404);
            $fingerprint = RestraintCommandValues::digest(['injury', null, (int) $current->id, 'create', null, $payload]);
            $accepted = WorkplaceInjuryCommandReceipt::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($accepted) {
                $row = WorkplaceInjury::whereKey($accepted->workplace_injury_id)->lockForUpdate()->firstOrFail();
                $this->access->assertInjury($row, $current, $reads, $sites, 'create');
                $this->assertDependencies($row, $current, $reads, $sites, 'create', $payload);
                return $this->replay($accepted, $row, $current, 'create', $fingerprint, $reads);
            }
            $this->access->currentStaff($payload['user_id'], $payload['site_id'], $current, $reads, $sites);
            $row = new WorkplaceInjury;
            $row->forceFill([...$payload, 'status' => 'reported', 'lost_time_days' => $payload['lost_time_days'] ?? 0,
                'acc_claim_lodged' => $payload['acc_claim_lodged'] ?? filled($payload['acc_claim_number'] ?? null),
                'created_by' => $current->id, 'source_schema_version' => 1, 'source_revision' => 1,
                'source_creation_key' => $key, 'reference_number' => app(ReferenceNumberGenerator::class)->next(WorkplaceInjury::REFERENCE_PREFIX)]);
            // The optional association is independently authorised, not implied by the injury.
            if (isset($payload['related_incident_id'])) {
                $this->access->assertIncident($row, $payload['related_incident_id'], $current, $reads, $sites, true);
            }
            if (! $row->saveQuietly()) {
                    throw new LogicException('The injury source write was not persisted.');
                }
            $row->refresh();
            $this->journey->synchronize($row);
            $version = $this->version($row, $row, $current, 'create', $key, null, $payload);
            return $this->accept($row, $row, $current, 'create', $key, $fingerprint, null, $version, true, $reads);
        });
    }

    public function update(WorkplaceInjury $injury, User $actor, array $input): array
    {
        return $this->execute($injury, $actor, 'update', $input, fn ($data) => $this->sourceValues($data, false),
            function ($row, $current, $reads, $sites, $payload) {
                if (array_intersect(array_keys($payload), InjuryAccessService::PRIVATE_INJURY_FIELDS)) {
                    $this->access->assertMedicalSource($row, $current, $reads, $sites, 'update');
                }
                $worker = (int) ($payload['user_id'] ?? $row->user_id);
                $site = (int) ($payload['site_id'] ?? $row->site_id);
                if (($worker !== (int) $row->user_id || $site !== (int) $row->site_id) && ($row->returnToWorkPlans()->withTrashed()->where('source_schema_version', 1)->exists()
                    || $row->capacityAssessments()->withTrashed()->where('source_schema_version', 1)->exists()
                    || ModifiedDuty::withTrashed()->where('source_schema_version', 1)->whereHas('returnToWorkPlan', fn ($plan) => $plan->withTrashed()->where('workplace_injury_id', $row->id))->exists()
                    || $row->attachments()->withTrashed()->where('source_schema_version', 1)->exists()
                    || WorkplaceInjurySourceVersion::where('workplace_injury_id', $row->id)
                        ->whereIn('source_type', ['capacity_assessment', 'rtw_plan', 'modified_duty', 'attachment'])
                        ->where(fn ($proof) => $proof->where('source_schema_version', '<>', 0)->orWhere('aggregate_revision', '<>', 0)
                            ->orWhereNotNull('factual_content')->orWhereNotNull('factual_digest'))->exists())) {
                    self::invalid($worker !== (int) $row->user_id ? 'user_id' : 'site_id', 'The recorded worker/Site has attributed recovery facts. Reconcile those sources explicitly first.');
                }
                if ($worker !== (int) $row->user_id || $site !== (int) $row->site_id) {
                    $this->access->currentStaff($worker, $site, $current, $reads, $sites);
                }
                $transfer = isset($payload['coordinator_user_id']);
                if ($transfer && $site === (int) $row->site_id) {
                    self::invalid('site_id', 'An explicit coordinator transfer in this command requires a genuine Site correction. Use the assignment command for the current Site.');
                }
                $incidentId = array_key_exists('related_incident_id', $payload) ? $payload['related_incident_id'] : $row->related_incident_id;
                $row->forceFill(array_diff_key($payload, array_flip(['coordinator_user_id', 'reason', 'next_review_date'])));
                if ($transfer) {
                    $coordinator = $this->access->currentStaff($payload['coordinator_user_id'], $site, $current, $reads, $sites, true);
                    $row->forceFill(['coordinator_user_id' => $coordinator->id, 'coordinator_snapshot' => self::identity($coordinator),
                        'next_review_date' => $payload['next_review_date']]);
                }
                if ($incidentId !== null) {
                    $this->access->assertIncident($row, (int) $incidentId, $current, $reads, $sites);
                }
                if (! $row->saveQuietly()) {
                    throw new LogicException('The injury correction was not persisted.');
                }
                return $row;
            }, synchronize: true);
    }

    public function transition(WorkplaceInjury $injury, User $actor, array $input): array
    {
        return $this->execute($injury, $actor, 'transition', $input, fn ($data) => self::values($data,
            ['status' => ['required', 'in:reported,under_treatment,return_to_work,recovered,closed'], 'reason' => ['required', 'string', 'max:2000']]),
            function ($row, $current, $reads, $sites, $payload) {
                if ($payload['status'] !== $row->status && ! in_array($payload['status'], self::TRANSITIONS[$row->status] ?? [], true)) {
                    self::invalid('status', 'Review the current lifecycle; that transition is not available.');
                }
                $row->status = $payload['status'];
                $row->saveQuietly();
                return $row; // No invented actual return, medical clearance or other domain closure.
            });
    }

    public function assign(WorkplaceInjury $injury, User $actor, array $input): array
    {
        return $this->execute($injury, $actor, 'assign', $input, fn ($data) => self::values($data,
            ['coordinator_user_id' => ['required', 'integer', 'min:1'], 'next_review_date' => ['required', 'string'],
                'reason' => ['required', 'string', 'max:2000']]),
            function ($row, $current, $reads, $sites, $payload) {
                $coordinator = $this->access->currentStaff($payload['coordinator_user_id'], (int) $row->site_id, $current, $reads, $sites, true);
                $row->forceFill(['coordinator_user_id' => $coordinator->id, 'coordinator_snapshot' => self::identity($coordinator),
                    'next_review_date' => self::date($payload['next_review_date'], 'next_review_date')])->saveQuietly();
                return $row;
            });
    }

    /** Only source/recovery services should supply operations; no callback lock handle escapes acceptance. */
    public function execute(WorkplaceInjury $injury, User $actor, string $command, array $input, callable $normalize, callable $operation,
        string $sourceType = 'injury', ?int $sourceId = null, bool $medical = false, bool $synchronize = false): array
    {
        $values = $normalize($input);
        $key = $values['idempotency_key'];
        $expected = $values['expected_version'];
        $payload = array_diff_key($values, array_flip(['idempotency_key', 'expected_version']));
        return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($injury, $command, $operation, $sourceType, $sourceId, $medical, $synchronize, $key, $expected, $payload) {
            $op = $sourceType === 'injury' ? $command : ($sourceType === 'attachment' ? 'evidence' : 'recovery');
            $this->access->assertOperation($current, $op);
            $requiresMedical = $medical || ($sourceType === 'injury' && (bool) array_intersect(array_keys($payload), InjuryAccessService::PRIVATE_INJURY_FIELDS));
            abort_unless(! $requiresMedical || $current->canDo('hr.wellbeing.view'), 403);
            $query = WorkplaceInjury::whereKey($injury->id);
            if (! $current->canDo('hr.wellbeing.view')) {
                $query->select(InjuryAccessService::FACTUAL_COLUMNS);
            }
            $row = $query->lockForUpdate()->firstOrFail();
            $this->access->assertInjury($row, $current, $reads, $sites, $op);
            if ($medical) {
                $this->access->assertMedicalSource($row, $current, $reads, $sites, $op);
            }
            $target = $sourceId === null ? ($sourceType === 'injury' ? $row : null) : $this->target($sourceType, $sourceId, $command === 'remove_evidence');
            if ($target && $sourceType !== 'injury') {
                $this->access->assertChild($row, $target, $current, $reads, $sites, $op, $command === 'remove_evidence');
            }
            // Normalized current sensitive fields still need authority BEFORE accepted replay.
            if ($sourceType === 'injury' && array_intersect(array_keys($payload), InjuryAccessService::PRIVATE_INJURY_FIELDS)) {
                $this->access->assertMedicalSource($row, $current, $reads, $sites, $op);
            }
            $this->assertDependencies($row, $current, $reads, $sites, $command, $payload);
            $fingerprint = RestraintCommandValues::digest([$sourceType, (int) $row->id, $sourceId, (int) $current->id, $command, $expected, $payload]);
            $receipt = WorkplaceInjuryCommandReceipt::where('idempotency_key', $key)->lockForUpdate()->first();
            if ($receipt) {
                $result = $this->replay($receipt, $row, $current, $command, $fingerprint, $reads);
                $this->assertSiteCorrectionReceipt($row, $current, $reads, $sites, $result);
                return $result;
            }
            if ((int) $row->source_revision !== $expected) {
                self::invalid('expected_version', 'This injury changed. Keep your draft and explicitly review the current source version.');
            }
            if ($row->status === 'closed' && $command !== 'assign') {
                self::invalid('status', 'A closed source retains its history and cannot accept a new factual command.');
            }
            if ($expected >= 4294967294) {
                self::invalid('expected_version', 'This source revision cannot be safely incremented.');
            }
            if ((int) $row->source_schema_version === 0 && ((int) $row->source_revision !== 0
                || WorkplaceInjurySourceVersion::where('workplace_injury_id', $row->id)->where(fn ($proof) => $proof->where('source_schema_version', '<>', 0)
                    ->orWhere('aggregate_revision', '<>', 0)->orWhereNotNull('factual_content')->orWhereNotNull('factual_digest'))->exists())) {
                throw new LogicException('A governed injury aggregate cannot be downgraded to an unknown legacy source.');
            }
            if ((int) $row->source_schema_version > 0) {
                if ((int) $row->source_schema_version !== 1 || ! WorkplaceInjurySourceVersion::where('workplace_injury_id', $row->id)
                    ->where('aggregate_revision', $row->source_revision)->where('source_schema_version', 1)->exists()) {
                    throw new LogicException('The current injury aggregate revision is missing its immutable source proof.');
                }
                if ($this->access->factualSourceProof($row, $current, $reads, $sites, $op)['attribution_state'] !== 'recorded') {
                    throw new LogicException('The current injury factual snapshot requires source repair.');
                }
                if ($current->canDo('hr.wellbeing.view')) {
                    $this->access->currentVersionForSource($row, $row, $reads);
                }
            }
            if (! WorkplaceInjurySourceVersion::where('workplace_injury_id', $row->id)->exists()) {
                if ((int) $row->source_schema_version !== 0 || (int) $row->source_revision !== 0) {
                    throw new LogicException('An attributed injury source cannot invent a missing historical baseline.');
                }
                $this->version($row, $row, $current, 'legacy_baseline', null, null, []);
            }
            if ($target && $sourceType !== 'injury') {
                $this->access->currentVersionForSource($row, $target, $reads);
            }
            $before = $target?->attributesToArray();
            $subject = $operation($row, $current, $reads, $sites, $payload, $target);
            if (! $subject instanceof Model || $this->type($subject) !== $sourceType || ($sourceId !== null && (int) $subject->id !== $sourceId)) {
                throw new LogicException('An injury command returned a mismatched source subject.');
            }
            if ($sourceType !== 'injury') {
                $this->access->assertChild($row, $subject, $current, $reads, $sites, $op, $command === 'remove_evidence');
            }
            $subject = $this->reloadSubject($subject, $current);
            $changed = ! in_array($command, ['update', 'transition', 'update_rtw_plan'], true) || $this->semantic($before) !== $this->semantic($subject->attributesToArray());
            if ($changed) {
                $row->forceFill(['source_revision' => $expected + 1, 'source_schema_version' => 1, 'updated_by' => $current->id])->saveQuietly();
                if ($sourceType !== 'injury') {
                    $subject->forceFill(['source_revision' => $row->source_revision])->saveQuietly();
                }
            }
            if ($synchronize && $changed) {
                $this->journey->synchronize($row);
            }
            $row = $this->reloadSubject($row, $current);
            $subject = $this->reloadSubject($subject, $current);
            $version = $changed ? $this->version($row, $subject, $current, $command, $key, $before, $payload)
                : WorkplaceInjurySourceVersion::where('workplace_injury_id', $row->id)->where('aggregate_revision', $row->source_revision)->firstOrFail();
            return $this->accept($row, $subject, $current, $command, $key, $fingerprint, $expected, $version, $changed, $reads, $requiresMedical);
        });
    }


    private function reloadSubject(Model $subject, User $current): Model
    {
        $query = $subject->newQuery();
        if ($subject instanceof WorkplaceInjury && ! $current->canDo('hr.wellbeing.view')) {
            $query->select(InjuryAccessService::FACTUAL_COLUMNS);
        }
        if ($subject instanceof WorkplaceInjuryAttachment && $subject->trashed()) {
            $query->withTrashed();
        }
        return $query->whereKey($subject->id)->firstOrFail();
    }

    private function semantic(?array $facts): string
    {
        return RestraintCommandValues::digest($facts === null ? null : array_diff_key($facts, array_flip(['updated_at', 'updated_by'])));
    }

    private function target(string $type, int $id, bool $includeArchived = false): Model
    {
        $class = match ($type) {
            'injury' => WorkplaceInjury::class, 'capacity_assessment' => WorkCapacityAssessment::class,
            'rtw_plan' => ReturnToWorkPlan::class, 'modified_duty' => ModifiedDuty::class, 'attachment' => WorkplaceInjuryAttachment::class,
            default => throw new LogicException('Unsupported injury source type.'),
        };
        $query = $class::query();
        if ($includeArchived && $type === 'attachment') {
            $query->withTrashed();
        }
        return $query->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function type(Model $subject): string
    {
        return match ($subject::class) {
            WorkplaceInjury::class => 'injury', WorkCapacityAssessment::class => 'capacity_assessment',
            ReturnToWorkPlan::class => 'rtw_plan', ModifiedDuty::class => 'modified_duty', WorkplaceInjuryAttachment::class => 'attachment',
            default => throw new LogicException('Unsupported injury source subject.'),
        };
    }

    private function version(WorkplaceInjury $injury, Model $subject, User $recorder, string $command, ?string $key, ?array $before, array $facts): WorkplaceInjurySourceVersion
    {
        $content = ['scope' => $subject instanceof WorkplaceInjury && ! $recorder->canDo('hr.wellbeing.view') && $command !== 'create' ? 'factual' : 'medical', 'before' => $before, 'after' => $subject->attributesToArray(), 'command_facts' => $facts,
            'attribution_state' => $command === 'legacy_baseline' ? 'legacy_unknown' : 'recorded'];
        $factual = $command === 'legacy_baseline' ? null : InjuryAccessService::factualSnapshot(
            WorkplaceInjury::query()->whereKey($injury->id)->firstOrFail(InjuryAccessService::FACTUAL_COLUMNS));
        $factualDigest = $factual === null ? null : RestraintCommandValues::digest($factual);
        $version = WorkplaceInjurySourceVersion::create(['workplace_injury_id' => $injury->id, 'aggregate_revision' => (int) $injury->source_revision,
            'source_schema_version' => $command === 'legacy_baseline' ? 0 : 1, 'source_type' => $this->type($subject), 'source_id' => $subject->id,
            'command' => $command, 'worker_id' => $injury->user_id, 'site_id' => $injury->site_id,
            'prior_version_id' => WorkplaceInjurySourceVersion::where('workplace_injury_id', $injury->id)->max('id'),
            'recorded_by' => $recorder->id, 'recorder_snapshot' => self::identity($recorder), 'content' => $content,
            'content_digest' => RestraintCommandValues::digest($content), 'factual_content' => $factual, 'factual_digest' => $factualDigest,
            'idempotency_key' => $key, 'recorded_at' => now()->utc()]);
        $saved = WorkplaceInjurySourceVersion::whereKey($version->id)->lockForUpdate()->first();
        if (! $saved || ! hash_equals($saved->content_digest, RestraintCommandValues::digest($saved->content))
            || RestraintCommandValues::digest($saved->content) !== RestraintCommandValues::digest($content)
            || $saved->factual_digest !== $factualDigest
            || RestraintCommandValues::digest($saved->factual_content) !== RestraintCommandValues::digest($factual)) {
            throw new LogicException('The exact immutable injury source facts were not persisted.');
        }
        return $saved;
    }

    private function accept(WorkplaceInjury $injury, Model $subject, User $actor, string $command, string $key, string $fingerprint,
        ?int $expected, WorkplaceInjurySourceVersion $version, bool $changed, CurrentAuthorizationReads $reads, bool $requiresMedical = false): array
    {
        $result = ['accepted' => true, 'injury_id' => (int) $injury->id, 'source_type' => $this->type($subject), 'source_id' => (int) $subject->id,
            'actor_id' => (int) $actor->id, 'command' => $command, 'idempotency_key' => $key, 'expected_version' => $expected,
            'version' => (int) $injury->source_revision, 'source_version_id' => (int) $version->id, 'changed' => $changed];
        $request = Request::create('/health-safety/injuries');
        $request->setUserResolver(fn () => $actor);
        $action = 'health_safety.injury.'.$command;
        AuditLogger::logOrFail($action, $injury, ['actor_id' => (int) $actor->id, 'idempotency_key' => $key,
            'source_version_id' => (int) $version->id, 'requires_medical_access' => $requiresMedical, 'result' => $result, 'fingerprint' => $fingerprint], $request);
        $audits = AuditLog::where('user_id', $actor->id)->where('auditable_type', $injury->getMorphClass())
            ->where('auditable_id', $injury->id)->where('action', $action)->where('meta->idempotency_key', $key)->lockForUpdate()->get();
        if ($audits->count() !== 1) {
            throw new LogicException('Injury acceptance requires exactly one actor/command/source-bound audit.');
        }
        $receipt = WorkplaceInjuryCommandReceipt::create(['workplace_injury_id' => $injury->id, 'source_type' => $this->type($subject),
            'source_id' => $subject->id, 'user_id' => $actor->id, 'actor_snapshot' => self::identity($actor), 'command' => $command,
            'expected_version' => $expected, 'requires_medical_access' => $requiresMedical,
            'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'accepted_result' => $result,
            'audit_log_id' => $audits->sole()->id, 'created_at' => now()->utc()])->persisted();
        $confirmed = $this->validatedReceipt($receipt, $injury, $actor, $reads);
        return [...$confirmed, 'replayed' => false];
    }

    private function replay(WorkplaceInjuryCommandReceipt $receipt, WorkplaceInjury $injury, User $actor, string $command,
        string $fingerprint, CurrentAuthorizationReads $reads): array
    {
        if ((int) $receipt->workplace_injury_id !== (int) $injury->id || (int) $receipt->user_id !== (int) $actor->id
            || $receipt->command !== $command || ! hash_equals($receipt->fingerprint, $fingerprint)) {
            self::invalid('idempotency_key', 'This command identity is bound to a different actor, source or submitted payload.');
        }
        return $this->validatedReceipt($receipt, $injury, $actor, $reads);
    }

    public function confirmationForViewer(User $viewer, int $injuryId, string $key): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $key)) {
            return null;
        }
        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($injuryId, $key) {
            $receipt = $reads->query(WorkplaceInjuryCommandReceipt::query())->where('workplace_injury_id', $injuryId)
                ->where('user_id', $current->id)->where('idempotency_key', strtolower($key))->first();
            if (! $receipt) {
                return null;
            }
            abort_unless(! $receipt->requires_medical_access || $current->canDo('hr.wellbeing.view'), 403);
            $query = WorkplaceInjury::query();
            if (! $current->canDo('hr.wellbeing.view')) {
                $query->select(InjuryAccessService::FACTUAL_COLUMNS);
            }
            $injury = $reads->query($query)->whereKey($injuryId)->firstOrFail();
            $op = $receipt->command === 'create' ? 'create' : ($receipt->source_type === 'injury' ? $receipt->command
                : ($receipt->source_type === 'attachment' ? 'evidence' : 'recovery'));
            $this->access->assertInjury($injury, $current, $reads, $sites, $op);
            if ($receipt->requires_medical_access) {
                $this->access->assertMedicalSource($injury, $current, $reads, $sites, $op);
            }
            if ($receipt->source_type !== 'injury') {
                $this->access->assertChild($injury, $this->target($receipt->source_type, (int) $receipt->source_id, $receipt->command === 'remove_evidence'),
                    $current, $reads, $sites, $op, $receipt->command === 'remove_evidence');
            }
            $result = $this->validatedReceipt($receipt, $injury, $current, $reads);
            // Dependencies are inspected only after current original command authority and exact receipt proof.
            $version = $reads->query(WorkplaceInjurySourceVersion::query())->whereKey($result['source_version_id'])->firstOrFail();
            $this->assertDependencies($injury, $current, $reads, $sites, $receipt->command,
                $result['changed'] ? ($version->content['command_facts'] ?? []) : []);
            $this->assertSiteCorrectionReceipt($injury, $current, $reads, $sites, $result);
            return $result;
        });
    }


    private function assertDependencies(WorkplaceInjury $row, User $current, CurrentAuthorizationReads $reads,
        array $sites, string $command, array $payload): void
    {
        if ($command === 'create') {
            $this->access->currentStaff((int) ($payload['user_id'] ?? $row->user_id), (int) ($payload['site_id'] ?? $row->site_id), $current, $reads, $sites);
        }
        if ($command === 'update' && isset($payload['coordinator_user_id'])) {
            // Prove assignment authority on the original locked source; the named replacement needs only the proposed destination.
            $this->access->assertInjury($row, $current, $reads, $sites, 'assign');
            $this->access->currentStaff((int) $payload['coordinator_user_id'], (int) ($payload['site_id'] ?? $row->site_id), $current, $reads, $sites, true);
        } elseif ($command === 'update' && isset($payload['site_id']) && (int) $payload['site_id'] !== (int) $row->site_id) {
            $this->assertRetainedCoordinatorAtSite($row, (int) $payload['site_id'], $current, $reads, $sites);
        }
        $incident = array_key_exists('related_incident_id', $payload) ? $payload['related_incident_id'] : $row->related_incident_id;
        if ($incident !== null && in_array($command, ['create', 'update'], true)) {
            $association = clone $row;
            if (isset($payload['site_id'])) {
                $association->site_id = $payload['site_id'];
            }
            $this->access->assertIncident($association, (int) $incident, $current, $reads, $sites, $command === 'create');
        }
        foreach (['coordinator_user_id', 'manager_id', 'assessor_user_id'] as $field) {
            if (isset($payload[$field]) && ! ($command === 'update' && $field === 'coordinator_user_id')) {
                $this->access->currentStaff((int) $payload[$field], (int) $row->site_id, $current, $reads, $sites, $field !== 'assessor_user_id');
            }
        }
        if (isset($payload['hr_document_id'])) {
            $this->access->assertHrDocument($row, (int) $payload['hr_document_id'], $current, $reads, $sites, 'recovery');
        }
        if (in_array($command, ['create_rtw_plan', 'update_rtw_plan'], true) && isset($payload['worker_id'])) {
            abort_unless((int) $payload['worker_id'] === (int) $row->user_id, 404);
        }
        if (in_array($command, ['create_rtw_plan', 'add_modified_duty'], true)) {
            $this->access->currentStaff((int) $row->user_id, (int) $row->site_id, $current, $reads, $sites);
        }
        if ($command === 'add_modified_duty') {
            abort_unless(! isset($payload['user_id']) || (int) $payload['user_id'] === (int) $row->user_id, 404);
            $plan = ReturnToWorkPlan::whereKey($payload['return_to_work_plan_id'] ?? null)->lockForUpdate()->firstOrFail();
            $this->access->assertChild($row, $plan, $current, $reads, $sites);
            $this->access->currentVersionForSource($row, $plan, $reads);
        }
    }

    private function assertRetainedCoordinatorAtSite(WorkplaceInjury $injury, int $site, User $current,
        CurrentAuthorizationReads $reads, array $sites): void
    {
        abort_unless(in_array($site, $sites, true), 404);
        if ($injury->coordinator_user_id === null) {
            return;
        }
        try {
            $this->access->currentStaff((int) $injury->coordinator_user_id, $site, $current, $reads, $sites, true);
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }
            self::invalid('site_id', 'The retained coordinator cannot currently read this injury at that Site. Explicitly name an eligible coordinator with this Site correction, or assign one before correcting the Site.');
        }
    }

    /** Historical Site corrections do not reapply assignment; current retained ownership must still be safe. */
    private function assertSiteCorrectionReceipt(WorkplaceInjury $injury, User $current, CurrentAuthorizationReads $reads,
        array $sites, array $result): void
    {
        if ($result['command'] !== 'update' || $result['source_type'] !== 'injury' || ! $result['changed']) {
            return;
        }
        $version = $reads->query(WorkplaceInjurySourceVersion::query())->whereKey($result['source_version_id'])->firstOrFail();
        $before = $version->content['before']['site_id'] ?? null;
        $after = $version->content['after']['site_id'] ?? null;
        if ($before !== null && $after !== null && (int) $before !== (int) $after) {
            if (isset($version->content['command_facts']['coordinator_user_id'])) {
                $this->access->assertInjury($injury, $current, $reads, $sites, 'assign');
            }
            $this->access->currentStaff((int) $injury->user_id, (int) $injury->site_id, $current, $reads, $sites);
            $this->assertRetainedCoordinatorAtSite($injury, (int) $injury->site_id, $current, $reads, $sites);
        }
    }

    /** Capture-only recovery lookup is actor/create-bound; it never confers source read. */
    public function confirmationForKey(User $viewer, string $key): ?array
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $key)) {
            return null;
        }
        $injuryId = WorkplaceInjuryCommandReceipt::where('user_id', $viewer->id)->where('command', 'create')
            ->where('source_type', 'injury')->where('idempotency_key', strtolower($key))->value('workplace_injury_id');
        return $injuryId === null ? null : $this->confirmationForViewer($viewer, (int) $injuryId, $key);
    }

    private function validatedReceipt(WorkplaceInjuryCommandReceipt $receipt, WorkplaceInjury $injury, User $actor, CurrentAuthorizationReads $reads): array
    {
        $result = $receipt->accepted_result;
        $keys = ['accepted', 'injury_id', 'source_type', 'source_id', 'actor_id', 'command', 'idempotency_key', 'expected_version', 'version', 'source_version_id', 'changed'];
        if (! is_array($result) || array_diff(array_keys($result), $keys) || array_diff($keys, array_keys($result))
            || (int) $receipt->workplace_injury_id !== (int) $injury->id || (int) $receipt->user_id !== (int) $actor->id
            || $result['accepted'] !== true || $result['injury_id'] !== (int) $injury->id || $result['actor_id'] !== (int) $actor->id
            || $result['command'] !== $receipt->command || $result['source_type'] !== $receipt->source_type
            || $result['source_id'] !== (int) $receipt->source_id || $result['idempotency_key'] !== $receipt->idempotency_key
            || $result['expected_version'] !== $receipt->expected_version || ! is_int($result['version']) || ! is_bool($result['changed'])
            || ! is_int($result['source_version_id']) || $result['source_version_id'] < 1 || $result['version'] < 0
            || $result['version'] > (int) $injury->source_revision
            || $result['version'] !== ($receipt->expected_version === null ? 1 : $receipt->expected_version + ($result['changed'] ? 1 : 0))) {
            throw new LogicException('The retained injury acceptance identity is not trustworthy.');
        }
        $version = $reads->query(WorkplaceInjurySourceVersion::query())->whereKey($result['source_version_id'])->first();
        $audit = $reads->query(AuditLog::query())->whereKey($receipt->audit_log_id)->first();
        if (! $version || (int) $version->workplace_injury_id !== (int) $injury->id || (int) $version->aggregate_revision !== $result['version']
            || ! hash_equals($version->content_digest, RestraintCommandValues::digest($version->content))
            || ($result['changed'] && (! is_array($version->factual_content) || ! is_string($version->factual_digest)
                || ! preg_match('/^[0-9a-f]{64}$/D', $version->factual_digest)
                || ! hash_equals($version->factual_digest, RestraintCommandValues::digest($version->factual_content))
                || ($version->factual_content['schema_version'] ?? null) !== 1
                || ($version->factual_content['injury_id'] ?? null) !== (int) $version->workplace_injury_id
                || ($version->factual_content['aggregate_revision'] ?? null) !== (int) $version->aggregate_revision
                || ($version->factual_content['worker_id'] ?? null) !== (int) $version->worker_id
                || ($version->factual_content['site_id'] ?? null) !== (int) $version->site_id))
            || ($result['changed'] && ($version->command !== $receipt->command || $version->idempotency_key !== $receipt->idempotency_key
                || $version->source_type !== $receipt->source_type || (int) $version->source_id !== (int) $receipt->source_id
                || (int) $version->recorded_by !== (int) $actor->id))
            || ! $audit || (int) $audit->user_id !== (int) $actor->id || $audit->auditable_type !== $injury->getMorphClass()
            || (int) $audit->auditable_id !== (int) $injury->id || $audit->action !== 'health_safety.injury.'.$receipt->command
            || ($audit->meta['idempotency_key'] ?? null) !== $receipt->idempotency_key
            || ($audit->meta['source_version_id'] ?? null) !== $result['source_version_id']
            || ($audit->meta['requires_medical_access'] ?? null) !== $receipt->requires_medical_access
            || ($audit->meta['actor_id'] ?? null) !== (int) $actor->id || ($audit->meta['fingerprint'] ?? null) !== $receipt->fingerprint
            || RestraintCommandValues::digest($audit->meta['result'] ?? null) !== RestraintCommandValues::digest($result)) {
            throw new LogicException('The retained injury acceptance lacks exact immutable source/audit proof.');
        }
        return [...array_intersect_key($result, array_flip($keys)), 'receipt_id' => (int) $receipt->id, 'replayed' => true];
    }
}

