<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\NativeMonitorCommandReceipt;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Exactly one configuration mutation, then read-only recovery under current authority. */
final readonly class NativeMonitorCommandService
{
    public function __construct(
        private NativeMonitoringDefinitionService $definitions,
        private SecurityDevicesAccessService $access,
        private CanonicalDeviceSiteResolver $sites,
    ) {}

    public function execute(User $claimed, string $operation, ?int $target, array $input): array
    {
        $this->storage();
        abort_unless(in_array($operation, ['create', 'update', 'deactivate'], true) && (($operation === 'create') === ($target === null)), 400, 'invalid_request');
        $uuid = strtolower((string) ($input['request_uuid'] ?? Str::uuid()));
        $expectedActor = (int) ($input['expected_actor_id'] ?? $claimed->id);
        $attributes = Arr::except($input, ['request_uuid', 'expected_actor_id', 'expected_site_id', 'expected_device_id', 'review_token']);
        foreach (['device_id', 'profile_id', 'port', 'warn_days'] as $key) {
            if (isset($attributes[$key])) {
                $attributes[$key] = (int) $attributes[$key];
            }
        }
        if (isset($attributes['affects_availability'])) {
            $attributes['affects_availability'] = (bool) $attributes['affects_availability'];
        }
        if (isset($attributes['expected_status'])) {
            $attributes['expected_status'] = array_map('intval', $attributes['expected_status']);
        }
        $hash = $this->fingerprint([$operation, $target, $attributes,
            isset($input['expected_site_id']) ? (int) $input['expected_site_id'] : null,
            isset($input['expected_device_id']) ? (int) $input['expected_device_id'] : null, $input['review_token'] ?? null]);
        $probe = NativeMonitorCommandReceipt::query()->useWritePdo()->where('request_uuid', $uuid)->first();
        $mutex = $target ?? $probe?->monitor_id;
        $deviceId = (int) ($input['device_id'] ?? $probe?->device_id ?? 0);

        return $this->within($claimed, $expectedActor, $mutex, $deviceId, function (User $actor, CurrentAuthorizationReads $reads, Device $device, ?Monitor $monitor, int $site) use ($input, $uuid, $operation, $target, $attributes, $hash): array {
            $this->expectedScope($input, $device, $site);
            $receipt = NativeMonitorCommandReceipt::query()->useWritePdo()->where('request_uuid', $uuid)->lockForUpdate()->first();
            if ($receipt) {
                abort_unless($receipt->actor_user_id === (int) $actor->id, 404, 'unavailable');
                abort_unless($receipt->operation === $operation && $receipt->target_monitor_id === $target && hash_equals($receipt->request_hash, $hash), 409, 'This request already belongs to a different monitor change.');

                return $this->present($receipt, $actor, $reads, $device, $monitor, $site);
            }
            abort_unless($operation === 'create' || ($monitor && (int) $monitor->id === $target), 404, 'unavailable');
            if ($monitor && isset($input['review_token'])) {
                abort_unless(hash_equals($this->reviewToken($monitor, $site), $input['review_token']), 409, 'This monitor changed after it was reviewed. Reopen the current settings.');
            }
            $reserved = ['request_uuid' => $uuid, 'actor_user_id' => $actor->id, 'operation' => $operation,
                'request_hash' => $hash, 'target_monitor_id' => $target, 'device_id' => $device->id,
                'site_id' => $site, 'status' => 'reserved', 'created_at' => now()];
            if (DB::table('native_monitor_command_receipts')->insertOrIgnore($reserved) === 0) {
                // A competing binding appeared while waiting on the unique request identity.
                $receipt = NativeMonitorCommandReceipt::query()->useWritePdo()->where('request_uuid', $uuid)->lockForUpdate()->firstOrFail();
                abort_unless($receipt->actor_user_id === (int) $actor->id, 404, 'unavailable');
                abort_unless($receipt->operation === $operation && $receipt->target_monitor_id === $target && hash_equals($receipt->request_hash, $hash), 409, 'This request already belongs to a different monitor change.');

                return $this->present($receipt, $actor, $reads, $device, $monitor, $site);
            }
            $saved = match ($operation) {
                'create' => $this->definitions->createMonitor($actor, $device, $attributes),
                'update' => $this->definitions->updateMonitor($actor, $monitor, $attributes),
                'deactivate' => $this->definitions->deactivateMonitor($actor, $monitor, (string) $attributes['reason_code']),
            };
            $receipt = NativeMonitorCommandReceipt::query()->useWritePdo()->where('request_uuid', $uuid)->lockForUpdate()->firstOrFail();
            $receipt->forceFill(['status' => 'committed', 'monitor_id' => $saved->id,
                'saved_snapshot' => $this->safeMonitor($saved), 'definition_hash' => $this->definitionHash($saved),
                'committed_at' => now()])->save();

            return $this->present($receipt, $actor, $reads, $device, $saved, $site);
        });
    }

    public function recover(User $claimed, string $uuid, array $input): array
    {
        $this->storage();
        $probe = NativeMonitorCommandReceipt::query()->useWritePdo()->where('request_uuid', strtolower($uuid))->first();
        abort_unless($probe && $probe->actor_user_id === (int) $claimed->id, 404, 'unavailable');

        return $this->within($claimed, (int) $input['expected_actor_id'], $probe->monitor_id, $probe->device_id,
            function (User $actor, CurrentAuthorizationReads $reads, Device $device, ?Monitor $monitor, int $site) use ($uuid, $input): array {
                $this->expectedScope($input, $device, $site);
                $receipt = $reads->query(NativeMonitorCommandReceipt::query()->useWritePdo())->where('request_uuid', strtolower($uuid))->firstOrFail();

                return $this->present($receipt, $actor, $reads, $device, $monitor, $site);
            });
    }

    /** Bounded, paginated owner history; each result passes the same current recovery fences. */
    public function savedRequests(User $claimed, int $expectedActor, ?int $beforeId): array
    {
        $this->storage();

        return DB::transaction(function () use ($claimed, $expectedActor, $beforeId): array {
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $claimed->id, ['*']);
            $this->assertManager($actor, $expectedActor);
            $candidates = NativeMonitorCommandReceipt::query()->useWritePdo()
                ->where('actor_user_id', $actor->id)->where('status', 'committed')
                ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
                ->orderByDesc('id')->limit(26)->get(['id', 'request_uuid', 'site_id', 'device_id']);
            $page = $candidates->take(25);
            $rows = [];
            foreach ($page as $candidate) {
                try {
                    $rows[] = $this->recover($actor, $candidate->request_uuid, [
                        'expected_actor_id' => $actor->id, 'expected_site_id' => $candidate->site_id,
                        'expected_device_id' => $candidate->device_id,
                    ]);
                } catch (HttpException $error) {
                    if ($error->getStatusCode() !== 404) {
                        throw $error;
                    }
                } catch (ModelNotFoundException) {
                    // An unavailable current record cannot become a historical access grant.
                }
            }

            return ['actor_id' => (int) $actor->id, 'rows' => $rows,
                'next_before_id' => $candidates->count() > 25 ? (int) $page->last()->id : null];
        }, 3);
    }

    private function assertManager(User $actor, int $expectedActor): void
    {
        abort_unless((int) $actor->id === $expectedActor && $actor->approved_at && $actor->email_verified_at && ! $actor->external_clinical_account, 404, 'unavailable');
        foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
            abort_if($actor->role === $role || $actor->hasRole($role), 404, 'unavailable');
        }
        abort_unless($actor->canDo('securityDevices.monitoring.manage'), 404, 'unavailable');
    }

    private function within(User $claimed, int $expectedActor, ?int $monitorId, int $deviceId, callable $action): array
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($claimed, $expectedActor, $monitorId, $deviceId, $action): array {
            $monitor = $monitorId === null ? null : Monitor::query()->useWritePdo()->whereKey($monitorId)
                ->whereNull('collector_id')->whereIn('kind', NativeMonitoringDefinitionService::directKindValues())->lockForUpdate()->firstOrFail();
            $device = Device::query()->useWritePdo()->whereKey($monitor?->device_id ?? $deviceId)->lockForUpdate()->firstOrFail();
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $claimed->id, ['*']);
            $this->assertManager($actor, $expectedActor);
            $scope = $this->access->forCurrentEvidence($reads, (int) $device->id);
            $scope->assertCanViewDevice($actor, $device);
            $site = $this->sites->forCurrentEvidence($reads)->resolve((int) $device->id);
            abort_unless($site, 404, 'unavailable');
            $scope->assertCanViewSite($actor, $site);

            return $action($actor, $reads, $device, $monitor, $site);
        }), 3);
    }

    private function expectedScope(array $input, Device $device, int $site): void
    {
        if (isset($input['expected_device_id'])) {
            abort_unless((int) $input['expected_device_id'] === (int) $device->id, 404, 'unavailable');
        }
        if (isset($input['expected_site_id'])) {
            abort_unless((int) $input['expected_site_id'] === $site, 404, 'unavailable');
        }
    }

    private function present(NativeMonitorCommandReceipt $receipt, User $actor, CurrentAuthorizationReads $reads, Device $device, ?Monitor $monitor, int $site): array
    {
        abort_unless($receipt->actor_user_id === (int) $actor->id && $receipt->device_id === (int) $device->id && $receipt->site_id === $site, 404, 'unavailable');
        abort_unless($receipt->status === 'committed' && $receipt->monitor_id && $receipt->saved_snapshot && $receipt->committed_at, 503, 'The monitor save is not confirmed.');
        if (! $monitor || (int) $monitor->id !== $receipt->monitor_id) {
            $monitor = $reads->query(Monitor::query()->useWritePdo())->whereKey($receipt->monitor_id)->firstOrFail();
        }
        abort_unless((int) $monitor->device_id === (int) $device->id && $monitor->collector_id === null, 404, 'unavailable');

        return ['monitor' => $receipt->saved_snapshot, 'current_monitor' => [...$this->safeMonitor($monitor), 'review_token' => $this->reviewToken($monitor, $site)],
            'receipt' => ['schema_version' => 1, 'owner' => 'Native monitoring', 'request_uuid' => $receipt->request_uuid,
                'actor_id' => $receipt->actor_user_id, 'operation' => $receipt->operation,
                'target_monitor_id' => $receipt->target_monitor_id, 'monitor_id' => $receipt->monitor_id,
                'device_id' => $receipt->device_id, 'site_id' => $receipt->site_id,
                'committed_at' => $receipt->committed_at->utc()->toIso8601String(),
                'current_matches_saved' => hash_equals($receipt->definition_hash, $this->definitionHash($monitor)),
                'confirms' => 'configuration_saved', 'confirms_observation' => false]];
    }

    public function safeMonitor(Monitor $monitor): array
    {
        return ['id' => (int) $monitor->id, 'name' => $monitor->name,
            'kind' => $monitor->kind?->value ?? (string) $monitor->kind, 'device_id' => (int) $monitor->device_id,
            'profile_id' => (int) $monitor->profile_id, 'collection_mode' => 'central_direct',
            'enabled' => (bool) $monitor->is_enabled, 'affects_availability' => (bool) $monitor->affects_availability];
    }

    /** Opaque optimistic token. It is never an authorization credential or a public definition hash. */
    public function reviewToken(Monitor $monitor, int $site): string
    {
        $key = config('app.key');
        abort_unless(is_string($key) && $key !== '', 503, 'Monitor review needs a configured application key.');

        return hash_hmac('sha256', json_encode(['native_monitor_review_v1', $site, $this->definitionHash($monitor)], JSON_THROW_ON_ERROR), $key);
    }

    private function definitionHash(Monitor $monitor): string
    {
        return $this->fingerprint([$this->safeMonitor($monitor), $monitor->target, $monitor->config]);
    }

    private function fingerprint(mixed $value): string
    {
        $normalise = function (mixed $item) use (&$normalise): mixed {
            if (! is_array($item)) {
                return $item;
            }
            $result = array_map($normalise, $item);
            if (! array_is_list($result)) {
                ksort($result);
            }

            return $result;
        };

        return hash('sha256', json_encode($normalise($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function storage(): void
    {
        abort_unless(Schema::hasTable('native_monitor_command_receipts'), 503, 'Monitor save confirmation needs an application update.');
    }
}
