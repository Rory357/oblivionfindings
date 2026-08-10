<?php

namespace App\Domain\SecurityDevices\AccessControl\Services;

use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlScheduleRevision;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogger;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class AccessControlScheduleTransitionService
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function recordProviderTransition(
        User $actor,
        AccessControlSchedule $schedule,
        string $targetStatus,
        string $providerRequestKey,
        string $providerEventKey,
        ?string $failureReason = null,
        ?DateTimeInterface $occurredAt = null,
    ): AccessControlSchedule {
        abort_unless($actor->canDo('securityDevices.accessControl.manage'), 403);
        $this->assertSafeEvidenceKey($providerRequestKey, 'provider request reference');
        $this->assertSafeEvidenceKey($providerEventKey, 'provider event reference');
        if (! in_array($targetStatus, [
            AccessControlSchedule::RECONCILIATION_PENDING,
            AccessControlSchedule::RECONCILIATION_FAILED,
            AccessControlSchedule::RECONCILIATION_RECONCILED,
        ], true)) {
            throw new UnexpectedValueException('Schedule provider transition state is not recognised.');
        }

        $siteId = (int) $schedule->site_id;
        $this->access->assertCanViewSite($actor, $siteId);

        return DB::transaction(function () use (
            $actor,
            $schedule,
            $targetStatus,
            $providerRequestKey,
            $providerEventKey,
            $failureReason,
            $occurredAt,
            $siteId,
        ): AccessControlSchedule {
            $site = Site::query()
                ->whereKey($siteId)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();
            abort_unless($site, 404);
            $this->access->assertCanViewSite($actor, $siteId);

            $locked = AccessControlSchedule::query()
                ->whereKey($schedule->getKey())
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->firstOrFail();

            $duplicate = AccessControlScheduleRevision::query()
                ->where('provider_event_key', $providerEventKey)
                ->lockForUpdate()
                ->first();
            if ($duplicate !== null) {
                if ((int) $duplicate->access_schedule_id !== (int) $locked->getKey()
                    || $duplicate->provider_request_key !== $providerRequestKey
                    || data_get($duplicate->snapshot, 'provider_reconciliation_status') !== $targetStatus
                    || ($targetStatus === AccessControlSchedule::RECONCILIATION_FAILED
                        && data_get($duplicate->snapshot, 'provider_reconciliation_failure_reason') !== trim((string) $failureReason))) {
                    throw new UnexpectedValueException('Schedule provider event reference was already recorded for different evidence.');
                }

                return $locked->refresh()->load('revisions');
            }

            $sourceStatus = (string) $locked->provider_reconciliation_status;
            if ($targetStatus === AccessControlSchedule::RECONCILIATION_PENDING) {
                if (! in_array($sourceStatus, [
                    AccessControlSchedule::RECONCILIATION_REQUIRED,
                    AccessControlSchedule::RECONCILIATION_FAILED,
                ], true)) {
                    throw new UnexpectedValueException('Schedule provider request does not follow the current reconciliation state.');
                }
            } elseif ($sourceStatus !== AccessControlSchedule::RECONCILIATION_PENDING
                || $locked->provider_reconciliation_request_key !== $providerRequestKey) {
                throw new UnexpectedValueException('Schedule provider response is stale or does not match the active request.');
            }

            $eventOccurredAt = $occurredAt ?? now();
            $nextVersion = (int) $locked->version + 1;
            $activeCredentials = $this->lockActiveCredentialCount($locked);
            $cleanFailureReason = trim((string) $failureReason);
            $locked->forceFill([
                'version' => $nextVersion,
                'provider_reconciliation_status' => $targetStatus,
                'provider_reconciliation_request_key' => $providerRequestKey,
                'provider_reconciliation_event_key' => $providerEventKey,
                'provider_reconciliation_confirmed_at' => $targetStatus === AccessControlSchedule::RECONCILIATION_RECONCILED
                    ? $eventOccurredAt
                    : null,
                'provider_reconciliation_failure_reason' => $targetStatus === AccessControlSchedule::RECONCILIATION_FAILED
                    ? $cleanFailureReason
                    : null,
            ]);
            AccessControlSchedule::assertTruthfulProviderState($locked);

            $action = match ($targetStatus) {
                AccessControlSchedule::RECONCILIATION_PENDING => 'provider_pending',
                AccessControlSchedule::RECONCILIATION_FAILED => 'provider_failed',
                AccessControlSchedule::RECONCILIATION_RECONCILED => 'provider_reconciled',
            };
            $revision = AccessControlScheduleRevision::query()->create([
                'access_schedule_id' => $locked->getKey(),
                'site_id' => $siteId,
                'version' => $nextVersion,
                'action' => $action,
                'snapshot' => [
                    'site_id' => $siteId,
                    'name' => $locked->name,
                    'timezone' => $locked->timezone,
                    'days' => array_values($locked->days ?? []),
                    'starts_at' => $locked->starts_at,
                    'ends_at' => $locked->ends_at,
                    'is_active' => (bool) $locked->is_active,
                    'provider_reconciliation_status' => $targetStatus,
                    'provider_reconciliation_request_key' => $providerRequestKey,
                    'provider_reconciliation_event_key' => $providerEventKey,
                    'provider_reconciliation_confirmed_at' => $locked->provider_reconciliation_confirmed_at?->toIso8601String(),
                    'provider_reconciliation_failure_reason' => $locked->provider_reconciliation_failure_reason,
                ],
                'change_reason' => match ($targetStatus) {
                    AccessControlSchedule::RECONCILIATION_PENDING => 'Provider reconciliation request accepted for processing.',
                    AccessControlSchedule::RECONCILIATION_FAILED => 'Provider reconciliation failed: '.$cleanFailureReason,
                    AccessControlSchedule::RECONCILIATION_RECONCILED => 'Provider reconciliation evidence confirmed the schedule.',
                },
                'active_credentials_affected' => $activeCredentials,
                'provider_confirmed_credentials_affected' => $activeCredentials,
                'provider_request_key' => $providerRequestKey,
                'provider_event_key' => $providerEventKey,
                'provider_confirmed' => $targetStatus === AccessControlSchedule::RECONCILIATION_RECONCILED,
                'recorded_by_user_id' => $actor->getKey(),
                'created_at' => now(),
            ]);

            $locked->saveQuietly();
            AuditLogger::logOrFail('access_control.schedule.provider_transition', $locked, [
                'actor_id' => (int) $actor->getKey(),
                'site_id' => $siteId,
                'schedule_revision_id' => (int) $revision->getKey(),
                'provider_reconciliation_status' => $targetStatus,
                'provider_request_key' => $providerRequestKey,
                'provider_event_key' => $providerEventKey,
                'active_credentials_affected' => $activeCredentials,
            ]);

            return $locked->refresh()->load('revisions');
        });
    }

    private function assertSafeEvidenceKey(string $value, string $label): void
    {
        if ($value === '' || mb_strlen($value) > 191 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $value) !== 1) {
            throw new UnexpectedValueException(ucfirst($label).' is not a safe provider evidence reference.');
        }
    }

    private function lockActiveCredentialCount(AccessControlSchedule $schedule): int
    {
        $activeCredentialIds = DB::table('access_control_credential_device_binding_events as binding')
            ->join('access_control_credential_lifecycle_events as evidence', function ($join): void {
                $join->on('evidence.access_credential_id', '=', 'binding.access_credential_id')
                    ->on('evidence.site_id', '=', 'binding.site_id')
                    ->on('evidence.provider_request_key', '=', 'binding.provider_request_key')
                    ->on('evidence.provider_event_key', '=', 'binding.provider_event_key');
            })
            ->where('binding.site_id', $schedule->site_id)
            ->where('binding.binding_status', 'active')
            ->where('binding.provider_reconciliation_status', 'reconciled')
            ->where('binding.provider_confirmed', true)
            ->where('evidence.provider_action', 'issue')
            ->where('evidence.provider_confirmed', true)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('access_control_credential_device_binding_events as newer_binding')
                ->whereColumn('newer_binding.access_credential_id', 'binding.access_credential_id')
                ->whereColumn('newer_binding.device_id', 'binding.device_id')
                ->whereColumn('newer_binding.sequence', '>', 'binding.sequence'))
            ->distinct()
            ->pluck('binding.access_credential_id');

        return DB::table('access_control_credentials')
            ->where('access_schedule_id', $schedule->getKey())
            ->where('site_id', $schedule->site_id)
            ->whereIn('id', $activeCredentialIds)
            ->lockForUpdate()
            ->count();
    }
}
