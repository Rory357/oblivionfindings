<?php

namespace App\Support\HealthSafety;

use App\Models\AuditLog;
use App\Models\HsAttachment;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\ProcedureReviewDelivery;
use App\Models\ProcedureReviewRequest;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\Site;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Tasks\TaskAggregator;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Published instructions, editable work and historical evidence have separate authority. */
final class SafeWorkProcedurePresenter
{
    private const CONTENT_FIELDS = ['title', 'reference_number', 'category', 'purpose', 'scope', 'steps',
        'ppe_required', 'hazards_addressed', 'emergency_procedures', 'applicable_roles', 'applicable_sites',
        'related_training', 'review_date', 'review_frequency_months', 'owner_id'];

    private const TITLES = [
        'procedure.created' => 'Procedure created', 'procedure.revised' => 'Working revision saved',
        'procedure.submitted_for_review' => 'Submitted for review', 'procedure.approved' => 'Version approved',
        'procedure.changes_requested' => 'Changes requested', 'procedure.review_recorded' => 'Review recorded',
        'procedure.archived' => 'Procedure archived', 'procedure.restored' => 'Restored for review',
        'procedure.evidence_added' => 'Document added', 'procedure.evidence_removed' => 'Document removed',
        'procedure.acknowledged' => 'Version read and acknowledged',
        'procedure.review_requested' => 'Source review requested', 'procedure.review_request_start' => 'Source review started',
        'procedure.review_request_resolve' => 'Source review outcome recorded', 'procedure.review_request_decline' => 'Source review declined',
        'procedure.review_request_cancel' => 'Source review cancelled', 'procedure.review_request_reassign' => 'Source review reassigned',
    ];

    public static function detail(SafeWorkProcedure $record, ?User $viewer = null, ?string $returnTo = null, ?int $versionId = null, bool $working = false): array
    {
        $viewer ??= auth()->user();
        abort_unless($viewer, 403);
        $access = app(SafeWorkProcedureAccessService::class);
        $record = $access->recordForViewer($record, $viewer);
        $record->loadMissing('versions', 'publishedVersion');
        $canReadWorking = $access->canReadWorking($record, $viewer);
        $current = $record->versions->firstWhere('version_number', (int) $record->current_version);
        $published = $record->publishedVersion;
        if ($published && ($record->status === 'archived' || ! $access->isApprovedVersion($published) || (int) $published->safe_work_procedure_id !== (int) $record->id || ! $access->canViewVersion($record, $published, $viewer))) {
            $published = null;
        }
        if ($versionId !== null) {
            $viewed = $record->versions->firstWhere('id', $versionId);
            abort_unless($viewed && $access->canViewVersion($record, $viewed, $viewer), 404);
            $kind = 'historical';
        } elseif ($working) {
            abort_unless($canReadWorking, 404);
            $viewed = $current;
            $kind = 'working';
        } elseif ($published) {
            $viewed = $published;
            $kind = 'published';
        } elseif ($record->status === 'archived' || ! $canReadWorking) {
            $viewed = $record->versions->first(fn (SafeWorkProcedureVersion $v) => (int) $v->safe_work_procedure_id === (int) $record->id
                && $access->isApprovedVersion($v) && $access->canViewVersion($record, $v, $viewer));
            abort_unless($viewed, 404);
            $kind = 'historical';
        } else {
            $viewed = $current;
            $kind = 'working';
        }
        $snapshot = $kind === 'working' ? $record->only(self::CONTENT_FIELDS) : self::snapshot($viewed?->content_snapshot);
        $version = $kind === 'working' ? (int) $record->current_version : (int) $viewed->version_number;
        $content = self::content($snapshot, $viewer);
        $manage = $access->canManage($record, $viewer);
        $mutable = $record->status !== 'archived' && $kind !== 'historical';
        $commands = $mutable && $manage;
        $acknowledgement = self::acknowledgement($record, $viewed, $viewer, $kind, $manage, $access);
        $readableVersions = $record->versions->filter(fn (SafeWorkProcedureVersion $v) => (int) $v->safe_work_procedure_id === (int) $record->id && $access->canViewVersion($record, $v, $viewer));
        $date = $kind === 'published' ? $record->published_review_date?->toDateString() : self::date($snapshot['review_date'] ?? null);
        $manifest = is_array($viewed?->document_manifest) ? $viewed->document_manifest : [];
        $attachments = self::attachments($record, $viewed, $manifest);
        $reviewRequests = self::reviewRequests($record, $viewer, $returnTo, $kind, $viewed, $access);

        return [...$content,
            'id' => (int) $record->id,
            'status' => $record->status === 'archived' ? 'archived' : ($kind === 'published' ? 'approved' : ($kind === 'working' ? $record->status : 'historical')),
            'is_current' => $kind === 'published',
            'previous_status' => $manage ? $record->previous_status : null,
            'version' => $version,
            'expected_version' => $canReadWorking ? (int) $record->current_version : $version,
            'lock_version' => $canReadWorking ? (int) $record->lock_version : 0,
            'review_date' => $date,
            'review_state' => self::reviewState($date, $kind === 'published'),
            'approved_by' => self::actor($viewed?->approved_by),
            'approved_at' => $viewed?->approved_at?->toIso8601String(),
            'creator' => $canReadWorking ? self::actor($record->created_by) : null,
            'updater' => $canReadWorking ? self::actor($record->updated_by) : null,
            'publication' => $published ? [...self::versionLink($record, $published, $returnTo),
                'href' => SafeWorkProcedureReturn::href((int) $record->id, $returnTo),
                'version_id' => (int) $published->id, 'approved_by' => self::actor($published->approved_by),
                'approved_at' => $published->approved_at?->toIso8601String(),
                'review_date' => $record->published_review_date?->toDateString(),
                ...self::provenance($published),
                'legacy_approved_by' => self::actor($published->legacy_approved_by),
                'legacy_approved_at' => $published->legacy_approved_at?->toIso8601String(),
            ] : null,
            'working_revision' => $canReadWorking ? ['version_id' => $current?->id, 'version' => (int) $record->current_version,
                'status' => $record->status, 'href' => SafeWorkProcedureReturn::href((int) $record->id, $returnTo, null, null, true)] : null,
            'viewed_revision' => ['version_id' => $viewed?->id, 'version' => $version, 'kind' => $kind, ...self::provenance($viewed)],
            'attachments' => $attachments,
            'documents_state' => ! is_array($viewed?->document_manifest) ? 'unrecorded' : (count($attachments) === count(self::ids($manifest)) ? 'available' : 'partially_available'),
            'versions' => $readableVersions->map(fn (SafeWorkProcedureVersion $v) => [...self::versionLink($record, $v, $returnTo),
                'change_summary' => $v->change_summary, 'changed_by' => self::actor($v->changed_by),
                'created_at' => $v->created_at?->toIso8601String(), ...self::provenance($v),
            ])->values()->all(),
            'history' => self::history($record, $readableVersions, $canReadWorking, $viewer, $reviewRequests),
            'review_requests' => $reviewRequests,
            'acknowledgement' => $acknowledgement,
            'acknowledged' => $acknowledgement['acknowledged'],
            'acknowledged_count' => $acknowledgement['current_version_count'],
            'form' => $commands && $canReadWorking ? [...self::form($record), 'expected_version' => (int) $record->current_version,
                'expected_lock_version' => (int) $record->lock_version] : null,
            'can' => ['view' => true, 'create' => $viewer->canDo('procedures.create'), 'manage' => $manage,
                'edit' => $commands && $canReadWorking,
                'submit_review' => $commands && $kind === 'working' && $record->status === 'draft',
                'approve' => $mutable && $kind === 'working' && $record->status === 'under_review' && $access->canApprove($record, $viewer),
                'request_changes' => $commands && $kind === 'working' && in_array($record->status, ['under_review', 'approved'], true),
                'record_review' => $commands && $kind === 'published',
                'archive' => $commands, 'restore' => $manage && $record->status === 'archived' && $kind !== 'historical',
                'add_evidence' => $commands && $kind === 'working', 'remove_evidence' => $commands && $kind === 'working',
                'acknowledge' => $acknowledgement['eligible'] && ! $acknowledgement['acknowledged'],
                'manage_review_requests' => $kind !== 'historical' && $access->canManageReviewRequests($record, $viewer),
            ],
        ];
    }

    /** Profile rows always describe the publication, even while its successor is drafted. */
    public static function applicableRow(SafeWorkProcedure $record, User $viewer, ?string $returnTo = null, ?int $subjectUserId = null): array
    {
        $access = app(SafeWorkProcedureAccessService::class);
        $record = $access->recordForViewer($record, $viewer);
        $record->loadMissing('publishedVersion');
        $v = $record->publishedVersion;
        abort_unless($v && $record->status !== 'archived' && (int) $v->safe_work_procedure_id === (int) $record->id && $access->canViewVersion($record, $v, $viewer), 404);
        $snapshot = self::snapshot($v->content_snapshot);
        $receipt = $record->acknowledgementReceipts()->where('version_id', $v->id)->where('user_id', $subjectUserId ?? $viewer->id)->first();

        return ['id' => (int) $record->id, 'reference_number' => $snapshot['reference_number'] ?? null,
            'title' => $snapshot['title'] ?? 'Procedure version', 'category' => $snapshot['category'] ?? '',
            'status' => 'approved', 'version' => (int) $v->version_number,
            'review_date' => $record->published_review_date?->toDateString(),
            'owner' => self::actor($snapshot['owner_id'] ?? null),
            'acknowledged' => $receipt !== null, 'published_version_id' => (int) $v->id,
            'href' => SafeWorkProcedureReturn::href((int) $record->id, $returnTo), ...self::provenance($v)];
    }

    /** Source panels project only live published snapshots; unavailable rows disclose nothing. */
    public static function applicableRows(?User $viewer, ?string $returnTo = null, ?int $siteId = null, ?User $subject = null, ?string $hazardType = null): Collection
    {
        if (! $viewer || ! $viewer->canDo('procedures.view')) {
            return collect();
        }
        $access = app(SafeWorkProcedureAccessService::class);
        try {
            $roles = $sites = null;
            if ($subject) {
                [$roles, $sites] = $access->withCurrentViewer($subject, fn ($current, $reads, $siteIds) => [
                    $current->roles->pluck('name')->push($current->role)->filter()->unique()->values()->all(), $siteIds,
                ]);
            }
            $records = $access->publishedForAudience($viewer, $siteId, $roles, $sites);
            if ($hazardType !== null) {
                $category = SafeWorkProcedure::HAZARD_TYPE_CATEGORIES[$hazardType] ?? null;
                $records = $records->filter(fn ($record) => $category !== null && $record->publishedValue('category') === $category);
            }

            return $records->map(function ($record) use ($viewer, $returnTo, $subject) {
                try {
                    return self::applicableRow($record, $viewer, $returnTo, $subject?->id);
                } catch (HttpException $error) {
                    if (in_array($error->getStatusCode(), [403, 404], true)) {
                        return null;
                    }
                    throw $error;
                }
            })->filter()->sortBy('title')->values();
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return collect();
            }
            throw $error;
        }
    }

    public static function pickers(User $viewer, ?SafeWorkProcedure $record = null): array
    {
        return app(SafeWorkProcedureAccessService::class)->pickerOptions($viewer, $record);
    }

    private static function content(array $snapshot, User $viewer): array
    {
        $siteIds = app(UserSiteAccessService::class)->accessibleHealthSafetySiteIds($viewer);
        $sites = self::ids($snapshot['applicable_sites'] ?? null);
        $roles = self::strings($snapshot['applicable_roles'] ?? null);
        $training = self::ids($snapshot['related_training'] ?? null);
        $roleLabels = Role::query()->whereIn('name', $roles)->pluck('label', 'name');

        return ['title' => is_string($snapshot['title'] ?? null) ? $snapshot['title'] : 'Procedure version',
            'reference_number' => $snapshot['reference_number'] ?? null, 'category' => $snapshot['category'] ?? '',
            'purpose' => self::text($snapshot['purpose'] ?? null), 'scope' => self::text($snapshot['scope'] ?? null),
            'steps' => self::steps($snapshot['steps'] ?? null), 'ppe_required' => self::strings($snapshot['ppe_required'] ?? null),
            'hazards_addressed' => self::strings($snapshot['hazards_addressed'] ?? null),
            'emergency_procedures' => is_array($snapshot['emergency_procedures'] ?? null)
                ? implode("\n", self::strings($snapshot['emergency_procedures'])) : (self::text($snapshot['emergency_procedures'] ?? null) ?? ''),
            'owner' => self::actor($snapshot['owner_id'] ?? null),
            'review_frequency_months' => is_numeric($snapshot['review_frequency_months'] ?? null) ? (int) $snapshot['review_frequency_months'] : null,
            'applies' => ['roles' => array_map(fn ($key) => ['key' => $key, 'label' => $roleLabels[$key] ?? $key], $roles),
                'sites' => Site::query()->whereIn('id', array_intersect($sites, $siteIds))->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
                'training' => TrainingCourse::query()->whereIn('id', $training)->get(['id', 'name', 'code'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'code' => $c->code])->all(),
                'roles_scope' => self::audienceState($snapshot, 'applicable_roles'),
                'sites_scope' => self::audienceState($snapshot, 'applicable_sites'),
            ],
        ];
    }

    private static function form(SafeWorkProcedure $record): array
    {
        return ['id' => (int) $record->id, 'title' => $record->title, 'reference_number' => $record->reference_number,
            'category' => $record->category, 'purpose' => $record->purpose ?? '', 'scope' => $record->scope ?? '',
            'steps' => self::steps($record->steps), 'ppe_required' => self::strings($record->ppe_required),
            'hazards_addressed' => self::strings($record->hazards_addressed),
            'emergency_procedures' => is_array($record->emergency_procedures) ? implode("\n", self::strings($record->emergency_procedures)) : ($record->emergency_procedures ?? ''),
            'applicable_roles' => $record->applicable_roles, 'applicable_sites' => $record->applicable_sites,
            'related_training' => $record->related_training, 'review_date' => $record->review_date?->toDateString(),
            'review_frequency_months' => $record->review_frequency_months, 'owner_id' => $record->owner_id];
    }

    private static function acknowledgement(SafeWorkProcedure $record, ?SafeWorkProcedureVersion $v, User $viewer, string $kind, bool $manage, SafeWorkProcedureAccessService $access): array
    {
        $receipt = $v ? $record->acknowledgementReceipts()->where('version_id', $v->id)->where('user_id', $viewer->id)->first() : null;
        $eligible = $kind === 'published' && $v && $record->status !== 'archived' && $access->canAcknowledge($record, $v, $viewer);

        return ['eligible' => (bool) $eligible, 'version' => $v?->version_number,
            'version_id' => $v?->id, 'acknowledged' => $receipt !== null,
            'acknowledged_at' => $receipt?->acknowledged_at?->toIso8601String(),
            'current_version_count' => $manage && $record->published_version_id
                ? $record->acknowledgementReceipts()->where('version_id', $record->published_version_id)->count() : null,
            'provenance' => $v?->provenance];
    }

    private static function attachments(SafeWorkProcedure $record, ?SafeWorkProcedureVersion $v, array $manifest): array
    {
        return $record->attachments()->whereIn('id', self::ids($manifest))->with('uploader:id,name')->get()
            ->map(fn (HsAttachment $a) => ['id' => (int) $a->id, 'original_name' => basename(str_replace('\\', '/', (string) $a->original_name)),
                'mime' => $a->mime_type, 'size' => $a->size_bytes, 'description' => $a->description,
                'version' => $a->version_at_upload, 'uploaded_by_name' => $a->uploader?->name,
                'created_at' => $a->created_at?->toIso8601String(),
                'url' => '/health-safety/procedures/'.$record->id.'/attachments/'.$a->id.'/download'.($v ? '?version='.$v->id : ''),
            ])->all();
    }

    private static function reviewRequests(SafeWorkProcedure $record, User $viewer, ?string $returnTo, string $kind, ?SafeWorkProcedureVersion $viewed, SafeWorkProcedureAccessService $access): array
    {
        $permissions = array_values(array_unique([
            ...\App\Services\HealthSafety\FirstAidAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\RestraintAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\InjuryAccessService::PERMISSIONS,
            ...SafeWorkProcedureAccessService::PERMISSIONS,
            ...\App\Services\HealthSafety\EmergencyDrillAccessService::PERMISSIONS,
        ]));
        return \App\Services\HealthSafety\HealthSafetyReadContext::within($viewer, $permissions,
            fn ($context) => $access->withReadContext($context, function ($viewer, $reads, $sites) use ($record, $returnTo, $kind, $viewed, $access, $context) {
                if (! $viewer->canDo('procedures.view') || ! $viewer->canDo('hazards.view')) {
                    return [];
                }
                $record = $reads->query(SafeWorkProcedure::query())->whereKey($record->id)->with(['versions', 'publishedVersion'])->firstOrFail();
                $working = $access->workingAllowed($record, $viewer, $sites);
                $versionReadable = fn ($version) => $version && (int) $version->safe_work_procedure_id === (int) $record->id
                    && ($access->isApprovedVersion($version) ? $access->audienceAllows($version->content_snapshot ?? [], $viewer, $sites)
                        : $working && $access->audienceAllows($version->content_snapshot ?? [], $viewer, $sites, true));
                $published = $record->publishedVersion;
                $admitted = $working || ($record->status !== 'archived' && $published && $access->isApprovedVersion($published) && $versionReadable($published))
                    || $record->versions->contains(fn ($version) => $access->isApprovedVersion($version) && $versionReadable($version));
                if (! $admitted) {
                    return [];
                }
                $viewed = $viewed ? $record->versions->firstWhere('id', $viewed->id) : null;
                if ($kind === 'historical' && ! $viewed) {
                    return [];
                }
                $requests = $reads->query(ProcedureReviewRequest::query())->where('safe_work_procedure_id', $record->id)->orderBy('id')->get()
                    ->filter(function ($request) use ($record, $kind, $viewed, $versionReadable, $sites) {
                        if (! in_array((int) $request->source_site_id, $sites, true)
                            || ($kind === 'historical' && ! in_array((int) $viewed?->id, [(int) $request->requested_version_id, (int) $request->result_version_id], true))) {
                            return false;
                        }
                        return $versionReadable($record->versions->firstWhere('id', $request->requested_version_id))
                            && (! $request->result_version_id || $versionReadable($record->versions->firstWhere('id', $request->result_version_id)));
                    });
                if ($requests->isEmpty()) {
                    return [];
                }
                // Named actions must belong to the request's exact event before
                // confidential source discovery, even for damaged legacy requests.
                $actions = $reads->query(HsCorrectiveAction::query())
                    ->whereKey($requests->pluck('hs_corrective_action_id')->filter()->unique()->all())
                    ->get(['id', 'hs_event_id'])->keyBy('id');
                $requests = $requests->filter(fn ($request) => ! $request->hs_corrective_action_id
                    || ($actions->has($request->hs_corrective_action_id)
                        && (int) $actions->get($request->hs_corrective_action_id)->hs_event_id === (int) $request->hs_event_id));
                if ($requests->isEmpty()) {
                    return [];
                }
                $candidates = HsEvent::query()->where(function ($tuples) use ($requests) {
                    foreach ($requests->groupBy('source_site_id') as $site => $atSite) {
                        $tuples->orWhere(fn ($pair) => $pair->where('site_id', (int) $site)->whereKey($atSite->pluck('hs_event_id')->all()));
                    }
                });
                $events = app(HsEventAccessService::class)->applyReadableScope(clone $candidates, $viewer, $context, $candidates)->get()->keyBy('id');
                $deliveries = $reads->query(ProcedureReviewDelivery::query())->whereIn('procedure_review_request_id', $requests->modelKeys())
                    ->orderByDesc('assignment_revision')->get()->unique('procedure_review_request_id')->keyBy('procedure_review_request_id');
                $manager = $viewer->canDo('procedures.manage') && is_array($record->applicable_sites) && $working
                    && $access->allSitesAllowed($record->applicable_sites, $sites, $viewer);
                $baseRecordHref = SafeWorkProcedureReturn::href((int) $record->id, $returnTo, 'connections', $kind === 'historical' ? $viewed?->id : null, $kind === 'working');
                $rows = [];
                foreach ($requests as $request) {
                    $requested = $record->versions->firstWhere('id', $request->requested_version_id);
                    $result = $request->result_version_id ? $record->versions->firstWhere('id', $request->result_version_id) : null;
                    $event = $events->get($request->hs_event_id);
                    if (! $event || (int) $request->source_site_id !== (int) $event->site_id) {
                        continue;
                    }
                    $action = $request->hs_corrective_action_id ? $actions->get($request->hs_corrective_action_id) : null;
                    if ($request->hs_corrective_action_id && (! $action || (int) $action->hs_event_id !== (int) $event->id)) {
                        continue;
                    }
                    $actionTask = $action ? self::requestActionTask($viewer, (int) $action->id, $context) : null;
                    if ($action && ! $actionTask?->link) {
                        continue;
                    }
                    $recordHref = $baseRecordHref.'&request='.$request->id;
                    $approvedAudience = $access->isApprovedVersion($requested) && $access->audienceAllows($requested->content_snapshot ?? [], $viewer, $sites);
                    $live = $approvedAudience && in_array($request->status, ['requested', 'in_review'], true) && $record->status !== 'archived';
                    $executor = $manager && (int) $request->assigned_to_user_id === (int) $viewer->id;
                    $can = $kind === 'historical' ? [] : [
                        'start' => $executor && $live && $request->status === 'requested',
                        'resolve' => $executor && $live && $request->status === 'in_review',
                        'decline' => $executor && $live,
                        'cancel' => $live && ((int) $request->requested_by_user_id === (int) $viewer->id || $manager),
                        'reassign' => $manager && $live,
                    ];
                    $delivery = $deliveries->get($request->id);
                    $deliveryProjection = $approvedAudience && ($manager || (int) $request->assigned_to_user_id === (int) $viewer->id)
                        && $delivery && (int) $delivery->recipient_user_id === (int) $request->assigned_to_user_id
                        && in_array($delivery->status, ['pending', 'failed', 'delivered', 'skipped'], true)
                        ? ['status' => $delivery->status,
                            'failure_code' => in_array($delivery->failure_code, ['queue_unavailable', 'delivery_failed', 'assignment_inactive', 'authority_changed', 'preference_suppressed'], true) ? $delivery->failure_code : null,
                            'attempts' => max(0, (int) $delivery->attempts),
                            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
                            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String()]
                        : null;
                    $rows[] = ['id' => (int) $request->id, 'status' => $request->status, 'lock_version' => (int) $request->lock_version,
                        'note' => $request->note, 'requested_at' => $request->requested_at?->toIso8601String(),
                        'requested_by' => self::actor($request->requested_by_user_id), 'assigned_to' => self::actor($request->assigned_to_user_id),
                        'requested_version' => self::versionLink($record, $requested, $returnTo, (int) $request->id),
                        'source' => ['id' => (int) $event->id, 'reference_number' => $event->reference_number, 'status' => $event->status,
                            'href' => '/health-safety/events/'.$event->id.'?section=actions&return_to='.rawurlencode($recordHref)],
                        'corrective_action' => $action ? ['id' => (int) $action->id, 'reference_number' => $actionTask->ref,
                            'title' => $actionTask->title, 'status' => $actionTask->status,
                            'href' => '/health-safety/corrective-actions/'.$action->id.'?return_to='.rawurlencode($recordHref)] : null,
                        'result_version' => $result ? self::versionLink($record, $result, $returnTo, (int) $request->id) : null,
                        'outcome' => in_array($request->outcome, ['no_change', 'revised'], true) ? $request->outcome : null,
                        'decided_at' => $request->decided_at?->toIso8601String(), 'decided_by' => self::actor($request->decided_by_user_id),
                        'decision_note' => $request->decision_note,
                        'delivery' => $deliveryProjection,
                        'can' => array_map(fn ($key) => (bool) ($can[$key] ?? false), array_combine(['start', 'resolve', 'decline', 'cancel', 'reassign'], ['start', 'resolve', 'decline', 'cancel', 'reassign'])),
                    ];
                }
                return $rows;
            }));
    }

    private static function requestActionTask(User $viewer, int $id, \App\Services\HealthSafety\HealthSafetyReadContext $context): ?\App\Services\Tasks\TaskItem
    {
        return app(TaskAggregator::class)->findItemFor($viewer, 'corrective_action', $id, ['include_done' => true], $context);
    }

    private static function history(SafeWorkProcedure $record, Collection $versions, bool $working, User $viewer, array $reviewRequests): array
    {
        $ids = $versions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $logs = AuditLog::query()->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->id)->with('user:id,name')->orderByDesc('id')->get();
        $semanticCreation = $logs->contains('action', 'procedure.created');
        $requestIds = array_column($reviewRequests, 'id');
        $siteIds = app(UserSiteAccessService::class)->accessibleHealthSafetySiteIds($viewer);
        $rows = [];
        foreach ($logs as $log) {
            $meta = is_array($log->meta) ? $log->meta : [];
            $legacy = ! isset(self::TITLES[$log->action]);
            $requestAction = str_starts_with($log->action, 'procedure.review_request');
            if ($requestAction && ! in_array((int) ($meta['request_id'] ?? 0), $requestIds, true)) {
                continue;
            }
            if ($legacy && (! $working || ! in_array($log->action, ['safeworkprocedure.create', 'safeworkprocedure.update'], true)
                || ($semanticCreation && $log->action === 'safeworkprocedure.create'))) {
                continue;
            }
            if (! $working && ! $requestAction && ! in_array((int) ($meta['version_id'] ?? 0), $ids, true)) {
                continue;
            }
            $snapshot = $requestAction ? [] : self::snapshot($legacy ? ($meta['after'] ?? []) : ($meta['snapshot'] ?? []));
            if (is_array($snapshot['applicable_sites'] ?? null)) {
                $snapshot['applicable_sites'] = array_values(array_intersect($snapshot['applicable_sites'], $siteIds));
            }
            if ($requestAction) {
                $snapshot['request_id'] = (int) $meta['request_id'];
                if (in_array($meta['outcome'] ?? null, ['no_change', 'revised'], true)) {
                    $snapshot['outcome'] = $meta['outcome'];
                }
                if (in_array((int) ($meta['result_version_id'] ?? 0), $ids, true)) {
                    $snapshot['result_version_id'] = (int) $meta['result_version_id'];
                }
            }
            foreach (['version', 'version_id', 'published_version_id', 'published_review_date', 'status', 'from', 'to'] as $key) {
                if ($requestAction && in_array($key, ['version', 'version_id', 'published_version_id'], true) && ! in_array((int) ($meta['version_id'] ?? 0), $ids, true)) {
                    continue;
                }
                if (! $legacy && (is_string($meta[$key] ?? null) || is_int($meta[$key] ?? null))) {
                    $snapshot[$key] = $meta[$key];
                }
            }
            if (! $legacy && in_array($log->action, ['procedure.evidence_added', 'procedure.evidence_removed'], true)) {
                $snapshot['names'] = array_map(fn ($name) => basename(str_replace('\\', '/', $name)), self::strings($meta['names'] ?? null));
            }
            $rows[] = ['id' => 'audit-'.$log->id, 'action' => $log->action,
                'title' => self::TITLES[$log->action] ?? 'Legacy procedure change — historical detail incomplete',
                'actor' => $log->user ? ['id' => (int) $log->user->id, 'name' => $log->user->name] : null,
                'occurred_at' => $log->created_at?->toIso8601String(), 'note' => $legacy ? null : self::text($meta['note'] ?? null),
                'snapshot' => $snapshot, 'legacy' => $legacy];
        }

        return $rows;
    }

    private static function versionLink(SafeWorkProcedure $record, SafeWorkProcedureVersion $v, ?string $returnTo, ?int $requestId = null): array
    {
        return ['id' => (int) $v->id, 'version' => (int) $v->version_number,
            'href' => SafeWorkProcedureReturn::href((int) $record->id, $returnTo, $requestId === null ? null : 'connections', (int) $v->id).($requestId === null ? '' : '&request='.$requestId)];
    }

    private static function provenance(?SafeWorkProcedureVersion $v): array
    {
        $provenance = in_array($v?->provenance, ['controlled', 'legacy_current_capture'], true) ? $v->provenance : 'legacy_incomplete';

        return ['provenance' => $provenance, 'captured_at' => $v?->captured_at?->toIso8601String(),
            'legacy' => str_starts_with($provenance, 'legacy'),
            'provenance_label' => $provenance === 'legacy_current_capture'
                ? 'Legacy approved record — captured current instructions; original version provenance incomplete'
                : (str_starts_with($provenance, 'legacy') ? 'Historical version provenance incomplete' : 'Controlled version')];
    }

    private static function snapshot(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $safe = [];
        foreach (['title', 'reference_number', 'category', 'purpose', 'scope'] as $key) {
            if (array_key_exists($key, $value) && (is_string($value[$key]) || $value[$key] === null)) {
                $safe[$key] = $value[$key];
            }
        }
        foreach (['owner_id', 'review_frequency_months'] as $key) {
            if (array_key_exists($key, $value)) {
                $safe[$key] = (is_int($value[$key]) || (is_string($value[$key]) && ctype_digit($value[$key]))) && (int) $value[$key] > 0 ? (int) $value[$key] : null;
            }
        }
        if (array_key_exists('review_date', $value)) {
            $safe['review_date'] = self::date($value['review_date']);
        }
        if (array_key_exists('steps', $value)) {
            $safe['steps'] = self::steps($value['steps']);
        }
        foreach (['ppe_required', 'hazards_addressed', 'emergency_procedures', 'applicable_roles'] as $key) {
            if (array_key_exists($key, $value)) {
                $safe[$key] = is_array($value[$key]) ? self::strings($value[$key]) : ($key === 'emergency_procedures' ? self::text($value[$key]) : null);
            }
        }
        foreach (['applicable_sites', 'related_training'] as $key) {
            if (array_key_exists($key, $value)) {
                $safe[$key] = is_array($value[$key]) ? self::ids($value[$key]) : null;
            }
        }

        return $safe;
    }

    private static function actor(mixed $id): ?array
    {
        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }
        $user = User::query()->staff()->find((int) $id, ['id', 'name']);

        return $user ? ['id' => (int) $user->id, 'name' => $user->name] : null;
    }

    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, fn ($item) => is_string($item) && $item !== '')) : [];
    }

    private static function ids(mixed $value): array
    {
        return is_array($value) ? array_values(array_unique(array_map('intval', array_filter($value, fn ($item) => (is_int($item) || (is_string($item) && ctype_digit($item))) && (int) $item > 0)))) : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function steps(mixed $value): array
    {
        return is_array($value) ? array_values(array_map(fn ($step) => ['step_number' => is_numeric($step['step_number'] ?? null) ? (int) $step['step_number'] : null,
            'description' => self::text($step['description'] ?? null) ?? '', 'safety_notes' => self::text($step['safety_notes'] ?? null) ?? ''], array_filter($value, 'is_array'))) : [];
    }

    private static function audienceState(array $snapshot, string $key): string
    {
        return ! array_key_exists($key, $snapshot) || ! is_array($snapshot[$key]) ? 'unknown' : ($snapshot[$key] === [] ? 'all' : 'specific');
    }

    private static function date(mixed $value): ?string
    {
        $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $value;
    }

    private static function reviewState(?string $date, bool $published): string
    {
        if (! $published || ! $date) {
            return 'none';
        }
        $today = now('Pacific/Auckland');

        return $date < $today->toDateString() ? 'overdue' : ($date === $today->toDateString() ? 'today' : ($date <= $today->addDays(30)->toDateString() ? 'due_soon' : 'current'));
    }
}
