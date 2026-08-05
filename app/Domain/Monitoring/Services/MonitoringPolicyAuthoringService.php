<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Exceptions\MonitoringPolicyVersionConflict;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MetricSeries;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitorDependency;
use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitoringProfile;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MonitoringPolicyAuthoringService
{
    public function __construct(
        private readonly MonitoringPolicyRules $rules,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly MonitoringPolicyAuditWriter $audit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function createProfile(User $actor, array $attributes): MonitoringProfile
    {
        $values = $this->rules->profile([
            'description' => null,
            'interval_seconds' => 60,
            'failure_confirmations' => 3,
            'failure_duration_seconds' => 0,
            'recovery_confirmations' => 2,
            'recovery_duration_seconds' => 0,
            'stale_after_seconds' => 300,
            'rising_threshold' => null,
            'falling_threshold' => null,
            'baseline_window_seconds' => 3600,
            'baseline_minimum_samples' => 10,
            'baseline_deviation_multiplier' => null,
            'maintenance_policy' => 'suppress_notifications_and_ticketing',
            'rollup_policy' => 'worst_applicable',
            'retention_policy_id' => null,
            ...$attributes,
        ]);

        return DB::transaction(function () use ($actor, $values): MonitoringProfile {
            $this->lockActiveRetentionAttachment($values['retention_policy_id']);
            $profile = MonitoringProfile::query()->create([
                ...$values,
                'is_active' => true,
                'version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->audit->write('monitoring.profile.created', $profile, $actor, $this->auditContext($profile));

            return $profile->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateProfile(
        User $actor,
        MonitoringProfile $profile,
        int $expectedVersion,
        array $attributes,
    ): MonitoringProfile {
        return DB::transaction(function () use ($actor, $profile, $expectedVersion, $attributes): MonitoringProfile {
            if (array_key_exists('retention_policy_id', $attributes)
                && is_numeric($attributes['retention_policy_id'])) {
                $this->lockActiveRetentionAttachment((int) $attributes['retention_policy_id']);
            }
            $locked = MonitoringProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertActive($locked);
            $values = $this->rules->profile([
                ...$this->profileValues($locked),
                ...$attributes,
            ]);
            $locked->forceFill([
                ...$values,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->write('monitoring.profile.updated', $locked, $actor, $this->auditContext($locked));

            return $locked->fresh();
        }, 3);
    }

    public function deactivateProfile(
        User $actor,
        MonitoringProfile $profile,
        int $expectedVersion,
        string $reason,
        ?int $replacementProfileId = null,
    ): MonitoringProfile {
        $reason = $this->rules->operationalReason($reason);

        return DB::transaction(function () use (
            $actor,
            $profile,
            $expectedVersion,
            $reason,
            $replacementProfileId,
        ): MonitoringProfile {
            $locked = MonitoringProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertActive($locked);

            $monitors = Monitor::query()->where('profile_id', $locked->id)->lockForUpdate()->get();
            if ($monitors->isNotEmpty() && $replacementProfileId === null) {
                $this->fail('replacement_profile_id', 'A replacement profile is required while monitors still use this profile.');
            }
            if ($replacementProfileId !== null) {
                if ($replacementProfileId === (int) $locked->id) {
                    $this->fail('replacement_profile_id', 'The replacement profile must be different.');
                }
                $replacement = MonitoringProfile::query()->whereKey($replacementProfileId)->lockForUpdate()->first();
                if ($replacement === null || ! $replacement->is_active) {
                    $this->fail('replacement_profile_id', 'The replacement monitoring profile is unavailable.');
                }
                Monitor::query()->whereIn('id', $monitors->pluck('id'))->update([
                    'profile_id' => $replacement->id,
                    'updated_at' => now(),
                ]);
            }

            $locked->forceFill([
                'is_active' => false,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
                'deactivated_at' => CarbonImmutable::now('UTC'),
                'deactivated_by_user_id' => $actor->id,
                'deactivation_reason' => $reason,
            ])->save();
            $this->audit->write('monitoring.profile.deactivated', $locked, $actor, [
                ...$this->auditContext($locked),
                'replacement_profile_id' => $replacementProfileId,
                'reassigned_monitor_count' => $monitors->count(),
                'reason_recorded' => true,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function createCoverageExpectation(User $actor, array $attributes): MonitoringCoverageExpectation
    {
        $values = $this->rules->coverage([
            'site_id' => null,
            'device_category' => null,
            'minimum_count' => 1,
            'support_status' => 'supported',
            ...$attributes,
        ]);

        return DB::transaction(function () use ($actor, $values): MonitoringCoverageExpectation {
            $identity = MonitoringCoverageExpectation::identityFor(
                $values['site_id'],
                $values['device_domain'],
                $values['device_category'],
                $values['capability'],
            );
            $this->rejectExistingCoverageIdentity($identity);
            $this->lockSite($values['site_id']);
            $expectation = MonitoringCoverageExpectation::query()->create([
                ...$values,
                'is_active' => true,
                'version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->audit->write('monitoring.coverage.created', $expectation, $actor, $this->auditContext($expectation));

            return $expectation->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateCoverageExpectation(
        User $actor,
        MonitoringCoverageExpectation $expectation,
        int $expectedVersion,
        array $attributes,
    ): MonitoringCoverageExpectation {
        return DB::transaction(function () use ($actor, $expectation, $expectedVersion, $attributes): MonitoringCoverageExpectation {
            $locked = MonitoringCoverageExpectation::query()->lockForUpdate()->findOrFail($expectation->id);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertActive($locked);
            $values = $this->rules->coverage([
                ...$this->coverageValues($locked),
                ...$attributes,
            ]);
            $this->lockSite($values['site_id']);
            $locked->forceFill([
                ...$values,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->write('monitoring.coverage.updated', $locked, $actor, $this->auditContext($locked));

            return $locked->fresh();
        }, 3);
    }

    public function deactivateCoverageExpectation(
        User $actor,
        MonitoringCoverageExpectation $expectation,
        int $expectedVersion,
        string $reason,
    ): MonitoringCoverageExpectation {
        return $this->deactivate(
            actor: $actor,
            model: $expectation,
            expectedVersion: $expectedVersion,
            reason: $reason,
            action: 'monitoring.coverage.deactivated',
        );
    }

    /** @param array<string, mixed> $attributes */
    public function reactivateCoverageExpectation(
        User $actor,
        MonitoringCoverageExpectation $expectation,
        int $expectedVersion,
        string $reason,
        array $attributes = [],
    ): MonitoringCoverageExpectation {
        $reason = $this->rules->operationalReason($reason);

        return DB::transaction(function () use (
            $actor,
            $expectation,
            $expectedVersion,
            $reason,
            $attributes,
        ): MonitoringCoverageExpectation {
            $locked = MonitoringCoverageExpectation::query()
                ->lockForUpdate()
                ->findOrFail($expectation->id);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertInactive($locked);
            $values = $this->rules->coverage([
                ...$this->coverageValues($locked),
                ...$attributes,
            ]);
            $identity = MonitoringCoverageExpectation::identityFor(
                $values['site_id'],
                $values['device_domain'],
                $values['device_category'],
                $values['capability'],
            );
            if (! hash_equals((string) $locked->identity_key, $identity)) {
                $this->fail('identity', 'Reactivate this coverage identity without changing its governed scope.');
            }
            $this->lockSite($values['site_id']);
            $previousDeactivationReason = (string) $locked->deactivation_reason;
            $locked->forceFill([
                ...$values,
                'is_active' => true,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
                'deactivated_at' => null,
                'deactivated_by_user_id' => null,
                'deactivation_reason' => null,
            ])->save();
            $this->audit->write('monitoring.coverage.reactivated', $locked, $actor, [
                ...$this->auditContext($locked),
                'previous_deactivation_reason' => $previousDeactivationReason,
                'reactivation_reason' => $reason,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function createManualDependency(User $actor, array $attributes): MonitorDependency
    {
        $values = $this->rules->dependency($attributes);

        return DB::transaction(function () use ($actor, $values): MonitorDependency {
            $this->lockSite((int) $values['site_id']);
            $existing = MonitorDependency::query()
                ->where('upstream_monitor_id', $values['upstream_monitor_id'])
                ->where('downstream_monitor_id', $values['downstream_monitor_id'])
                ->where('policy', $values['policy'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->source !== 'manual') {
                    $this->fail('source', 'Topology and provider dependencies cannot be changed in Settings.');
                }
                $existing->forceFill([
                    ...$values,
                    'is_active' => true,
                    'version' => $existing->version + 1,
                    'updated_by_user_id' => $actor->id,
                    'deactivated_at' => null,
                    'deactivated_by_user_id' => null,
                    'deactivation_reason' => null,
                ])->save();
                $this->audit->write('monitoring.dependency.reactivated', $existing, $actor, $this->auditContext($existing));

                return $existing->fresh();
            }

            $dependency = MonitorDependency::query()->create([
                ...$values,
                'is_active' => true,
                'version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->audit->write('monitoring.dependency.created', $dependency, $actor, $this->auditContext($dependency));

            return $dependency->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateManualDependency(
        User $actor,
        MonitorDependency $dependency,
        int $expectedVersion,
        array $attributes,
    ): MonitorDependency {
        return DB::transaction(function () use ($actor, $dependency, $expectedVersion, $attributes): MonitorDependency {
            $locked = MonitorDependency::query()->lockForUpdate()->findOrFail($dependency->id);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertActive($locked);
            if ($locked->source !== 'manual') {
                $this->fail('source', 'Topology and provider dependencies cannot be changed in Settings.');
            }
            $values = $this->rules->dependency([
                ...$this->dependencyValues($locked),
                ...$attributes,
            ]);
            $this->lockSite((int) $values['site_id']);
            $locked->forceFill([
                ...$values,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->write('monitoring.dependency.updated', $locked, $actor, $this->auditContext($locked));

            return $locked->fresh();
        }, 3);
    }

    public function deactivateManualDependency(
        User $actor,
        MonitorDependency $dependency,
        int $expectedVersion,
        string $reason,
    ): MonitorDependency {
        if ($dependency->source !== 'manual') {
            $this->fail('source', 'Topology and provider dependencies cannot be changed in Settings.');
        }

        return $this->deactivate(
            actor: $actor,
            model: $dependency,
            expectedVersion: $expectedVersion,
            reason: $reason,
            action: 'monitoring.dependency.deactivated',
        );
    }

    /** @param array<string, mixed> $attributes */
    public function createMaintenanceWindow(User $actor, array $attributes): MonitoringMaintenanceWindow
    {
        $values = $this->rules->maintenance([
            'monitor_id' => null,
            'device_id' => null,
            'recurrence' => null,
            'recurrence_until' => null,
            'timezone' => 'UTC',
            ...$attributes,
        ]);

        return DB::transaction(function () use ($actor, $values): MonitoringMaintenanceWindow {
            $this->lockSite((int) $values['site_id']);
            $this->assertMaintenanceScope($values);
            $window = MonitoringMaintenanceWindow::query()->create([
                ...$values,
                'status' => 'active',
                'version' => 1,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->audit->write('monitoring.maintenance.created', $window, $actor, $this->auditContext($window));

            return $window->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateMaintenanceWindow(
        User $actor,
        MonitoringMaintenanceWindow $window,
        int $expectedVersion,
        array $attributes,
    ): MonitoringMaintenanceWindow {
        return DB::transaction(function () use ($actor, $window, $expectedVersion, $attributes): MonitoringMaintenanceWindow {
            $locked = MonitoringMaintenanceWindow::query()->lockForUpdate()->findOrFail($window->id);
            $this->assertVersion($locked, $expectedVersion);
            if ($locked->status !== 'active') {
                $this->fail('status', 'Only an active maintenance window can be changed.');
            }
            $values = $this->rules->maintenance([
                ...$this->maintenanceValues($locked),
                ...$attributes,
            ]);
            $this->lockSite((int) $values['site_id']);
            $this->assertMaintenanceScope($values);
            $locked->forceFill([
                ...$values,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->write('monitoring.maintenance.updated', $locked, $actor, $this->auditContext($locked));

            return $locked->fresh();
        }, 3);
    }

    public function cancelMaintenanceWindow(
        User $actor,
        MonitoringMaintenanceWindow $window,
        int $expectedVersion,
        string $reason,
    ): MonitoringMaintenanceWindow {
        $reason = $this->rules->operationalReason($reason);

        return DB::transaction(function () use ($actor, $window, $expectedVersion, $reason): MonitoringMaintenanceWindow {
            $locked = MonitoringMaintenanceWindow::query()->lockForUpdate()->findOrFail($window->id);
            $this->assertVersion($locked, $expectedVersion);
            if ($locked->status !== 'active') {
                $this->fail('status', 'Only an active maintenance window can be cancelled.');
            }
            $locked->forceFill([
                'status' => 'cancelled',
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => $reason,
            ])->save();
            $this->audit->write('monitoring.maintenance.cancelled', $locked, $actor, [
                ...$this->auditContext($locked),
                'reason_recorded' => true,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes
     * @return array{metric_series_candidates: int, snapshot_candidates: int, requires_confirmation: bool, legal_hold_removal: bool, scope_changed: bool}
     */
    public function previewRetentionPolicy(
        array $attributes,
        ?MonitoringRetentionPolicy $current = null,
        ?CarbonImmutable $now = null,
    ): array {
        $values = $this->rules->retention($attributes);
        $now ??= CarbonImmutable::now('UTC');

        $series = MetricSeries::query()->whereNotNull('first_point_at');
        $this->applyRetentionScope($series, $values);
        $metricCount = $series->where(function (Builder $query) use ($values, $now): void {
            foreach (['raw', 'hourly', 'daily'] as $tier) {
                $query->orWhere(function (Builder $tierQuery) use ($tier, $values, $now): void {
                    $tierQuery
                        ->where('retention_tier', $tier)
                        ->where('first_point_at', '<=', $now->subDays((int) $values["{$tier}_days"]));
                });
            }
        })->count();

        $snapshotCount = 0;
        if ($values['scope_kind'] !== 'privacy'
            && ($values['scope_kind'] !== 'data_class' || $values['data_class'] === 'configuration')) {
            $snapshots = ConfigurationSnapshot::query()
                ->where('storage_state', 'available')
                ->where('captured_at', '<=', $now->subDays((int) $values['daily_days']));
            $this->applySnapshotRetentionScope($snapshots, $values);
            $snapshotCount = $snapshots->count();
        }

        $legalHoldRemoval = $current !== null && $current->legal_hold && ! $values['legal_hold'];
        $scopeChanged = $current !== null && $current->identity_key !== MonitoringRetentionPolicy::identityFor(
            $values['scope_kind'],
            $values['site_id'],
            $values['device_id'],
            $values['data_class'],
            $values['privacy_class'],
        );
        $shortening = $current === null
            ? true
            : $scopeChanged
                || (int) $values['raw_days'] < (int) $current->raw_days
                || (int) $values['hourly_days'] < (int) $current->hourly_days
                || (int) $values['daily_days'] < (int) $current->daily_days;

        return [
            'metric_series_candidates' => $metricCount,
            'snapshot_candidates' => $snapshotCount,
            'requires_confirmation' => ! $values['legal_hold'] && ($shortening || $legalHoldRemoval),
            'legal_hold_removal' => $legalHoldRemoval,
            'scope_changed' => $scopeChanged,
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function createRetentionPolicy(
        User $actor,
        array $attributes,
        ?string $confirmation = null,
        ?string $reason = null,
    ): MonitoringRetentionPolicy {
        $values = $this->rules->retention($attributes);
        $preview = $this->previewRetentionPolicy($values);
        $reasonRecorded = false;
        $confirmedReason = null;
        if ($preview['requires_confirmation']) {
            $confirmedReason = $this->rules->requireRetentionConfirmation($confirmation, (string) $reason);
            $reasonRecorded = true;
        }

        return DB::transaction(function () use (
            $actor,
            $values,
            $preview,
            $reasonRecorded,
            $confirmedReason,
        ): MonitoringRetentionPolicy {
            $identity = MonitoringRetentionPolicy::identityFor(
                $values['scope_kind'],
                $values['site_id'],
                $values['device_id'],
                $values['data_class'],
                $values['privacy_class'],
            );
            $this->rejectExistingRetentionIdentity($identity);
            $this->lockRetentionScope($values);
            $policy = MonitoringRetentionPolicy::query()->create([
                ...$values,
                'is_active' => true,
                'version' => 1,
                'change_reason' => $confirmedReason,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->audit->write('monitoring.retention.created', $policy, $actor, [
                ...$this->auditContext($policy),
                ...$preview,
                'reason_recorded' => $reasonRecorded,
            ]);

            return $policy->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function reactivateRetentionPolicy(
        User $actor,
        MonitoringRetentionPolicy $policy,
        int $expectedVersion,
        string $reason,
        array $attributes = [],
        ?string $confirmation = null,
    ): MonitoringRetentionPolicy {
        $reason = $this->rules->operationalReason($reason);

        return DB::transaction(function () use (
            $actor,
            $policy,
            $expectedVersion,
            $reason,
            $attributes,
            $confirmation,
        ): MonitoringRetentionPolicy {
            $locked = MonitoringRetentionPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertInactive($locked);
            $values = $this->rules->retention([
                ...$this->retentionValues($locked),
                ...$attributes,
            ]);
            $identity = MonitoringRetentionPolicy::identityFor(
                $values['scope_kind'],
                $values['site_id'],
                $values['device_id'],
                $values['data_class'],
                $values['privacy_class'],
            );
            if (! hash_equals((string) $locked->identity_key, $identity)) {
                $this->fail('identity', 'Reactivate this retention identity without changing its governed scope.');
            }
            $preview = $this->previewRetentionPolicy($values);
            if ($preview['requires_confirmation']) {
                $this->rules->requireRetentionConfirmation($confirmation, $reason);
            }
            $this->lockRetentionScope($values);
            $previousDeactivationReason = (string) $locked->deactivation_reason;
            $locked->forceFill([
                ...$values,
                'is_active' => true,
                'version' => $locked->version + 1,
                'change_reason' => $reason,
                'updated_by_user_id' => $actor->id,
                'deactivated_at' => null,
                'deactivated_by_user_id' => null,
                'deactivation_reason' => null,
            ])->save();
            $this->audit->write('monitoring.retention.reactivated', $locked, $actor, [
                ...$this->auditContext($locked),
                ...$preview,
                'previous_deactivation_reason' => $previousDeactivationReason,
                'reactivation_reason' => $reason,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateRetentionPolicy(
        User $actor,
        MonitoringRetentionPolicy $policy,
        int $expectedVersion,
        array $attributes,
        ?string $confirmation = null,
        ?string $reason = null,
    ): MonitoringRetentionPolicy {
        return DB::transaction(function () use (
            $actor,
            $policy,
            $expectedVersion,
            $attributes,
            $confirmation,
            $reason,
        ): MonitoringRetentionPolicy {
            $locked = MonitoringRetentionPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertActive($locked);
            $values = $this->rules->retention([
                ...$this->retentionValues($locked),
                ...$attributes,
            ]);
            $preview = $this->previewRetentionPolicy($values, $locked);
            $reasonRecorded = false;
            $confirmedReason = $locked->change_reason;
            if ($preview['requires_confirmation']) {
                $confirmedReason = $this->rules->requireRetentionConfirmation($confirmation, (string) $reason);
                $reasonRecorded = true;
            }
            $this->lockRetentionScope($values);
            $locked->forceFill([
                ...$values,
                'version' => $locked->version + 1,
                'change_reason' => $confirmedReason,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit->write('monitoring.retention.updated', $locked, $actor, [
                ...$this->auditContext($locked),
                ...$preview,
                'reason_recorded' => $reasonRecorded,
            ]);

            return $locked->fresh();
        }, 3);
    }

    public function deactivateRetentionPolicy(
        User $actor,
        MonitoringRetentionPolicy $policy,
        int $expectedVersion,
        string $reason,
        ?string $confirmation = null,
    ): MonitoringRetentionPolicy {
        $reason = $this->rules->operationalReason($reason);

        return DB::transaction(function () use ($actor, $policy, $expectedVersion, $reason, $confirmation): MonitoringRetentionPolicy {
            $locked = MonitoringRetentionPolicy::query()->lockForUpdate()->findOrFail($policy->id);
            $this->assertVersion($locked, $expectedVersion);
            $this->assertActive($locked);
            $attachedProfiles = MonitoringProfile::query()
                ->where('retention_policy_id', $locked->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            if ($attachedProfiles->isNotEmpty()) {
                $this->fail('retention_policy_id', 'An active monitoring profile still uses this retention policy.');
            }
            if ($locked->scope_kind === 'application') {
                $applicationPolicies = MonitoringRetentionPolicy::query()
                    ->where('scope_kind', 'application')
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->get();
                if ($applicationPolicies->count() <= 1) {
                    $this->fail('policy', 'The last active application retention policy cannot be deactivated.');
                }
            }
            if ($locked->legal_hold) {
                $this->rules->requireRetentionConfirmation($confirmation, $reason);
            }

            $locked->forceFill([
                'is_active' => false,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
                'deactivated_at' => CarbonImmutable::now('UTC'),
                'deactivated_by_user_id' => $actor->id,
                'deactivation_reason' => $reason,
            ])->save();
            $this->audit->write('monitoring.retention.deactivated', $locked, $actor, [
                ...$this->auditContext($locked),
                'legal_hold_removed' => (bool) $locked->legal_hold,
                'reason_recorded' => true,
            ]);

            return $locked->fresh();
        }, 3);
    }

    /** @template TModel of Model
     * @param  TModel  $model
     * @return TModel
     */
    private function deactivate(
        User $actor,
        Model $model,
        int $expectedVersion,
        string $reason,
        string $action,
    ): Model {
        $reason = $this->rules->operationalReason($reason);

        return DB::transaction(function () use ($actor, $model, $expectedVersion, $reason, $action): Model {
            /** @var TModel $locked */
            $locked = $model->newQuery()->lockForUpdate()->findOrFail($model->getKey());
            $this->assertVersion($locked, $expectedVersion);
            $this->assertActive($locked);
            $locked->forceFill([
                'is_active' => false,
                'version' => $locked->version + 1,
                'updated_by_user_id' => $actor->id,
                'deactivated_at' => CarbonImmutable::now('UTC'),
                'deactivated_by_user_id' => $actor->id,
                'deactivation_reason' => $reason,
            ])->save();
            $this->audit->write($action, $locked, $actor, [
                ...$this->auditContext($locked),
                'reason_recorded' => true,
            ]);

            return $locked->fresh();
        }, 3);
    }

    private function assertVersion(Model $model, int $expectedVersion): void
    {
        if ((int) $model->getAttribute('version') !== $expectedVersion) {
            throw new MonitoringPolicyVersionConflict;
        }
    }

    private function assertActive(Model $model): void
    {
        if (! (bool) $model->getAttribute('is_active')) {
            $this->fail('policy', 'Only an active monitoring policy can be changed.');
        }
    }

    private function assertInactive(Model $model): void
    {
        if ((bool) $model->getAttribute('is_active')) {
            $this->fail('policy', 'Only an inactive monitoring policy can be reactivated.');
        }
    }

    private function lockActiveRetentionAttachment(mixed $retentionPolicyId): ?MonitoringRetentionPolicy
    {
        if ($retentionPolicyId === null) {
            return null;
        }

        $policy = MonitoringRetentionPolicy::query()
            ->whereKey((int) $retentionPolicyId)
            ->lockForUpdate()
            ->first();
        if ($policy === null || ! $policy->is_active) {
            $this->fail('retention_policy_id', 'The native monitoring retention policy is unavailable.');
        }

        return $policy;
    }

    private function rejectExistingCoverageIdentity(string $identity): void
    {
        $existing = MonitoringCoverageExpectation::query()
            ->where('identity_key', $identity)
            ->lockForUpdate()
            ->first();
        if ($existing === null) {
            return;
        }

        $message = $existing->is_active
            ? 'An active coverage expectation already uses this identity.'
            : 'An inactive coverage expectation already uses this identity. Reactivate that governed record.';
        $this->fail('identity', $message);
    }

    private function rejectExistingRetentionIdentity(string $identity): void
    {
        $existing = MonitoringRetentionPolicy::query()
            ->where('identity_key', $identity)
            ->lockForUpdate()
            ->first();
        if ($existing === null) {
            return;
        }

        $message = $existing->is_active
            ? 'An active retention policy already uses this identity.'
            : 'An inactive retention policy already uses this identity. Reactivate that governed record.';
        $this->fail('identity', $message);
    }

    private function lockSite(?int $siteId): void
    {
        if ($siteId !== null) {
            Site::query()
                ->whereKey($siteId)
                ->where('is_active', true)
                ->where(fn (Builder $query) => $query->whereNull('archived')->orWhere('archived', false))
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    /** @param array<string, mixed> $values */
    private function assertMaintenanceScope(array $values): void
    {
        $siteId = (int) $values['site_id'];
        if ($values['monitor_id'] !== null) {
            $monitor = Monitor::query()->findOrFail((int) $values['monitor_id']);
            if ($this->siteResolver->resolve((int) $monitor->device_id) !== $siteId) {
                $this->fail('monitor_id', 'The monitor does not belong to the selected Site.');
            }
        }
        if ($values['device_id'] !== null
            && $this->siteResolver->resolve((int) $values['device_id']) !== $siteId) {
            $this->fail('device_id', 'The device does not belong to the selected Site.');
        }
    }

    /** @param Builder<Model> $query
     * @param  array<string, mixed>  $values
     */
    private function applyRetentionScope(Builder $query, array $values): void
    {
        match ($values['scope_kind']) {
            'application' => null,
            'site' => $query->where('site_id', $values['site_id']),
            'device' => $query->where('device_id', $values['device_id']),
            'data_class' => $query->where('data_class', $values['data_class']),
            'privacy' => $query->where('privacy_class', $values['privacy_class']),
        };
    }

    /** @param Builder<Model> $query
     * @param  array<string, mixed>  $values
     */
    private function applySnapshotRetentionScope(Builder $query, array $values): void
    {
        match ($values['scope_kind']) {
            'application', 'data_class' => null,
            'site' => $query->where('site_id', $values['site_id']),
            'device' => $query->where('device_id', $values['device_id']),
            'privacy' => $query->whereRaw('1 = 0'),
        };
    }

    /** @param array<string, mixed> $values */
    private function lockRetentionScope(array $values): void
    {
        if ($values['scope_kind'] === 'application') {
            MonitoringRetentionPolicy::query()
                ->where('scope_kind', 'application')
                ->lockForUpdate()
                ->get();
        }
        if ($values['scope_kind'] === 'site') {
            $this->lockSite((int) $values['site_id']);
        }
        if ($values['scope_kind'] === 'device') {
            $deviceId = (int) $values['device_id'];
            $siteId = $this->siteResolver->resolve($deviceId);
            $this->lockSite($siteId);
        }
    }

    /** @return array<string, mixed> */
    private function auditContext(Model $model): array
    {
        return array_filter([
            'policy_kind' => class_basename($model),
            'version' => (int) $model->getAttribute('version'),
            'site_id' => $model->getAttribute('site_id'),
            'device_id' => $model->getAttribute('device_id'),
            'state' => $model->getAttribute('status') ?? ((bool) $model->getAttribute('is_active') ? 'active' : 'inactive'),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function profileValues(MonitoringProfile $profile): array
    {
        return $profile->only([
            'name', 'description', 'interval_seconds', 'failure_confirmations', 'failure_duration_seconds',
            'recovery_confirmations', 'recovery_duration_seconds', 'stale_after_seconds', 'rising_threshold',
            'falling_threshold', 'baseline_window_seconds', 'baseline_minimum_samples',
            'baseline_deviation_multiplier', 'maintenance_policy', 'rollup_policy', 'retention_policy_id',
        ]);
    }

    /** @return array<string, mixed> */
    private function coverageValues(MonitoringCoverageExpectation $expectation): array
    {
        return [
            ...$expectation->only([
                'site_id', 'device_domain', 'device_category', 'capability', 'minimum_count', 'support_status',
            ]),
            'rationale' => (string) data_get($expectation->support_evidence, 'rationale', 'Existing governed coverage policy.'),
        ];
    }

    /** @return array<string, mixed> */
    private function dependencyValues(MonitorDependency $dependency): array
    {
        return $dependency->only(['site_id', 'upstream_monitor_id', 'downstream_monitor_id', 'confidence']);
    }

    /** @return array<string, mixed> */
    private function maintenanceValues(MonitoringMaintenanceWindow $window): array
    {
        return $window->only([
            'site_id', 'monitor_id', 'device_id', 'name', 'starts_at', 'ends_at',
            'recurrence', 'recurrence_until', 'timezone', 'reason',
        ]);
    }

    /** @return array<string, mixed> */
    private function retentionValues(MonitoringRetentionPolicy $policy): array
    {
        return $policy->only([
            'name', 'scope_kind', 'site_id', 'device_id', 'data_class', 'privacy_class',
            'raw_days', 'hourly_days', 'daily_days', 'legal_hold',
        ]);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
