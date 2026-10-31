<?php

namespace App\Support\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\HsEvent;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAttachment;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HealthSafetyReadMaterialisationProof;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\HealthSafety\EmergencyDrillAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Services\HealthSafety\RiskAssessmentWorkspaceConnectionsService;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Support\HealthSafety\QueryPaging;

/**
 * Single source of truth for serialising a risk assessment to the React layer —
 * reused by the standalone register (HsRiskAssessmentController), the Client profile
 * (ClientController) and the Site profile (SiteController) so the row + detail shapes
 * never diverge. Tone mapping lives in the row-kit on the client; the presenter only
 * ships raw values + a computed review_state.
 */
class RiskAssessmentPresenter
{
    /** Compact row for register tables. Expects assessable/hsEvent eager-loaded + attachments_count. */
    public static function row(HsRiskAssessment $ra, ?User $viewer = null, ?HealthSafetyReadContext $context = null, ?Builder $originalParent = null): array
    {
        return self::materialise($viewer, $context, function (User $viewer, $reads, HealthSafetyReadContext $context) use ($ra, $originalParent): array {
            $query = HsRiskAssessment::query()->whereKey($ra->id)->with(['assessable', 'hsEvent', 'assessedBy'])->withCount('attachments');
            $raw = $ra->getRawOriginal();
            if (count(array_intersect(['assessable_type', 'assessable_id', 'hs_event_id'], array_keys($raw))) === 3) {
                foreach (['assessable_type', 'assessable_id', 'hs_event_id'] as $key) {
                    $raw[$key] === null ? $query->whereNull('hs_risk_assessments.'.$key) : $query->where('hs_risk_assessments.'.$key, $raw[$key]);
                }
            }
            if ($originalParent !== null) {
                $query->whereIn('hs_risk_assessments.id', (clone $originalParent)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select('hs_risk_assessments.id'));
                $context->reconsumeIncidentPredicate($query, $originalParent);
            }
            $ra = self::firstOrRefuse(self::guardEagerQuery($query, $viewer, $context), $ra->id);

            return self::rowPayload($ra, $viewer, $context);
        });
    }

    /**
     * A row of the risk assessment register (speed R2, 31 Oct 2026; the same method as speed option B that Stephan
     * approved on 30 Oct). The register's page query already loaded each assessment and its links through
     * guardEagerQuery(). row() would fetch every one of them again through that same guard (four guarded queries per
     * row, each about a second on real data). Instead, the readable assessments of the register query are proved
     * ONCE in the same locked read, keyed by id with the same pinned links, and each row must be in that set with the
     * same links. The proof query is tracked, so the read's final barrier replays it before the response leaves (any
     * change withholds the page, 404). A row that arrives without its guarded links takes row() as before. With
     * ProofParity on (tests), row() also runs and the two payloads must be identical.
     */
    public static function registerRow(HsRiskAssessment $ra, User $viewer, HealthSafetyReadContext $context, Builder $registerQuery): array
    {
        foreach (['assessable', 'hsEvent', 'assessedBy'] as $relation) {
            if (! $ra->relationLoaded($relation)) {
                return self::row($ra, $viewer, $context, $registerQuery);
            }
        }
        if (! array_key_exists('attachments_count', $ra->getAttributes())) {
            return self::row($ra, $viewer, $context, $registerQuery);
        }
        $expected = ProofParity::$enabled ? self::row($ra, $viewer, $context, $registerQuery) : null;

        $payload = self::materialise($viewer, $context, function (User $viewer, $reads, HealthSafetyReadContext $context) use ($ra, $registerQuery): array {
            $proven = self::provenRegisterRows($registerQuery, $viewer, $context);
            $raw = $ra->getRawOriginal();
            $pins = [$raw['assessable_type'] ?? null, isset($raw['assessable_id']) ? (int) $raw['assessable_id'] : null,
                isset($raw['hs_event_id']) ? (int) $raw['hs_event_id'] : null];
            if (($proven[(int) $ra->id] ?? null) !== $pins) {
                throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(HsRiskAssessment::class, [$ra->id]);
            }

            return self::rowPayload($ra, $viewer, $context);
        });
        if ($expected !== null && $expected !== $payload) {
            throw new \LogicException("Risk assessment register row {$ra->id}: the proven-set row differs from the per-row proof.");
        }

        return $payload;
    }

    /** @var \WeakMap<Builder, array{context: HealthSafetyReadContext, viewer: int, rows: array<int, array{0: ?string, 1: ?int, 2: ?int}>}>|null */
    private static ?\WeakMap $provenRegisterRows = null;

    /**
     * The register query's readable assessments, proved once per register query and read context. row() proves a row
     * as "in the register query, with its incident predicate, through guardEagerQuery()". The register query already
     * carries guardEagerQuery() (the controller taps it) and its incident predicate, and both only add conditions, so
     * running the register query itself, unpaged, admits exactly those rows. Its read stays tracked (the clone keeps
     * the materialisation proof's hooks), so the final barrier replays it.
     *
     * @return array<int, array{0: ?string, 1: ?int, 2: ?int}> id => [assessable_type, assessable_id, hs_event_id]
     */
    private static function provenRegisterRows(Builder $registerQuery, User $viewer, HealthSafetyReadContext $context): array
    {
        self::$provenRegisterRows ??= new \WeakMap;
        $known = self::$provenRegisterRows[$registerQuery] ?? null;
        if ($known !== null && $known['context'] === $context && $known['viewer'] === (int) $viewer->id) {
            return $known['rows'];
        }
        $context->assertViewer($viewer);
        $context->assertActive();
        $rows = [];
        // The base query keeps the register query's read locks and the proof's replay hooks; only the columns change.
        $base = (clone $registerQuery)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->toBase()
            ->cloneWithout(['columns'])->cloneWithoutBindings(['select'])
            ->select(['hs_risk_assessments.id', 'hs_risk_assessments.assessable_type', 'hs_risk_assessments.assessable_id', 'hs_risk_assessments.hs_event_id']);
        foreach ($base->get() as $row) {
            $raw = (array) $row;
            $rows[(int) $raw['id']] = [$raw['assessable_type'] ?? null, isset($raw['assessable_id']) ? (int) $raw['assessable_id'] : null,
                isset($raw['hs_event_id']) ? (int) $raw['hs_event_id'] : null];
        }
        self::$provenRegisterRows[$registerQuery] = ['context' => $context, 'viewer' => (int) $viewer->id, 'rows' => $rows];

        return $rows;
    }

    /** The register row's fields, from an assessment that has already passed its proof. */
    private static function rowPayload(HsRiskAssessment $ra, User $viewer, HealthSafetyReadContext $context): array
    {
            return [
                'id' => $ra->id,
                'reference_number' => $ra->reference_number,
                'title' => $ra->title,
                'risk_description' => $ra->risk_description,
                'status' => $ra->status,
                'attached_to' => self::attachedTo($ra),
                'likelihood' => $ra->likelihood,
                'consequence' => $ra->consequence,
                'risk_score' => $ra->risk_score,
                'risk_level' => $ra->risk_level,
                'residual_likelihood' => $ra->residual_likelihood,
                'residual_consequence' => $ra->residual_consequence,
                'residual_risk_score' => $ra->residual_risk_score,
                'residual_risk_level' => $ra->residual_risk_level,
                'risk_acceptable' => $ra->risk_acceptable,
                'assessed_by_name' => $ra->assessedBy?->name,
                'review_due_at' => optional($ra->review_due_at)->toDateString(),
                'review_state' => self::reviewState($ra),
                'is_due_for_review' => in_array(self::reviewState($ra)['kind'], ['today', 'overdue'], true),
                'attachments_count' => $ra->attachments_count ?? 0,
                // nextForViewer() answers null unless the assessment is superseded or archived and points at another
                // version; this row was read in the same locked read, so only those rows need the guarded lookup.
                'superseded_by_id' => $ra->superseded_by_id && (int) $ra->superseded_by_id !== (int) $ra->id
                    && in_array($ra->status, [HsRiskAssessment::STATUS_SUPERSEDED, HsRiskAssessment::STATUS_ARCHIVED], true)
                    ? (app(RiskAssessmentWorkspaceConnectionsService::class)->nextForViewer($ra, $viewer, null, $context)['id'] ?? null)
                    : null,
            ];
    }

    /** Additive JSON and permanent-page detail; every connected destination is reauthorized. */
    public static function detail(HsRiskAssessment $ra, bool $canManage = false, ?User $viewer = null, ?string $returnTo = null, ?HealthSafetyReadContext $context = null): array
    {
        return self::materialise($viewer, $context, function (User $viewer, $reads, HealthSafetyReadContext $context) use ($ra, $canManage, $returnTo): array {
            abort_unless($viewer?->canDo('hazards.view'), 403);
            $query = HsRiskAssessment::query()->whereKey($ra->getKey())->with(['assessable', 'hsEvent', 'assessedBy', 'approvedBy', 'creator', 'attachments.uploader']);
            $original = self::guardEagerQuery($query, $viewer, $context);
            $ra = self::firstOrRefuse(clone $original, $ra->getKey());
            $connections = app(RiskAssessmentWorkspaceConnectionsService::class)->forViewer($ra, $viewer, $returnTo, $context);
            $canManage = $canManage && $viewer->canDo('hazards.manage');
            $draft = $ra->status === HsRiskAssessment::STATUS_DRAFT;
            $live = in_array($ra->status, [HsRiskAssessment::STATUS_ACTIVE, HsRiskAssessment::STATUS_UNDER_REVIEW], true);
            $mutable = $draft || $live;
            $reviewReasons = [];
            if ($ra->status !== HsRiskAssessment::STATUS_UNDER_REVIEW) {
                $reviewReasons[] = 'Request review before completing it.';
            }
            if ($ra->approved_at === null || $ra->approved_by_user_id === null) {
                $reviewReasons[] = 'A recorded approval is required.';
            }
            if (! in_array($ra->residual_likelihood, [1, 2, 3, 4, 5], true) || ! in_array($ra->residual_consequence, [1, 2, 3, 4, 5], true)) {
                $reviewReasons[] = 'Record residual likelihood and consequence.';
            }
            if ($ra->risk_acceptable === null) {
                $reviewReasons[] = 'Record whether residual risk is acceptable.';
            }

            return array_merge(self::row($ra, $viewer, $context, $original), [
                'existing_controls' => $ra->existing_controls,
                'additional_controls' => $ra->additional_controls,
                'review_frequency_days' => $ra->review_frequency_days,
                'approval_note' => $ra->approval_note,
                'last_review_note' => $ra->last_review_note,
                'assessed_at' => optional($ra->assessed_at)->toIso8601String(),
                'approved_by_name' => $ra->approvedBy?->name,
                'approved_at' => optional($ra->approved_at)->toIso8601String(),
                'created_by_name' => $ra->creator?->name,
                'created_at' => optional($ra->created_at)->toIso8601String(),
                'updated_at' => optional($ra->updated_at)->toIso8601String(),
                'superseded_by' => $connections['versions']['next'],
                'hs_event' => $connections['safety_review'],

                'attachments' => $ra->attachments->map(fn (HsRiskAssessmentAttachment $a) => self::attachment($ra, $a))->values()->all(),
                'attachments_count' => $ra->attachments->count(),
                'can' => [
                    'manage' => $canManage,
                    'edit' => $canManage && $draft,
                    'activate' => $canManage && $draft,
                    'mark_for_review' => $canManage && $ra->status === HsRiskAssessment::STATUS_ACTIVE,
                    'record_residual' => $canManage && $live,
                    'complete_review' => $canManage && $reviewReasons === [],

                    'supersede' => $canManage && $live,
                    'archive' => $canManage && $mutable,
                    'add_evidence' => $canManage && $mutable,
                    'remove_evidence' => $canManage && $mutable,
                ],
                'connections' => $connections,
                'history' => self::history($ra, $original, $context),
                'review_completion_gate' => ['eligible' => $reviewReasons === [], 'reasons' => $reviewReasons],
                'form' => self::formPrefill($ra),
            ]);

        });
    }

    /** firstOrFail() names only the model; the refusal also names the one assessment it withholds. */
    private static function firstOrRefuse(Builder $query, int|string $id): HsRiskAssessment
    {
        return $query->firstOr(static fn () => throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(HsRiskAssessment::class, [$id]));
    }

    public static function attachment(HsRiskAssessment $ra, HsRiskAssessmentAttachment $a): array
    {
        return [
            'id' => $a->id,
            'original_name' => $a->original_name,
            'mime' => $a->mime,
            'size' => $a->size,
            'kind' => $a->kind,
            'notes' => $a->notes,
            'is_image' => $a->isImage(),
            'uploaded_by_name' => $a->uploader?->name,
            'created_at' => optional($a->created_at)->toIso8601String(),
            'download_url' => "/health-safety/risk-assessments/{$ra->id}/attachments/{$a->id}/download",
        ];
    }

    /**
     * Lightweight picker datasets for the create / supersede wizards and the
     * site/client filters. Shared by the register, Client profile and Site profile.
     *
     * @return array{sites:Collection,clients:Collection,events:Collection}
     */
    public static function pickers(): array
    {
        $siteAccess = app(UserSiteAccessService::class);

        return self::siteScopedPickers($siteAccess->accessibleSiteIds(
            auth()->user(),
            ['healthSafety.viewAllSites'],
        ));
    }

    /**
     * Picker data for the standalone register, constrained to canonical Site access.
     *
     * @param  array<int, int|string>  $siteIds
     * @return array{sites:Collection,clients:Collection,events:Collection}
     */
    public static function siteScopedPickers(array $siteIds, ?User $viewer = null, ?HealthSafetyReadContext $context = null): array
    {
        $viewer ??= auth()->user();
        $rows = $viewer ? self::materialise($viewer, $context, fn (User $current, $reads, HealthSafetyReadContext $active): array => self::pickerRowsInContext($current, $siteIds, $active)) : ['sites' => [], 'clients' => [], 'events' => []];
        return array_map(fn ($values) => collect($values), $rows);
    }

    public static function pickerRowsInContext(User $viewer, array $siteIds, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::readPermissions(), function (User $current, $reads) use ($siteIds, $context): array {
            $empty = ['sites' => [], 'clients' => [], 'events' => []];
            if (! $current->canDo('hazards.view')) { return $empty; }
            $sites = app(UserSiteAccessService::class)->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            $siteIds = array_values(array_unique(array_map('intval', array_intersect($siteIds, $sites))));
            if ($siteIds === []) { return $empty; }
            $authority = app(HsRiskAssessmentService::class)->authorityQueryInContext($current, $context);
            $siteQuery = $reads->query(Site::query())->whereIn('id', $siteIds)->whereExists((clone $authority)->selectRaw('1')->toBase())->orderBy('name');
            $clientQuery = $reads->query(Client::query())->whereIn('site_id', $siteIds)->whereExists((clone $authority)->selectRaw('1')->toBase())->orderBy('first_name')->orderBy('last_name');
            $candidates = HsEvent::query()->whereIn('site_id', $siteIds);
            $events = app(HsEventAccessService::class)->applyReadableScope(clone $candidates, $current, $context, $candidates)
                ->whereExists((clone $authority)->selectRaw('1')->toBase())->latest();
            return [
                'sites' => self::track($siteQuery, $context)->get(['id', 'name'])->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name])->all(),
                'clients' => self::track($clientQuery, $context)->get(['id', 'first_name', 'last_name'])->map(fn (Client $client): array => ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)])->all(),
                'events' => self::track($events, $context)->get(['id', 'reference_number'])->map(fn (HsEvent $event): array => ['id' => $event->id, 'name' => $event->reference_number])->all(),
            ];
        });
    }

    /** Prefill bag for the Edit / Supersede wizards. */
    public static function formPrefill(HsRiskAssessment $ra): array
    {
        $attached = self::attachedTo($ra);

        return [
            'title' => $ra->title,
            'risk_description' => $ra->risk_description,
            'attach_type' => $attached['type'],
            'attach_id' => $attached['id'],
            'likelihood' => $ra->likelihood,
            'consequence' => $ra->consequence,
            'existing_controls' => $ra->existing_controls,
            'additional_controls' => $ra->additional_controls,
            'residual_likelihood' => $ra->residual_likelihood,
            'residual_consequence' => $ra->residual_consequence,
            'risk_acceptable' => $ra->risk_acceptable,
            'review_frequency_days' => $ra->review_frequency_days,
            'review_due_at' => optional($ra->review_due_at)->toDateString(),
        ];
    }

    /** @return array{type:string,id:int|null,name:string} */
    protected static function attachedTo(HsRiskAssessment $ra): array
    {
        if ($ra->assessable_type && $ra->assessable_id) {
            if (in_array($ra->assessable_type, [HsEvent::class, (new HsEvent)->getMorphClass()], true)) {
                return ['type' => 'event', 'id' => $ra->assessable_id,
                    'name' => $ra->relationLoaded('assessable') && $ra->assessable ? $ra->assessable->reference_number : 'H&S event'];
            }
            if (in_array($ra->assessable_type, [HsEvent::class, (new HsEvent)->getMorphClass()], true)) {
                return ['type' => 'event', 'id' => $ra->assessable_id,
                    'name' => $ra->relationLoaded('assessable') && $ra->assessable ? $ra->assessable->reference_number : 'H&S event'];
            }
            $base = class_basename($ra->assessable_type);
            $entity = $ra->relationLoaded('assessable') ? $ra->assessable : null;
            // Client has no `name` column — only a `full_name` accessor; Site has `name`.
            $name = $entity
                ? ($base === 'Client'
                    ? trim((string) ($entity->full_name ?? ($entity->first_name.' '.$entity->last_name)))
                    : ($entity->name ?? null))
                : null;

            return [
                'type' => strtolower($base),
                'id' => $ra->assessable_id,
                'name' => $name ?: ($base.' #'.$ra->assessable_id),
            ];
        }

        if ($ra->hs_event_id) {
            return [
                'type' => 'event',
                'id' => $ra->hs_event_id,
                'name' => ($ra->relationLoaded('hsEvent') && $ra->hsEvent)
                    ? $ra->hsEvent->reference_number
                    : 'H&S event',
            ];
        }

        return ['type' => 'standalone', 'id' => null, 'name' => 'Standalone'];
    }

    /** @return array{kind:string,days:int|null} NZ calendar: overdue / today / soon (next 30d) / ok / none. */
    protected static function reviewState(HsRiskAssessment $ra): array
    {
        $terminal = in_array($ra->status, [
            HsRiskAssessment::STATUS_DRAFT,
            HsRiskAssessment::STATUS_SUPERSEDED,
            HsRiskAssessment::STATUS_ARCHIVED,
        ], true);

        if (! $ra->review_due_at || $terminal) {
            return ['kind' => 'none', 'days' => null];
        }

        $days = (int) Carbon::now(config('app.worker_timezone', 'Pacific/Auckland') ?: config('app.timezone') ?: 'UTC')->startOfDay()->diffInDays(Carbon::parse($ra->review_due_at->toDateString(), config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay(), false);

        return match (true) {
            $days < 0 => ['kind' => 'overdue', 'days' => $days],
            $days === 0 => ['kind' => 'today', 'days' => 0],
            $days <= 30 => ['kind' => 'soon', 'days' => $days],
            default => ['kind' => 'ok', 'days' => $days],
        };
    }

    /** Business history only: no request metadata, storage paths, or inferred actors. */
    private static function history(HsRiskAssessment $assessment, Builder $original, HealthSafetyReadContext $context): array
    {
        $query = AuditLog::query()->whereIn('auditable_type', array_unique([HsRiskAssessment::class, $assessment->getMorphClass()]))
            ->where('auditable_id', $assessment->id)
            ->whereIn('action', ['hsriskassessment.create', 'hsriskassessment.update', 'risk_assessment.created',
                'risk_assessment.draft_updated', 'risk_assessment.status_changed', 'risk_assessment.residual_recorded',
                'risk_assessment.review_completed', 'risk_assessment.evidence_added', 'risk_assessment.evidence_removed'])
            ->with('user:id,name')->orderByDesc('id');
        $query = self::guardChildQuery($query, 'audit_logs.auditable_id', $original, $context);
        $logs = $query->get();
        $semanticCreation = $logs->contains('action', 'risk_assessment.created');

        return $logs->reject(fn (AuditLog $log) => $semanticCreation && $log->action === 'hsriskassessment.create')
            ->map(function (AuditLog $log): array {
                $meta = $log->meta ?? [];
                $legacy = str_starts_with($log->action, 'hsriskassessment.');
                $raw = $legacy ? ($meta['after'] ?? []) : ($meta['snapshot'] ?? []);
                $snapshot = self::historySnapshot(is_array($raw) ? $raw : []);
                $note = $legacy ? ($snapshot['last_review_note'] ?? $snapshot['approval_note'] ?? null) : ($meta['note'] ?? null);
                $title = match ($log->action) {
                    'hsriskassessment.create', 'risk_assessment.created' => 'Assessment created',
                    'risk_assessment.draft_updated' => 'Draft updated',
                    'risk_assessment.status_changed' => match ($meta['to'] ?? '') {
                        'active' => 'Assessment activated', 'under_review' => 'Review requested',
                        'superseded' => 'Assessment superseded', 'archived' => 'Assessment archived',
                        default => 'Status changed',
                    },
                    'risk_assessment.residual_recorded' => 'Residual risk recorded',
                    'risk_assessment.review_completed' => 'Review completed',
                    'risk_assessment.evidence_added' => 'Evidence added',
                    'risk_assessment.evidence_removed' => 'Evidence removed',
                    default => 'Record updated',
                };
                if (in_array($log->action, ['risk_assessment.evidence_added', 'risk_assessment.evidence_removed'], true)) {
                    $names = is_array($meta['names'] ?? null) ? $meta['names'] : [];
                    $snapshot['names'] = collect($names)->filter(fn ($name) => is_string($name))
                        ->map(fn ($name) => basename(str_replace('\\', '/', $name)))->values()->all();
                }

                return ['id' => 'audit-'.$log->id, 'action' => $log->action, 'title' => $title,
                    'actor' => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                    'occurred_at' => $log->created_at?->toIso8601String(),
                    'note' => is_string($note) ? $note : null, 'snapshot' => $snapshot, 'legacy' => $legacy];
            })->values()->all();
    }

    private static function historySnapshot(array $snapshot): array
    {
        $fields = ['title', 'risk_description', 'status', 'likelihood', 'consequence', 'risk_score', 'risk_level',
            'existing_controls', 'additional_controls', 'residual_likelihood', 'residual_consequence',
            'residual_risk_score', 'residual_risk_level', 'risk_acceptable', 'review_frequency_days',
            'review_due_at', 'approval_note', 'last_review_note'];

        return collect($snapshot)->only($fields)->filter(fn ($value) => is_scalar($value) || $value === null)->all();
    }

    public static function readPermissions(): array
    {
        return array_values(array_unique([...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
            ...InjuryAccessService::PERMISSIONS, ...SafeWorkProcedureAccessService::PERMISSIONS, ...EmergencyDrillAccessService::PERMISSIONS, ...\App\Services\ControlRoom\ControlRoomGovernanceReadService::PERMISSIONS,
            'governance.view', 'sites.viewAny', 'sites.type.head_office.view', 'sites.type.house.view', 'sites.type.facility.view']));
    }

    public static function materialise(?User $viewer, ?HealthSafetyReadContext $context, callable $operation): mixed
    {
        $viewer ??= auth()->user(); abort_unless($viewer, 403);
        $consume = function (HealthSafetyReadContext $active) use ($viewer, $operation): mixed {
            $active->assertViewer($viewer);
            return $active->consumeSourceViewer(self::readPermissions(), fn (User $current, $reads) => HealthSafetyReadMaterialisationProof::within($active,
                fn () => $operation($current, $reads, $active)));
        };
        return $context ? $consume($context) : HealthSafetyReadContext::within($viewer, self::readPermissions(), $consume);
    }

    /** Apply canonical Site/morph ownership and original source predicates before any risk body or eager relation. */
    /** Canonical Site scope, including modern event morphs and agreement with any legacy event binding. */
    public static function applySiteScopeInContext(Builder $query, User $viewer, HealthSafetyReadContext $context, ?array $selectedSiteIds = null): Builder
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::readPermissions(), function (User $current, $reads) use ($query, $context, $selectedSiteIds): Builder {
            $sites = app(UserSiteAccessService::class);
            $siteIds = $sites->accessibleSiteIds($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
            if ($selectedSiteIds !== null) { $siteIds = array_values(array_intersect($siteIds, $selectedSiteIds)); }
            $table = $query->getModel()->getTable();
            $query->where(function (Builder $scope) use ($sites, $siteIds, $current, $selectedSiteIds, $table): void {
                $scope->where(function (Builder $ordinary) use ($sites, $siteIds): void { $sites->applyHsRiskAssessmentSiteScopeForSiteIds($ordinary, $siteIds); });
                $scope->orWhere(function (Builder $modern) use ($siteIds, $table): void {
                    $event = HsEvent::query()->whereColumn('hs_events.id', $table.'.assessable_id')->whereIn('hs_events.site_id', $siteIds);
                    $event->where(function (Builder $bindings) use ($table): void {
                        $bindings->whereNull($table.'.hs_event_id')->orWhereExists(HsEvent::query()->from('hs_events as legacy_risk_event')
                            ->whereColumn('legacy_risk_event.id', $table.'.hs_event_id')
                            ->whereNull('legacy_risk_event.deleted_at')
                            ->whereColumn('legacy_risk_event.site_id', 'hs_events.site_id')->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)->selectRaw('1')->toBase());
                    });
                    $modern->whereIn($table.'.assessable_type', array_unique([HsEvent::class, (new HsEvent)->getMorphClass()]))
                        ->whereExists($event->selectRaw('1')->toBase());
                });
                if ($selectedSiteIds === null && $sites->canBypass($current, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS)) {
                    $scope->orWhere(fn (Builder $standalone) => $standalone->whereNull('hs_event_id')->whereNull('assessable_type')->whereNull('assessable_id'));
                }
            });
            return $context->query($query);
        });
    }

    public static function guardEagerQuery(Builder $query, User $viewer, HealthSafetyReadContext $context): Builder
    {
        $context->assertViewer($viewer);
        return $context->consumeSourceViewer(self::readPermissions(), function (User $current) use ($query, $context): Builder {
            abort_unless($current->canDo('hazards.view'), 403);
            self::applySiteScopeInContext($query, $current, $context);
            $authority = app(HsRiskAssessmentService::class)->authorityQueryInContext($current, $context);
            $query->whereExists((clone $authority)->selectRaw('1')->toBase());
            app(HsEventAccessService::class)->applyFirstAidRiskSourceScope($query, $current, $context);
            $parent = (clone $query)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...));
            $loads = $query->getEagerLoads();
            foreach (['hsEvent' => 'hs_event_id', 'assessedBy' => 'assessed_by_user_id', 'approvedBy' => 'approved_by_user_id', 'creator' => 'created_by'] as $name => $column) {
                if (! isset($loads[$name])) { continue; }
                $original = $loads[$name]; $binding = (clone $parent)->select('hs_risk_assessments.'.$column);
                $query->with([$name => function ($relation) use ($original, $binding, $parent, $context): void {
                    $original($relation); $relation->whereIn($relation->getRelated()->qualifyColumn('id'), clone $binding);
                    $context->reconsumeIncidentPredicate($relation->getQuery(), $parent); self::track($relation->getQuery(), $context);
                }]);
            }
            if (isset($loads['assessable'])) {
                $original = $loads['assessable'];
                $query->with(['assessable' => function (MorphTo $relation) use ($original, $parent, $context): void {
                    $original($relation);
                    $constraints = [];
                    foreach ([Site::class, Client::class, HsEvent::class] as $type) {
                        $constraints[$type] = function (Builder $target) use ($type, $parent, $context): void {
                            $binding = (clone $parent)->whereIn('assessable_type', array_unique([$type, (new $type)->getMorphClass()]))->select('hs_risk_assessments.assessable_id');
                            $target->whereIn($target->getModel()->qualifyColumn('id'), $binding);
                            $context->reconsumeIncidentPredicate($target, $parent); self::track($target, $context);
                        };
                    }
                    $relation->constrain($constraints);
                }]);
            }
            if (isset($loads['attachments'])) {
                $original = $loads['attachments'];
                $query->with(['attachments' => function ($relation) use ($original, $parent, $context): void {
                    $original($relation); self::guardChildQuery($relation->getQuery(), 'hs_risk_assessment_attachments.hs_risk_assessment_id', $parent, $context);
                }]);
            }
            if (isset($loads['attachments.uploader'])) {
                $original = $loads['attachments.uploader'];
                $attachments = self::guardChildQuery(HsRiskAssessmentAttachment::query(), 'hs_risk_assessment_attachments.hs_risk_assessment_id', $parent, $context);
                $query->with(['attachments.uploader' => function ($relation) use ($original, $attachments, $parent, $context): void {
                    $original($relation); $relation->whereIn('users.id', (clone $attachments)->select('uploaded_by'));
                    $context->reconsumeIncidentPredicate($relation->getQuery(), $parent); self::track($relation->getQuery(), $context);
                }]);
            }
            return self::track($query, $context);
        });
    }

    public static function guardChildQuery(Builder $child, string $parentColumn, Builder $original, HealthSafetyReadContext $context): Builder
    {
        $child->whereIn($parentColumn, (clone $original)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select('hs_risk_assessments.id'));
        $context->reconsumeIncidentPredicate($child, $original);
        foreach (['user' => 'user_id', 'uploader' => 'uploaded_by'] as $relationName => $column) {
            $loads = $child->getEagerLoads();
            if (! isset($loads[$relationName])) { continue; }
            $load = $loads[$relationName]; $proof = (clone $child)->setEagerLoads([])->reorder()->when(true, QueryPaging::clear(...))->select($child->getModel()->qualifyColumn($column));
            $child->with([$relationName => function ($relation) use ($load, $proof, $original, $context): void {
                $load($relation); $relation->whereIn('users.id', clone $proof); $context->reconsumeIncidentPredicate($relation->getQuery(), $original); self::track($relation->getQuery(), $context);
            }]);
        }
        return self::track($child, $context);
    }

    private static function track(Builder $query, HealthSafetyReadContext $context): Builder
    {
        $query = $context->query($query);
        return HealthSafetyReadMaterialisationProof::current($context)?->protect($query) ?? $query;
    }
}
