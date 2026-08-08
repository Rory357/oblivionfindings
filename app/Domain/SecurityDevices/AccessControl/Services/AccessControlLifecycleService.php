<?php

namespace App\Domain\SecurityDevices\AccessControl\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlScheduleRevision;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessControlLifecycleService
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    /** @param array<string, mixed> $data */
    public function createSchedule(User $actor, array $data): AccessControlSchedule
    {
        $this->assertCanManage($actor);
        $siteId = (int) $data['site_id'];
        $this->access->assertCanViewSite($actor, $siteId);

        return DB::transaction(function () use ($actor, $data, $siteId): AccessControlSchedule {
            $operationalSite = Site::query()
                ->whereKey($siteId)
                ->where('is_active', true)
                ->where('archived', false)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();
            abort_unless($operationalSite, 404);
            $this->access->assertCanViewSite($actor, $siteId);

            $schedule = AccessControlSchedule::query()->create([
                'site_id' => $siteId,
                'name' => trim((string) $data['name']),
                'timezone' => (string) config('app.worker_timezone', 'Pacific/Auckland'),
                'days' => array_values($data['days']),
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'is_active' => true,
                'version' => 1,
                'provider_reconciliation_status' => 'required',
                'provider_reconciliation_required_at' => now(),
                'created_by_user_id' => $actor->getKey(),
            ]);

            $this->recordScheduleRevision(
                $schedule,
                $actor,
                'created',
                'Initial schedule creation.',
                0,
            );

            AuditLogger::logOrFail('access_control.schedule.created', $schedule, [
                'actor_id' => (int) $actor->getKey(),
                'site_id' => $siteId,
                'version' => 1,
                'provider_reconciliation_status' => 'required',
            ]);

            return $schedule;
        });
    }

    /** @param array<string, mixed> $data */
    public function updateSchedule(User $actor, AccessControlSchedule $schedule, array $data): AccessControlSchedule
    {
        $this->assertCanManage($actor);
        $siteId = (int) $schedule->site_id;
        $this->access->assertCanViewSite($actor, $siteId);

        return DB::transaction(function () use ($actor, $schedule, $data, $siteId): AccessControlSchedule {
            $this->lockOperationalSite($actor, $siteId);
            $locked = AccessControlSchedule::query()
                ->whereKey($schedule->getKey())
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($locked->is_active, 409, 'Inactive access schedules cannot be changed.');
            $this->assertExpectedVersion($locked, (int) $data['expected_version']);
            $activeCredentials = $this->lockActiveCredentials($locked);
            $this->assertImpactConfirmation($data, 'UPDATE', $activeCredentials);
            $reason = trim((string) $data['reason']);
            $nextVersion = (int) $locked->version + 1;
            $now = now();

            $locked->forceFill([
                'name' => trim((string) $data['name']),
                'days' => array_values($data['days']),
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'version' => $nextVersion,
                'provider_reconciliation_status' => 'required',
                'provider_reconciliation_request_key' => null,
                'provider_reconciliation_event_key' => null,
                'provider_reconciliation_confirmed_at' => null,
                'provider_reconciliation_failure_reason' => null,
                'provider_reconciliation_required_at' => $now,
            ]);
            AccessControlSchedule::assertTruthfulProviderState($locked);
            $locked->saveQuietly();

            $this->recordScheduleRevision($locked, $actor, 'updated', $reason, $activeCredentials);
            AuditLogger::logOrFail('access_control.schedule.updated', $locked, [
                'actor_id' => (int) $actor->getKey(),
                'site_id' => (int) $locked->site_id,
                'version' => $nextVersion,
                'active_credentials_affected' => $activeCredentials,
                'provider_reconciliation_status' => 'required',
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function deactivateSchedule(User $actor, AccessControlSchedule $schedule, array $data): AccessControlSchedule
    {
        $this->assertCanManage($actor);
        $siteId = (int) $schedule->site_id;
        $this->access->assertCanViewSite($actor, $siteId);

        return DB::transaction(function () use ($actor, $schedule, $data, $siteId): AccessControlSchedule {
            $this->lockOperationalSite($actor, $siteId);
            $locked = AccessControlSchedule::query()
                ->whereKey($schedule->getKey())
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($locked->is_active, 409, 'This access schedule is already inactive.');
            $this->assertExpectedVersion($locked, (int) $data['expected_version']);
            $activeCredentials = $this->lockActiveCredentials($locked);
            $this->assertImpactConfirmation($data, 'DEACTIVATE', $activeCredentials);
            $reason = trim((string) $data['reason']);
            $nextVersion = (int) $locked->version + 1;
            $now = now();

            $locked->forceFill([
                'is_active' => false,
                'version' => $nextVersion,
                'provider_reconciliation_status' => 'required',
                'provider_reconciliation_request_key' => null,
                'provider_reconciliation_event_key' => null,
                'provider_reconciliation_confirmed_at' => null,
                'provider_reconciliation_failure_reason' => null,
                'provider_reconciliation_required_at' => $now,
                'deactivated_at' => $now,
                'deactivated_by_user_id' => $actor->getKey(),
                'deactivation_reason' => $reason,
            ]);
            AccessControlSchedule::assertTruthfulProviderState($locked);
            $locked->saveQuietly();

            $this->recordScheduleRevision($locked, $actor, 'deactivated', $reason, $activeCredentials);
            AuditLogger::logOrFail('access_control.schedule.deactivated', $locked, [
                'actor_id' => (int) $actor->getKey(),
                'site_id' => (int) $locked->site_id,
                'version' => $nextVersion,
                'active_credentials_affected' => $activeCredentials,
                'provider_reconciliation_status' => 'required',
                'reason' => $reason,
            ]);

            return $locked;
        });
    }

    /** @param array<string, mixed> $data */
    public function issueCredential(User $actor, array $data): AccessControlCredential
    {
        $this->assertCanManage($actor);
        $siteId = (int) $data['site_id'];
        $this->access->assertCanViewSite($actor, $siteId);
        $schedule = AccessControlSchedule::query()
            ->whereKey((int) $data['access_schedule_id'])
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->first();
        abort_unless($schedule, 404);

        $this->assertHolderAtSite($actor, (string) $data['holder_type'], (int) $data['holder_id'], $siteId);
        $this->authorisedAccessDevices($actor, $data['device_ids'], $siteId);

        throw ValidationException::withMessages([
            'provider_action' => 'Credential issue is unavailable because no approved provider execution and reconciliation adapter is connected. No access was granted and no local credential was created.',
        ]);
    }

    public function revokeCredential(User $actor, AccessControlCredential $credential, string $reason): AccessControlCredential
    {
        $this->assertCanManage($actor);
        $this->access->assertCanViewSite($actor, (int) $credential->site_id);

        throw ValidationException::withMessages([
            'provider_action' => 'Credential revocation is unavailable because no approved provider execution and reconciliation adapter is connected. No provider access was changed.',
        ]);
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless($actor->canDo('securityDevices.accessControl.manage'), 403);
    }

    private function assertExpectedVersion(AccessControlSchedule $schedule, int $expectedVersion): void
    {
        if ((int) $schedule->version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'expected_version' => 'This schedule changed after you opened it. Review the current version before trying again.',
            ]);
        }
    }

    private function lockActiveCredentials(AccessControlSchedule $schedule): int
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
            ->where('binding.provider_reconciliation_status', AccessControlCredential::RECONCILIATION_RECONCILED)
            ->where('binding.provider_confirmed', true)
            ->where('evidence.provider_action', AccessControlCredential::PROVIDER_ACTION_ISSUE)
            ->where('evidence.provider_confirmed', true)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('access_control_credential_device_binding_events as newer_binding')
                ->whereColumn('newer_binding.access_credential_id', 'binding.access_credential_id')
                ->whereColumn('newer_binding.device_id', 'binding.device_id')
                ->whereColumn('newer_binding.sequence', '>', 'binding.sequence'))
            ->distinct()
            ->pluck('binding.access_credential_id');

        return $schedule->credentials()
            ->where('site_id', $schedule->site_id)
            ->whereKey($activeCredentialIds)
            ->lockForUpdate()
            ->get(['access_control_credentials.id'])
            ->count();
    }

    /** @param array<string, mixed> $data */
    private function assertImpactConfirmation(array $data, string $action, int $activeCredentials): void
    {
        $confirmedCount = $data['confirmed_active_credentials'] ?? null;
        if ($confirmedCount !== null && (int) $confirmedCount !== $activeCredentials) {
            throw ValidationException::withMessages([
                'confirmation_text' => 'The active-credential impact changed. Review the current preview before trying again.',
            ]);
        }

        if ($activeCredentials === 0) {
            return;
        }

        $expected = $action.' '.$activeCredentials;
        $confirmation = trim((string) ($data['confirmation_text'] ?? ''));
        if ($confirmedCount === null || ! hash_equals($expected, $confirmation)) {
            throw ValidationException::withMessages([
                'confirmation_text' => "The impact changed or the confirmation did not match. Review the preview and type {$expected} exactly.",
            ]);
        }
    }

    private function recordScheduleRevision(
        AccessControlSchedule $schedule,
        User $actor,
        string $action,
        string $reason,
        int $activeCredentials,
    ): void {
        AccessControlScheduleRevision::query()->create([
            'access_schedule_id' => $schedule->getKey(),
            'site_id' => (int) $schedule->site_id,
            'version' => (int) $schedule->version,
            'action' => $action,
            'snapshot' => [
                'site_id' => (int) $schedule->site_id,
                'name' => $schedule->name,
                'timezone' => $schedule->timezone,
                'days' => array_values($schedule->days ?? []),
                'starts_at' => $schedule->starts_at,
                'ends_at' => $schedule->ends_at,
                'is_active' => (bool) $schedule->is_active,
                'provider_reconciliation_status' => $schedule->provider_reconciliation_status,
                'deactivated_at' => $schedule->deactivated_at?->toIso8601String(),
            ],
            'change_reason' => $reason,
            'active_credentials_affected' => $activeCredentials,
            'provider_confirmed_credentials_affected' => $activeCredentials,
            'provider_request_key' => $schedule->provider_reconciliation_request_key,
            'provider_event_key' => $schedule->provider_reconciliation_event_key,
            'provider_confirmed' => $schedule->provider_reconciliation_status === AccessControlSchedule::RECONCILIATION_RECONCILED,
            'recorded_by_user_id' => $actor->getKey(),
            'created_at' => now(),
        ]);
    }

    private function assertHolderAtSite(User $actor, string $holderType, int $holderId, int $siteId): void
    {
        if ($holderType === AccessControlCredential::HOLDER_CLIENT) {
            $client = $this->access->assignableClient($actor, $holderId);
            abort_unless($client instanceof Client && (int) $client->site_id === $siteId, 404);

            return;
        }

        if ($holderType === AccessControlCredential::HOLDER_STAFF) {
            $staff = $this->access->assignableStaffMember($actor, $holderId);
            $profile = $staff?->hrEmployeeProfile()
                ->where('is_active', true)
                ->where(fn ($query) => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
                ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
                ->atSite($siteId)
                ->first();
            abort_unless($profile instanceof HrEmployeeProfile, 404);

            return;
        }

        abort(404);
    }

    /** @param array<int, mixed> $deviceIds */
    private function authorisedAccessDevices(User $actor, array $deviceIds, int $siteId): EloquentCollection
    {
        $ids = collect($deviceIds)->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $devices = $this->access->visibleDevices($actor)
            ->whereKey($ids)
            ->where('domain', 'security')
            ->where('category', 'access_control')
            ->with(['assignments' => fn ($query) => $query->active()->where('assigned_at', '<=', now())])
            ->get();

        abort_unless($devices->count() === $ids->count(), 404);
        foreach ($devices as $device) {
            abort_unless($this->effectiveSiteId($device) === $siteId, 404);
        }

        return $devices;
    }

    private function effectiveSiteId(Device $device): ?int
    {
        $assignment = $device->assignments->first();
        if (! $assignment instanceof DeviceAssignment) {
            return null;
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_SITE) {
            return (int) $assignment->assignable_id;
        }

        if ($assignment->assignable_type === DeviceAssignment::TARGET_ROOM) {
            $siteId = SiteRoom::query()->whereKey($assignment->assignable_id)->value('site_id');

            return is_numeric($siteId) ? (int) $siteId : null;
        }

        return null;
    }

    private function lockOperationalSite(User $actor, int $siteId): Site
    {
        $site = Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->lockForUpdate()
            ->first();
        abort_unless($site, 404);
        $this->access->assertCanViewSite($actor, $siteId);

        return $site;
    }
}
