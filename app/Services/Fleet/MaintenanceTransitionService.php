<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\FleetChecklistRun;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Fleet\Data\VehicleReadinessContext;
use App\Services\UserSiteAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaintenanceTransitionService
{
    /** Answer values that record an issue, or that the item couldn't be assessed. */
    private const ISSUE_ANSWERS = ['fail', 'unable', 'issue', 'poor'];

    public function __construct(
        private readonly MaintenanceAccessService $access,
        private readonly MaintenancePolicyService $policy,
        private readonly UserSiteAccessService $siteAccess,
        private readonly VehicleReadinessService $readiness,
        private readonly MaintenanceRestrictionService $restrictions,
    ) {}

    /** @param array<string, mixed> $payload */
    public function execute(User $actor, int $workOrderId, string $operation, int $expectedVersion, string $key, array $payload = []): FleetWorkOrder
    {
        $preview = FleetWorkOrder::query()->select('id', 'asset_id')->findOrFail($workOrderId);
        $fingerprint = MaintenanceFingerprint::of([
            'actor_id' => (int) $actor->id,
            'operation' => $operation,
            'payload' => $payload,
        ]);

        return DB::transaction(function () use ($actor, $preview, $workOrderId, $operation, $expectedVersion, $key, $payload, $fingerprint): FleetWorkOrder {
            $currentActor = User::query()->findOrFail($actor->id);
            $asset = $this->access->asset($currentActor, (int) $preview->asset_id, true);
            $order = FleetWorkOrder::query()->whereKey($workOrderId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $order->asset_id === (int) $asset->id, 409);

            if (! in_array($operation, ['accept_handover', 'acknowledge_custody', 'release'], true)) {
                abort_unless($this->access->canManage($currentActor), 403);
            }
            if (in_array($operation, ['accept_handover', 'acknowledge_custody'], true)) {
                $this->siteAccess->assertCanUseCurrentStaffAtSite(
                    $currentActor, (int) $currentActor->id, (int) $asset->site_id, ['sites.viewAll'],
                );
            }
            if ($operation === 'release') {
                abort_unless($this->access->canReview($currentActor, $asset), 403);
                $this->siteAccess->assertCanUseCurrentStaffAtSite(
                    $currentActor, (int) $currentActor->id, (int) $asset->site_id, ['sites.viewAll'],
                );
            }

            $prior = DB::table('fleet_maintenance_actions')
                ->where('work_order_id', $order->id)
                ->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($prior) {
                abort_unless((int) $prior->actor_user_id === (int) $currentActor->id
                    && $prior->action_type === $operation
                    && hash_equals((string) $prior->payload_sha256, $fingerprint), 409);

                if ($operation === 'acknowledge_custody') {
                    $latestCustody = DB::table('fleet_maintenance_actions')
                        ->where('work_order_id', $order->id)
                        ->whereIn('action_type', ['propose_custody', 'acknowledge_custody'])
                        ->orderByDesc('id')->first();
                    abort_unless($latestCustody && (int) $latestCustody->id === (int) $prior->id
                        && (int) $latestCustody->target_user_id === (int) $currentActor->id
                        && ! DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
                            ->where('action_type', 'release')->where('id', '>', $prior->id)->exists(), 403);
                }
                if ($operation === 'accept_handover') {
                    abort_unless((int) $order->assigned_to_user_id === (int) $currentActor->id, 403);
                }

                return $order;
            }

            abort_unless((int) $order->version === $expectedVersion, 409);
            $targetId = null;
            $checkRunId = null;
            $policyVersionId = null;
            switch ($operation) {
                case 'start':
                    $this->requireStatus($order, ['open', 'on_hold']);
                    $order->status = 'in_progress';
                    $order->waiting_reason = null;
                    $order->started_at ??= now();
                    break;
                case 'hold':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold']);
                    $reason = trim((string) ($payload['waiting_reason'] ?? ''));
                    if ($reason === '' || mb_strlen($reason) > 64) {
                        throw ValidationException::withMessages(['waiting_reason' => 'Choose a waiting reason.']);
                    }
                    $order->status = 'on_hold';
                    $order->waiting_reason = $reason;
                    break;
                case 'resume':
                    $this->requireStatus($order, ['on_hold']);
                    $order->status = 'in_progress';
                    $order->waiting_reason = null;
                    break;
                case 'complete':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold']);
                    $this->assertCanComplete($order, (int) $asset->site_id, (string) $asset->category);
                    $order->status = 'completed';
                    $order->waiting_reason = null;
                    $order->completed_at = now();
                    break;
                case 'cancel':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold']);
                    if (DB::table('fleet_maintenance_restrictions')->where('work_order_id', $order->id)
                        ->where('state', 'active')->lockForUpdate()->first(['id'])) {
                        throw ValidationException::withMessages(['status' => 'This work has an active maintenance restriction. Complete the repair and use independent release before cancelling the record.']);
                    }
                    $order->status = 'cancelled';
                    $order->waiting_reason = null;
                    break;
                case 'update_next_action':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold']);
                    $next = trim((string) ($payload['next_action'] ?? ''));
                    if ($next === '' || mb_strlen($next) > 5000) {
                        throw ValidationException::withMessages(['next_action' => 'Enter a next action.']);
                    }
                    $order->next_action = $next;
                    if (array_key_exists('due_local', $payload)) {
                        try {
                            $order->due_at = $payload['due_local'] === null || $payload['due_local'] === ''
                                ? null : MaintenanceLocalTime::toUtc((string) $payload['due_local'], $payload['due_offset'] ?? null);
                        } catch (ValidationException $error) {
                            throw ValidationException::withMessages(['due_local' => collect($error->errors())->flatten()->first()]);
                        }
                    } elseif (array_key_exists('due_at', $payload)) {
                        $order->due_at = $payload['due_at'];
                    }
                    break;
                case 'note':
                    $note = trim((string) ($payload['note'] ?? ''));
                    if ($note === '' || mb_strlen($note) > 5000) {
                        throw ValidationException::withMessages(['note' => 'Enter a note.']);
                    }
                    break;
                case 'propose_handover':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold']);
                    $targetId = (int) ($payload['target_user_id'] ?? 0);
                    if (! $targetId || $targetId === (int) $order->assigned_to_user_id) {
                        throw ValidationException::withMessages(['target_user_id' => 'Choose a different eligible worker at this site.']);
                    }
                    $this->siteAccess->assertCanUseCurrentStaffAtSite(
                        $currentActor, $targetId, (int) $asset->site_id, ['sites.viewAll'],
                    );
                    // Current owner remains accountable until acceptance.
                    break;
                case 'accept_handover':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold']);
                    $latest = DB::table('fleet_maintenance_actions')
                        ->where('work_order_id', $order->id)
                        ->whereIn('action_type', ['propose_handover', 'accept_handover'])
                        ->orderByDesc('id')->first();
                    abort_unless($latest && $latest->action_type === 'propose_handover'
                        && (int) $latest->target_user_id === (int) $currentActor->id, 403);
                    $targetId = (int) $currentActor->id;
                    $order->assigned_to_user_id = $targetId;
                    break;
                case 'place_restriction':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold', 'completed']);
                    $holdPolicy = $this->policy->current((int) $asset->site_id, (string) $asset->category, 'hold', true);
                    if (! $holdPolicy || ! in_array(($payload['restriction_kind'] ?? null), $holdPolicy['rules']['allowed_kinds'] ?? [], true)) {
                        throw ValidationException::withMessages(['restriction_kind' => 'An approved hold rule for this asset and restriction is required.']);
                    }
                    if (! empty($payload['source_run_id'])) {
                        $source = DB::table('fleet_checklist_runs')
                            ->where('id', (int) $payload['source_run_id'])
                            ->where('asset_id', $asset->id)
                            ->where('work_order_id', $order->id)->first();
                        abort_unless($source && $source->outcome !== 'passed', 404);
                        $checkRunId = (int) $source->id;
                    }
                    $policyVersionId = $holdPolicy['id'];
                    break;
                case 'review_booking_impact':
                    $impactId = (int) ($payload['booking_impact_id'] ?? 0);
                    $impact = DB::table('fleet_maintenance_booking_impacts')
                        ->where('id', $impactId)->where('work_order_id', $order->id)
                        ->where('asset_id', $asset->id)->lockForUpdate()->first();
                    abort_unless($impact, 404);
                    abort_unless((int) $impact->owner_user_id === (int) $currentActor->id
                        || (int) $order->assigned_to_user_id === (int) $currentActor->id, 403);
                    abort_unless($impact->followup_state === 'needs_review', 409);
                    $note = trim((string) ($payload['review_note'] ?? ''));
                    if ($note === '' || mb_strlen($note) > 2000) {
                        throw ValidationException::withMessages(['review_note' => 'Record the booking follow-up completed.']);
                    }
                    break;
                case 'attest_repair':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold', 'completed']);
                    if (trim((string) ($payload['summary'] ?? '')) === '') {
                        throw ValidationException::withMessages(['summary' => 'Describe the completed repair.']);
                    }
                    $repairPolicy = $this->policy->current((int) $asset->site_id, (string) $asset->category, 'repair', true);
                    if (! $repairPolicy) {
                        throw ValidationException::withMessages(['status' => 'Approved repair requirements are missing.']);
                    }
                    if ($order->status === 'completed') {
                        $lastAttestation = DB::table('fleet_maintenance_actions')
                            ->where('work_order_id', $order->id)->where('action_type', 'attest_repair')
                            ->orderByDesc('id')->lockForUpdate()->first();
                        $hasActiveHold = DB::table('fleet_maintenance_restrictions')
                            ->where('work_order_id', $order->id)->where('state', 'active')
                            ->lockForUpdate()->exists();
                        if (! $hasActiveHold || ! $lastAttestation
                            || (int) $lastAttestation->policy_version_id === (int) $repairPolicy['id']) {
                            throw ValidationException::withMessages(['status' => 'A completed repair can be re-attested only while an active hold needs the newly approved repair rule.']);
                        }
                    }
                    $policyVersionId = $repairPolicy['id'];
                    break;
                case 'plan_provider':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold']);
                    $provider = trim((string) ($payload['provider_name'] ?? ''));
                    if ($provider === '' || mb_strlen($provider) > 255) {
                        throw ValidationException::withMessages(['provider_name' => 'Name the proposed provider.']);
                    }
                    if (empty($payload['starts_local']) || empty($payload['ends_local'])) {
                        throw ValidationException::withMessages(['starts_at' => 'Enter the proposed start and end.']);
                    }
                    $startsUtc = MaintenanceLocalTime::toUtc((string) $payload['starts_local'], $payload['starts_offset'] ?? null);
                    $endsUtc = MaintenanceLocalTime::toUtc((string) $payload['ends_local'], $payload['ends_offset'] ?? null);
                    $starts = CarbonImmutable::parse($startsUtc, 'UTC');
                    $ends = CarbonImmutable::parse($endsUtc, 'UTC');
                    if (! $ends->greaterThan($starts)) {
                        throw ValidationException::withMessages(['ends_at' => 'The proposed end must be after the start.']);
                    }
                    $payload['starts_at'] = $startsUtc;
                    $payload['ends_at'] = $endsUtc;
                    break;
                case 'record_provider_confirmation':
                    $this->requireStatus($order, ['open', 'in_progress', 'on_hold']);
                    $this->requireLatestProviderAction($order, ['plan_provider']);
                    if (trim((string) ($payload['response_method'] ?? '')) === ''
                        || trim((string) ($payload['provider_reference'] ?? '')) === '') {
                        throw ValidationException::withMessages(['provider_reference' => 'Record the provider response method and reference.']);
                    }
                    break;
                case 'record_provider_cancellation':
                    $this->requireLatestProviderAction($order, ['plan_provider', 'record_provider_confirmation']);
                    if (trim((string) ($payload['reason'] ?? '')) === '') {
                        throw ValidationException::withMessages(['reason' => 'Record why the provider appointment was cancelled.']);
                    }
                    break;
                case 'record_provider_completion':
                    $this->requireLatestProviderAction($order, ['record_provider_confirmation']);
                    if (trim((string) ($payload['service_summary'] ?? '')) === '') {
                        throw ValidationException::withMessages(['service_summary' => 'Record the provider service summary.']);
                    }
                    break;
                case 'propose_custody':
                    $this->requireStatus($order, ['completed']);
                    $targetId = (int) ($payload['target_user_id'] ?? 0);
                    abort_unless($targetId > 0, 422, 'Choose the receiving person.');
                    $this->siteAccess->assertCanUseCurrentStaffAtSite(
                        $currentActor, $targetId, (int) $asset->site_id, ['sites.viewAll'],
                    );
                    break;
                case 'acknowledge_custody':
                    $this->requireStatus($order, ['completed']);
                    abort_unless(($payload['received'] ?? null) === true, 422, 'Confirm receipt of this asset.');
                    $offer = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
                        ->whereIn('action_type', ['propose_custody', 'acknowledge_custody'])
                        ->orderByDesc('id')->first();
                    abort_unless($offer && $offer->action_type === 'propose_custody'
                        && (int) $offer->target_user_id === (int) $currentActor->id, 403);
                    $targetId = (int) $currentActor->id;
                    break;
                case 'release':
                    $this->requireStatus($order, ['completed']);
                    [$policyVersionId, $checkRunId, $restrictionSources] = $this->assertCanRelease(
                        $currentActor, $order, (int) $asset->site_id, (string) $asset->category,
                    );
                    $payload['restriction_sources'] = $restrictionSources;
                    if (\App\Models\Asset::vehicles()->whereKey($asset->id)->exists()) {
                        $releaseRestrictionIds = array_map(fn (array $source): int => $source['id'], $restrictionSources);
                        $resolvedCheckRunIds = array_values(array_map(
                            fn (array $source): int => (int) $source['source_run_id'],
                            array_filter($restrictionSources, fn (array $source): bool => $source['source_run_id'] !== null),
                        ));
                        $assessment = $this->readiness->assess($asset, new VehicleReadinessContext(
                            purpose: 'maintenance_release',
                            releaseRestrictionIds: $releaseRestrictionIds,
                            resolvedCheckRunIds: $resolvedCheckRunIds,
                        ), true);
                        $this->readiness->assertCanProceed($assessment, 'status');
                        $payload['vehicle_readiness'] = [
                            'input_fingerprint' => $assessment->inputFingerprint,
                            'compliance_version_ids' => $assessment->complianceVersionIds,
                            'odometer_observation_id' => $assessment->odometerObservationId,
                            'restriction_ids' => $assessment->restrictionIds,
                            'check_run_ids' => $assessment->checkRunIds,
                            'reason_codes' => array_map(fn ($reason) => $reason->code, $assessment->reasons),
                        ];
                    }
                    break;
                default:
                    throw ValidationException::withMessages(['operation' => 'Unsupported maintenance action.']);
            }

            $order->version = $expectedVersion + 1;
            $order->save();
            $actionId = DB::table('fleet_maintenance_actions')->insertGetId([
                'work_order_id' => $order->id,
                'action_type' => $operation,
                'actor_user_id' => $currentActor->id,
                'target_user_id' => $targetId,
                'check_run_id' => $checkRunId,
                'policy_version_id' => $policyVersionId,
                'expected_version' => $expectedVersion,
                'resulting_version' => $expectedVersion + 1,
                'idempotency_key' => $key,
                'payload_sha256' => $fingerprint,
                'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
                'occurred_at' => now()->format('Y-m-d H:i:s.u'),
                'created_at' => now(),
            ]);

            if ($operation === 'place_restriction') {
                $restrictionId = DB::table('fleet_maintenance_restrictions')->insertGetId([
                    'work_order_id' => $order->id,
                    'asset_id' => $asset->id,
                    'source_run_id' => $payload['source_run_id'] ?? null,
                    'created_action_id' => $actionId,
                    'source_key' => 'maintenance-action-'.$actionId,
                    'restriction_kind' => $payload['restriction_kind'],
                    'state' => 'active',
                    'created_by_user_id' => $currentActor->id,
                    'created_at' => now(),
                ]);
                $affectedBookings = DB::table('fleet_vehicle_bookings')
                    ->where('asset_id', $asset->id)->whereNull('deleted_at')
                    ->where(fn ($query) => $query->where('status', 'checked_out')
                        ->orWhere(fn ($scheduled) => $scheduled
                            ->whereIn('status', ['pending', 'approved'])
                            ->where('ends_at', '>=', now())))
                    ->orderBy('id')->lockForUpdate()->pluck('id');
                foreach ($affectedBookings as $bookingId) {
                    DB::table('fleet_maintenance_booking_impacts')->insert([
                        'work_order_id' => $order->id, 'restriction_id' => $restrictionId,
                        'booking_id' => $bookingId, 'asset_id' => $asset->id,
                        'owner_user_id' => $currentActor->id, 'created_action_id' => $actionId,
                        'followup_state' => 'needs_review', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            if ($operation === 'review_booking_impact') {
                DB::table('fleet_maintenance_booking_impacts')->where('id', $impactId)->update([
                    'followup_state' => 'reviewed', 'review_action_id' => $actionId,
                    'reviewed_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($operation === 'release') {
                DB::table('fleet_maintenance_restrictions')
                    ->where('work_order_id', $order->id)->where('state', 'active')
                    ->update(['state' => 'released', 'released_by_user_id' => $currentActor->id,
                        'released_at' => now(), 'release_action_id' => $actionId, 'version' => DB::raw('version + 1')]);
                DB::table('fleet_maintenance_booking_impacts')
                    ->where('work_order_id', $order->id)->whereNull('release_action_id')
                    ->update(['release_action_id' => $actionId, 'source_released_at' => now(),
                        'updated_at' => now()]);
                DB::table('fleet_maintenance_effects')->insert([
                    'work_order_id' => $order->id,
                    'action_id' => $actionId,
                    'effect_key' => 'maintenance-release-'.$actionId,
                    'effect_kind' => 'maintenance.released',
                    'payload_json' => json_encode(['asset_id' => $asset->id, 'work_order_id' => $order->id], JSON_THROW_ON_ERROR),
                    'state' => 'pending',
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                if (config('fleet_maintenance.effects_enabled', false)) {
                    $effectId = (int) DB::table('fleet_maintenance_effects')
                        ->where('effect_key', 'maintenance-release-'.$actionId)->value('id');
                    DB::afterCommit(static function () use ($effectId): void {
                        app(MaintenanceEffectDispatcher::class)->dispatchOne($effectId);
                    });
                }
            }

            return $order;
        }, 3);
    }

    /**
     * Maintenance assessment of one submitted check: "No issue found —
     * release for use". It resolves only that check's hold on the vehicle,
     * under the same vehicle lock and readiness gate as a per-work release,
     * and keeps the readiness evidence beside the decision. Maintenance holds
     * are untouched (repair, retest and independent release still apply) and
     * the check's own answers and outcome never change.
     *
     * The person who recorded a check may release it only when no approved
     * rule applied and it recorded no issue; otherwise another maintenance
     * manager assesses it. An identical retry returns the same decision.
     */
    public function assessCheck(User $actor, int $assetId, int $runId, string $reason, bool $confirmed, string $key): object
    {
        abort_unless($this->access->canManage($actor), 403);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Record why the vehicle is safe to use.']);
        }
        if (mb_strlen($reason) > 2000) {
            throw ValidationException::withMessages(['reason' => 'Keep the reason under 2,000 characters.']);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm that you assessed this check and found nothing that stops safe use.']);
        }
        if (mb_strlen(trim($key)) < 8 || mb_strlen($key) > 100) {
            throw ValidationException::withMessages(['request_key' => 'A request key is required.']);
        }
        $decision = MaintenanceRestrictionService::NO_ISSUE_RELEASE;
        $fingerprint = MaintenanceFingerprint::of([
            'actor_id' => (int) $actor->id,
            'operation' => 'maintenance.check.assess',
            'asset_id' => $assetId,
            'run_id' => $runId,
            'decision' => $decision,
            'reason' => $reason,
            'confirmed' => true,
        ]);

        try {
            return DB::transaction(function () use ($actor, $assetId, $runId, $reason, $key, $decision, $fingerprint): object {
                $currentActor = User::query()->findOrFail($actor->id);
                abort_unless($this->access->canManage($currentActor), 403);
                // The vehicle first: the same lock bookings, checks, reports and
                // release take. Maintenance's approved Sites decide the scope.
                $asset = $this->access->asset($currentActor, $assetId, true);

                $prior = DB::table('fleet_maintenance_check_assessments')
                    ->where('assessed_by_user_id', $currentActor->id)->where('request_key', $key)
                    ->lockForUpdate()->first();
                if ($prior) {
                    abort_unless((int) $prior->asset_id === (int) $asset->id
                        && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                        'This request was already used for a different assessment. Reload and try again.');

                    return $prior;
                }

                $run = DB::table('fleet_checklist_runs')->where('id', $runId)->where('asset_id', $asset->id)
                    ->whereNotNull('submitted_at')->lockForUpdate()
                    ->first(['id', 'user_id', 'outcome', 'check_kind', 'rule_version_id', 'rule_snapshot_json', 'responses', 'work_order_id']);
                abort_unless($run !== null, 404);
                abort_if(DB::table('fleet_maintenance_check_assessments')->where('check_run_id', $run->id)
                    ->lockForUpdate()->exists(), 409, 'This check has already been assessed. Reload to see the decision.');

                $workIds = self::checkWorkIds($run, true);
                $held = DB::table('fleet_maintenance_restrictions')->where('asset_id', $asset->id)->where('state', 'active')
                    ->where(fn ($query) => $query->where('source_run_id', $run->id)
                        ->when($workIds !== [], fn ($linked) => $linked->orWhereIn('work_order_id', $workIds)))
                    ->lockForUpdate()->exists();
                $blocking = in_array((int) $run->id, $this->restrictions->blockers((int) $asset->id, true)['check_run_ids'], true);
                $responses = json_decode((string) $run->responses, true);
                $issues = self::recordedIssues(is_array($responses) ? $responses : []);
                $refusal = self::checkAssessmentRefusal($run, (int) $currentActor->id, $blocking, $held, $issues);
                if ($refusal !== null) {
                    throw ValidationException::withMessages(['run' => $refusal]);
                }

                $readiness = null;
                if (Asset::vehicles()->whereKey($asset->id)->exists()) {
                    // The same gate as a per-work release: this check stops
                    // counting, every other hold and check still does, and the
                    // vehicle's compliance evidence must be current.
                    $assessment = $this->readiness->assess($asset, new VehicleReadinessContext(
                        purpose: 'maintenance_release',
                        resolvedCheckRunIds: [(int) $run->id],
                    ), true);
                    $this->readiness->assertCanProceed($assessment, 'run');
                    $readiness = [
                        'input_fingerprint' => $assessment->inputFingerprint,
                        'compliance_version_ids' => $assessment->complianceVersionIds,
                        'odometer_observation_id' => $assessment->odometerObservationId,
                        'restriction_ids' => $assessment->restrictionIds,
                        'check_run_ids' => $assessment->checkRunIds,
                        'reason_codes' => array_map(fn ($item) => $item->code, $assessment->reasons),
                    ];
                }

                $workOrderId = $workIds[0] ?? null;
                $id = DB::table('fleet_maintenance_check_assessments')->insertGetId([
                    'asset_id' => $asset->id,
                    'check_run_id' => $run->id,
                    'work_order_id' => $workOrderId,
                    'decision' => $decision,
                    'reason' => $reason,
                    'run_outcome' => (string) ($run->outcome ?? 'needs_assessment'),
                    'run_rule_version_id' => $run->rule_version_id,
                    'acknowledged_issues_json' => json_encode($issues, JSON_THROW_ON_ERROR),
                    'readiness_json' => $readiness === null ? null : json_encode($readiness, JSON_THROW_ON_ERROR),
                    'assessed_by_user_id' => $currentActor->id,
                    'assessed_at' => now()->format('Y-m-d H:i:s.u'),
                    'request_key' => $key,
                    'request_fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]);
                AuditLogger::logOrFail('fleet.maintenance.check.assess', FleetChecklistRun::query()->findOrFail($run->id), [
                    'actor_id' => (int) $currentActor->id,
                    'asset_id' => (int) $asset->id,
                    'assessment_id' => (int) $id,
                    'decision' => $decision,
                    'reason' => $reason,
                    'run_outcome' => $run->outcome,
                    'rule_version_id' => $run->rule_version_id !== null ? (int) $run->rule_version_id : null,
                    'recorded_by_user_id' => (int) $run->user_id,
                    'work_order_id' => $workOrderId,
                    'acknowledged_issues' => $issues,
                    'vehicle_readiness' => $readiness,
                ]);

                return DB::table('fleet_maintenance_check_assessments')->where('id', $id)->first();
            }, 3);
        } catch (QueryException $exception) {
            // A same-key retry racing on another vehicle: return the identical
            // decision, or refuse a changed one.
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            $currentActor = User::query()->findOrFail($actor->id);
            abort_unless($this->access->canManage($currentActor), 403);
            $asset = $this->access->asset($currentActor, $assetId);
            $prior = DB::table('fleet_maintenance_check_assessments')
                ->where('assessed_by_user_id', $currentActor->id)->where('request_key', $key)->first();
            abort_unless($prior !== null && (int) $prior->asset_id === (int) $asset->id
                && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                'This check has already been assessed. Reload to see the decision.');

            return $prior;
        }
    }

    /**
     * Why a check can't take a "no issue found" assessment, or null when it
     * can. Shared by assessCheck() and the checks read model.
     *
     * @param  list<array{question_id:string, answer:string}>  $issues
     */
    public static function checkAssessmentRefusal(object $run, int $actorId, bool $blocking, bool $held, array $issues): ?string
    {
        $ruled = $run->rule_version_id !== null;

        return match (true) {
            ($run->check_kind ?? 'check') !== 'check' => 'A retest is resolved when its Maintenance work is released.',
            ! $blocking => 'This check doesn’t stop the vehicle being used, so there’s nothing to release.',
            $ruled && $run->outcome === 'failed' => 'An approved check rule recorded a failure. Report it to Maintenance for repair, a retest and an independent release.',
            $held => 'Maintenance has placed a hold for this check. The hold is released through repair, a retest and an independent release.',
            (int) $run->user_id === $actorId && ($ruled || $issues !== []) => 'This check recorded an issue or couldn’t be fully assessed, so another maintenance manager must assess it.',
            default => null,
        };
    }

    /**
     * Answers that recorded an issue, or that an item couldn't be assessed.
     *
     * @param  array<string|int, mixed>  $responses
     * @return list<array{question_id:string, answer:string}>
     */
    public static function recordedIssues(array $responses): array
    {
        $issues = [];
        foreach ($responses as $question => $answer) {
            if ((string) $question === '_metadata') {
                continue;
            }
            $value = is_array($answer) ? ($answer['result'] ?? null) : $answer;
            $value = is_string($value) ? strtolower(trim($value)) : null;
            if ($value !== null && in_array($value, self::ISSUE_ANSWERS, true)) {
                $issues[] = ['question_id' => (string) $question, 'answer' => $value];
            }
        }

        return $issues;
    }

    /**
     * The Maintenance work a check belongs to: the work it was recorded on,
     * then work its reports opened or joined.
     *
     * @return list<int>
     */
    public static function checkWorkIds(object $run, bool $lock = false): array
    {
        $reports = DB::table('fleet_maintenance_reports')->where('source_type', 'fleet_checklist_run')
            ->where('source_id', $run->id)->whereNull('duplicate_of_report_id')->orderBy('id');
        $reported = ($lock ? $reports->lockForUpdate() : $reports)->pluck('work_order_id')
            ->map(fn ($id): int => (int) $id)->all();

        return array_values(array_unique(array_filter([
            $run->work_order_id !== null ? (int) $run->work_order_id : null, ...$reported,
        ])));
    }

    private function requireStatus(FleetWorkOrder $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => 'This work order has moved on. Refresh it before acting.']);
        }
    }

    private function assertCanComplete(FleetWorkOrder $order, int $siteId, string $category): void
    {
        $lastProvider = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
            ->whereIn('action_type', ['plan_provider', 'record_provider_confirmation',
                'record_provider_cancellation', 'record_provider_completion'])
            ->orderByDesc('id')->first();
        if ($lastProvider && ! in_array($lastProvider->action_type,
            ['record_provider_cancellation', 'record_provider_completion'], true)) {
            throw ValidationException::withMessages(['status' => 'The planned provider appointment needs a recorded completion or cancellation.']);
        }
        $repairPolicy = $this->policy->current($siteId, $category, 'repair', true);
        if (! $repairPolicy) {
            throw ValidationException::withMessages(['status' => 'Approved repair requirements are missing. Complete work after they are configured and met.']);
        }

        $attestation = DB::table('fleet_maintenance_actions')
            ->where('work_order_id', $order->id)
            ->where('action_type', 'attest_repair')
            ->orderByDesc('id')->first();
        if (! $attestation || (int) $attestation->policy_version_id !== $repairPolicy['id']
            || ! DB::table('fleet_maintenance_attachments')
            ->where('work_order_id', $order->id)
            ->where('action_id', $attestation->id)->exists()) {
            throw ValidationException::withMessages(['status' => 'Repair attestation and saved service evidence are required before completion.']);
        }
    }

    private function requireLatestProviderAction(FleetWorkOrder $order, array $allowed): void
    {
        $last = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
            ->whereIn('action_type', ['plan_provider', 'record_provider_confirmation',
                'record_provider_cancellation', 'record_provider_completion'])
            ->orderByDesc('id')->first();
        if (! $last || ! in_array($last->action_type, $allowed, true)) {
            throw ValidationException::withMessages(['provider_reference' => 'The provider plan has changed. Review the latest response first.']);
        }
    }

    /** @return array{int,int,array<int,array{id:int,version:int,source_run_id:?int}>} */
    private function assertCanRelease(User $reviewer, FleetWorkOrder $order, int $siteId, string $category): array
    {
        abort_unless($reviewer->canDo('fleet.maintenance.release'), 403);
        $grant = DB::table('fleet_maintenance_reviewer_grants')
            ->where('site_id', $siteId)->where('asset_category', $category)
            ->where('review_kind', 'maintenance_release')->where('user_id', $reviewer->id)
            ->orderByDesc('version')->lockForUpdate()->first();
        abort_unless($grant && $grant->decision === 'grant', 403);
        $this->siteAccess->assertCanUseCurrentStaffAtSite($reviewer, (int) $reviewer->id, $siteId, ['sites.viewAll']);

        $releasePolicy = $this->policy->current($siteId, $category, 'release', true);
        $retestPolicy = $this->policy->current($siteId, $category, 'retest', true);
        $repairPolicy = $this->policy->current($siteId, $category, 'repair', true);
        if (! $releasePolicy || ! $retestPolicy || ! $repairPolicy
            || ! array_key_exists('requires_custody', $releasePolicy['rules'])
            || ! is_bool($releasePolicy['rules']['requires_custody'])) {
            throw ValidationException::withMessages(['status' => 'Approved release, retest, repair and custody rules are required.']);
        }
        $attestation = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
            ->where('action_type', 'attest_repair')->orderByDesc('id')->lockForUpdate()->first();
        if (! $attestation || (int) $attestation->policy_version_id !== $repairPolicy['id']
            || (int) $attestation->actor_user_id === (int) $reviewer->id
            || ! DB::table('fleet_maintenance_attachments')->where('work_order_id', $order->id)
                ->where('action_id', $attestation->id)->exists()) {
            throw ValidationException::withMessages(['status' => 'Current repair attestation, evidence and an independent reviewer are required.']);
        }
        $retest = DB::table('fleet_checklist_runs')->where('work_order_id', $order->id)
            ->where('check_kind', 'retest')->orderByDesc('id')->lockForUpdate()->first();
        if (! $retest || $retest->outcome !== 'passed'
            || (int) $retest->rule_version_id !== $retestPolicy['id']
            || $retest->submitted_at <= $attestation->occurred_at) {
            throw ValidationException::withMessages(['status' => 'A new passing retest under the current approved rule is required.']);
        }
        $activeRestrictions = DB::table('fleet_maintenance_restrictions')
            ->where('asset_id', $order->asset_id)->where('state', 'active')
            ->orderBy('id')->lockForUpdate()->get(['id', 'work_order_id', 'source_run_id', 'created_action_id', 'version']);
        $activeIds = $activeRestrictions->pluck('id')->map(fn ($id) => (int) $id)->all();
        $coveredIds = json_decode((string) $retest->covered_restriction_ids_json, true);
        $ownRestrictions = $activeRestrictions->filter(fn ($restriction) => (int) $restriction->work_order_id === (int) $order->id);
        if ($ownRestrictions->isEmpty() || ! is_array($coveredIds) || $coveredIds !== $activeIds) {
            throw ValidationException::withMessages(['status' => 'The retest does not cover the current restriction set. Save a new retest after reviewing every hold.']);
        }
        foreach ($activeRestrictions as $restriction) {
            $origin = $restriction->created_action_id
                ? DB::table('fleet_maintenance_actions')->where('id', $restriction->created_action_id)->lockForUpdate()->first()
                : null;
            if (! $origin || $origin->occurred_at >= $retest->submitted_at) {
                throw ValidationException::withMessages(['status' => 'A newer hold requires fresh retest and review.']);
            }
        }
        // Submitted checks only, as readiness counts them: legacy daily checks
        // without a submission time never hold a vehicle. A check Maintenance
        // assessed as "no issue found" no longer counts either.
        $newer = DB::table('fleet_checklist_runs')->where('asset_id', $order->asset_id)
            ->where('id', '>', $retest->id)->whereNotNull('submitted_at')
            ->where(fn ($query) => $query->whereNull('outcome')->orWhere('outcome', '!=', 'passed'))
            ->orderBy('id')->lockForUpdate()->get(['id', 'outcome', 'rule_version_id', 'rule_snapshot_json'])
            ->filter(fn ($run) => MaintenanceRestrictionService::blocksAvailability($run))
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        if (array_diff($newer, $this->restrictions->assessedRunIds($newer, true)) !== []) {
            throw ValidationException::withMessages(['status' => 'A newer unresolved check prevents release.']);
        }
        if ($releasePolicy['rules']['requires_custody'] === true) {
            $custody = DB::table('fleet_maintenance_actions')->where('work_order_id', $order->id)
                ->whereIn('action_type', ['propose_custody', 'acknowledge_custody'])
                ->orderByDesc('id')->lockForUpdate()->first();
            if (! $custody || $custody->action_type !== 'acknowledge_custody'
                || $custody->occurred_at <= $retest->submitted_at) {
                throw ValidationException::withMessages(['status' => 'The receiving person must acknowledge custody after the retest.']);
            }
        }
        // Release only this work's sources. Other work remains restricted and
        // assertBookable continues to block until its own requirements pass.
        // The retest above must still cover the exact current asset-wide set.
        $sources = $ownRestrictions->map(fn ($restriction) => [
            'id' => (int) $restriction->id,
            'version' => (int) $restriction->version,
            'source_run_id' => $restriction->source_run_id ? (int) $restriction->source_run_id : null,
        ])->all();

        return [$releasePolicy['id'], (int) $retest->id, $sources];
    }
}
