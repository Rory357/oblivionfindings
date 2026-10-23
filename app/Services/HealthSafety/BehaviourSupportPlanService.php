<?php

namespace App\Services\HealthSafety;

use App\Models\AuditLog;
use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\BehaviourSupportPlanReview;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\RestraintCommandReceipt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\References\ReferenceNumberGenerator;
use App\Services\Respite\RespiteStayScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Canonical plan content and lifecycle commands; publication never implies clinical qualification. */
final class BehaviourSupportPlanService
{
    public const CONTENT = ['title', 'triggers', 'de_escalation_strategies', 'approved_interventions', 'prohibited_interventions', 'restrictive_practice_type', 'developed_by', 'developed_at', 'review_date', 'notes'];

    public const STATUSES = ['draft', 'active', 'under_review', 'archived'];

    public const OUTCOMES = ['continued', 'modified', 'reduced', 'discontinued', 'escalated'];

    public function __construct(private readonly RestraintAccessService $access, private readonly BehaviourSupportApprovalPolicySettings $settings) {}

    public function create(User $actor, array $input): array
    {
        $run = fn (?array $policy = null) => $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($input, $policy) {
            $this->access->assertOperation($current, 'create');
            $payload = $this->content($input, true);
            $this->only($input, [...self::CONTENT, 'client_id', 'site_id', 'stay_id', 'status', 'idempotency_key', 'expected_policy_revision']);
            $extra = Validator::make($input, ['client_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'site_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'stay_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'status' => ['sometimes', Rule::in(self::STATUSES)], 'idempotency_key' => ['required', 'uuid'], 'expected_policy_revision' => ['nullable', 'integer', 'min:0', 'max:'.(PHP_INT_MAX - 1)]])->validate();
            foreach (['client_id', 'site_id', 'stay_id', 'expected_policy_revision'] as $field) {
                if (isset($input[$field]) && is_bool($input[$field])) {
                    $this->invalid($field, 'Use an exact integer identifier or revision.');
                }
            }
            $client = $this->access->assertClient(Client::findOrFail($extra['client_id']), $current, $reads, $sites);
            $site = (int) $client->site_id;
            $stayId = isset($extra['stay_id']) ? (int) $extra['stay_id'] : null;
            if ($stayId !== null) {
                $stay = $this->access->assertStay($stayId, (int) $client->id, isset($extra['site_id']) ? (int) $extra['site_id'] : $site, $current, $reads, $sites);
                $site = app(RespiteStayScope::class)->siteId($stay);
            }
            abort_unless(! isset($extra['site_id']) || (int) $extra['site_id'] === $site, 403);
            $status = $extra['status'] ?? 'draft';
            abort_unless($status === 'draft' || $current->canDo('restraints.manage'), 403);
            $key = strtolower($extra['idempotency_key']);
            $payload = [...$payload, 'client_id' => (int) $client->id, 'site_id' => $site, 'stay_id' => $stayId, 'requested_status' => $status, 'expected_policy_revision' => isset($extra['expected_policy_revision']) ? (int) $extra['expected_policy_revision'] : null];
            $fingerprint = $this->fingerprint(['plan', null, (int) $current->id, 'create', null, $payload]);
            $existing = BehaviourSupportPlan::withTrashed()->where('creation_key', $key)->lockForUpdate()->first();
            if ($existing) {
                $this->access->assertPlan($existing, $current, $reads, $sites, 'create');

                return $this->accepted($existing, $current, 'create', $key, $fingerprint);
            }
            $this->rejectUsedKey($key);
            if ($status === 'active') {
                $this->settings->assertUsable($policy ?? [], $payload['expected_policy_revision'] ?? -1);
            }
            $this->developer($payload, $site, $reads);
            $row = new BehaviourSupportPlan([...array_intersect_key($payload, array_flip(self::CONTENT)), 'client_id' => $client->id, 'source_site_id' => $site, 'source_stay_id' => $stayId, 'source_schema_version' => 1, 'status' => $status === 'active' ? 'draft' : $status, 'lock_version' => 1, 'creation_key' => $key, 'creation_fingerprint' => $fingerprint, 'version_sequence' => 0, 'created_by' => $current->id, 'updated_by' => $current->id, 'status_changed_by' => $current->id, 'status_changed_at' => now()->utc()]);
            try {
                $row->reference_number = app(ReferenceNumberGenerator::class)->next(BehaviourSupportPlan::REFERENCE_PREFIX);
            } catch (\Throwable $failure) {
                report($failure);
            }
            $row->saveQuietly();
            $this->persisted($row);
            $candidate = $this->candidate($row, $current, $key);
            $row->saveQuietly();
            $audit = $this->audit($row, $current, 'create', $key, ['candidate_version_id' => $candidate->id, 'requested_status' => $status]);

            return $this->confirmation($row, $current, 'create', $key, $fingerprint, $audit, ['candidate_version_id' => $candidate->id, 'requested_status' => $status, 'disposition' => $status === 'active' ? 'approval_pending' : 'created']);
        });

        return $this->transaction(fn () => ($input['status'] ?? 'draft') === 'active' ? $this->settings->withLockedPolicy($run) : $run());
    }

    public function update(BehaviourSupportPlan $plan, User $actor, array $input): array
    {
        return $this->execute($plan, $actor, 'update', $input, fn ($data) => $this->content($data), function ($row, $current, $reads, $sites, $payload, $policy, $key) {
            $this->developer($payload, (int) ($row->source_site_id ?? $row->client->site_id), $reads);
            $row->forceFill($payload);
            if ($row->status === 'active') {
                $this->status($row, 'under_review', $current);
            }
            $candidate = $this->candidate($row, $current, $key);

            return ['candidate_version_id' => $candidate->id, 'disposition' => 'draft_replacement', 'published_version_id' => $row->current_published_version_id];
        });
    }

    public function submitReview(BehaviourSupportPlan $plan, User $actor, array $input): array
    {
        return $this->execute($plan, $actor, 'submit_review', $input, fn ($data) => $this->emptyPayload($data), function ($row, $current, $reads, $sites, $payload, $policy, $key) {
            if (! in_array($row->status, ['draft', 'active'], true)) {
                $this->invalid('status', 'Only a draft or active plan can be submitted for review.');
            }
            $candidate = $this->currentCandidate($row, $current, $key);
            $this->access->assertVersion($candidate, $row, $current, $reads, $sites);
            $this->status($row, 'under_review', $current);

            return ['candidate_version_id' => $candidate->id, 'disposition' => 'submitted_for_review'];
        });
    }

    public function archive(BehaviourSupportPlan $plan, User $actor, array $input): array
    {
        return $this->execute($plan, $actor, 'archive', $input, fn ($data) => $this->emptyPayload($data), function ($row, $current) {
            $this->status($row, 'archived', $current);

            return ['disposition' => 'archived'];
        });
    }

    public function activate(BehaviourSupportPlan $plan, User $actor, array $input): array
    {
        return $this->execute($plan, $actor, 'activate', $input, fn ($data) => $this->decisionTarget($data), function ($row, $current, $reads, $sites, $payload, $policy, $key) {
            if (! in_array($row->status, ['draft', 'under_review'], true)) {
                $this->invalid('status', 'Only a draft or under-review plan can be activated.');
            }
            $candidate = $this->target($row, $payload, $current, $reads, $sites);
            $this->settings->assertUsable($policy, $payload['expected_policy_revision']);
            $approval = $this->applicableApproval($candidate, $policy);
            if (! $approval) {
                return ['candidate_version_id' => $candidate->id, 'requested_status' => 'active', 'disposition' => 'approval_pending'];
            }
            $published = $this->publish($row, $candidate, $approval, $current, $key);

            return ['candidate_version_id' => $candidate->id, 'published_version_id' => $published->id, 'approval_id' => $approval->id, 'applied_policy' => $approval->policy_snapshot, 'disposition' => 'activated'];
        }, true);
    }

    public function review(BehaviourSupportPlan $plan, User $actor, array $input): array
    {
        $bound = ($input['resulting_status'] ?? null) === 'active' || isset($input['approval_decision']);

        return $this->execute($plan, $actor, 'review', $input, fn ($data) => $this->reviewValues($data), function ($row, $current, $reads, $sites, $payload, $policy, $key, $audit) {
            if (array_key_exists('next_review_date', $payload) && $payload['next_review_date'] !== $row->review_date?->format('Y-m-d')) {
                if (isset($payload['approval_decision'])) {
                    $this->invalid('next_review_date', 'Save the changed review date as a candidate before approving its exact content.');
                }
                $row->review_date = $payload['next_review_date'];
                $candidate = $this->candidate($row, $current, $key);
            } else {
                $candidate = $this->currentCandidate($row, $current, $key);
            }
            if (isset($payload['candidate_version_id'])) {
                $candidate = $this->target($row, $payload, $current, $reads, $sites);
            }
            $this->access->assertVersion($candidate, $row, $current, $reads, $sites);
            $requested = $payload['resulting_status'] ?? null;
            $approval = null;
            $published = null;
            $disposition = 'review_recorded';
            if (isset($payload['approval_decision'])) {
                $this->settings->assertUsable($policy, $payload['expected_policy_revision']);
                $approval = app(BehaviourSupportPlanApprovalService::class)->record($row, $candidate, $current, $reads, $sites, $payload, $policy, $key, $audit);
            }
            if ($requested === 'active') {
                $this->settings->assertUsable($policy, $payload['expected_policy_revision'] ?? -1);
                $approval = $this->applicableApproval($candidate, $policy);
                if ($approval) {
                    $published = $this->publish($row, $candidate, $approval, $current, $key);
                    $disposition = 'activated';
                } else {
                    $disposition = 'approval_pending';
                    // Legacy administrative active remains truthful, never creates a published approval.
                }
            } elseif ($requested !== null) {
                $this->status($row, $requested, $current);
            }
            $review = BehaviourSupportPlanReview::create(['behaviour_support_plan_id' => $row->id, 'reviewed_by' => $current->id, 'reviewed_at' => $payload['reviewed_at'], 'outcome' => $payload['outcome'], 'next_review_date' => $payload['next_review_date'] ?? null, 'resulting_status' => $row->status, 'requested_resulting_status' => $requested, 'approval_disposition' => $disposition, 'notes' => $payload['notes'] ?? null, 'plan_version_id' => $candidate->id, 'approval_id' => $approval?->id, 'reviewer_snapshot' => $this->actorSnapshot($current), 'source_version' => (int) $row->lock_version, 'command_key' => $key, 'audit_id' => $audit]);
            $this->persisted($review);

            return ['review_id' => $review->id, 'candidate_version_id' => $candidate->id, 'published_version_id' => $published?->id, 'approval_id' => $approval?->id, 'applied_policy' => $approval?->policy_snapshot, 'requested_status' => $requested, 'actual_status' => $row->status, 'disposition' => $disposition];
        }, $bound);
    }

    /** Shared approval command seam: policy mutex precedes canonical plan and actor locks. */
    public function execute(BehaviourSupportPlan $plan, User $actor, string $command, array $input, callable $normalize, callable $operation, bool $policyBound = false): array
    {
        return $this->transaction(function () use ($plan, $actor, $command, $input, $normalize, $operation, $policyBound) {
            $run = function (?array $policy = null) use ($plan, $actor, $command, $input, $normalize, $operation) {
                $row = BehaviourSupportPlan::withTrashed()->whereKey($plan->id)->lockForUpdate()->firstOrFail();

                return $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($row, $command, $input, $normalize, $operation, $policy) {
                    $op = $command === 'approval' ? (($input['origin'] ?? null) === 'authorised_reviewer' ? 'review' : 'approve') : $command;
                    $this->access->assertPlan($row, $current, $reads, $sites, $op);
                    $envelope = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1', 'max:'.(PHP_INT_MAX - 1)], 'idempotency_key' => ['required', 'uuid']])->validate();
                    if (is_bool($input['expected_version'] ?? null)) {
                        $this->invalid('expected_version', 'Use the actual positive parent version.');
                    }
                    $payload = $normalize(array_diff_key($input, array_flip(['expected_version', 'idempotency_key'])));
                    $key = strtolower($envelope['idempotency_key']);
                    $fingerprint = $this->fingerprint(['plan', (int) $row->id, (int) $current->id, $command, (int) $envelope['expected_version'], $payload]);
                    $accepted = RestraintCommandReceipt::where('idempotency_key', $key)->lockForUpdate()->first();
                    if ($accepted) {
                        return $this->matchReceipt($accepted, $row, $current, $command, $fingerprint);
                    }
                    if ((int) $row->lock_version !== (int) $envelope['expected_version']) {
                        $this->invalid('expected_version', 'The plan changed. Your draft was not saved; review the current version.');
                    }
                    if ($row->trashed() || $row->status === 'archived') {
                        $this->invalid('status', 'An archived plan retains its history and cannot accept a new command.');
                    }
                    if ((int) $row->lock_version >= PHP_INT_MAX - 1) {
                        $this->invalid('expected_version', 'The plan version cannot be incremented safely.');
                    }
                    $result = $operation($row, $current, $reads, $sites, $payload, $policy, $key, null);
                    $row->lock_version = (int) $row->lock_version + 1;
                    $row->updated_by = $current->id;
                    $row->saveQuietly();
                    $this->persisted($row);
                    $audit = $this->audit($row, $current, $command, $key, ['expected_version' => (int) $envelope['expected_version'], 'policy_revision' => $policy['policy_revision'] ?? null, 'result' => $result]);
                    $this->sealCommandAudit($row, $key, $audit);

                    return $this->confirmation($row, $current, $command, $key, $fingerprint, $audit, $result, (int) $envelope['expected_version']);
                });
            };

            return $policyBound ? $this->settings->withLockedPolicy($run) : $run();
        });
    }

    public function target(BehaviourSupportPlan $plan, array $payload, User $current, CurrentAuthorizationReads $reads, array $sites): BehaviourSupportPlanVersion
    {
        $candidate = BehaviourSupportPlanVersion::where('behaviour_support_plan_id', $plan->id)->where('kind', 'candidate')->whereKey($payload['candidate_version_id'] ?? null)->lockForUpdate()->first();
        abort_unless($candidate, 403);
        $this->access->assertVersion($candidate, $plan, $current, $reads, $sites);
        if ((($payload['approval_decision'] ?? null) !== 'revoke' && (int) $plan->current_candidate_version_id !== (int) $candidate->id) || ! hash_equals($candidate->content_digest, (string) ($payload['content_digest'] ?? '')) || ! hash_equals($candidate->content_digest, $this->fingerprint($candidate->content))) {
            $this->invalid('candidate_version_id', 'The candidate content changed. Review its exact version before deciding.');
        }

        return $candidate;
    }

    public function candidate(BehaviourSupportPlan $row, User $actor, string $key): BehaviourSupportPlanVersion
    {
        $content = [];
        foreach (self::CONTENT as $field) {
            $content[$field] = in_array($field, ['developed_at', 'review_date'], true) ? $row->{$field}?->format('Y-m-d') : $row->{$field};
        }
        $row->version_sequence = (int) $row->version_sequence + 1;
        $version = BehaviourSupportPlanVersion::create(['behaviour_support_plan_id' => $row->id, 'sequence' => $row->version_sequence, 'kind' => 'candidate', 'candidate_version_id' => null, 'client_id' => $row->client_id, 'site_id' => $row->source_site_id ?? $row->client->site_id, 'stay_id' => $row->source_stay_id, 'context_origin' => $row->source_schema_version === null ? 'legacy_current_context_candidate' : 'recorded_source', 'content' => $content, 'content_digest' => $this->fingerprint($content), 'recorded_by' => $actor->id, 'actor_snapshot' => $this->actorSnapshot($actor), 'recorded_at' => now()->utc(), 'review_date' => $content['review_date'], 'command_key' => $key]);
        $this->persisted($version);
        $row->current_candidate_version_id = $version->id;

        return $version;
    }

    public function currentCandidate(BehaviourSupportPlan $row, User $actor, string $key): BehaviourSupportPlanVersion
    {
        if (! $row->current_candidate_version_id) {
            return $this->candidate($row, $actor, $key);
        }
        $version = BehaviourSupportPlanVersion::where('behaviour_support_plan_id', $row->id)->where('kind', 'candidate')->whereKey($row->current_candidate_version_id)->lockForUpdate()->first();
        if (! $version || ! hash_equals($version->content_digest, $this->fingerprint($version->content))) {
            throw new LogicException('The exact current candidate content must be retained.');
        }

        return $version;
    }

    public function applicableApproval(BehaviourSupportPlanVersion $candidate, array $policy): ?BehaviourSupportPlanApproval
    {
        $approval = BehaviourSupportPlanApproval::where('plan_version_id', $candidate->id)->orderByDesc('id')->lockForUpdate()->first();
        if (! $approval || $approval->decision !== 'approve' || (int) $approval->behaviour_support_plan_id !== (int) $candidate->behaviour_support_plan_id || $approval->client_id !== $candidate->client_id || $approval->site_id !== $candidate->site_id || $approval->stay_id !== $candidate->stay_id || ! hash_equals($candidate->content_digest, $approval->content_digest)
            || ($approval->policy_snapshot['policy_revision'] ?? null) !== $policy['policy_revision']
            || ($approval->policy_snapshot['policy_digest'] ?? null) !== $policy['policy_digest']) {
            return null;
        }

        return $approval;
    }

    public function publish(BehaviourSupportPlan $row, BehaviourSupportPlanVersion $candidate, BehaviourSupportPlanApproval $approval, User $actor, string $key): BehaviourSupportPlanVersion
    {
        $row->version_sequence = (int) $row->version_sequence + 1;
        $version = BehaviourSupportPlanVersion::create(['behaviour_support_plan_id' => $row->id, 'sequence' => $row->version_sequence, 'kind' => 'published', 'candidate_version_id' => $candidate->id, 'client_id' => $candidate->client_id, 'site_id' => $candidate->site_id, 'stay_id' => $candidate->stay_id, 'context_origin' => $candidate->context_origin, 'content' => $candidate->content, 'content_digest' => $candidate->content_digest, 'recorded_by' => $actor->id, 'actor_snapshot' => $this->actorSnapshot($actor), 'recorded_at' => now()->utc(), 'published_by' => $actor->id, 'published_at' => now()->utc(), 'effective_from' => $approval->effective_from, 'effective_to' => $approval->effective_to, 'review_date' => $candidate->review_date?->format('Y-m-d'), 'command_key' => $key, 'approval_id' => $approval->id, 'policy_snapshot' => $approval->policy_snapshot]);
        $this->persisted($version);
        $row->current_published_version_id = $version->id;
        $this->status($row, 'active', $actor);

        return $version;
    }

    /** Event's factual assertion survives missing approval; no current mutable snapshot substitutes. */
    public function resolveForEvent(Client $client, int $siteId, ?int $stayId, Carbon $startedAt, bool $assertedWithin, ?int $planId, ?int $versionId, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $reads->assertActive();
        $operation = $current->canDo('restraints.create') || $current->canDo('restraints.manage') ? 'capture' : ($current->canDo('restraints.review') ? 'review' : 'respite_capture');
        $this->access->assertClient($client, $current, $reads, $sites, $operation);
        if ($stayId !== null) {
            $this->access->assertStay($stayId, (int) $client->id, $siteId, $current, $reads, $sites);
        }
        abort_unless(in_array($siteId, $sites, true), 403);
        $plans = BehaviourSupportPlan::withTrashed()->where('client_id', $client->id)->when($planId !== null, fn ($q) => $q->whereKey($planId), fn ($q) => $q->where(fn ($scope) => $scope->whereNull('source_schema_version')->orWhere(fn ($typed) => $typed->where('source_schema_version', 1)->where('source_site_id', $siteId)->where(fn ($stay) => $stay->whereNull('source_stay_id')->when($stayId !== null, fn ($s) => $s->orWhere('source_stay_id', $stayId))))))->orderBy('id')->lockForUpdate()->get();
        if ($planId !== null) {
            abort_unless($plans->count() === 1, 403);
            $selectedPlan = $plans->sole();
            abort_unless($selectedPlan->source_schema_version === null || ($selectedPlan->source_schema_version === 1 && (int) $selectedPlan->source_site_id === $siteId && ($selectedPlan->source_stay_id === null || (int) $selectedPlan->source_stay_id === $stayId)), 403);
        }
        if ($versionId !== null && $planId === null) {
            abort(403);
        }
        $versions = BehaviourSupportPlanVersion::whereIn('behaviour_support_plan_id', $plans->pluck('id'))->where('kind', 'published')->when($versionId !== null, fn ($q) => $q->whereKey($versionId))->orderBy('id')->lockForUpdate()->get();
        if ($versionId !== null) {
            abort_unless($versions->count() === 1, 403);
        }
        $eligible = [];
        $states = [];
        foreach ($versions as $version) {
            abort_unless($versionId === null || ((int) $version->client_id === (int) $client->id && (int) $version->site_id === $siteId && ($version->stay_id === null || $version->stay_id === $stayId)), 403);
            if ((int) $version->site_id !== $siteId || ($version->stay_id !== null && $version->stay_id !== $stayId)) {
                continue;
            }
            $plan = $plans->firstWhere('id', $version->behaviour_support_plan_id);
            $this->access->assertVersion($version, $plan, $current, $reads, $sites);
            $candidate = BehaviourSupportPlanVersion::where('behaviour_support_plan_id', $plan->id)->where('kind', 'candidate')->whereKey($version->candidate_version_id)->lockForUpdate()->first();
            if ($candidate) {
                $this->access->assertVersion($candidate, $plan, $current, $reads, $sites);
            }
            $approval = BehaviourSupportPlanApproval::whereKey($version->approval_id)->where('behaviour_support_plan_id', $plan->id)->where('plan_version_id', $version->candidate_version_id)->where('client_id', $version->client_id)->where('site_id', $version->site_id)->where('stay_id', $version->stay_id)->lockForUpdate()->first();
            $withdrawn = $approval && BehaviourSupportPlanApproval::where('plan_version_id', $version->candidate_version_id)->where('supersedes_approval_id', $approval->id)->where('decision', 'revoke')->lockForUpdate()->exists();
            $state = 'verified';
            if ($plan->trashed() || $plan->status === 'archived') {
                $state = 'archived';
            } elseif (! $candidate || ! $approval || $approval->decision !== 'approve' || ! hash_equals($version->content_digest, $approval->content_digest) || ! hash_equals($candidate->content_digest, $version->content_digest) || ! hash_equals($candidate->content_digest, $this->fingerprint($candidate->content)) || ! hash_equals($version->content_digest, $this->fingerprint($version->content))) {
                $state = 'approval_unknown';
            } elseif ($withdrawn) {
                $state = 'withdrawn';
            } elseif ($version->effective_from === null) {
                $state = 'currency_unknown';
            } elseif ($approval->approved_at->gt($startedAt) || $version->effective_from->gt($startedAt)) {
                $state = 'future';
            } elseif ($version->effective_to?->lte($startedAt)) {
                $state = 'expired';
            } elseif (BehaviourSupportPlanVersion::where('behaviour_support_plan_id', $plan->id)->where('kind', 'published')->where('sequence', '>', $version->sequence)->where('effective_from', '<=', $startedAt)->lockForUpdate()->exists()) {
                $state = 'superseded';
            }
            $states[] = $state;
            if ($state === 'verified') {
                $eligible[] = [$version, $approval];
            }
        }
        $state = ! $assertedWithin ? 'asserted_out_of_plan' : (count($eligible) === 1 ? 'verified' : (count($eligible) > 1 ? 'ambiguous' : ($states[0] ?? ($plans->isNotEmpty() ? 'approval_unknown' : 'missing'))));
        $selected = count($eligible) === 1 ? $eligible[0] : null;

        return ['plan_id' => $selected ? (int) $selected[0]->behaviour_support_plan_id : $planId, 'plan_version_id' => $selected ? (int) $selected[0]->id : $versionId, 'approval_id' => $selected ? (int) $selected[1]->id : null, 'authorization_state' => $state, 'explanation' => match ($state) {
            'verified' => 'A retained approved version was effective at the recorded start; this does not establish intervention, consent or medication authority.', 'ambiguous' => 'More than one approved version applies. Select the actual version.', 'asserted_out_of_plan' => 'The event was recorded as outside the plan.', default => 'Plan approval could not be verified: '.$state.'. The factual event remains recordable.'
        }, 'policy_snapshot' => $selected ? $selected[1]->policy_snapshot : null];
    }

    public function decisionTarget(array $data): array
    {
        $this->only($data, ['candidate_version_id', 'content_digest', 'expected_policy_revision']);

        return $this->targetValues($data);
    }

    public function targetValues(array $data): array
    {
        $valid = Validator::make($data, ['candidate_version_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'content_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'expected_policy_revision' => ['required', 'integer', 'min:0', 'max:'.(PHP_INT_MAX - 1)]])->validate();
        if (is_bool($data['candidate_version_id']) || is_bool($data['expected_policy_revision'])) {
            $this->invalid('candidate_version_id', 'Use the exact candidate and policy revision.');
        }

        return ['candidate_version_id' => (int) $valid['candidate_version_id'], 'content_digest' => $valid['content_digest'], 'expected_policy_revision' => (int) $valid['expected_policy_revision']];
    }

    private function reviewValues(array $data): array
    {
        $approvalFields = BehaviourSupportPlanApprovalService::FIELDS;
        $this->only($data, ['outcome', 'reviewed_at', 'next_review_date', 'resulting_status', 'notes', ...$approvalFields]);
        $valid = Validator::make($data, ['outcome' => ['required', Rule::in(self::OUTCOMES)], 'reviewed_at' => ['required', 'string'], 'next_review_date' => ['nullable', 'string'], 'resulting_status' => ['nullable', Rule::in(self::STATUSES)], 'notes' => ['nullable', 'string', 'max:20000']])->validate();
        $valid['reviewed_at'] = RestraintDates::instant($valid['reviewed_at'], 'reviewed_at')->format('Y-m-d\TH:i:s.uP');
        if (array_key_exists('next_review_date', $valid)) {
            $valid['next_review_date'] = RestraintDates::day($valid['next_review_date'], 'next_review_date');
        }
        if (isset($data['approval_decision'])) {
            return [...$valid, ...app(BehaviourSupportPlanApprovalService::class)->normalize(array_intersect_key($data, array_flip($approvalFields)))];
        }
        if (($data['resulting_status'] ?? null) === 'active' || isset($data['candidate_version_id'])) {
            $valid = [...$valid, ...$this->targetValues($data)];
        }

        return $valid;
    }

    private function content(array $data, bool $create = false): array
    {
        if (! $create) {
            $this->only($data, self::CONTENT);
        }
        $rules = [];
        foreach (self::CONTENT as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:20000'];
        }
        $rules['title'] = [$create ? 'required' : 'sometimes', 'string', 'min:1', 'max:255'];
        $rules['developed_by'] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX];
        $rules['restrictive_practice_type'] = ['sometimes', 'nullable', Rule::in(['physical', 'chemical', 'mechanical', 'seclusion', 'environmental'])];
        $valid = Validator::make($data, $rules)->validate();
        if ((isset($valid['title']) && trim($valid['title']) === '') || (isset($data['developed_by']) && is_bool($data['developed_by']))) {
            $this->invalid('title', 'Use a meaningful title and exact staff identifier.');
        }
        foreach (['developed_at', 'review_date'] as $field) {
            if (array_key_exists($field, $valid)) {
                $valid[$field] = RestraintDates::day($valid[$field], $field);
            }
        }
        if (isset($valid['developed_by'])) {
            $valid['developed_by'] = (int) $valid['developed_by'];
        }

        return $valid;
    }

    private function emptyPayload(array $data): array
    {
        $this->only($data, []);

        return [];
    }

    private function developer(array $payload, int $site, CurrentAuthorizationReads $reads): void
    {
        if (isset($payload['developed_by'])) {
            $this->access->eligibleStaff((int) $payload['developed_by'], $site, $reads);
        }
    }

    private function status(BehaviourSupportPlan $row, string $status, User $actor): void
    {
        $row->status = $status;
        $row->status_changed_by = $actor->id;
        $row->status_changed_at = now()->utc();
    }

    public function only(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) {
            $this->invalid('command', 'Only the typed command fields may be submitted.');
        }
    }

    public function actorSnapshot(User $actor): array
    {
        return ['id' => (int) $actor->id, 'name' => $actor->name, 'roles' => $actor->roles->map(fn ($role) => ['id' => (int) $role->id, 'name' => $role->name])->all(), 'permissions' => collect(RestraintAccessService::PERMISSIONS)->mapWithKeys(fn ($key) => [$key => $actor->canDo($key)])->all(), 'qualification_verified' => false];
    }

    private function fingerprint(array $value): string
    {
        return RestraintCommandValues::digest($value);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function persisted($row): void
    {
        if (! $row->exists || ! $row->getKey() || ! $row->newQuery()->whereKey($row->getKey())->lockForUpdate()->exists()) {
            throw new LogicException('Plan command evidence must be persisted before acceptance.');
        }
    }

    /** Finalize provenance once before commit; no accepted immutable fact is rewritten. */
    private function sealCommandAudit(BehaviourSupportPlan $plan, string $key, int $audit): void
    {
        foreach (['behaviour_support_plan_approvals', 'behaviour_support_plan_reviews'] as $table) {
            $rows = DB::table($table)->where('behaviour_support_plan_id', $plan->id)->where('command_key', $key)->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($row->audit_id !== null || DB::table($table)->where('id', $row->id)->where('behaviour_support_plan_id', $plan->id)->where('command_key', $key)->whereNull('audit_id')->update(['audit_id' => $audit]) !== 1) {
                    throw new LogicException('New command evidence needs its exact semantic audit before commit.');
                }
            }
        }
    }

    private function audit(BehaviourSupportPlan $row, User $actor, string $command, string $key, array $meta): int
    {
        $request = clone request();
        $request->setUserResolver(fn () => $actor);
        $action = 'behaviour_support_plan.'.$command;
        AuditLogger::logOrFail($action, $row, [...$meta, 'actor_id' => (int) $actor->id, 'idempotency_key' => $key], $request);
        $audits = AuditLog::where('user_id', $actor->id)->where('auditable_type', $row->getMorphClass())->where('auditable_id', $row->id)->where('action', $action)->where('meta->idempotency_key', $key)->lockForUpdate()->get();
        if ($audits->count() !== 1) {
            throw new LogicException('A plan command needs exactly one attributable semantic audit.');
        }

        return (int) $audits->sole()->id;
    }

    private function confirmation(BehaviourSupportPlan $row, User $actor, string $command, string $key, string $fingerprint, int $audit, array $result, ?int $expected = null): array
    {
        $receipt = RestraintCommandReceipt::create(['source_type' => 'plan', 'source_id' => $row->id, 'user_id' => $actor->id, 'command' => $command, 'expected_version' => $expected, 'actor_snapshot' => $this->actorSnapshot($actor), 'idempotency_key' => $key, 'fingerprint' => $fingerprint, 'audit_log_id' => $audit, 'accepted_result' => ['accepted' => true, 'source_type' => 'plan', 'plan_id' => (int) $row->id, 'command' => $command, 'idempotency_key' => $key, 'expected_version' => $expected, 'version' => (int) $row->lock_version, 'status' => $row->status, ...$result], 'created_at' => now()->utc()]);
        $receipt = $receipt->persisted();

        return [...$receipt->accepted_result, 'receipt_id' => (int) $receipt->id, 'replayed' => false];
    }

    private function matchReceipt(RestraintCommandReceipt $receipt, BehaviourSupportPlan $row, User $actor, string $command, string $fingerprint): array
    {
        if ($receipt->source_type !== 'plan' || (int) $receipt->source_id !== (int) $row->id || (int) $receipt->user_id !== (int) $actor->id || $receipt->command !== $command || ! hash_equals($receipt->fingerprint, $fingerprint)) {
            $this->invalid('idempotency_key', 'This command key belongs to different saved work.');
        }

        return [...$receipt->accepted_result, 'receipt_id' => (int) $receipt->id, 'replayed' => true];
    }

    private function accepted(BehaviourSupportPlan $row, User $actor, string $command, string $key, string $fingerprint): array
    {
        $receipt = RestraintCommandReceipt::where('idempotency_key', $key)->lockForUpdate()->first();
        if (! $receipt) {
            throw new LogicException('The original accepted plan command receipt is missing.');
        }

        return $this->matchReceipt($receipt, $row, $actor, $command, $fingerprint);
    }

    private function rejectUsedKey(string $key): void
    {
        if (RestraintCommandReceipt::where('idempotency_key', $key)->lockForUpdate()->exists()) {
            $this->invalid('idempotency_key', 'This command key belongs to different saved work.');
        }
    }

    private function transaction(callable $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1062, 1205, 1213, 3572], true)) {
                $this->invalid('expected_version', 'The plan or its authority changed concurrently. Your draft was not saved.');
            }
            throw $error;
        }
    }
}
