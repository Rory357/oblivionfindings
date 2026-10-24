<?php

namespace App\Services\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use LogicException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Risk commands share one source lock, current authorization and strict semantic history. */
class HsRiskAssessmentService
{
    private \WeakMap $authorityProofs;

    private const PERMISSIONS = ['hazards.manage', 'hazards.view', 'healthSafety.viewAllSites'];

    private const SNAPSHOT_FIELDS = ['title', 'risk_description', 'status', 'likelihood', 'consequence', 'risk_score', 'risk_level', 'existing_controls', 'additional_controls', 'residual_likelihood', 'residual_consequence', 'residual_risk_score', 'residual_risk_level', 'risk_acceptable', 'review_frequency_days', 'approval_note', 'last_review_note'];

    public function __construct(
        private readonly UserSiteAccessService $sites,
        private readonly AuthorizationEvidenceLockService $authorization,
    ) { $this->authorityProofs = new \WeakMap; }

    public function create(array $data, ?User $actor = null, ?HealthSafetyReadContext $context = null): HsRiskAssessment
    {
        $actor ??= auth()->user();
        if ($actor === null) {
            abort_unless($context === null, 403);
            return $this->transaction(fn () => CurrentAuthorizationReads::within(fn ($reads) => $this->createPrepared($data, null, $reads, null)));
        }
        $result = null;
        $this->withinCommandContext($actor, function ($current, $reads, $active) use ($data, &$result): void {
            $result = $this->createPrepared($data, $current, $reads, $active);
        }, $context);
        return $result;
    }

    private function createPrepared(array $data, ?User $current, CurrentAuthorizationReads $reads, ?HealthSafetyReadContext $context): HsRiskAssessment
    {
        if ($current === null) {
            // Trusted ordinary commands have no source-reader identity. They cannot manufacture private-source authority.
            $ids = array_filter([$data['hs_event_id'] ?? null,
                in_array($data['assessable_type'] ?? null, [HsEvent::class, (new HsEvent)->getMorphClass()], true) ? ($data['assessable_id'] ?? null) : null]);
            foreach ($ids as $id) {
                abort_unless(app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query()->whereKey($id), null)->exists(), 403);
            }
        }
        $source = $current ? $this->assertContext($current, $data, $reads, $context) : null;
        if ($source) { abort_unless((clone $source)->exists(), 403); }
        $assessment = new HsRiskAssessment;
        $assessment->forceFill([
            ...$this->draftFields($data), 'reference_number' => HsRiskAssessment::generateReferenceNumber(),
            'status' => HsRiskAssessment::STATUS_DRAFT,
            'assessed_by_user_id' => $current?->id ?? $data['assessed_by_user_id'] ?? null,
            'assessed_at' => now(), 'created_by' => $current?->id ?? $data['created_by'] ?? null,
        ]);
        if (! $assessment->saveOrFail() || ! $assessment->exists) { throw new LogicException('Risk assessment creation was not persisted.'); }
        if ($source) { abort_unless((clone $source)->exists(), 403); }
        $this->audit('risk_assessment.created', $assessment, $current);
        if ($source) { abort_unless((clone $source)->exists(), 403); }
        return $assessment;
    }

    /** HTTP callers derive their detached receipt in this same callback; programmatic model APIs remain compatible. */
    public function withinCommandContext(User $actor, callable $operation, ?HealthSafetyReadContext $context = null): mixed
    {
        $permissions = \App\Support\HealthSafety\RiskAssessmentPresenter::readPermissions();
        $consume = function (HealthSafetyReadContext $active) use ($actor, $permissions, $operation): mixed {
            $active->assertViewer($actor);
            return $active->consumeSourceViewer($permissions, function (User $current, CurrentAuthorizationReads $reads) use ($active, $operation): mixed {
                abort_unless($current->approved_at !== null && $current->canDo('hazards.manage'), 403);
                abort_unless($reads->query(User::query()->staff())->whereKey($current->id)->exists(), 403);
                return $operation($current, $reads, $active);
            });
        };
        return $this->transaction(fn () => $context ? $consume($context) : HealthSafetyReadContext::within($actor, $permissions, $consume));
    }

    public function updateDraft(HsRiskAssessment $assessment, array $data, ?User $actor = null, ?HealthSafetyReadContext $context = null): HsRiskAssessment
    {
        return $this->command($assessment, $actor, function ($locked, $current, $reads, $readContext, $sourceProof) use ($data): HsRiskAssessment {
            $this->requireStatus($locked, [HsRiskAssessment::STATUS_DRAFT], 'Only draft assessments can be edited.');
            $bindingTreatment = $locked->hs_event_id !== null && $reads->query(HsEvent::query())
                ->whereKey($locked->hs_event_id)->whereIn('source_type', array_unique([FirstAidRecord::class, (new FirstAidRecord)->getMorphClass(),
                    \App\Models\RestraintEvent::class, (new \App\Models\RestraintEvent)->getMorphClass(),
                    \App\Models\WorkplaceInjury::class, (new \App\Models\WorkplaceInjury)->getMorphClass()]))->exists();
            $incidentBinding = $locked->hs_event_id !== null && app(\App\Services\Incidents\IncidentHsEventSourceReadService::class)
                ->incidentRelated(HsEvent::query()->whereKey($locked->hs_event_id))->exists();
            $samePrimary = $locked->assessable_type !== null && $locked->hs_event_id !== null
                && ($data['assessable_type'] ?? null) === $locked->assessable_type
                && (int) ($data['assessable_id'] ?? 0) === (int) $locked->assessable_id;
            $modernEvent = in_array($locked->assessable_type, [HsEvent::class, (new HsEvent)->getMorphClass()], true);
            if ($modernEvent && ($data['assessable_type'] ?? null) === null && (int) ($data['hs_event_id'] ?? 0) === (int) $locked->assessable_id) {
                // The existing event wizard speaks the legacy tuple; retain a modern record's original binding on an ordinary edit.
                $data['assessable_type'] = $locked->assessable_type;
                $data['assessable_id'] = $locked->assessable_id;
                $data['hs_event_id'] = $locked->hs_event_id;
            }
            $modernPrivate = $modernEvent && (app(\App\Services\Incidents\IncidentHsEventSourceReadService::class)->incidentRelated(HsEvent::query()->whereKey($locked->assessable_id))->exists()
                || $reads->query(HsEvent::query())->whereKey($locked->assessable_id)->whereIn('source_type', array_unique([
                    FirstAidRecord::class, (new FirstAidRecord)->getMorphClass(), \App\Models\RestraintEvent::class, (new \App\Models\RestraintEvent)->getMorphClass(),
                    \App\Models\WorkplaceInjury::class, (new \App\Models\WorkplaceInjury)->getMorphClass(),
                ]))->exists());
            if ($modernPrivate) {
                if (($data['assessable_type'] ?? null) !== $locked->assessable_type || (int) ($data['assessable_id'] ?? 0) !== (int) $locked->assessable_id) {
                    throw ValidationException::withMessages(['attach_id' => 'Keep this assessment attached to its original safety review. Create a separate assessment for another source.']);
                }
            }
            if ($bindingTreatment || $incidentBinding || $samePrimary) {
                if (isset($data['hs_event_id']) && (int) $data['hs_event_id'] !== (int) $locked->hs_event_id) {
                    throw ValidationException::withMessages(['attach_id' => 'Keep the original safety review linked to this assessment.']);
                }
                // A primary reattachment cannot detach private source provenance protecting retained narrative/history.
                $data['hs_event_id'] = $locked->hs_event_id;
            }
            if ($current) {
                $newSource = $this->assertContext($current, $data, $reads, $readContext);
                $sourceProof->whereExists((clone $newSource)->selectRaw('1')->toBase());
            }
            $this->persistRisk($locked, [...$this->draftFields($data), 'updated_by' => $current?->id], $reads, $readContext, $sourceProof);
            $this->audit('risk_assessment.draft_updated', $locked, $current);

            return $locked;
        }, $context);
    }

    public function activate(HsRiskAssessment $assessment, array $approval = [], ?User $actor = null, ?HealthSafetyReadContext $context = null): HsRiskAssessment
    {
        return $this->command($assessment, $actor, function ($locked, $current, $reads, $readContext, $sourceProof) use ($approval): HsRiskAssessment {
            $this->requireStatus($locked, [HsRiskAssessment::STATUS_DRAFT], 'Only draft assessments can be approved and activated.');
            $this->persistRisk($locked, [
                'status' => HsRiskAssessment::STATUS_ACTIVE,
                'approved_by_user_id' => $current?->id ?? $approval['approved_by_user_id'] ?? null,
                'approved_at' => now(),
                'approval_note' => $approval['approver_note'] ?? null,
                'review_due_at' => $locked->review_due_at?->toDateString()
                    ?? ($locked->review_frequency_days ? now(config('app.worker_timezone', 'Pacific/Auckland'))->addDays($locked->review_frequency_days)->toDateString() : null),
                'updated_by' => $current?->id,
            ], $reads, $readContext, $sourceProof);
            $this->audit('risk_assessment.status_changed', $locked, $current, $approval['approver_note'] ?? null, ['from' => 'draft', 'to' => 'active']);

            return $locked;
        }, $context);
    }

    public function markForReview(HsRiskAssessment $assessment, ?User $actor = null, ?HealthSafetyReadContext $context = null): HsRiskAssessment
    {
        return $this->command($assessment, $actor, function ($locked, $current, $reads, $readContext, $sourceProof): HsRiskAssessment {
            $this->requireStatus($locked, [HsRiskAssessment::STATUS_ACTIVE], 'Only active assessments can be marked for review.');
            $this->persistRisk($locked, ['status' => HsRiskAssessment::STATUS_UNDER_REVIEW, 'updated_by' => $current?->id], $reads, $readContext, $sourceProof);
            $this->audit('risk_assessment.status_changed', $locked, $current, null, ['from' => 'active', 'to' => 'under_review']);

            return $locked;
        }, $context);
    }

    public function updateResidualRisk(HsRiskAssessment $assessment, int $likelihood, int $consequence, ?bool $acceptable = null, ?User $actor = null, ?string $note = null, bool $acceptabilitySubmitted = true, ?HealthSafetyReadContext $context = null): HsRiskAssessment
    {
        return $this->command($assessment, $actor, function ($locked, $current, $reads, $readContext, $sourceProof) use ($likelihood, $consequence, $acceptable, $note, $acceptabilitySubmitted): HsRiskAssessment {
            $this->requireMutable($locked);
            Validator::make(['residual_likelihood' => $likelihood, 'residual_consequence' => $consequence], [
                'residual_likelihood' => ['required', 'integer', 'between:1,5'],
                'residual_consequence' => ['required', 'integer', 'between:1,5'],
            ])->validate();
            $residual = HsRiskAssessment::calculateScore($likelihood, $consequence);
            $this->persistRisk($locked, [
                'residual_likelihood' => $likelihood, 'residual_consequence' => $consequence,
                'residual_risk_score' => $residual['score'], 'residual_risk_level' => $residual['level'],
                'risk_acceptable' => $acceptabilitySubmitted ? $acceptable : $locked->risk_acceptable,
                'last_review_note' => $note ?? $locked->last_review_note, 'updated_by' => $current?->id,
            ], $reads, $readContext, $sourceProof);
            $this->audit('risk_assessment.residual_recorded', $locked, $current, $note);

            return $locked;
        }, $context);
    }

    public function completeReview(HsRiskAssessment $assessment, array $data, ?User $actor = null, ?HealthSafetyReadContext $context = null): HsRiskAssessment
    {
        return $this->command($assessment, $actor, function ($locked, $current, $reads, $readContext, $sourceProof) use ($data): HsRiskAssessment {
            $this->requireStatus($locked, [HsRiskAssessment::STATUS_UNDER_REVIEW], 'Request review before completing it.');
            if ($locked->approved_by_user_id === null || $locked->approved_at === null
                || ! in_array($locked->residual_likelihood, [1, 2, 3, 4, 5], true)
                || ! in_array($locked->residual_consequence, [1, 2, 3, 4, 5], true)
                || $locked->risk_acceptable === null) {
                throw ValidationException::withMessages(['review_note' => 'Record the prior approval, residual risk and explicit acceptability before completing review.']);
            }
            $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
            Validator::make($data, [
                'review_note' => ['required', 'string', 'max:5000'],
                'review_due_at' => ['required', 'date_format:Y-m-d', 'after:'.$today],
                'review_frequency_days' => ['sometimes', 'nullable', 'integer', 'in:30,90,180,365'],
            ])->validate();
            if (trim((string) ($data['review_note'] ?? '')) === '') {
                throw ValidationException::withMessages(['review_note' => 'Add a review note and choose a future NZ review date.']);
            }
            $this->persistRisk($locked, [
                'status' => HsRiskAssessment::STATUS_ACTIVE, 'last_review_note' => $data['review_note'],
                'review_due_at' => $data['review_due_at'], 'updated_by' => $current?->id,
                'review_frequency_days' => array_key_exists('review_frequency_days', $data) ? $data['review_frequency_days'] : $locked->review_frequency_days,
            ], $reads, $readContext, $sourceProof);
            $this->audit('risk_assessment.review_completed', $locked, $current, $data['review_note'], [
                'from' => 'under_review', 'to' => 'active', 'reviewed_at' => now()->toISOString(),
                'approved_by_user_id' => $locked->approved_by_user_id, 'approved_at' => $locked->approved_at->toISOString(),
            ]);

            return $locked;
        }, $context);
    }

    public function supersede(HsRiskAssessment $assessment, array $newData, ?User $actor = null, ?HealthSafetyReadContext $context = null): HsRiskAssessment
    {
        return $this->command($assessment, $actor, function ($locked, $current, $reads, $readContext, $sourceProof) use ($newData): HsRiskAssessment {
            $this->requireStatus($locked, [HsRiskAssessment::STATUS_ACTIVE, HsRiskAssessment::STATUS_UNDER_REVIEW], 'Only active or under-review assessments can be superseded.');
            if ($current && array_key_exists('assessable_type', $newData)) {
                $this->assertContext($current, $newData, $reads, $readContext);
                $source = $locked->assessable_type !== null
                    ? [$locked->assessable_type, (int) $locked->assessable_id]
                    : ($locked->hs_event_id !== null ? [HsEvent::class, (int) $locked->hs_event_id] : [null, null]);
                $destination = ($newData['assessable_type'] ?? null) !== null
                    ? [$newData['assessable_type'], (int) $newData['assessable_id']]
                    : (isset($newData['hs_event_id']) ? [HsEvent::class, (int) $newData['hs_event_id']] : [null, null]);
                if ($source !== $destination) {
                    throw ValidationException::withMessages(['attach_id' => 'A successor must remain attached to the same source. Create a separate assessment for another source.']);
                }
            }
            $retained = ['assessable_type' => $locked->assessable_type, 'assessable_id' => $locked->assessable_id, 'hs_event_id' => $locked->hs_event_id];
            // A new version always retains the source tuple, including valid dual provenance.
            $new = $this->create(['review_frequency_days' => $locked->review_frequency_days, ...$newData, ...$retained], $current, $readContext);
            $previous = $locked->status;
            $this->persistRisk($locked, ['status' => HsRiskAssessment::STATUS_SUPERSEDED, 'superseded_by_id' => $new->id, 'updated_by' => $current?->id], $reads, $readContext, $sourceProof);
            $this->audit('risk_assessment.status_changed', $locked, $current, null, ['from' => $previous, 'to' => 'superseded', 'successor_id' => $new->id]);

            return $new;
        }, $context);
    }

    public function archive(HsRiskAssessment $assessment, ?User $actor = null, ?HealthSafetyReadContext $context = null): HsRiskAssessment
    {
        return $this->command($assessment, $actor, function ($locked, $current, $reads, $readContext, $sourceProof): HsRiskAssessment {
            $this->requireMutable($locked);
            $previous = $locked->status;
            $this->persistRisk($locked, ['status' => HsRiskAssessment::STATUS_ARCHIVED, 'updated_by' => $current?->id], $reads, $readContext, $sourceProof);
            $this->audit('risk_assessment.status_changed', $locked, $current, null, ['from' => $previous, 'to' => 'archived']);

            return $locked;
        }, $context);
    }

    /** Shared lock for evidence commands; attachments cannot race a finishing transition. */
    public function command(HsRiskAssessment $assessment, ?User $actor, callable $operation, ?HealthSafetyReadContext $context = null): mixed
    {
        $actor ??= auth()->user();
        $result = null;
        $consume = function (?User $current, CurrentAuthorizationReads $reads, ?HealthSafetyReadContext $active) use ($assessment, $operation, &$result): void {
            $query = HsRiskAssessment::query()->whereKey($assessment->getKey());
            if ($current) {
                \App\Support\HealthSafety\RiskAssessmentPresenter::applySiteScopeInContext($query, $current, $active);
                $query->whereExists($this->authorityQueryInContext($current, $active, self::PERMISSIONS)->selectRaw('1')->toBase());
            }
            $risk = app(HsEventAccessService::class)->applyFirstAidRiskSourceScope($query, $current, $active);
            $locked = $reads->query(clone $risk)->lockForUpdate()->firstOrFail();
            $source = $current ? $this->assertContext($current, $locked->getAttributes(), $reads, $active) : null;
            $result = $operation($locked, $current, $reads, $active, $source, $risk);
            if ($source) { abort_unless((clone $source)->exists(), 403, 'The original risk source changed before completion.'); }
        };
        if ($actor) { $this->withinCommandContext($actor, $consume, $context); }
        else {
            abort_unless($context === null, 403);
            $this->transaction(fn () => CurrentAuthorizationReads::within(fn ($reads) => $consume(null, $reads, null)));
        }
        return $result;
    }

    private function persistRisk(HsRiskAssessment $record, array $attributes, CurrentAuthorizationReads $reads, ?HealthSafetyReadContext $context, ?Builder $source): void
    {
        $original = $record->getRawOriginal();
        $record->forceFill($attributes);
        if ($record->usesTimestamps()) { $record->setUpdatedAt($record->freshTimestamp()); }
        $target = HsRiskAssessment::query()->whereKey($record->id);
        foreach (['assessable_type', 'assessable_id', 'hs_event_id'] as $column) {
            ($original[$column] ?? null) === null ? $target->whereNull($column) : $target->where($column, $original[$column]);
        }
        if ($source) {
            $target->whereExists((clone $source)->selectRaw('1')->toBase());
            $context->reconsumeIncidentPredicate($target, $source);
        }
        $dirty = $record->getDirty();
        if ($dirty === []) { abort_unless($reads->query($target)->exists(), 403); }
        else { abort_unless($reads->query($target)->update($dirty) === 1, 403, 'The original risk source changed before persistence.'); }
        $record->syncChanges(); $record->syncOriginal();
    }

    /** Preserve the ordinary soft-delete hooks while retaining source authority on the actual UPDATE. */
    public function deleteAttachment(HsRiskAssessmentAttachment $attachment, Builder $originalRisk, HealthSafetyReadContext $context): void
    {
        $context->consumeSourceViewer(\App\Support\HealthSafety\RiskAssessmentPresenter::readPermissions(), function (User $current, CurrentAuthorizationReads $reads) use ($attachment, $originalRisk, $context): void {
            $event = function (string $name, bool $halt = true) { return $this->fireModelEvent($name, $halt); };
            if ($event->call($attachment, 'deleting') === false) { throw new LogicException('Risk evidence removal was vetoed.'); }
            (function (): void { $this->touchOwners(); })->call($attachment);
            $target = HsRiskAssessmentAttachment::query()->whereKey($attachment->id);
            foreach (['hs_risk_assessment_id', 'disk', 'path'] as $key) {
                $value = $attachment->getRawOriginal($key);
                $value === null ? $target->whereNull($key) : $target->where($key, $value);
            }
            $target->whereExists((clone $originalRisk)->setEagerLoads([])->selectRaw('1')->toBase());
            $context->reconsumeIncidentPredicate($target, $originalRisk);
            $time = $attachment->freshTimestamp();
            $values = [$attachment->getDeletedAtColumn() => $attachment->fromDateTime($time)];
            if ($attachment->usesTimestamps() && $attachment->getUpdatedAtColumn() !== null) {
                $values[$attachment->getUpdatedAtColumn()] = $attachment->fromDateTime($time);
            }
            abort_unless($reads->query($target)->update($values) === 1, 403, 'The original evidence source changed before removal.');
            $attachment->forceFill($values)->syncOriginalAttributes(array_keys($values));
            $event->call($attachment, 'trashed', false);
            $event->call($attachment, 'deleted', false);
        });
    }

    public function requireMutable(HsRiskAssessment $assessment): void
    {
        $this->requireStatus($assessment, [HsRiskAssessment::STATUS_DRAFT, HsRiskAssessment::STATUS_ACTIVE, HsRiskAssessment::STATUS_UNDER_REVIEW], 'This assessment is finished. Its recorded details and evidence cannot be changed.');
    }

    public function audit(string $action, HsRiskAssessment $assessment, ?User $actor, ?string $note = null, array $meta = []): void
    {
        $snapshot = $assessment->only(self::SNAPSHOT_FIELDS);
        $snapshot['review_due_at'] = $assessment->review_due_at?->toDateString();
        $auditRequest = clone request();
        if ($actor) {
            $auditRequest->setUserResolver(fn () => $actor);
        }
        AuditLogger::logOrFail($action, $assessment, [...$meta, 'snapshot' => $snapshot, 'note' => $note, 'actor_id' => $actor?->id], $auditRequest);
    }

    /** Fence every concrete provenance read rather than trusting ordinary cached relations. */
    private function assertContext(User $actor, array $data, CurrentAuthorizationReads $reads, ?HealthSafetyReadContext $context): Builder
    {
        $proof = $this->authorityQueryInContext($actor, $context, self::PERMISSIONS);
        $type = $data['assessable_type'] ?? null;
        $id = isset($data['assessable_id']) ? (int) $data['assessable_id'] : null;
        $eventId = isset($data['hs_event_id']) ? (int) $data['hs_event_id'] : null;
        abort_unless(($type === null) === ($id === null), 403);
        $siteIds = $this->sites->accessibleSiteIds($actor, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
        $eventSite = null;
        if ($eventId !== null) {
            $eventQuery = app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query()->whereKey($eventId), $actor, $context);
            $event = $reads->query(clone $eventQuery)->first(['id', 'site_id']);
            $proof->whereExists((clone $eventQuery)->selectRaw('1')->toBase());
            abort_unless($event && $event->site_id !== null, 403);
            $eventSite = (int) $event->site_id;
            abort_unless(in_array($eventSite, $siteIds, true), 403);
        }
        $assessableSite = null;
        if ($type === Site::class) {
            $siteQuery = Site::query()->whereKey($id)->whereIn('id', $siteIds)->whereExists((clone $proof)->selectRaw('1')->toBase());
            $site = $reads->query(clone $siteQuery)->first(['id']);
            abort_unless($site, 403);
            $assessableSite = (int) $site->id;
            $proof->whereExists($siteQuery->selectRaw('1')->toBase());
        } elseif ($type === Client::class) {
            $clientQuery = Client::query()->whereKey($id)->whereIn('site_id', $siteIds)->whereExists((clone $proof)->selectRaw('1')->toBase());
            $client = $reads->query(clone $clientQuery)->first(['id', 'site_id']);
            abort_unless($client && $client->site_id !== null, 403);
            $assessableSite = (int) $client->site_id;
            $clientQuery->where('site_id', $client->site_id);
            $proof->whereExists($clientQuery->selectRaw('1')->toBase());
        } elseif (in_array($type, [HsEvent::class, (new HsEvent)->getMorphClass()], true)) {
            $eventQuery = app(HsEventAccessService::class)->applyFirstAidSourceScope(HsEvent::query()->whereKey($id), $actor, $context);
            $event = $reads->query(clone $eventQuery)->first(['id', 'site_id']);
            abort_unless($event && $event->site_id !== null, 403);
            $assessableSite = (int) $event->site_id;
            $proof->whereExists((clone $eventQuery)->selectRaw('1')->toBase());
        } elseif ($type !== null) {
            abort(403);
        }
        if ($assessableSite !== null) {
            abort_unless(in_array($assessableSite, $siteIds, true), 403);
        }
        abort_unless($eventSite === null || $assessableSite === null || $eventSite === $assessableSite, 403);
        if ($eventSite === null && $assessableSite === null) {
            abort_unless($this->sites->canBypass($actor, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS), 403);
        }
        return $context->reconsumeIncidentPredicate($proof, clone $proof);
    }

    private function draftFields(array $data): array
    {
        Validator::make($data, [
            'likelihood' => ['required', 'integer', 'between:1,5'],
            'consequence' => ['required', 'integer', 'between:1,5'],
            'residual_likelihood' => ['nullable', 'required_with:residual_consequence', 'integer', 'between:1,5'],
            'residual_consequence' => ['nullable', 'required_with:residual_likelihood', 'integer', 'between:1,5'],
            'risk_acceptable' => ['nullable', 'boolean'],
            'review_due_at' => ['nullable', 'date_format:Y-m-d'],
            'review_frequency_days' => ['nullable', 'integer', 'in:30,90,180,365'],
        ])->validate();
        $inherent = HsRiskAssessment::calculateScore((int) $data['likelihood'], (int) $data['consequence']);
        $residual = isset($data['residual_likelihood'], $data['residual_consequence']) ? HsRiskAssessment::calculateScore((int) $data['residual_likelihood'], (int) $data['residual_consequence']) : null;

        return [
            'assessable_type' => $data['assessable_type'] ?? null, 'assessable_id' => $data['assessable_id'] ?? null, 'hs_event_id' => $data['hs_event_id'] ?? null,
            'title' => $data['title'], 'risk_description' => $data['risk_description'] ?? null,
            'likelihood' => (int) $data['likelihood'], 'consequence' => (int) $data['consequence'], 'risk_score' => $inherent['score'], 'risk_level' => $inherent['level'],
            'existing_controls' => $data['existing_controls'] ?? null, 'additional_controls' => $data['additional_controls'] ?? null,
            'residual_likelihood' => $data['residual_likelihood'] ?? null, 'residual_consequence' => $data['residual_consequence'] ?? null,
            'residual_risk_score' => $residual['score'] ?? null, 'residual_risk_level' => $residual['level'] ?? null,
            'risk_acceptable' => $data['risk_acceptable'] ?? null, 'review_frequency_days' => $data['review_frequency_days'] ?? null, 'review_due_at' => $data['review_due_at'] ?? null,
        ];
    }

    private function requireStatus(HsRiskAssessment $assessment, array $allowed, string $message): void
    {
        if (! in_array($assessment->status, $allowed, true)) {
            throw new \InvalidArgumentException($message);
        }
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (\Illuminate\Database\DeadlockException) {
                // Inside a transaction Laravel raises a deadlock (1213) as DeadlockException, not QueryException.
                throw ValidationException::withMessages(['assessment' => 'This assessment or its access is changing. Reload it before trying again.']);
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw ValidationException::withMessages(['assessment' => 'This assessment or its access is changing. Reload it before trying again.']);
            }
            throw $error;
        }
    }

    public function authorityQueryInContext(User $current, HealthSafetyReadContext $context, array $keys = ['hazards.view', 'hazards.manage', 'healthSafety.viewAllSites', 'governance.view']): Builder
    {
        $context->assertViewer($current); sort($keys); $cacheKey = implode('|', $keys);
        $cached = $this->authorityProofs[$context] ?? [];
        if (isset($cached[$cacheKey])) { return clone $cached[$cacheKey]; }
        $query = $context->consumeSourceViewer($keys, function (User $current, CurrentAuthorizationReads $reads) use ($keys): Builder {
            $proof = User::query()->whereKey($current->id);
            foreach (['id', 'role', 'approved_at', 'external_clinical_account'] as $column) { $this->exactValue($proof, 'users.'.$column, $current->getRawOriginal($column)); }
            $definitions = $reads->query(DB::table('permissions')->whereIn('key', $keys))->get(['id', 'key'])->map(fn ($row) => (array) $row)->all();
            $ids = array_column($definitions, 'id'); $roles = []; $members = []; $grants = []; $overrides = [];
            foreach ($current->roles as $role) {
                $roles[] = ['id' => (int) $role->id, 'name' => $role->getRawOriginal('name')];
                $members[] = ['role_id' => (int) $role->id, 'user_id' => (int) $current->id];
                foreach ($role->permissions as $permission) { if (in_array($permission->key, $keys, true)) { $grants[] = ['role_id' => (int) $role->id, 'permission_id' => (int) $permission->id]; } }
            }
            foreach ($current->permissionOverrides as $permission) { if (in_array($permission->key, $keys, true)) { $overrides[] = ['permission_id' => (int) $permission->id, 'user_id' => (int) $current->id, 'allowed' => (int) (bool) $permission->pivot->allowed]; } }
            $roleIds = array_column($roles, 'id');
            foreach ([
                [DB::table('permissions')->whereIn('key', $keys), $definitions, ['id']],
                [DB::table('role_user')->where('user_id', $current->id), $members, ['role_id', 'user_id']],
                [DB::table('roles')->whereIn('id', $roleIds), $roles, ['id']],
                [DB::table('role_permission')->whereIn('role_id', $roleIds)->whereIn('permission_id', $ids), $grants, ['role_id', 'permission_id']],
                [DB::table('permission_user')->where('user_id', $current->id)->whereIn('permission_id', $ids), $overrides, ['permission_id', 'user_id']],
            ] as [$selector, $rows, $identity]) { $this->expectedSet($proof, $selector, $rows, $identity); }
            $profiles = DB::table('hr_employee_profiles')->where('user_id', $current->id)->whereNull('deleted_at');
            $rows = $reads->query(clone $profiles)->get(['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at'])->map(fn ($row) => (array) $row)->all();
            if ($current->relationLoaded('hrEmployeeProfile') && $current->hrEmployeeProfile) {
                $originalProfile = array_intersect_key($current->hrEmployeeProfile->getRawOriginal(), array_flip(['id', 'user_id', 'primary_site_id', 'secondary_site_ids', 'is_active', 'start_date', 'end_date', 'deleted_at']));
                $rows = array_values(array_filter($rows, fn ($row) => (int) $row['id'] !== (int) $originalProfile['id']));
                $rows[] = $originalProfile;
            }
            $this->expectedSet($proof, $profiles, $rows, ['id']);
            $siteIds = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            $sites = DB::table('sites')->whereIn('id', $siteIds);
            $rows = $reads->query(clone $sites)->get(['id', 'is_active', 'archived', 'archived_at', 'deleted_at'])->map(fn ($row) => (array) $row)->all();
            $this->expectedSet($proof, $sites, $rows, ['id']);
            $zone = (string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC'); $day = now($zone)->toDateString();
            $proof->whereRaw(new class($reads, $zone, $day) extends \Illuminate\Database\Query\Expression {
                public function __construct(private readonly CurrentAuthorizationReads $reads, private readonly string $zone, private readonly string $day) { parent::__construct('1 = 1'); }
                public function getValue(\Illuminate\Database\Grammar $grammar) { $this->reads->assertActive(); if ((string) (config('app.worker_timezone') ?: config('app.timezone') ?: 'UTC') !== $this->zone || now($this->zone)->toDateString() !== $this->day) { throw new LogicException('Risk authorization date evidence changed during materialisation.'); } return parent::getValue($grammar); }
            });
            return $reads->query($proof);
        });
        $cached[$cacheKey] = clone $query; $this->authorityProofs[$context] = $cached;
        return $query;
    }

    private function exactValue(Builder|QueryBuilder $query, string $column, mixed $value): void
    {
        if ($value === null) { $query->whereNull($column); }
        elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
        else { $query->where($column, $value); }
    }

    private function expectedSet(Builder $proof, QueryBuilder $selector, array $rows, array $identity): void
    {
        foreach ($rows as $row) { $known = clone $selector; foreach ($row as $column => $value) { $this->exactValue($known, $column, $value); } $proof->whereExists($known->selectRaw('1')); }
        $extra = clone $selector;
        if ($rows !== []) { $extra->whereNot(function (QueryBuilder $known) use ($rows, $identity): void { foreach ($rows as $row) { $known->orWhere(function (QueryBuilder $entry) use ($row, $identity): void { foreach ($identity as $column) { $this->exactValue($entry, $column, $row[$column]); } }); } }); }
        $proof->whereNotExists($extra->selectRaw('1'));
    }
}
