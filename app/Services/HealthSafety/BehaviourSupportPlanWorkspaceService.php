<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\BehaviourSupportPlanReview;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\RestraintCommandReceipt;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Respite\RespiteStayScope;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\RestraintReturn;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Detached plan facts and operation-specific review/signoff reads; never a source-read grant. */
final class BehaviourSupportPlanWorkspaceService
{
    private const POLICY_FIELDS = ['setting_key', 'schema_version', 'mode', 'policy_revision', 'policy_digest', 'policy_snapshot', 'setting_change_id', 'origin', 'reviewed_at', 'configuration_valid', 'configuration_error'];

    private const RECEIPT_FIELDS = ['accepted', 'source_type', 'plan_id', 'command', 'idempotency_key', 'expected_version', 'version', 'status', 'candidate_version_id', 'approval_id', 'published_version_id', 'review_id', 'requested_status', 'actual_status', 'disposition'];

    private const COMMANDS = ['create', 'update', 'submit_review', 'activate', 'archive', 'review', 'approval'];

    public function __construct(private readonly RestraintAccessService $access, private readonly BehaviourSupportApprovalPolicySettings $settings) {}

    public function detail(BehaviourSupportPlan $plan, User $viewer, string $workspace = 'view', ?string $origin = null, array $options = []): array
    {
        abort_unless(in_array($workspace, ['view', 'review', 'approval'], true), 404);

        return $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($plan, $workspace, $origin, $options): array {
            $row = $reads->query(BehaviourSupportPlan::withTrashed())->findOrFail($plan->id);
            $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($current, [...RestraintAccessService::PERMISSIONS, 'clients.update', 'staff.credentials.viewAny', 'staff.viewAny']);
            $policy = null;
            $policyAuthority = $current->canDo('restraints.review') || $current->canDo('restraints.manage') || $current->canDo('restraints.approve');
            if ($workspace === 'approval') {
                abort_unless($policyAuthority, 403);
                // Reading an editor never materializes a default identity or mutates the policy mutex.
                $policy = $this->settings->snapshot();
                $this->access->assertPlan($row, $current, $reads, $sites, $this->approvalOperation($policy, $current));
            } else {
                $this->access->assertPlan($row, $current, $reads, $sites, $workspace);
                if ($policyAuthority) {
                    $policy = $this->settings->snapshot();
                }
            }
            $includeHistory = $options['include_history'] ?? true;
            $selectedVersion = $options['version'] ?? null;
            $selectedApproval = $options['approval'] ?? null;
            $versionsQuery = $reads->query(BehaviourSupportPlanVersion::query())->where('behaviour_support_plan_id', $row->id);
            if (! $includeHistory) {
                $ids = array_values(array_filter([$row->current_candidate_version_id, $row->current_published_version_id, $selectedVersion]));
                if ($selectedApproval !== null) {
                    $choice = $reads->query(BehaviourSupportPlanApproval::query())->where('behaviour_support_plan_id', $row->id)->findOrFail($selectedApproval);
                    $ids[] = $choice->plan_version_id;
                }
                $versionsQuery->whereIn('id', $ids);
            }
            $versions = [];
            foreach ($versionsQuery->orderBy('sequence')->get() as $version) {
                $safe = $this->conceal(function () use ($version, $row, $current, $reads, $sites): BehaviourSupportPlanVersion {
                    $this->access->assertVersion($version, $row, $current, $reads, $sites);

                    return $version;
                });
                if ($safe) {
                    $versions[(int) $safe->id] = $safe;
                }
            }
            // A published selection needs its exact immutable candidate, even in a compact command refresh.
            foreach (array_values($versions) as $version) {
                if ($version->candidate_version_id && ! isset($versions[$version->candidate_version_id])) {
                    $candidate = $reads->query(BehaviourSupportPlanVersion::query())->where('behaviour_support_plan_id', $row->id)->find($version->candidate_version_id);
                    if ($candidate && $this->conceal(fn () => $this->versionAllowed($candidate, $row, $current, $reads, $sites))) {
                        $versions[(int) $candidate->id] = $candidate;
                    }
                }
            }
            if ($selectedVersion !== null) {
                abort_unless(isset($versions[$selectedVersion]), 404);
            }
            $candidate = $versions[$row->current_candidate_version_id] ?? null;
            $published = $versions[$row->current_published_version_id] ?? null;
            if ($candidate && $candidate->kind !== 'candidate') {
                $candidate = null;
            }
            if ($published && $published->kind !== 'published') {
                $published = null;
            }
            $approvalsQuery = $reads->query(BehaviourSupportPlanApproval::query())->where('behaviour_support_plan_id', $row->id)->whereIn('plan_version_id', array_keys($versions));
            if (! $includeHistory) {
                $approvalsQuery->where(fn ($q) => $q->where('plan_version_id', $candidate?->id ?? 0)->orWhereIn('id', array_values(array_filter([$published?->approval_id, $selectedApproval]))));
            }
            $approvals = [];
            foreach ($approvalsQuery->orderBy('id')->get() as $approval) {
                $target = $versions[$approval->plan_version_id] ?? null;
                if ($target && $target->kind === 'candidate' && $approval->client_id === $target->client_id && $approval->site_id === $target->site_id && $approval->stay_id === $target->stay_id && hash_equals($approval->content_digest, $target->content_digest)) {
                    $approvals[(int) $approval->id] = $approval;
                }
            }
            if ($selectedApproval !== null) {
                abort_unless(isset($approvals[$selectedApproval]), 404);
            }
            $staffCache = [];
            $staff = function ($id, ?array $snapshot = null) use ($row, $reads, &$staffCache): ?array {
                if (! $id && ! isset($snapshot['id'])) {
                    return null;
                }
                $key = $this->identity($id ?? $snapshot['id']);
                if ($key === null) {
                    return ['id' => null, 'name' => null, 'visibility' => 'identity_retained'];
                }
                if (! array_key_exists($key, $staffCache)) {
                    $person = $this->conceal(fn () => $this->access->eligibleStaff($key, (int) ($row->source_site_id ?? $row->client->site_id), $reads));
                    $staffCache[$key] = $person ? ['id' => (int) $person->id, 'name' => $person->name, 'visibility' => 'current'] : ['id' => null, 'name' => null, 'visibility' => 'identity_retained'];
                }
                $label = $staffCache[$key];
                if ($label['visibility'] === 'current' && $this->identity($snapshot['id'] ?? null) === $key && is_string($snapshot['name'] ?? null)) {
                    $label['recorded_name'] = $snapshot['name'];
                }

                return $label;
            };
            $mutable = ! $row->trashed() && $row->status !== 'archived';
            $reviewAuthority = $current->canDo('restraints.review') || $current->canDo('restraints.manage');
            $approvalAuthority = $policy && $policy['configuration_valid'] && ($policy['mode'] === 'authorised_reviewer' ? $reviewAuthority : $current->canDo('restraints.approve'));
            $candidateValid = $candidate && $this->contentValid($candidate->content) && hash_equals($candidate->content_digest, RestraintCommandValues::digest($candidate->content));
            $can = ['view' => $current->canDo('restraints.view'), 'review' => $mutable && $reviewAuthority, 'approve' => $mutable && (bool) $approvalAuthority && (bool) $candidateValid,
                'edit' => $mutable && $current->canDo('restraints.manage'), 'submit_review' => $mutable && $current->canDo('restraints.manage') && in_array($row->status, ['draft', 'active'], true),
                'activate' => $mutable && $current->canDo('restraints.manage') && in_array($row->status, ['draft', 'under_review'], true) && (bool) ($policy['configuration_valid'] ?? false) && (bool) $candidateValid,
                'archive' => $mutable && $current->canDo('restraints.manage'), 'create' => $current->canDo('restraints.create') || $current->canDo('restraints.manage')];
            $base = RestraintReturn::REGISTER.'/plans/'.$row->id;
            $endpoints = ['store' => $can['create'] ? RestraintReturn::REGISTER.'/plans' : null, 'update' => $can['edit'] ? $base : null, 'submit_review' => $can['submit_review'] ? $base.'/submit-review' : null,
                'activate' => $can['activate'] ? $base.'/activate' : null, 'archive' => $can['archive'] ? $base.'/archive' : null, 'review' => $can['review'] ? $base.'/review' : null, 'decision' => $can['approve'] ? $base.'/approvals' : null];
            $hrefs = ['show' => $can['view'] ? self::href((int) $row->id, 'view', $origin) : null, 'review' => $reviewAuthority ? self::href((int) $row->id, 'review', $origin) : null, 'approval' => $approvalAuthority ? self::href((int) $row->id, 'approval', $origin) : null];
            $versionDtos = [];
            foreach ($versions as $id => $version) {
                $versionDtos[$id] = $this->version($version, $versions, $approvals, $staff, $origin, $workspace);
            }
            $approvalDtos = [];
            foreach ($approvals as $id => $approval) {
                if (! $includeHistory && $selectedApproval !== $id) {
                    continue;
                }
                $approvalDtos[$id] = $this->approval($approval, $versions[$approval->plan_version_id], $workspace !== 'view', $current, $reads, $sites, $staff);
                $approvalDtos[$id]['supersedes_approval_id'] = isset($approvals[$approval->supersedes_approval_id]) ? $approval->supersedes_approval_id : null;
            }
            $reviews = [];
            if ($includeHistory) {
                foreach ($reads->query(BehaviourSupportPlanReview::query())->where('behaviour_support_plan_id', $row->id)->orderBy('id')->get() as $review) {
                    if ($review->plan_version_id !== null && ! isset($versions[$review->plan_version_id])) {
                        continue;
                    }
                    $reviews[] = ['id' => (int) $review->id, 'reviewed_at' => $this->instant($review->reviewed_at), 'outcome' => $review->outcome, 'next_review_date' => $review->next_review_date?->format('Y-m-d'),
                        'resulting_status' => $review->resulting_status, 'requested_resulting_status' => $review->requested_resulting_status, 'approval_disposition' => $review->approval_disposition,
                        'notes' => $review->notes, 'plan_version_id' => $review->plan_version_id, 'approval_id' => isset($approvals[$review->approval_id]) ? $review->approval_id : null, 'reviewer' => $staff($review->reviewed_by, $review->reviewer_snapshot), 'legacy' => $review->command_key === null];
                }
            }
            $client = $row->client;
            $siteId = $row->source_site_id ?? $client->site_id;
            $site = $reads->query(Site::query())->find($siteId);

            return ['id' => (int) $row->id, 'reference_number' => $row->reference_number, 'title' => $row->title, 'status' => $row->status, 'lock_version' => (int) $row->lock_version,
                'client' => ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name)], 'site' => $site ? ['id' => (int) $site->id, 'name' => $site->name] : null, 'stay' => $row->source_stay_id ? ['id' => (int) $row->source_stay_id] : null,
                'source_provenance' => $row->source_schema_version === 1 ? 'recorded' : 'legacy_uncertainty', 'content' => $this->content($row), 'developer' => $staff($row->developed_by), 'created_by' => $staff($row->created_by), 'updated_by' => $staff($row->updated_by),
                'candidate' => $candidate ? $versionDtos[$candidate->id] : null, 'published_version' => $published ? $versionDtos[$published->id] : null, 'selected_version' => $selectedVersion !== null ? $versionDtos[$selectedVersion] : null,
                'selected_approval' => $selectedApproval !== null ? $approvalDtos[$selectedApproval] : null, 'versions' => $includeHistory ? array_values($versionDtos) : [], 'reviews' => $reviews, 'approvals' => $includeHistory ? array_values($approvalDtos) : [],
                'approval_options' => $workspace !== 'view' && $candidate && $approvalAuthority ? $this->approvalOptions($row, $candidate, $policy, $current, $reads, $sites) : null, 'pickers' => ['staff' => $can['edit'] ? $this->developerOptions($row, $reads) : []], 'current_policy' => $policy ? self::policy($policy) : null, 'can' => $can, 'commands' => ['can' => $can, 'endpoints' => $endpoints], 'readable_hrefs' => $hrefs,
                'connections' => $this->connections($row, $current, $reads, $sites, $site, $origin), 'history' => $includeHistory ? $this->history($row, $reads, $staff) : [],
                'legacy_approval_unknown' => $published === null || $versionDtos[$published->id]['approval_id'] === null, 'approval_explanation' => 'Plan approval records the selected organisation workflow; it does not establish professional qualification, consent, intervention or medication authority.'];
        });
    }

    public function confirmationForViewer(User $viewer, mixed $hint): ?array
    {
        if (! is_array($hint) || ! is_int($hint['plan_id'] ?? null) || ! is_string($hint['idempotency_key'] ?? null) || ! Str::isUuid($hint['idempotency_key'])) {
            return null;
        }

        return $this->conceal(fn () => $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($hint): ?array {
            $row = $reads->query(BehaviourSupportPlan::withTrashed())->find($hint['plan_id']);
            $receipt = $reads->query(RestraintCommandReceipt::query())->where('source_type', 'plan')->where('source_id', $hint['plan_id'])->where('user_id', $current->id)->where('idempotency_key', strtolower($hint['idempotency_key']))->first();
            if (! $row || ! $receipt || ! in_array($receipt->command, self::COMMANDS, true)) {
                return null;
            }
            $result = $receipt->accepted_result;
            foreach (['version', 'command', 'receipt_id'] as $key) {
                $actual = $key === 'receipt_id' ? (int) $receipt->id : ($result[$key] ?? null);
                if (array_key_exists($key, $hint) && $hint[$key] !== $actual) {
                    return null;
                }
            }
            $operation = $receipt->command === 'approval' ? (($result['applied_policy']['mode'] ?? null) === 'authorised_reviewer' ? 'review' : 'approve') : $receipt->command;
            $this->access->assertPlan($row, $current, $reads, $sites, $operation);
            $audit = $reads->query(AuditLog::query())->where('id', $receipt->audit_log_id)->where('user_id', $current->id)->where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)->where('action', 'behaviour_support_plan.'.$receipt->command)->where('meta->idempotency_key', $receipt->idempotency_key)->first();
            if (! $audit || ($result['accepted'] ?? null) !== true || ($result['source_type'] ?? null) !== 'plan' || ($result['command'] ?? null) !== $receipt->command || ($result['expected_version'] ?? null) !== $receipt->expected_version || (int) ($result['plan_id'] ?? 0) !== (int) $row->id || ($result['idempotency_key'] ?? null) !== $receipt->idempotency_key) {
                return null;
            }

            return [...Arr::only($result, self::RECEIPT_FIELDS), 'applied_policy' => is_array($result['applied_policy'] ?? null) ? self::policy($result['applied_policy']) : null, 'receipt_id' => (int) $receipt->id, 'replayed' => false];
        }));
    }

    public function readableHref(BehaviourSupportPlan $plan, User $viewer, ?string $origin = null, ?string $section = null, ?string $preferred = null): ?string
    {
        return $this->conceal(fn () => $this->access->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($plan, $origin, $section, $preferred): ?string {
            $row = $reads->query(BehaviourSupportPlan::withTrashed())->findOrFail($plan->id);
            foreach (array_unique(array_filter([$preferred, 'view', 'review', 'approval'])) as $mode) {
                if ($mode === 'approval') {
                    if (! ($current->canDo('restraints.approve') || $current->canDo('restraints.review') || $current->canDo('restraints.manage'))) {
                        continue;
                    }
                    $policy = $this->settings->snapshot();
                    $operation = $this->approvalOperation($policy, $current);
                } else {
                    $operation = $mode;
                }
                if ($this->conceal(function () use ($row, $current, $reads, $sites, $operation): bool {
                    $this->access->assertPlan($row, $current, $reads, $sites, $operation);

                    return true;
                })) {
                    return self::href((int) $row->id, $mode, $origin, $section);
                }
            }

            return null;
        }));
    }

    public static function href(int $id, string $workspace = 'view', ?string $origin = null, ?string $section = null, ?int $version = null): string
    {
        $base = RestraintReturn::REGISTER.'/plans/'.$id;
        $href = RestraintReturn::plan($id, $origin, $section, $version);

        return $workspace === 'view' ? $href : $base.'/'.$workspace.substr($href, strlen($base));
    }

    private function approvalOperation(array $policy, User $current): string
    {
        if (! $policy['configuration_valid']) {
            return $current->canDo('restraints.approve') ? 'approve' : 'review';
        }

        return $policy['mode'] === 'authorised_reviewer' ? 'review' : 'approve';
    }

    private function versionAllowed(BehaviourSupportPlanVersion $version, BehaviourSupportPlan $plan, User $current, CurrentAuthorizationReads $reads, array $sites): bool
    {
        $this->access->assertVersion($version, $plan, $current, $reads, $sites);

        return true;
    }

    private function version(BehaviourSupportPlanVersion $row, array $versions, array $approvals, callable $staff, ?string $origin, string $workspace): array
    {
        $approval = $approvals[$row->approval_id] ?? null;
        $candidate = $versions[$row->candidate_version_id] ?? null;
        if (! $candidate || $candidate->kind !== 'candidate' || $candidate->client_id !== $row->client_id || $candidate->site_id !== $row->site_id || $candidate->stay_id !== $row->stay_id || ! hash_equals($candidate->content_digest, $row->content_digest) || ! $this->contentValid($candidate->content) || ! hash_equals($candidate->content_digest, RestraintCommandValues::digest($candidate->content)) || ! $this->contentValid($row->content) || ! hash_equals($row->content_digest, RestraintCommandValues::digest($row->content))) {
            $approval = null;
        }

        return ['id' => (int) $row->id, 'sequence' => $row->sequence, 'kind' => $row->kind, 'candidate_version_id' => $row->candidate_version_id, 'approval_id' => $approval && $approval->plan_version_id === $row->candidate_version_id ? (int) $approval->id : null,
            'content_digest' => $row->content_digest, 'content' => $this->safeContent($row->content), 'review_date' => $row->review_date?->format('Y-m-d'),
            'recorded_at' => $this->instant($row->recorded_at), 'recorded_by' => $staff($row->recorded_by, $row->actor_snapshot), 'published_at' => $this->instant($row->published_at), 'published_by' => $staff($row->published_by, $row->actor_snapshot),
            'effective_from' => $this->instant($row->effective_from), 'effective_to' => $this->instant($row->effective_to), 'applied_policy' => $approval ? self::policy($approval->policy_snapshot) : null,
            'integrity_valid' => $this->contentValid($row->content) && hash_equals($row->content_digest, RestraintCommandValues::digest($row->content)), 'href' => self::href((int) $row->behaviour_support_plan_id, $workspace, $origin, 'versions', (int) $row->id)];
    }

    private function approval(BehaviourSupportPlanApproval $row, BehaviourSupportPlanVersion $candidate, bool $bounded, User $current, CurrentAuthorizationReads $reads, array $sites, callable $staff): array
    {
        $approver = $row->origin === 'external_specialist' ? ($bounded ? ['id' => null, 'name' => $this->text($row->approver_snapshot['name'] ?? null), 'role' => $this->text($row->approver_snapshot['factual_role'] ?? null), 'organisation' => $this->text($row->approver_snapshot['organisation'] ?? null), 'decision_reference' => $this->text($row->approver_snapshot['decision_reference'] ?? null), 'decision_date' => $this->text($row->approver_snapshot['decision_date'] ?? null), 'visibility' => 'recorded_external_decision'] : null) : $staff($row->approver_id, $row->approver_snapshot);
        if ($bounded && $approver !== null) {
            $approver['role'] = $this->text($row->approver_snapshot['factual_role'] ?? null);
        }

        return ['id' => (int) $row->id, 'plan_version_id' => $row->plan_version_id, 'decision' => $row->decision, 'origin' => $row->origin, 'approved_at' => $this->instant($row->approved_at), 'recorded_at' => $this->instant($row->recorded_at),
            'effective_from' => $this->instant($row->effective_from), 'effective_to' => $this->instant($row->effective_to), 'applied_policy' => self::policy($row->policy_snapshot), 'supersedes_approval_id' => $row->supersedes_approval_id,
            'approver' => $approver, 'recorder' => $staff($row->recorded_by, $row->recorder_snapshot), 'basis' => $bounded ? $row->basis : null, 'professional_eligibility_verified' => false,
            'evidence' => $bounded ? array_map(fn ($reference) => $this->evidence($reference, $candidate, $current, $reads, $sites), (is_array($row->evidence_references) ? $row->evidence_references : [])) : []];
    }

    private function evidence(mixed $reference, BehaviourSupportPlanVersion $candidate, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $type = is_array($reference) && in_array($reference['type'] ?? null, ['client_document', 'event_attachment'], true) ? $reference['type'] : null;
        $hidden = ['type' => $type, 'id' => null, 'label' => null, 'availability' => 'inaccessible', 'href' => null];
        if ($type === null || $this->identity($reference['id'] ?? null) === null) {
            return $hidden;
        }

        return $this->conceal(function () use ($reference, $candidate, $current, $reads, $sites): array {
            if (($reference['type'] ?? null) === 'client_document') {
                $document = $reads->query(ClientDocument::query())->where('client_id', $candidate->client_id)->findOrFail($reference['id']);
                abort_unless($this->documentReadAllowed((int) $candidate->client_id, $current, $reads), 403);
                $disk = $document->storage_disk;
                $path = $document->storage_path;
                $label = $document->title;
                $removed = false;
                $href = '/clients/'.$candidate->client_id.'/documents/'.$document->id.'/download';
            } else {
                abort_unless(($reference['type'] ?? null) === 'event_attachment', 404);
                $attachment = $reads->query(RestraintEventAttachment::query())->findOrFail($reference['id']);
                $event = $reads->query(RestraintEvent::withTrashed())->findOrFail($attachment->restraint_event_id);
                abort_unless((int) $event->client_id === $candidate->client_id && (int) $event->site_id === $candidate->site_id && ($candidate->stay_id === null || (int) $event->stay_id === $candidate->stay_id), 404);
                $this->access->assertEvent($event, $current, $reads, $sites, 'view');
                $disk = $attachment->disk;
                $path = $attachment->path;
                $label = $attachment->original_name;
                $removed = $attachment->removed_at !== null;
                $href = RestraintReturn::REGISTER.'/events/'.$event->id.'/attachments/'.$attachment->id.'/download';
            }
            $available = ! $removed && $this->available($disk, $path);

            return ['type' => $reference['type'], 'id' => (int) $reference['id'], 'label' => $label, 'availability' => $removed ? 'removed' : ($available ? 'available' : 'unavailable'), 'href' => $available ? $href : null, 'current_bytes_verified' => false, 'preservation_claim' => false];
        }) ?? $hidden;
    }

    private function developerOptions(BehaviourSupportPlan $plan, CurrentAuthorizationReads $reads): array
    {
        $people = [];
        foreach ($reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())->orderBy('name')->orderBy('id')->pluck('id') as $id) {
            $person = $this->conceal(fn () => $this->access->eligibleStaff((int) $id, (int) ($plan->source_site_id ?? $plan->client->site_id), $reads));
            if ($person) {
                $people[] = ['id' => (int) $person->id, 'name' => $person->name];
            }
        }

        return $people;
    }

    /** Choice references are command inputs, independently re-authorised again on save. */
    private function approvalOptions(BehaviourSupportPlan $plan, BehaviourSupportPlanVersion $candidate, array $policy, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $people = [];
        $query = $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery());
        if ($policy['mode'] === 'authorised_reviewer') {
            $query->whereKey($current->id);
        }
        foreach ($query->orderBy('name')->orderBy('id')->get(['users.id', 'users.name']) as $person) {
            $eligible = $this->conceal(function () use ($person, $candidate, $plan, $policy, $reads): User {
                $person = $this->access->eligibleStaff((int) $person->id, (int) $candidate->site_id, $reads, $policy['mode'] === 'authorised_reviewer' ? ['restraints.review', 'restraints.manage'] : ['restraints.approve']);
                $personSites = app(UserSiteAccessService::class)->accessibleSiteIds($person, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
                $this->access->assertPlan($plan, $person, $reads, $personSites, $policy['mode'] === 'authorised_reviewer' ? 'review' : 'approve');

                return $person;
            });
            if ($eligible) {
                $people[] = ['id' => (int) $eligible->id, 'name' => $eligible->name];
            }
        }
        $evidence = [];
        if ($this->documentReadAllowed((int) $candidate->client_id, $current, $reads)) {
            foreach ($reads->query(ClientDocument::query())->where('client_id', $candidate->client_id)->orderBy('id')->get(['id', 'client_id']) as $document) {
                $option = $this->evidence(['type' => 'client_document', 'id' => (int) $document->id], $candidate, $current, $reads, $sites);
                if ($option['id'] !== null) {
                    $evidence[] = $this->evidenceOption($option);
                }
            }
        }
        if ($current->canDo('restraints.view')) {
            $events = $reads->query(RestraintEvent::query())->where('client_id', $candidate->client_id)->where('site_id', $candidate->site_id)->when($candidate->stay_id !== null, fn ($q) => $q->where('stay_id', $candidate->stay_id))->pluck('id');
            foreach ($reads->query(RestraintEventAttachment::query())->whereIn('restraint_event_id', $events)->whereNull('removed_at')->orderBy('id')->get(['id']) as $attachment) {
                $option = $this->evidence(['type' => 'event_attachment', 'id' => (int) $attachment->id], $candidate, $current, $reads, $sites);
                if ($option['id'] !== null) {
                    $evidence[] = $this->evidenceOption($option);
                }
            }
        }
        $credentials = [];
        if ($policy['mode'] === 'separate_specialist') {
            $allowed = array_column($people, 'id');
            if (! ($current->canDo('staff.credentials.viewAny') || $current->canDo('staff.viewAny'))) {
                $allowed = array_values(array_intersect($allowed, [(int) $current->id]));
            }
            $today = now()->timezone('Pacific/Auckland')->format('Y-m-d');
            foreach ($reads->query(StaffCredential::query())->whereIn('user_id', $allowed)->where(fn ($q) => $q->whereNull('issued_at')->orWhereDate('issued_at', '<=', $today))->where(fn ($q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', $today))->orderBy('id')->get() as $credential) {
                $credentials[] = ['id' => (int) $credential->id, 'approver_id' => (int) $credential->user_id, 'label' => $credential->type, 'issuer' => $credential->issuer, 'reference' => $credential->reference, 'issued_at' => $credential->issued_at?->format('Y-m-d'), 'expires_at' => $credential->expires_at?->format('Y-m-d'), 'professional_eligibility_verified' => false];
            }
        }

        return ['internal_approvers' => $people, 'evidence' => $evidence, 'credentials' => $credentials];
    }

    private function evidenceOption(array $evidence): array
    {
        return ['label' => $evidence['label'], 'reference' => ['type' => $evidence['type'], 'id' => $evidence['id']], 'availability' => $evidence['availability'], 'selectable' => $evidence['availability'] === 'available', 'href' => $evidence['href']];
    }

    /** Actual Client documents section currently requires source editor access. */
    private function documentReadAllowed(int $clientId, User $current, CurrentAuthorizationReads $reads): bool
    {
        $client = $reads->query(Client::query())->find($clientId);

        return $client && app(ClientPolicy::class)->updateFromCurrentEvidence($current, $client, $reads);
    }

    private function connections(BehaviourSupportPlan $row, User $current, CurrentAuthorizationReads $reads, array $sites, ?Site $site, ?string $origin): array
    {
        $links = ['client' => ($current->canDo('clients.viewAny') || $current->canDo('clients.viewAssigned')) ? ['id' => (int) $row->client_id, 'href' => '/clients/'.$row->client_id] : null, 'site' => null, 'stay' => null, 'events' => []];
        if ($site && $this->canReadSite($site, $current, $reads)) {
            $links['site'] = ['id' => (int) $site->id, 'href' => '/sites/'.$site->id];
        }
        if ($row->source_stay_id && $current->canDo('respite.viewAny')) {
            $links['stay'] = $this->conceal(function () use ($row, $current): array {
                $request = Request::create('/');
                $request->setUserResolver(fn () => $current);
                $stay = app(RespiteStayScope::class)->resolveAuthorizedStay($request, (int) $row->source_stay_id);
                abort_unless((int) $stay->client_id === (int) $row->client_id && app(RespiteStayScope::class)->siteId($stay) === (int) ($row->source_site_id ?? $row->client->site_id), 404);

                return ['id' => (int) $stay->id, 'href' => '/respite/stays/'.$stay->id];
            });
        }
        if ($current->canDo('restraints.view')) {
            foreach ($reads->query(RestraintEvent::query())->where('behaviour_support_plan_id', $row->id)->where('client_id', $row->client_id)->orderBy('id')->get() as $event) {
                $link = $this->conceal(function () use ($event, $current, $reads, $sites, $origin): array {
                    $this->access->assertEvent($event, $current, $reads, $sites);

                    return ['id' => (int) $event->id, 'reference_number' => $event->reference_number, 'href' => RestraintReturn::event((int) $event->id, $origin)];
                });
                if ($link) {
                    $links['events'][] = $link;
                }
            }
        }

        return $links;
    }

    /** Mirrors actual SitePolicy::view with fresh explicit destination grants and no ordinary Site cache. */
    private function canReadSite(Site $site, User $viewer, CurrentAuthorizationReads $reads): bool
    {
        $types = ['head_office' => 'sites.type.head_office.view', 'house' => 'sites.type.house.view', 'facility' => 'sites.type.facility.view', 'residential' => 'sites.type.house.view'];
        $current = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($viewer, [...RestraintAccessService::PERMISSIONS, 'sites.viewAny', ...array_values($types)]);
        if (! $current->canDo('sites.viewAny')) {
            return false;
        }
        $hasTypeScope = collect(array_unique(array_values($types)))->contains(fn ($key) => $current->canDo($key));
        if ($hasTypeScope && (! isset($types[$site->type]) || ! $current->canDo($types[$site->type]))) {
            return false;
        }

        return in_array((int) $site->id, app(UserSiteAccessService::class)->accessibleSiteIds($current, ['sites.viewAll'], $reads), true);
    }

    private function history(BehaviourSupportPlan $plan, CurrentAuthorizationReads $reads, callable $staff): array
    {
        return $reads->query(RestraintCommandReceipt::query())->where('source_type', 'plan')->where('source_id', $plan->id)->whereIn('command', self::COMMANDS)->orderBy('id')->get()->map(fn ($row) => ['id' => (int) $row->id, 'command' => $row->command, 'version' => $row->accepted_result['version'] ?? null, 'status' => $row->accepted_result['status'] ?? null, 'recorded_at' => $this->instant($row->created_at), 'actor' => $staff($row->user_id, $row->actor_snapshot)])->all();
    }

    private function content(BehaviourSupportPlan $plan): array
    {
        $result = [];
        foreach (BehaviourSupportPlanService::CONTENT as $field) {
            $result[$field] = in_array($field, ['developed_at', 'review_date'], true) ? $plan->{$field}?->format('Y-m-d') : $plan->{$field};
        }

        return $this->safeContent($result);
    }

    private function safeContent(array $content): array
    {
        $safe = [];
        foreach (BehaviourSupportPlanService::CONTENT as $field) {
            $value = $content[$field] ?? null;
            $safe[$field] = $field === 'developed_by' ? $this->identity($value) : $this->text($value);
        }

        return $safe;
    }

    private function contentValid(array $content): bool
    {
        if (array_diff(array_keys($content), BehaviourSupportPlanService::CONTENT) || trim((string) $this->text($content['title'] ?? null)) === '') {
            return false;
        }
        foreach (BehaviourSupportPlanService::CONTENT as $field) {
            if (($content[$field] ?? null) !== null && ($field === 'developed_by' ? $this->identity($content[$field]) === null : ! is_string($content[$field]))) {
                return false;
            }
        }

        return true;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function identity(mixed $value): ?int
    {
        if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9]\\d*$/D', (string) $value) || strlen((string) $value) > strlen((string) PHP_INT_MAX) || (strlen((string) $value) === strlen((string) PHP_INT_MAX) && strcmp((string) $value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $value;
    }

    private static function policy(array $snapshot): array
    {
        $safe = Arr::only($snapshot, self::POLICY_FIELDS);
        $safe['policy_snapshot'] = is_array($safe['policy_snapshot'] ?? null) ? Arr::only($safe['policy_snapshot'], ['schema_version', 'mode']) : null;

        return $safe;
    }

    private function instant($value): ?string
    {
        return $value?->copy()->utc()->format('Y-m-d\\TH:i:s.uP');
    }

    private function available(mixed $disk, mixed $path): bool
    {
        if ($disk !== 'private' || ! is_string($path) || $path === '' || str_contains($path, chr(92)) || str_starts_with($path, '/') || preg_match('/(^|\\/)\\.\\.?(\\/|$)|[\\x00-\\x1f]|^[a-z]+:/i', $path)) {
            return false;
        }

        return Storage::disk('private')->exists($path);
    }

    private function conceal(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (ModelNotFoundException) {
            return null;
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return null;
            }
            throw $error;
        }
    }
}
