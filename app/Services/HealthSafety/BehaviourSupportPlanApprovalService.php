<?php

namespace App\Services\HealthSafety;

use App\Models\BehaviourSupportPlan;
use App\Models\BehaviourSupportPlanApproval;
use App\Models\BehaviourSupportPlanReview;
use App\Models\BehaviourSupportPlanVersion;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\RestraintEvent;
use App\Models\RestraintEventAttachment;
use App\Models\StaffCredential;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Attributed decisions against an already materialized immutable candidate. */
final class BehaviourSupportPlanApprovalService
{
    public const FIELDS = ['candidate_version_id', 'content_digest', 'expected_policy_revision', 'approval_decision', 'origin', 'approver_id', 'approver_role', 'approved_at', 'effective_from', 'effective_to', 'basis', 'evidence_references', 'credential_id', 'external_name', 'external_role', 'external_organisation', 'external_reference', 'external_decision_date', 'supersedes_approval_id', 'activate_requested'];

    public function __construct(private readonly RestraintAccessService $access, private readonly BehaviourSupportApprovalPolicySettings $settings) {}

    public function decide(BehaviourSupportPlan $plan, User $actor, array $input): array
    {
        $plans = app(BehaviourSupportPlanService::class);

        return $plans->execute($plan, $actor, 'approval', $input, fn ($data) => $this->normalize($data), function ($row, $current, $reads, $sites, $payload, $policy, $key, $audit) use ($plans) {
            $candidate = $plans->target($row, $payload, $current, $reads, $sites);
            $this->settings->assertUsable($policy, $payload['expected_policy_revision']);
            $approval = $this->record($row, $candidate, $current, $reads, $sites, $payload, $policy, $key, $audit);
            $published = null;
            if ($payload['activate_requested'] && $approval->decision === 'approve') {
                $pending = BehaviourSupportPlanReview::where('behaviour_support_plan_id', $row->id)->where('plan_version_id', $candidate->id)->orderByDesc('id')->lockForUpdate()->first();
                if (! $current->canDo('restraints.manage') && ! ($pending && $pending->requested_resulting_status === 'active' && $pending->approval_disposition === 'approval_pending')) {
                    throw ValidationException::withMessages(['activate_requested' => 'Record an authorised activation request for this exact candidate before completing it.']);
                }
                $published = $plans->publish($row, $candidate, $approval, $current, $key);
            }

            return ['candidate_version_id' => $candidate->id, 'approval_id' => $approval->id, 'published_version_id' => $published?->id, 'applied_policy' => $approval->policy_snapshot, 'disposition' => $published ? 'activated' : ($approval->decision === 'approve' ? 'approved_not_published' : $approval->decision)];
        }, true);
    }

    public function normalize(array $input): array
    {
        $plans = app(BehaviourSupportPlanService::class);
        $plans->only($input, self::FIELDS);
        $target = $plans->targetValues($input);
        foreach (['approver_id', 'credential_id', 'supersedes_approval_id'] as $field) {
            if (isset($input[$field]) && is_bool($input[$field])) {
                throw ValidationException::withMessages([$field => 'Use the exact integer identifier.']);
            }
        }
        if (isset($input['evidence_references']) && (! is_array($input['evidence_references']) || ! array_is_list($input['evidence_references']))) {
            throw ValidationException::withMessages(['evidence_references' => 'Use an ordered list of actual evidence references.']);
        }
        foreach ($input['evidence_references'] ?? [] as $reference) {
            if (! is_array($reference) || (isset($reference['id']) && is_bool($reference['id']))) {
                throw ValidationException::withMessages(['evidence_references' => 'Use exact typed evidence references.']);
            }
        }
        $valid = Validator::make($input, [
            'approval_decision' => ['required', Rule::in(['approve', 'decline', 'revoke'])],
            'origin' => ['required', Rule::in(['internal_specialist', 'external_specialist', 'authorised_reviewer'])],
            'approver_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'approver_role' => ['required', 'string', 'max:255'],
            'approved_at' => ['required', 'string'], 'effective_from' => ['required', 'string'], 'effective_to' => ['nullable', 'string'],
            'basis' => ['required', 'string', 'min:1', 'max:10000'], 'evidence_references' => ['sometimes', 'array', 'max:20'],
            'evidence_references.*.type' => ['required', Rule::in(['client_document', 'event_attachment'])], 'evidence_references.*.id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'credential_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'external_name' => ['nullable', 'string', 'max:255'], 'external_role' => ['nullable', 'string', 'max:255'],
            'external_organisation' => ['nullable', 'string', 'max:255'], 'external_reference' => ['nullable', 'string', 'max:500'], 'external_decision_date' => ['nullable', 'string'],
            'supersedes_approval_id' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX], 'activate_requested' => ['sometimes', 'boolean'],
        ])->validate();
        if (trim($valid['basis']) === '' || trim($valid['approver_role']) === '') {
            throw ValidationException::withMessages(['basis' => 'Retain the actual decision basis and factual approver role.']);
        }
        $actual = RestraintDates::instant($valid['approved_at'], 'approved_at');
        $from = RestraintDates::instant($valid['effective_from'], 'effective_from', false);
        $to = isset($valid['effective_to']) ? RestraintDates::instant($valid['effective_to'], 'effective_to', false) : null;
        if ($from->lt($actual) || ($to && $to->lte($from))) {
            throw ValidationException::withMessages(['effective_from' => 'The effective interval must start no earlier than the actual decision and end after its start.']);
        }
        $valid['approved_at'] = $actual->format('Y-m-d\TH:i:s.uP');
        $valid['effective_from'] = $from->format('Y-m-d\TH:i:s.uP');
        $valid['effective_to'] = $to?->format('Y-m-d\TH:i:s.uP');
        $valid['activate_requested'] = (bool) ($valid['activate_requested'] ?? false);
        $valid['evidence_references'] = array_values($valid['evidence_references'] ?? []);
        foreach ($valid['evidence_references'] as &$reference) {
            $plans->only($reference, ['type', 'id']);
            $reference['id'] = (int) $reference['id'];
        }
        unset($reference);
        foreach (['approver_id', 'credential_id', 'supersedes_approval_id'] as $field) {
            if (isset($valid[$field])) {
                $valid[$field] = (int) $valid[$field];
            }
        }
        if (isset($valid['external_decision_date'])) {
            $valid['external_decision_date'] = RestraintDates::day($valid['external_decision_date'], 'external_decision_date');
        }

        return [...$target, ...$valid];
    }

    /** Called inside policy->plan->fresh authority transaction; no handle leaves the command. */
    public function record(BehaviourSupportPlan $plan, BehaviourSupportPlanVersion $candidate, User $current, CurrentAuthorizationReads $reads, array $sites, array $payload, array $policy, string $key, ?int $audit): BehaviourSupportPlanApproval
    {
        $reads->assertActive();
        $this->settings->assertUsable($policy, $payload['expected_policy_revision']);
        $plans = app(BehaviourSupportPlanService::class);
        if ($policy['mode'] === 'authorised_reviewer') {
            $this->access->assertPlan($plan, $current, $reads, $sites, 'review');
            abort_unless($payload['origin'] === 'authorised_reviewer' && (! isset($payload['approver_id']) || $payload['approver_id'] === (int) $current->id), 403);
            $approver = $current;
            $snapshot = [...$plans->actorSnapshot($current), 'factual_role' => $payload['approver_role']];
        } else {
            $this->access->assertPlan($plan, $current, $reads, $sites, 'approve');
            abort_unless(in_array($payload['origin'], ['internal_specialist', 'external_specialist'], true), 403);
            if ($payload['origin'] === 'external_specialist') {
                foreach (['external_name', 'external_role', 'external_organisation', 'external_reference', 'external_decision_date'] as $field) {
                    if (! isset($payload[$field]) || trim($payload[$field]) === '') {
                        throw ValidationException::withMessages([$field => 'Retain the actual external specialist decision identity and reference.']);
                    }
                }
                if ($payload['external_decision_date'] > now()->timezone('Pacific/Auckland')->format('Y-m-d') || $payload['evidence_references'] === []) {
                    throw ValidationException::withMessages(['evidence_references' => 'A documented external decision requires its real private supporting evidence.']);
                }
                abort_unless(! isset($payload['approver_id']) && ! isset($payload['credential_id']), 403);
                $approver = null;
                $snapshot = ['name' => $payload['external_name'], 'factual_role' => $payload['external_role'], 'organisation' => $payload['external_organisation'], 'decision_reference' => $payload['external_reference'], 'decision_date' => $payload['external_decision_date'], 'professional_registration_verified' => false];
            } else {
                $approver = $this->access->eligibleStaff($payload['approver_id'] ?? (int) $current->id, (int) $candidate->site_id, $reads, ['restraints.approve']);
                $approverSites = app(UserSiteAccessService::class)->accessibleSiteIds($approver, UserSiteAccessService::HEALTH_SAFETY_SITE_BYPASS_PERMISSIONS, $reads);
                $this->access->assertPlan($plan, $approver, $reads, $approverSites, 'approve');
                $snapshot = [...$plans->actorSnapshot($approver), 'factual_role' => $payload['approver_role']];
                if (isset($payload['credential_id'])) {
                    $credentialViewer = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($current, [...RestraintAccessService::PERMISSIONS, 'staff.credentials.viewAny', 'staff.viewAny']);
                    abort_unless((int) $credentialViewer->id === (int) $approver->id || $credentialViewer->canDo('staff.credentials.viewAny') || $credentialViewer->canDo('staff.viewAny'), 403);
                    $credential = $reads->query(StaffCredential::query())->where('user_id', $approver->id)->find($payload['credential_id']);
                    $today = now()->timezone('Pacific/Auckland')->format('Y-m-d');
                    abort_unless($credential && (! $credential->issued_at || $credential->issued_at->format('Y-m-d') <= $today) && (! $credential->expires_at || $credential->expires_at->format('Y-m-d') >= $today), 403);
                    $snapshot['recorded_credential'] = ['id' => (int) $credential->id, 'type' => $credential->type, 'issuer' => $credential->issuer, 'reference' => $credential->reference, 'issued_at' => $credential->issued_at?->format('Y-m-d'), 'expires_at' => $credential->expires_at?->format('Y-m-d'), 'professional_eligibility_verified' => false];
                }
            }
        }
        $supersedes = null;
        if ($payload['approval_decision'] === 'revoke' || isset($payload['supersedes_approval_id'])) {
            $supersedes = BehaviourSupportPlanApproval::where('behaviour_support_plan_id', $plan->id)->where('plan_version_id', $candidate->id)->whereKey($payload['supersedes_approval_id'] ?? null)->lockForUpdate()->first();
            abort_unless($supersedes && $supersedes->decision === 'approve', 403);
        }
        $evidence = $this->evidence($payload['evidence_references'], $candidate, $current, $reads, $sites);
        if (! is_int($policy['setting_change_id'] ?? null)) {
            throw new LogicException('The applied policy identity must be retained before a decision.');
        }
        $row = BehaviourSupportPlanApproval::create(['behaviour_support_plan_id' => $plan->id, 'plan_version_id' => $candidate->id, 'content_digest' => $candidate->content_digest, 'client_id' => $candidate->client_id, 'site_id' => $candidate->site_id, 'stay_id' => $candidate->stay_id, 'decision' => $payload['approval_decision'], 'origin' => $payload['origin'], 'approver_id' => $approver?->id, 'recorded_by' => $current->id, 'approver_snapshot' => $snapshot, 'recorder_snapshot' => $plans->actorSnapshot($current), 'approved_at' => $payload['approved_at'], 'recorded_at' => now()->utc(), 'effective_from' => $payload['effective_from'], 'effective_to' => $payload['effective_to'], 'basis' => $payload['basis'], 'evidence_references' => $evidence, 'policy_snapshot' => $policy, 'setting_change_id' => $policy['setting_change_id'], 'supersedes_approval_id' => $supersedes?->id, 'command_key' => $key, 'audit_id' => $audit]);
        if (! $row->exists || ! $row->newQuery()->whereKey($row->id)->lockForUpdate()->exists()) {
            throw new LogicException('An approval decision must be persisted before acceptance.');
        }

        return $row;
    }

    private function evidence(array $references, BehaviourSupportPlanVersion $candidate, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $result = [];
        foreach ($references as $reference) {
            if ($reference['type'] === 'client_document') {
                $document = $reads->query(ClientDocument::query())->where('client_id', $candidate->client_id)->find($reference['id']);
                abort_unless($document, 403);
                $documentViewer = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($current, [...RestraintAccessService::PERMISSIONS, 'clients.update']);
                $client = $reads->query(Client::query())->find($candidate->client_id);
                abort_unless($client && app(ClientPolicy::class)->updateFromCurrentEvidence($documentViewer, $client, $reads), 403);
                $disk = $document->storage_disk;
                $path = $document->storage_path;
                $label = $document->title;
                $owner = (int) $document->client_id;
            } else {
                $attachment = $reads->query(RestraintEventAttachment::query())->find($reference['id']);
                abort_unless($attachment && $attachment->removed_at === null, 403);
                $event = $reads->query(RestraintEvent::withTrashed())->find($attachment->restraint_event_id);
                abort_unless($event && (int) $event->client_id === (int) $candidate->client_id && (int) $event->site_id === (int) $candidate->site_id && ($candidate->stay_id === null || (int) $event->stay_id === $candidate->stay_id), 403);
                $this->access->assertEvent($event, $current, $reads, $sites);
                $disk = $attachment->disk;
                $path = $attachment->path;
                $label = $attachment->original_name;
                $owner = (int) $event->id;
            }
            if ($disk !== 'private' || ! is_string($path) || $path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/(^|\\/)\\.\\.?(\\/|$)|[\\x00-\\x1f]|^[a-z]+:/i', $path) || ! Storage::disk('private')->exists($path)) {
                throw ValidationException::withMessages(['evidence_references' => 'The selected private supporting evidence is unavailable. Retain the draft and choose available evidence.']);
            }
            $stream = Storage::disk('private')->readStream($path);
            if (! is_resource($stream)) {
                throw new LogicException('Private supporting evidence could not be read.');
            }
            try {
                $hash = hash_init('sha256');
                hash_update_stream($hash, $stream);
                $digest = hash_final($hash);
            } finally {
                fclose($stream);
            }
            $result[] = [...$reference, 'source_id' => $owner, 'label' => $label, 'sha256' => $digest, 'available_at_recording' => true, 'preservation_claim' => false];
        }

        return $result;
    }
}
