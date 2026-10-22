<?php

namespace App\Domain\SecurityDevices\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\MaintenanceHistory;
use App\Domain\Monitoring\Services\MaintenancePolicyAccess;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceGroup;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnexpectedValueException;

/** Read-only adapter for one current group member; Monitoring remains the policy owner. */
final class DeviceGroupMaintenancePreview
{
    private const MONITOR_LIMIT = 50;

    private const RESOLUTION_RECORD_LIMIT = 200;

    private const RESOLUTION_BYTE_LIMIT = 262144;

    public function __construct(
        private readonly SecurityDevicesAccessService $deviceAccess,
        private readonly MaintenancePolicyAccess $policyAccess,
        private readonly MaintenanceHistory $history,
        private readonly CanonicalDeviceSiteResolver $sites,
    ) {}

    public function read(User $claimed, int $groupId, array $query, string $rawQuery): array
    {
        $values = $this->input($query, $rawQuery);

        try {
            return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($claimed, $groupId, $values): array {
                $group = $reads->query(DeviceGroup::query())->whereKey($groupId)->first();
                abort_unless($group, 404, 'unavailable');
                $member = $reads->query($group->devices()->getQuery())->select('devices.*')->where('devices.id', $values['device_id'])->first();
                abort_unless($member, 404, 'unavailable');
                try {
                    $siteId = $this->sites->forCurrentEvidence($reads)->resolve((int) $member->id);
                } catch (UnexpectedValueException) {
                    abort(404, 'unavailable');
                }

                return $this->policyAccess->within($claimed, $values['expected_actor_id'], null, $siteId, false,
                    function (User $actor, CurrentAuthorizationReads $current) use ($groupId, $values, $siteId): array {
                        $access = $this->deviceAccess->forCurrentEvidence($current, $values['device_id']);
                        abort_unless($actor->canDo('securityDevices.groups.manage') && $access->canViewAllSites($actor), 404, 'unavailable');
                        $group = $current->query(DeviceGroup::query())->whereKey($groupId)->first();
                        abort_unless($group, 404, 'unavailable');
                        $member = $current->query($group->devices()->getQuery())->select('devices.*')->where('devices.id', $values['device_id'])->first();
                        abort_unless($member, 404, 'unavailable');
                        $this->policyAccess->scope($actor, ['site_id' => $siteId, 'device_id' => (int) $member->id], $current);
                        $site = $current->query(Site::query())->whereKey($siteId)->first();
                        abort_unless($site, 404, 'unavailable');
                        $base = $this->envelope($actor, $group, $member, $site, $values['mode']);

                        if ($values['mode'] === 'choices') {
                            $monitors = $current->query(Monitor::query())->where('device_id', $member->id)->orderBy('id')->limit(self::MONITOR_LIMIT + 1)->get();

                            return [...$base, 'monitors' => $monitors->take(self::MONITOR_LIMIT)->map(fn (Monitor $monitor): array => $this->monitor($monitor))->values()->all(),
                                'monitor_limit' => self::MONITOR_LIMIT, 'truncated' => $monitors->count() > self::MONITOR_LIMIT,
                                'state' => $monitors->isEmpty() ? 'no_monitors' : 'ready'];
                        }

                        $monitor = $current->query(Monitor::query())->whereKey($values['monitor_id'])->where('device_id', $member->id)->first();
                        abort_unless($monitor, 404, 'unavailable');
                        $this->policyAccess->scope($actor, ['site_id' => $siteId, 'monitor_id' => (int) $monitor->id], $current);
                        $resolution = $this->history->resolve($monitor, $values['instant']);
                        abort_if(count($resolution['matching']) + count($resolution['overridden']) > self::RESOLUTION_RECORD_LIMIT
                            || strlen(json_encode($resolution, JSON_THROW_ON_ERROR)) > self::RESOLUTION_BYTE_LIMIT, 503, 'unavailable');

                        return [...$base, 'monitor' => $this->monitor($monitor),
                            'requested_at' => $values['instant']->format('Y-m-d\TH:i:s.u\Z'), 'resolution' => $resolution];
                    });
            }));
        } catch (QueryException) {
            throw new HttpException(503, 'unavailable');
        }
    }

    private function input(array $query, string $rawQuery): array
    {
        abort_unless(strlen($rawQuery) <= 2048 && is_string($query['mode'] ?? null) && in_array($query['mode'], ['choices', 'preview'], true), 422, 'invalid_request');
        $keys = $query['mode'] === 'choices'
            ? ['mode', 'expected_actor_id', 'device_id']
            : ['mode', 'expected_actor_id', 'device_id', 'monitor_id', 'at'];
        abort_unless(count($query) === count($keys) && array_diff(array_keys($query), $keys) === [], 422, 'invalid_request');
        $seen = [];
        foreach (explode('&', $rawQuery) as $pair) {
            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            abort_unless(count($parts) === 2 && in_array($name, $keys, true) && ! isset($seen[$name])
                && is_string($query[$name]) && urldecode($parts[1]) === $query[$name], 422, 'invalid_request');
            $seen[$name] = true;
        }
        abort_unless(count($seen) === count($keys), 422, 'invalid_request');
        $values = ['mode' => $query['mode'], 'expected_actor_id' => $this->positiveId($query['expected_actor_id']), 'device_id' => $this->positiveId($query['device_id'])];
        if ($query['mode'] === 'preview') {
            $values['monitor_id'] = $this->positiveId($query['monitor_id']);
            $values['instant'] = $this->instant($query['at']);
        }

        return $values;
    }

    private function positiveId(mixed $value): int
    {
        abort_unless(is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/', $value) === 1 && (string) (int) $value === $value, 422, 'invalid_request');

        return (int) $value;
    }

    private function instant(mixed $value): CarbonImmutable
    {
        abort_unless(is_string($value) && preg_match('/\A([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?Z\z/', $value, $parts) === 1, 422, 'invalid_request');
        $canonical = $parts[1].'.'.str_pad($parts[2] ?? '', 6, '0').'Z';
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $canonical, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        abort_unless($instant !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $instant->format('Y-m-d\TH:i:s.u\Z') === $canonical && (int) $instant->format('Y') > 0, 422, 'invalid_request');

        return CarbonImmutable::instance($instant);
    }

    private function envelope(User $actor, DeviceGroup $group, Device $device, Site $site, string $mode): array
    {
        return ['schema_version' => 1, 'mode' => $mode, 'actor_id' => (int) $actor->id,
            'as_of' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.u\Z'), 'owner' => 'Monitoring',
            'capability' => 'technical_maintenance_notification_ticket_suppression',
            'supported_dimensions' => ['site', 'device', 'monitor'], 'precedence' => ['monitor', 'device', 'site'],
            'group_inheritance' => ['supported' => false, 'reason_code' => 'group_not_policy_dimension'],
            'commits_change' => false, 'execution_authority' => false, 'provider_action' => false,
            'health_claim' => false, 'safety_response_suppression' => false,
            'group' => ['id' => (int) $group->id, 'name' => $group->name],
            'device' => ['id' => (int) $device->id, 'name' => $device->name, 'device_uid' => $device->device_uid],
            'site' => ['id' => (int) $site->id, 'name' => $site->name]];
    }

    private function monitor(Monitor $monitor): array
    {
        return ['id' => (int) $monitor->id, 'device_id' => (int) $monitor->device_id, 'name' => $monitor->name,
            'kind' => $monitor->kind?->value, 'is_enabled' => (bool) $monitor->is_enabled];
    }
}
