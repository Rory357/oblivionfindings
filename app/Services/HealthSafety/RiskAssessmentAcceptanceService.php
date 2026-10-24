<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAcceptance;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Prospective foundation only: no existing writer, route or history reader is mounted here. */
final class RiskAssessmentAcceptanceService
{
    public const PERMISSIONS = [...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
        ...InjuryAccessService::PERMISSIONS, ...ChemicalExposureCommandAccess::PERMISSIONS,
        ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS];
    public const ACTIONS = ['risk_assessment.created', 'risk_assessment.draft_updated', 'risk_assessment.status_changed',
        'risk_assessment.residual_recorded', 'risk_assessment.review_completed', 'risk_assessment.evidence_added', 'risk_assessment.evidence_removed'];
    private const FIELDS = ['title', 'risk_description', 'status', 'likelihood', 'consequence', 'risk_score', 'risk_level',
        'existing_controls', 'additional_controls', 'residual_likelihood', 'residual_consequence', 'residual_risk_score',
        'residual_risk_level', 'risk_acceptable', 'review_frequency_days', 'approval_note', 'last_review_note'];

    public function __construct(private readonly UserSiteAccessService $sites,
        private readonly ChemicalExposureAccessService $private,
        private readonly ChemicalExposureSourceService $source,
        private readonly ChemicalExposureSourceProjectionService $projection) {}

    /** Existing caller transaction REQUIRED. The token correlates strict insertion; it is not a user receipt. */
    public function record(int $assessmentId, User $viewer, HealthSafetyReadContext $context, string $action,
        ?array $beforeAttachment = null, ?string $note = null, array $extras = []): array
    {
        $context->assertViewer($viewer);
        if (DB::connection()->transactionLevel() < 2) {
            throw new LogicException('Risk acceptance requires the existing caller transaction and active read callback.');
        }
        if (! in_array($action, self::ACTIONS, true)) { throw new LogicException('Unknown risk semantic action.'); }
        return $context->consumeSourceViewer(self::PERMISSIONS,
            function (User $current, CurrentAuthorizationReads $reads) use ($assessmentId, $context, $action, $beforeAttachment, $note, $extras): array {
                abort_unless($current->approved_at !== null && $current->canDo('hazards.manage'), 403);
                abort_unless($reads->query(User::query()->staff())->whereKey($current->id)->exists(), 403);
                $identity = $this->assessmentIdentity($assessmentId, $reads, true);
                $after = $this->binding($this->attachment($identity), $current, $reads, $context, 'manage');
                $before = $beforeAttachment === null ? null : $this->binding($beforeAttachment, $current, $reads, $context, 'manage');
                $this->refusePrivateRebind($before, $after);
                $this->auditBoundary($before, $after);
                $previousIdentity = $reads->query(HsRiskAssessmentAcceptance::query())->where('assessment_id', $assessmentId)
                    ->orderByDesc('sequence')->first(['id']);
                $previous = $previousIdentity ? $this->verifiedMarker((int) $previousIdentity->id, $assessmentId, $current, $reads, $context, 'manage') : null;
                if ($previous !== null && ChemicalCommandValues::digest($previous['after_binding']['attachment']) !== ChemicalCommandValues::digest($before['attachment'] ?? null)) {
                    throw new LogicException('Risk acceptance chain omitted or replaced its original source binding.');
                }
                if ($action === 'risk_assessment.created' && ($previous !== null || $before !== null)) {
                    throw new LogicException('Risk creation cannot replace an accepted chain.');
                }
                if ($previous !== null) {
                    $this->refusePrivateRebind($previous['after_binding'], $after);
                    $before = $previous['after_binding']; // Original accepted reference, never today's replacement tuple.
                    $this->auditBoundary($before, $after);
                }
                $risk = $this->exactAssessment($assessmentId, $this->attachment($identity), $reads)->lockForUpdate()->firstOrFail();
                $snapshot = $risk->only(self::FIELDS);
                $snapshot['review_due_at'] = $risk->review_due_at?->toDateString();
                $business = [...$this->extras($extras), 'snapshot' => $snapshot, 'note' => $note, 'actor_id' => (int) $current->id];
                $token = (string) Str::uuid();
                $meta = [...$business, 'risk_acceptance_token' => $token, 'risk_source_binding_before' => $before,
                    'risk_source_binding' => $after, 'risk_business_digest' => ChemicalCommandValues::digest($business),
                    'risk_before_binding_digest' => ChemicalCommandValues::digest($before),
                    'risk_after_binding_digest' => ChemicalCommandValues::digest($after)];
                $request = Request::create('/');
                $request->setUserResolver(fn () => $current);
                AuditLogger::logOrFail($action, $risk, $meta, $request);
                $audits = $reads->query(AuditLog::query())->where('auditable_type', $risk->getMorphClass())->where('auditable_id', $assessmentId)
                    ->where('user_id', $current->id)->where('action', $action)->where('meta->risk_acceptance_token', $token)->lockForUpdate()->get();
                if ($audits->count() !== 1 || ChemicalCommandValues::digest($audits[0]->meta) !== ChemicalCommandValues::digest($meta)) {
                    throw new LogicException('Exact risk semantic audit was suppressed, altered or stripped.');
                }
                if (($this->hasExposure($before) || $this->hasExposure($after))
                    && app(ChemicalExposureAuditAccessService::class)->scopeRiskAcceptanceSubjects(AuditLog::query()->whereKey($audits[0]->id))->exists()) {
                    throw new LogicException('Private risk acceptance remains visible through the unscoped general audit entry.');
                }
                $marker = new HsRiskAssessmentAcceptance(['assessment_id' => $assessmentId, 'audit_log_id' => (int) $audits[0]->id,
                    'actor_id' => (int) $current->id, 'sequence' => $previous === null ? 1 : $previous['sequence'] + 1,
                    'schema_version' => 1, 'action' => $action, 'actor_snapshot' => ['id' => (int) $current->id, 'name' => $current->name], 'correlation_token' => $token,
                    'prior_id' => $previous['id'] ?? null, 'prior_digest' => $previous['acceptance_digest'] ?? null,
                    'before_site_id' => $before['site_id'] ?? null, 'after_site_id' => $after['site_id'],
                    'before_client_id' => $before['client_id'] ?? null, 'after_client_id' => $after['client_id'],
                    'has_exposure' => $this->hasExposure($before) || $this->hasExposure($after),
                    'before_binding' => $before, 'after_binding' => $after, 'before_business' => $previous['business'] ?? null,
                    'business' => $business, 'business_digest' => ChemicalCommandValues::digest($business),
                    'before_binding_digest' => ChemicalCommandValues::digest($before), 'after_binding_digest' => ChemicalCommandValues::digest($after),
                    'recorded_at' => $audits[0]->created_at->copy()->utc()]);
                $marker->acceptance_digest = ChemicalCommandValues::digest($this->digestFields($marker));
                if (! $marker->save()) { throw new LogicException('Risk acceptance save was vetoed.'); }
                $retained = $marker->persisted();
                if ($retained->acceptance_digest !== ChemicalCommandValues::digest($this->digestFields($retained))) {
                    throw new LogicException('Risk acceptance did not retain its exact accepted facts.');
                }
                return ['id' => (int) $retained->id, 'assessment_id' => $assessmentId, 'audit_log_id' => (int) $retained->audit_log_id,
                    'sequence' => $retained->sequence, 'attribution_state' => 'recorded_business'];
            });
    }

    /** Detached business history only. No generic audit permission, body-search builder or model escapes. */
    public function history(int $assessmentId, User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::PERMISSIONS, function (User $current, CurrentAuthorizationReads $reads) use ($assessmentId, $context): array {
            abort_unless($current->canDo('hazards.view'), 403);
            $identity = $this->assessmentIdentity($assessmentId, $reads, false);
            $this->binding($this->attachment($identity), $current, $reads, $context, 'view');
            $identities = $reads->query(HsRiskAssessmentAcceptance::query())->where('assessment_id', $assessmentId)->orderBy('sequence')->get(['id']);
            $items = []; $seen = []; $previous = null;
            foreach ($identities as $candidate) {
                $accepted = $this->verifiedMarker((int) $candidate->id, $assessmentId, $current, $reads, $context);
                if ($accepted['sequence'] !== count($items) + 1 || $accepted['prior_id'] !== ($previous['id'] ?? null)
                    || $accepted['prior_digest'] !== ($previous['acceptance_digest'] ?? null)) {
                    throw new LogicException('Risk business history has a missing or substituted retained acceptance.');
                }
                $items[] = ['id' => 'audit-'.$accepted['audit_log_id'], 'action' => $accepted['action'],
                    'occurred_at' => $accepted['recorded_at'], 'note' => $accepted['business']['note'],
                    'snapshot' => $accepted['business']['snapshot'], 'actor' => $accepted['actor_snapshot'],
                    'legacy' => false, 'attribution_state' => 'recorded_business'];
                $seen[] = $accepted['audit_log_id']; $previous = $accepted;
            }
            $legacy = $reads->query(AuditLog::query())->whereIn('auditable_type', [HsRiskAssessment::class, (new HsRiskAssessment)->getMorphClass()])
                ->where('auditable_id', $assessmentId)->whereIn('action', [...self::ACTIONS, 'hsriskassessment.create', 'hsriskassessment.update'])
                ->whereNotIn('id', $seen)->count();
            return ['items' => $items, 'history_state' => $legacy > 0 || $items === [] ? 'unconfirmed' : 'recorded_business',
                'history_withheld' => $legacy];
        });
    }

    private function assessmentIdentity(int $id, CurrentAuthorizationReads $reads, bool $lock): HsRiskAssessment
    {
        $reads->assertActive();
        $query = HsRiskAssessment::query()->whereKey($id)->select(['id', 'hs_event_id', 'assessable_type', 'assessable_id']);
        // CurrentAuthorizationReads intentionally forces shared reads. Serialize the writer's exact root first.
        return ($lock ? $query->lockForUpdate() : $reads->query($query))->firstOrFail();
    }

    private function attachment(HsRiskAssessment $row): array
    {
        return ['hs_event_id' => $row->hs_event_id === null ? null : (int) $row->hs_event_id,
            'assessable_type' => $row->assessable_type, 'assessable_id' => $row->assessable_id === null ? null : (int) $row->assessable_id];
    }

    private function exactAssessment(int $id, array $tuple, CurrentAuthorizationReads $reads): Builder
    {
        $query = HsRiskAssessment::query()->whereKey($id);
        foreach ($tuple as $key => $value) { $query->where($key, $value); }
        return $reads->query($query);
    }

    private function binding(array $tuple, User $current, CurrentAuthorizationReads $reads, HealthSafetyReadContext $context, string $operation): array
    {
        $keys = ['hs_event_id', 'assessable_type', 'assessable_id'];
        if (array_diff($keys, array_keys($tuple)) !== [] || array_diff(array_keys($tuple), $keys) !== []
            || (($tuple['assessable_type'] === null) !== ($tuple['assessable_id'] === null))) {
            throw new LogicException('Risk original nullable attachment identity is incomplete.');
        }
        abort_unless($current->canDo($operation === 'manage' ? 'hazards.manage' : 'hazards.view'), 403);
        $sites = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
        $site = null; $client = null; $events = [];
        $type = $tuple['assessable_type']; $id = $tuple['assessable_id'];
        $eventTypes = array_unique([HsEvent::class, (new HsEvent)->getMorphClass()]);
        if ($type === Site::class) {
            abort_unless(in_array($id, $sites, true), 404); $site = $id;
        } elseif ($type === Client::class) {
            $row = $reads->query(Client::query())->whereIn('site_id', $sites)->whereKey($id)->first(['id', 'site_id']);
            abort_unless($row && app(ClientPolicy::class)->viewFromCurrentEvidence($current, $row, $reads), 404);
            $site = (int) $row->site_id; $client = (int) $row->id;
        } elseif ($type !== null && ! in_array($type, $eventTypes, true)) {
            abort(404);
        }
        $parents = [];
        if ($tuple['hs_event_id'] !== null) { $parents[] = ['role' => 'modern', 'id' => $tuple['hs_event_id']]; }
        if (in_array($type, $eventTypes, true)) { $parents[] = ['role' => 'legacy', 'id' => $id]; }
        foreach ($parents as $parent) {
            if (! is_int($parent['id']) || $parent['id'] < 1) { throw new LogicException('Risk parent identity is malformed.'); }
            $candidate = HsEvent::query()->whereKey($parent['id'])->whereIn('site_id', $sites);
            $identity = $reads->query(clone $candidate)->first(['id', 'site_id', 'source_type']);
            abort_unless($identity, 404);
            if ($site !== null) { abort_unless($site === (int) $identity->site_id, 404); }
            $site = (int) $identity->site_id;
            $exposure = in_array($identity->source_type, [SubstanceExposureRecord::class, (new SubstanceExposureRecord)->getMorphClass()], true);
            if ($exposure) { abort_unless($this->private->canRead($current), 403); $this->private->withReadContext($context, fn () => true); }
            $guard = $exposure ? $context->query(clone $candidate)
                : app(HsEventAccessService::class)->applyFirstAidSourceScope(clone $candidate, $current, $context, $candidate);
            if ($exposure) {
                $allowed = $this->projection->readableHsEventIdsInContext($current, $candidate, $context);
                $guard->whereIn('id', $allowed);
            }
            $event = $guard->first(['id', 'source_type', 'source_id', 'event_category', 'site_id', 'staff_id', 'client_id', 'shift_id', 'asset_id']);
            abort_unless($event, 404);
            $identityTuple = $event->only(['source_type', 'source_id', 'event_category', 'site_id', 'staff_id', 'client_id', 'shift_id', 'asset_id']);
            foreach (['source_id', 'site_id', 'staff_id', 'client_id', 'shift_id', 'asset_id'] as $column) {
                $identityTuple[$column] = $event->$column === null ? null : (int) $event->$column;
            }
            $entry = ['role' => $parent['role'], 'event_id' => $parent['id'], 'tuple' => $identityTuple, 'exposure' => null];
            if ($exposure) {
                $entry['exposure'] = $this->private->withReadContext($context,
                    fn ($actor, $activeReads, $approved) => $this->captureExposure((int) $event->source_id, $actor, $activeReads, $approved));
            }
            $events[] = $entry;
        }
        if ($site === null) { abort_unless($this->sites->canBypass($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS), 404); }
        return ['schema_version' => 1, 'attachment' => $tuple, 'site_id' => $site, 'client_id' => $client, 'events' => $events];
    }

    private function captureExposure(int $sourceId, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $proof = $this->source->sourceProof($sourceId, $current, $reads, $sites);
        if (($proof['attribution_state'] ?? null) === 'repair_required') { throw new LogicException('Exposure source needs repair.'); }
        if ($proof['attribution_state'] === 'legacy_unknown') {
            if ($proof['incident_binding'] !== 'absent') { throw new LogicException('Legacy exposure original association is unconfirmed.'); }
            return ['attribution_state' => 'legacy_unknown', 'source_id' => $sourceId, 'source_version_id' => null,
                'source_revision' => 0, 'receipt_id' => null, 'source_content_digest' => null,
                'source_tuple' => ['exposure_id' => $sourceId, 'substance_id' => $proof['substance_id'], 'worker_id' => $proof['worker_id'],
                    'site_id' => $proof['site_id'], 'related_incident_id' => null, 'original_context' => null]];
        }
        $receipt = $reads->query(ChemicalExposureCommandReceipt::query())->where('exposure_id', $sourceId)
            ->where('source_version_id', $proof['source_version_id'])->firstOrFail(['id']);
        $accepted = $this->source->admitReceiptMetadata((int) $receipt->id, $current, $reads, $sites);
        $acceptedProof = $this->source->sourceProofForAuditSubject(ChemicalExposureSourceVersion::class, (int) $proof['source_version_id'], $current, $reads, $sites);
        if ($acceptedProof === null || ($acceptedProof['attribution_state'] ?? null) === 'repair_required') { throw new LogicException('Exposure accepted source proof is damaged.'); }
        $version = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $sourceId)
            ->whereKey($proof['source_version_id'])->firstOrFail(['id', 'aggregate_revision', 'content_digest']);
        return ['attribution_state' => 'recorded', 'source_id' => $sourceId, 'source_version_id' => (int) $version->id,
            'source_revision' => (int) $version->aggregate_revision, 'receipt_id' => (int) $receipt->id,
            'source_content_digest' => $version->content_digest, 'source_tuple' => $accepted['after']];
    }

    private function proveBinding(array $binding, User $current, CurrentAuthorizationReads $reads, HealthSafetyReadContext $context, string $operation = 'view'): void
    {
        $keys = ['schema_version', 'attachment', 'site_id', 'client_id', 'events'];
        if (array_diff($keys, array_keys($binding)) !== [] || array_diff(array_keys($binding), $keys) !== []
            || $binding['schema_version'] !== 1 || ! is_array($binding['attachment']) || ! is_array($binding['events'])
            || ! array_is_list($binding['events']) || count($binding['events']) > 2) {
            throw new LogicException('Risk acceptance original binding is malformed.');
        }
        $sites = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
        $this->originalSiteClient($binding['site_id'], $binding['client_id'], $current, $reads, $sites);
        $tuple = $binding['attachment'];
        if (array_keys($tuple) !== ['hs_event_id', 'assessable_type', 'assessable_id']) {
            // Canonical key order is not authority. A valid JSON store may reorder keys.
            $keys = ['hs_event_id', 'assessable_type', 'assessable_id'];
            if (array_diff($keys, array_keys($tuple)) !== [] || array_diff(array_keys($tuple), $keys) !== []) {
                throw new LogicException('Risk acceptance original attachment is incomplete.');
            }
        }
        $expected = [];
        if ($tuple['hs_event_id'] !== null) { $expected['modern'] = $tuple['hs_event_id']; }
        if (in_array($tuple['assessable_type'], [HsEvent::class, (new HsEvent)->getMorphClass()], true)) { $expected['legacy'] = $tuple['assessable_id']; }
        if (($tuple['assessable_type'] === null) !== ($tuple['assessable_id'] === null)) { throw new LogicException('Risk retained nullable identity is inconsistent.'); }
        if ($tuple['assessable_type'] === Site::class && $binding['site_id'] !== $tuple['assessable_id']) { throw new LogicException('Risk original Site differs from its primary binding.'); }
        if ($tuple['assessable_type'] === Client::class && $binding['client_id'] !== $tuple['assessable_id']) { throw new LogicException('Risk original Client differs from its primary binding.'); }
        if ($tuple['assessable_type'] !== Client::class && $binding['client_id'] !== null) { throw new LogicException('Risk retained primary Client was invented.'); }
        $roles = [];
        foreach ($binding['events'] as $entry) {
            if (! is_array($entry) || array_diff(['role','event_id','tuple','exposure'], array_keys($entry)) !== []
                || array_diff(array_keys($entry), ['role','event_id','tuple','exposure']) !== []
                || ($expected[$entry['role']] ?? null) !== $entry['event_id'] || isset($roles[$entry['role']])) {
                throw new LogicException('Risk acceptance omitted or substituted a parent role.');
            }
            if (($entry['tuple']['site_id'] ?? null) !== $binding['site_id']) { throw new LogicException('Risk original event and primary Site disagree.'); }
            $roles[$entry['role']] = true;
            if ($entry['exposure'] === null) {
                $actual = $this->binding(['hs_event_id' => $entry['event_id'], 'assessable_type' => null, 'assessable_id' => null], $current, $reads, $context, $operation);
                if (ChemicalCommandValues::digest($actual['events'][0]['tuple']) !== ChemicalCommandValues::digest($entry['tuple'])) {
                    throw new LogicException('Risk original event identity has changed.');
                }
                if ($actual['events'][0]['exposure'] !== null) { throw new LogicException('Risk ordinary parent became a private exposure.'); }
                continue;
            }
            $this->private->withReadContext($context, function ($actor, $activeReads, $approved) use ($entry): void {
                $ref = $entry['exposure'];
                $event = $activeReads->query(HsEvent::query())->whereKey($entry['event_id'])->whereIn('site_id', $approved)
                    ->firstOrFail(['id','source_type','source_id','event_category']);
                if (! in_array($event->source_type, [SubstanceExposureRecord::class, (new SubstanceExposureRecord)->getMorphClass()], true)
                    || (int) $event->source_id !== ($ref['source_id'] ?? null) || $event->event_category !== HsEvent::CATEGORY_EXPOSURE) {
                    throw new LogicException('Risk historical exposure parent was rebound.');
                }
                if ($ref['attribution_state'] === 'legacy_unknown') {
                    $actual = $this->captureExposure($ref['source_id'], $actor, $activeReads, $approved);
                    if (ChemicalCommandValues::digest($actual) !== ChemicalCommandValues::digest($ref)) {
                        throw new LogicException('Risk original legacy exposure is no longer provable.');
                    }
                } else {
                    $this->source->sourceProof($ref['source_id'], $actor, $activeReads, $approved);
                    $receipt = $activeReads->query(ChemicalExposureCommandReceipt::query())->whereKey($ref['receipt_id'])
                        ->where('exposure_id', $ref['source_id'])->where('source_version_id', $ref['source_version_id'])->firstOrFail(['id']);
                    $accepted = $this->source->admitReceiptMetadata((int) $receipt->id, $actor, $activeReads, $approved);
                    if (ChemicalCommandValues::digest($accepted['after']) !== ChemicalCommandValues::digest($ref['source_tuple'])) {
                        throw new LogicException('Risk historical exposure tuple differs from its original acceptance.');
                    }
                    $proof = $this->source->sourceProofForAuditSubject(ChemicalExposureSourceVersion::class, $ref['source_version_id'], $actor, $activeReads, $approved);
                    $version = $activeReads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $ref['source_id'])
                        ->whereKey($ref['source_version_id'])->firstOrFail(['id', 'aggregate_revision', 'content_digest']);
                    if ($proof === null || ($proof['attribution_state'] ?? null) === 'repair_required'
                        || (int) $version->aggregate_revision !== $ref['source_revision'] || $version->content_digest !== $ref['source_content_digest']) {
                        throw new LogicException('Risk original exposure acceptance is damaged.');
                    }
                }
                $t = $entry['tuple']; $s = $ref['source_tuple'];
                if ($t['source_id'] !== $s['exposure_id'] || $t['staff_id'] !== $s['worker_id'] || $t['site_id'] !== $s['site_id']
                    || $t['event_category'] !== HsEvent::CATEGORY_EXPOSURE || $t['asset_id'] !== null
                    || $t['client_id'] !== ($s['original_context']['client_id'] ?? null) || $t['shift_id'] !== ($s['original_context']['shift_id'] ?? null)) {
                    throw new LogicException('Risk original HSE tuple differs from its accepted exposure.');
                }
            });
        }
        if (count($roles) !== count($expected)) { throw new LogicException('Risk acceptance omitted a parent.'); }
    }

    private function originalSiteClient(?int $site, ?int $client, User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        if ($site === null) {
            abort_unless($client === null && $this->sites->canBypass($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS), 404);
        } else {
            abort_unless(in_array($site, $sites, true), 404);
        }
        if ($client !== null) {
            $row = $reads->query(Client::query())->whereKey($client)->where('site_id', $site)->firstOrFail(['id','site_id']);
            abort_unless(app(ClientPolicy::class)->viewFromCurrentEvidence($current, $row, $reads), 404);
        }
    }

    /** Source metadata is admitted before any business/audit payload is selected. */
    private function verifiedMarker(int $id, int $assessmentId, User $current, CurrentAuthorizationReads $reads, HealthSafetyReadContext $context, string $operation = 'view'): array
    {
        $identity = $reads->query(HsRiskAssessmentAcceptance::query())->where('assessment_id', $assessmentId)->whereKey($id)
            ->select(['id','assessment_id','before_site_id','after_site_id','before_client_id','after_client_id','has_exposure','before_binding_digest','after_binding_digest','prior_id','sequence'])
            ->firstOrFail();
        $sites = $this->sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
        $this->originalSiteClient($identity->after_site_id, $identity->after_client_id, $current, $reads, $sites);
        if ($identity->before_site_id !== null || $identity->before_client_id !== null) {
            $this->originalSiteClient($identity->before_site_id, $identity->before_client_id, $current, $reads, $sites);
        }
        // Retained bindings contain identity metadata only. Private marker bodies are still unselected.
        if ($this->markerPrivate($id, $reads)) { $this->private->withReadContext($context, fn () => true); }
        $bindings = $reads->query(HsRiskAssessmentAcceptance::query())->where('assessment_id', $assessmentId)->whereKey($id)
            ->firstOrFail(['id','before_binding','after_binding']);
        if ($this->hasExposure($bindings->before_binding) || $this->hasExposure($bindings->after_binding)) {
            abort_unless($this->private->canRead($current), 403);
        }
        $this->proveBinding($bindings->after_binding, $current, $reads, $context, $operation);
        if ($bindings->before_binding !== null) { $this->proveBinding($bindings->before_binding, $current, $reads, $context, $operation); }
        if ($identity->before_binding_digest !== ChemicalCommandValues::digest($bindings->before_binding)
            || $identity->after_binding_digest !== ChemicalCommandValues::digest($bindings->after_binding)
            || $identity->before_site_id !== ($bindings->before_binding['site_id'] ?? null)
            || $identity->after_site_id !== $bindings->after_binding['site_id']
            || $identity->before_client_id !== ($bindings->before_binding['client_id'] ?? null)
            || $identity->after_client_id !== $bindings->after_binding['client_id']) {
            throw new LogicException('Risk acceptance original identity metadata was changed.');
        }
        $prior = null;
        if ($identity->prior_id !== null) {
            if ($identity->prior_id >= $id || $identity->sequence < 2) { throw new LogicException('Risk acceptance chain is cyclic or malformed.'); }
            $prior = $this->verifiedMarker($identity->prior_id, $assessmentId, $current, $reads, $context, $operation);
        }
        $marker = $reads->query(HsRiskAssessmentAcceptance::query())->whereKey($id)->where('assessment_id', $assessmentId)
            ->where('before_binding_digest', $identity->before_binding_digest)->where('after_binding_digest', $identity->after_binding_digest)
            ->where('before_site_id', $identity->before_site_id)->where('after_site_id', $identity->after_site_id)
            ->where('before_client_id', $identity->before_client_id)->where('after_client_id', $identity->after_client_id)
            ->where('prior_id', $identity->prior_id)->where('sequence', $identity->sequence)->firstOrFail();
        if (ChemicalCommandValues::digest($marker->before_binding) !== $identity->before_binding_digest
            || ChemicalCommandValues::digest($marker->after_binding) !== $identity->after_binding_digest
            || $marker->schema_version !== 1 || ! in_array($marker->action, self::ACTIONS, true)
            || $marker->business_digest !== ChemicalCommandValues::digest($marker->business)
            || $marker->acceptance_digest !== ChemicalCommandValues::digest($this->digestFields($marker))) {
            throw new LogicException('Risk retained acceptance payload differs from its admitted identity.');
        }
        $expected = [...$marker->business, 'risk_acceptance_token' => $marker->correlation_token,
            'risk_source_binding_before' => $marker->before_binding, 'risk_source_binding' => $marker->after_binding,
            'risk_business_digest' => $marker->business_digest, 'risk_before_binding_digest' => $marker->before_binding_digest,
            'risk_after_binding_digest' => $marker->after_binding_digest];
        $audit = $reads->query(AuditLog::query())->whereKey($marker->audit_log_id)
            ->whereIn('auditable_type', [HsRiskAssessment::class, (new HsRiskAssessment)->getMorphClass()])->where('auditable_id', $assessmentId)
            ->where('user_id', $marker->actor_id)->where('action', $marker->action)->where('meta->risk_acceptance_token', $marker->correlation_token)
            ->where('meta->risk_business_digest', $marker->business_digest)->where('meta->risk_before_binding_digest', $marker->before_binding_digest)
            ->where('meta->risk_after_binding_digest', $marker->after_binding_digest)->first();
        if ($audit === null) { throw new LogicException('Risk accepted semantic audit is missing or its identity metadata was altered.'); }
        if ($audit->action !== $marker->action || (int) $audit->user_id !== $marker->actor_id
            || (int) $audit->auditable_id !== $assessmentId || ! in_array($audit->auditable_type, [HsRiskAssessment::class, (new HsRiskAssessment)->getMorphClass()], true)
            || ChemicalCommandValues::digest($audit->meta) !== ChemicalCommandValues::digest($expected)
            || $audit->created_at->copy()->utc()->format('Y-m-d H:i:s.u') !== $marker->recorded_at->format('Y-m-d H:i:s.u')) {
            throw new LogicException('Risk semantic audit was changed after acceptance.');
        }
        if ($marker->prior_id === null) {
            if ($marker->sequence !== 1 || $marker->prior_digest !== null || $marker->before_business !== null) {
                throw new LogicException('Risk first prospective acceptance has a fabricated prior milestone.');
            }
        } else {
            if ($marker->prior_id >= (int) $marker->id || $marker->sequence < 2) {
                throw new LogicException('Risk acceptance chain is cyclic or malformed.');
            }
            if ($prior === null || $marker->prior_id !== $identity->prior_id) { throw new LogicException('Risk predecessor changed after identity admission.'); }
            if ($prior['sequence'] + 1 !== $marker->sequence || $prior['acceptance_digest'] !== $marker->prior_digest
                || ChemicalCommandValues::digest($prior['business']) !== ChemicalCommandValues::digest($marker->before_business)
                || ChemicalCommandValues::digest($prior['after_binding']) !== ChemicalCommandValues::digest($marker->before_binding)) {
                throw new LogicException('Risk original acceptance chain differs from its retained predecessor.');
            }
        }
        $context->assertActive();
        return [...$this->digestFields($marker), 'id' => (int) $marker->id, 'acceptance_digest' => $marker->acceptance_digest];
    }

    private function digestFields(HsRiskAssessmentAcceptance $marker): array
    {
        $data = $marker->only(['assessment_id','audit_log_id','actor_id','sequence','schema_version','action','actor_snapshot','correlation_token','prior_id','prior_digest',
            'before_site_id','after_site_id','before_client_id','after_client_id','has_exposure','before_binding','after_binding','before_business','business',
            'business_digest','before_binding_digest','after_binding_digest']);
        $data['recorded_at'] = $marker->recorded_at->copy()->utc()->format('Y-m-d H:i:s.u');
        return $data;
    }

    private function hasExposure(?array $binding): bool
    {
        foreach ($binding['events'] ?? [] as $entry) { if (($entry['exposure'] ?? null) !== null) { return true; } }
        return false;
    }

    private function refusePrivateRebind(?array $before, array $after): void
    {
        foreach ($before['events'] ?? [] as $old) {
            if (($old['exposure'] ?? null) === null) { continue; }
            $next = null;
            foreach ($after['events'] as $candidate) { if ($candidate['role'] === $old['role']) { $next = $candidate; break; } }
            if ($next === null || $next['exposure'] === null || $next['event_id'] !== $old['event_id']
                || $next['exposure']['source_id'] !== $old['exposure']['source_id']) {
                throw new LogicException('Risk private provenance cannot become an ordinary parent or another source.');
            }
        }
        if ($this->hasExposure($before) && ChemicalCommandValues::digest($before['attachment']) !== ChemicalCommandValues::digest($after['attachment'])) {
            throw new LogicException('A risk command cannot detach or rebind private exposure provenance.');
        }
    }

    private function extras(array $extras): array
    {
        $allowed = ['from','to','successor_id','reviewed_at','approved_by_user_id','approved_at','names','attachment_id'];
        if (array_diff(array_keys($extras), $allowed) !== []) { throw new LogicException('Unknown risk business milestone metadata.'); }
        if (isset($extras['names'])) {
            if (! is_array($extras['names']) || ! array_is_list($extras['names'])) { throw new LogicException('Risk evidence names must be explicit scalar names.'); }
            $extras['names'] = array_map(function ($name): string {
                if (! is_string($name)) { throw new LogicException('Risk evidence names must be scalar.'); }
                return basename(str_replace('\\', '/', $name));
            }, $extras['names']);
        }
        ChemicalCommandValues::digest($extras);
        return $extras;
    }

    private function auditBoundary(?array $before, array $after): void
    {
        if (! $this->hasExposure($before) && ! $this->hasExposure($after)) { return; }
        // No fabricated readiness flag. Root must review/mount the separately supplied recognition patch.
        if (! method_exists(app(ChemicalExposureAuditAccessService::class), 'scopeRiskAcceptanceSubjects')) {
            throw new LogicException('Private risk acceptance general audit protection is not mounted.');
        }
    }

    /** General audit caller retains its own permission; only private exposure adds the domain gates. */
    public function auditProofInContext(int $auditId, User $viewer, HealthSafetyReadContext $context): bool
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer([...self::PERMISSIONS, 'audit.viewAny'], function (User $current, CurrentAuthorizationReads $reads) use ($auditId, $context): bool {
            abort_unless($current->canDo('audit.viewAny'), 403);
            $identity = $reads->query(HsRiskAssessmentAcceptance::query())->where('audit_log_id', $auditId)->first(['id','assessment_id']);
            if (! $identity) { return false; }
            if ($this->markerPrivate((int) $identity->id, $reads)) {
                $this->private->withReadContext($context, fn () => true);
                $this->verifiedMarker((int) $identity->id, (int) $identity->assessment_id, $current, $reads, $context);
                return true;
            }
            $metadata = $reads->query(HsRiskAssessmentAcceptance::query())->whereKey($identity->id)
                ->firstOrFail(['id','before_binding','after_binding','before_binding_digest','after_binding_digest','business_digest','correlation_token','schema_version','action','sequence','prior_id']);
            if ($metadata->schema_version !== 1 || ! in_array($metadata->action, self::ACTIONS, true) || $metadata->sequence < 1
                || ! $this->ordinaryBindingIsKnown($metadata->after_binding)
                || ($metadata->before_binding !== null && ! $this->ordinaryBindingIsKnown($metadata->before_binding))
                || $metadata->before_binding_digest !== ChemicalCommandValues::digest($metadata->before_binding)
                || $metadata->after_binding_digest !== ChemicalCommandValues::digest($metadata->after_binding)) { return false; }
            $auditIdentity = $reads->query(AuditLog::query())->whereKey($auditId)->first(['id','auditable_type','auditable_id','action',
                'meta->risk_acceptance_token as token','meta->risk_business_digest as business_digest',
                'meta->risk_before_binding_digest as before_digest','meta->risk_after_binding_digest as after_digest']);
            if ($auditIdentity === null || (int) $auditIdentity->auditable_id !== (int) $identity->assessment_id
                || ! in_array($auditIdentity->auditable_type, [HsRiskAssessment::class, (new HsRiskAssessment)->getMorphClass()], true)
                || $auditIdentity->action !== $metadata->action || $auditIdentity->token !== $metadata->correlation_token
                || $auditIdentity->business_digest !== $metadata->business_digest || $auditIdentity->before_digest !== $metadata->before_binding_digest
                || $auditIdentity->after_digest !== $metadata->after_binding_digest) { return false; }
            // This is an ordinary audit anchor check under the existing audit caller boundary.
            // It does not grant risk workspace/source access or require a new H&S staff status.
            $marker = $reads->query(HsRiskAssessmentAcceptance::query())->whereKey($identity->id)->firstOrFail();
            if ($this->hasExposure($marker->before_binding) || $this->hasExposure($marker->after_binding)
                || $marker->has_exposure || $marker->schema_version !== 1
                || $marker->business_digest !== ChemicalCommandValues::digest($marker->business)
                || $marker->before_binding_digest !== ChemicalCommandValues::digest($marker->before_binding)
                || $marker->after_binding_digest !== ChemicalCommandValues::digest($marker->after_binding)
                || $marker->acceptance_digest !== ChemicalCommandValues::digest($this->digestFields($marker))) {
                return false;
            }
            $meta = [...$marker->business, 'risk_acceptance_token' => $marker->correlation_token,
                'risk_source_binding_before' => $marker->before_binding, 'risk_source_binding' => $marker->after_binding,
                'risk_business_digest' => $marker->business_digest, 'risk_before_binding_digest' => $marker->before_binding_digest,
                'risk_after_binding_digest' => $marker->after_binding_digest];
            $audit = $reads->query(AuditLog::query())->whereKey($auditId)
                ->whereIn('auditable_type', [HsRiskAssessment::class, (new HsRiskAssessment)->getMorphClass()])->where('auditable_id', $marker->assessment_id)
                ->where('user_id', $marker->actor_id)->where('action', $marker->action)->where('meta->risk_acceptance_token', $marker->correlation_token)
                ->where('meta->risk_business_digest', $metadata->business_digest)->where('meta->risk_before_binding_digest', $metadata->before_binding_digest)
                ->where('meta->risk_after_binding_digest', $metadata->after_binding_digest)->first();
            $context->assertActive();
            return $audit !== null && (int) $audit->auditable_id === $marker->assessment_id
                && in_array($audit->auditable_type, [HsRiskAssessment::class, (new HsRiskAssessment)->getMorphClass()], true)
                && $audit->action === $marker->action && (int) $audit->user_id === $marker->actor_id
                && ChemicalCommandValues::digest($audit->meta) === ChemicalCommandValues::digest($meta)
                && $audit->created_at->copy()->utc()->format('Y-m-d H:i:s.u') === $marker->recorded_at->format('Y-m-d H:i:s.u');
        });
    }

    private function ordinaryBindingIsKnown(mixed $binding): bool
    {
        if (! is_array($binding) || ($binding['schema_version'] ?? null) !== 1 || ! is_array($binding['attachment'] ?? null)
            || ! is_array($binding['events'] ?? null) || ! array_is_list($binding['events']) || count($binding['events']) > 2) { return false; }
        $keys = ['schema_version','attachment','site_id','client_id','events'];
        if (array_diff($keys, array_keys($binding)) !== [] || array_diff(array_keys($binding), $keys) !== []) { return false; }
        foreach (['site_id','client_id'] as $key) { if ($binding[$key] !== null && (! is_int($binding[$key]) || $binding[$key] < 1)) { return false; } }
        $attachment = $binding['attachment'];
        if (array_diff(['hs_event_id','assessable_type','assessable_id'], array_keys($attachment)) !== []
            || array_diff(array_keys($attachment), ['hs_event_id','assessable_type','assessable_id']) !== []) { return false; }
        if (! in_array($attachment['assessable_type'], [null,Site::class,Client::class,HsEvent::class,(new HsEvent)->getMorphClass()], true)
            || (($attachment['assessable_type'] === null) !== ($attachment['assessable_id'] === null))) { return false; }
        $expected = [];
        if ($attachment['hs_event_id'] !== null) { $expected['modern'] = $attachment['hs_event_id']; }
        if (in_array($attachment['assessable_type'], [HsEvent::class,(new HsEvent)->getMorphClass()], true)) { $expected['legacy'] = $attachment['assessable_id']; }
        $roles = [];
        foreach ($binding['events'] as $entry) {
            if (! is_array($entry) || (! array_key_exists('exposure', $entry) || $entry['exposure'] !== null) || ! is_array($entry['tuple'] ?? null)
                || ! in_array($entry['role'] ?? null, ['modern','legacy'], true) || isset($roles[$entry['role']])
                || ! is_int($entry['event_id'] ?? null) || $entry['event_id'] < 1 || ($expected[$entry['role']] ?? null) !== $entry['event_id']) { return false; }
            $roles[$entry['role']] = true;
            if (in_array($entry['tuple']['source_type'] ?? null, [SubstanceExposureRecord::class,(new SubstanceExposureRecord)->getMorphClass()], true)) { return false; }
        }
        return count($roles) === count($expected);
    }

    private function markerPrivate(int $id, CurrentAuthorizationReads $reads): bool
    {
        $query = HsRiskAssessmentAcceptance::query()->whereKey($id)->where(function (Builder $private): void {
            $private->where('has_exposure', true);
            $types = [SubstanceExposureRecord::class, (new SubstanceExposureRecord)->getMorphClass()];
            foreach (['before_binding','after_binding'] as $column) {
                foreach ([0,1] as $position) {
                    $path = '$.events['.$position.']';
                    $private->orWhereRaw("JSON_TYPE(JSON_EXTRACT(`{$column}`, ?)) = 'OBJECT'", [$path.'.exposure'])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(`{$column}`, ?)) IN (?, ?)", [$path.'.tuple.source_type', ...$types]);
                }
            }
        });
        // A stripped flag/binding cannot make a still-private current parent an ordinary audit subject.
        $query->orWhere(function (Builder $marker) use ($id): void {
            $marker->whereKey($id)->whereIn('assessment_id', HsRiskAssessment::query()->select('id')->where(function (Builder $risk): void {
                $privateEvents = HsEvent::withTrashed()->select('id')->whereIn('source_type', [SubstanceExposureRecord::class, (new SubstanceExposureRecord)->getMorphClass()]);
                $risk->whereIn('hs_event_id', clone $privateEvents)->orWhere(function (Builder $legacy) use ($privateEvents): void {
                    $legacy->whereIn('assessable_type', [HsEvent::class, (new HsEvent)->getMorphClass()])->whereIn('assessable_id', clone $privateEvents);
                });
            }));
        });
        return $reads->query($query)->exists();
    }
}
