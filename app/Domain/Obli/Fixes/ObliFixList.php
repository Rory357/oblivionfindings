<?php

namespace App\Domain\Obli\Fixes;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointActionPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRestartPolicy;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointServiceCatalogue;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * S&D Phase I: the fixes Obli can make (15j, pop-up 93). The list is what IT already allows today:
 * each approved Windows service operation in the endpoint action policy's catalogue, and the
 * governed computer restart. A fix is "on" for a computer only when IT turned it on for Obli there
 * (an allowance) AND that computer's existing policy allows it right now; turning one on goes
 * through today's policy update, with the turning-on person's own permissions.
 *
 * Today's governed path for these actions needs a technician, a step-up confirmation and the
 * computer's policy, so Obli never runs one by itself: a fix on the list becomes a one-off request
 * that IT approves and runs through the existing governed form (ObliFixRequests).
 */
final class ObliFixList
{
    /** Never offered to Obli, even when the deployment's catalogue lists them (O: never turn off Windows Update). */
    /** Services Obli never offers to stop: updates, protection, encryption, accounts and event logs. */
    private const NEVER_STOP = [
        'wuauserv', 'usosvc', 'waasmedicsvc', 'bits', 'cryptsvc', 'trustedinstaller', 'dosvc',
        'windefend', 'wdnissvc', 'wdnisdrv', 'sense', 'mdcoresvc', 'securityhealthservice', 'wscsvc', 'mpssvc', 'bfe', 'mpsdrv',
        'bdesvc', 'efs', 'samss', 'lsm', 'netlogon', 'vaultsvc', 'keyiso', 'appidsvc', 'eventlog', 'sgrmbroker', 'wintrust', 'cryptbase',
    ];

    public const MANAGE = 'securityDevices.obli.fixes.manage';

    /** @return array<string, array{key: string, kind: string, label: string, sub: string, icon: string, service_name: ?string, operation: ?string}> */
    public function catalogue(): array
    {
        $out = [];
        foreach (app(EndpointServiceCatalogue::class)->all() as $entry) {
            foreach ($entry['operations'] as $operation) {
                if (! self::safeServiceFix($entry['name'], $operation)) {
                    continue;
                }
                $key = 'service:'.$entry['name'].':'.$operation;
                if (strlen($key) > 64) {
                    // The tray reads fix keys of up to 64 characters.
                    continue;
                }
                $out[$key] = [
                    'key' => $key, 'kind' => 'service',
                    'label' => match ($operation) {
                        'restart' => 'Restart '.$entry['label'],
                        'start' => 'Start '.$entry['label'],
                        default => 'Stop '.$entry['label'],
                    },
                    'sub' => 'Windows service “'.$entry['name'].'”',
                    'icon' => 'cog',
                    'service_name' => $entry['name'], 'operation' => $operation,
                ];
            }
        }
        $out['restart:computer'] = [
            'key' => 'restart:computer', 'kind' => 'restart', 'label' => 'Restart the computer',
            'sub' => 'With a warning first, as IT’s restart rules say', 'icon' => 'power',
            'service_name' => null, 'operation' => null,
        ];

        return $out;
    }

    public static function safeServiceFix(string $name, string $operation): bool
    {
        return EndpointServiceCatalogue::safeName($name)
            && ! ($operation === 'stop' && in_array(strtolower($name), self::NEVER_STOP, true));
    }

    /** @return array{key: string, kind: string, label: string, sub: string, icon: string, service_name: ?string, operation: ?string}|null */
    public function definition(string $key): ?array
    {
        return $this->catalogue()[$key] ?? null;
    }

    /**
     * Whether the computer's existing policy allows this fix right now, and if not, why.
     *
     * @param  array<string, mixed>  $definition
     */
    public function policyAllows(Device $device, array $definition): ?string
    {
        if ($definition['kind'] === 'service') {
            $policies = app(EndpointActionPolicy::class);
            if (! $policies->available()) {
                return 'Service actions aren’t available on this server';
            }
            if (! $policies->current($device)['enabled']) {
                return 'This computer’s service actions are off';
            }
            if (! app(EndpointServiceCatalogue::class)->allows((string) $definition['service_name'], (string) $definition['operation'])) {
                return 'No longer on the approved list';
            }
            $agent = EndpointAgent::query()->where('device_id', $device->id)->first();
            $operations = is_array($agent?->service_actions) ? ($agent->service_actions['operations'] ?? null) : null;
            if (! is_array($operations) || ! in_array($definition['operation'], $operations, true)) {
                return 'The Oblivion service on this computer can’t do this yet';
            }

            return null;
        }
        $restart = app(EndpointRestartPolicy::class);
        if (! $restart->available()) {
            return 'Restarts aren’t available on this server';
        }

        return $restart->current($device)['enabled'] ? null : 'This computer’s restart rules are off';
    }

    /**
     * The fixes on for this computer right now, for the planner, the engine and the 9d tab.
     *
     * @return list<array{key: string, kind: string, label: string, sub: string, icon: string, service_name: ?string, operation: ?string}>
     */
    public function onFor(Device $device): array
    {
        if (! Schema::hasTable('obli_fix_allowances')) {
            return [];
        }
        $keys = DB::table('obli_fix_allowances')->where('device_id', $device->id)->whereNull('turned_off_at')->pluck('fix_key')->unique()->values();
        $out = [];
        foreach ($keys as $key) {
            $definition = $this->definition((string) $key);
            if ($definition !== null && $this->policyAllows($device, $definition) === null) {
                $out[] = $definition;
            }
        }

        return $out;
    }

    public function isOn(Device $device, string $key): bool
    {
        foreach ($this->onFor($device) as $definition) {
            if ($definition['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pop-up 93: turn a fix on for one computer, through today's policy update when the computer's
     * policy is off. The policy services check the person's own permissions (manage the action or
     * restart policy) and Site; nothing is written here if they refuse.
     */
    public function turnOn(User $actor, Device $device, string $key, string $reason): void
    {
        abort_unless($actor->canDo(self::MANAGE), 403);
        app(SecurityDevicesAccessService::class)->assertCanViewDevice($actor, $device);
        $definition = $this->definition($key);
        if ($definition === null) {
            throw ValidationException::withMessages(['fix' => 'That fix isn’t on the approved list.']);
        }
        $agent = EndpointAgent::query()->where('device_id', $device->id)->whereNotNull('enrolled_at')->whereNull('revoked_at')->first();
        abort_unless($agent !== null, 404);
        $policyChanged = false;
        if ($definition['kind'] === 'service') {
            $policies = app(EndpointActionPolicy::class);
            abort_unless($policies->available(), 422, 'Service actions aren’t available on this server.');
            $current = DB::transaction(fn (): array => $policies->current($device));
            if (! $current['enabled']) {
                $policies->save($actor, $device, [
                    'expected_actor_id' => (int) $actor->id, 'expected_version' => (int) $current['version'],
                    'expected_catalogue_digest' => app(EndpointServiceCatalogue::class)->digest(),
                    'enabled' => true, 'consent_mode' => 'attended', 'reason' => $reason,
                ]);
                $policyChanged = true;
            }
        } else {
            $restart = app(EndpointRestartPolicy::class);
            abort_unless($restart->available(), 422, 'Restarts aren’t available on this server.');
            $current = DB::transaction(fn (): array => $restart->current($device));
            if (! $current['enabled']) {
                $restart->save($actor, $device, [
                    'expected_actor_id' => (int) $actor->id, 'expected_version' => (int) $current['version'],
                    'enabled' => true, 'consent_mode' => 'attended', 'minimum_warning_seconds' => max(60, (int) $current['minimum_warning_seconds']), 'reason' => $reason,
                ]);
                $policyChanged = true;
            }
        }

        DB::transaction(function () use ($actor, $device, $key, $reason, $agent, $policyChanged): void {
            Device::query()->whereKey($device->id)->lockForUpdate()->first();
            $exists = DB::table('obli_fix_allowances')->where('device_id', $device->id)->where('fix_key', $key)->whereNull('turned_off_at')->exists();
            if (! $exists) {
                DB::table('obli_fix_allowances')->insert([
                    'fix_key' => $key, 'device_id' => $device->id, 'turned_on_by_user_id' => $actor->id,
                    'turned_on_at' => now(), 'reason' => mb_substr($reason, 0, 500), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            AuditLogger::logOrFail('obli.fix.turned_on', null, [
                'actor_id' => (int) $actor->id, 'fix_key' => $key, 'device_id' => (int) $device->id,
                'site_id' => (int) $agent->site_id, 'reason' => mb_substr($reason, 0, 500), 'policy_changed' => $policyChanged,
            ]);
        });
    }

    /** Turn a fix off for one computer. The computer's own policy is left as it is (other IT work may use it). */
    public function turnOff(User $actor, Device $device, string $key): void
    {
        abort_unless($actor->canDo(self::MANAGE), 403);
        app(SecurityDevicesAccessService::class)->assertCanViewDevice($actor, $device);
        DB::transaction(function () use ($actor, $device, $key): void {
            $changed = DB::table('obli_fix_allowances')->where('device_id', $device->id)->where('fix_key', $key)->whereNull('turned_off_at')
                ->update(['turned_off_at' => now(), 'turned_off_by_user_id' => $actor->id, 'updated_at' => now()]);
            abort_if($changed === 0, 404);
            AuditLogger::logOrFail('obli.fix.turned_off', null, [
                'actor_id' => (int) $actor->id, 'fix_key' => $key, 'device_id' => (int) $device->id,
                'site_id' => (int) (EndpointAgent::query()->where('device_id', $device->id)->value('site_id') ?? 0),
            ]);
        });
    }

    /**
     * Computers with Obli the viewer can see (pop-up 93's picker and 15j's "where").
     *
     * @return list<array{id: int, name: string, site: string|null}>
     */
    public function computers(User $viewer): array
    {
        $visible = app(SecurityDevicesAccessService::class)->visibleDevices($viewer)->select('devices.id');
        $agents = EndpointAgent::query()
            ->whereNotNull('enrolled_at')->whereNull('revoked_at')
            ->whereIn('device_id', $visible)
            ->with(['device:id,name'])
            ->limit(500)
            ->get();
        $sites = DB::table('sites')->whereIn('id', $agents->pluck('site_id')->unique()->all())->pluck('name', 'id');

        return $agents
            ->filter(fn (EndpointAgent $agent): bool => $agent->device !== null)
            ->map(fn (EndpointAgent $agent): array => [
                'id' => (int) $agent->device_id,
                'name' => (string) $agent->device->name,
                'site' => isset($sites[$agent->site_id]) ? (string) $sites[$agent->site_id] : null,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Setup › Computers & Obli › Fixes Obli can make (15j).
     *
     * @return array<string, mixed>
     */
    public function presentSetup(User $viewer): array
    {
        $computers = collect($this->computers($viewer))->keyBy('id');
        $since = CarbonImmutable::now()->subDays(30);
        $allowances = Schema::hasTable('obli_fix_allowances')
            ? DB::table('obli_fix_allowances')->whereNull('turned_off_at')->whereIn('device_id', $computers->keys()->all())->orderByDesc('turned_on_at')->get()
            : collect();
        $requests = Schema::hasTable('obli_fix_requests')
            ? DB::table('obli_fix_requests')->where('kind', 'fix')->where('asked_at', '>=', $since)->whereIn('device_id', $computers->keys()->all())->get(['fix_key', 'command_result', 'state'])
            : collect();
        $names = DB::table('users')->whereIn('id', $allowances->pluck('turned_on_by_user_id')->unique()->all())->pluck('name', 'id');
        $devices = Device::query()->whereIn('id', $allowances->pluck('device_id')->unique()->all())->get()->keyBy('id');
        $servicesOn = app(EndpointActionPolicy::class)->available();
        $restartsOn = app(EndpointRestartPolicy::class)->available();

        $rows = [];
        foreach ($this->catalogue() as $key => $definition) {
            $mine = $allowances->where('fix_key', $key)->values();
            $where = $mine->map(function (object $row) use ($computers, $devices, $definition, $names): array {
                $device = $devices->get($row->device_id);
                $blocked = $device instanceof Device ? $this->policyAllows($device, $definition) : 'Not available';

                return [
                    'device_id' => (int) $row->device_id,
                    'name' => (string) ($computers->get($row->device_id)['name'] ?? 'A computer'),
                    'site' => $computers->get($row->device_id)['site'] ?? null,
                    'by' => (string) ($names[$row->turned_on_by_user_id] ?? 'Someone who has left'),
                    'at' => CarbonImmutable::parse($row->turned_on_at)->toIso8601String(),
                    'blocked' => $blocked,
                ];
            })->all();
            $working = array_values(array_filter($where, fn (array $place): bool => $place['blocked'] === null));
            $asked = $requests->where('fix_key', $key);
            $available = $definition['kind'] === 'service' ? $servicesOn : $restartsOn;
            $rows[] = [
                'key' => $key,
                'label' => $definition['label'],
                'sub' => $definition['sub'],
                'icon' => $definition['icon'],
                'runs_as' => 'Through the Oblivion service',
                'turned_on' => $where === [] ? null : ['by' => $where[0]['by'], 'at' => $where[0]['at']],
                'where' => $where,
                'used' => $asked->isEmpty() ? null : ['asked' => $asked->count(), 'ran' => $asked->where('command_result', 'succeeded')->count()],
                'state' => match (true) {
                    ! $available => ['label' => 'Not available', 'tone' => 'neutral', 'note' => $definition['kind'] === 'service' ? 'Service actions aren’t switched on for this server' : 'Restarts aren’t switched on for this server'],
                    $working !== [] => ['label' => 'On', 'tone' => 'success', 'note' => count($working) === 1 ? $working[0]['name'] : count($working).' computers'],
                    $where !== [] => ['label' => 'Needs IT review', 'tone' => 'warning', 'note' => $where[0]['blocked']],
                    default => ['label' => 'Off', 'tone' => 'neutral', 'note' => null],
                },
                'can_turn_on' => $available && $viewer->canDo(self::MANAGE),
                'script' => $definition['kind'] === 'service'
                    ? ['# Asks the person at the computer first (attended)', 'SCM> '.$definition['operation'].' "'.$definition['service_name'].'"']
                    : ['# Asks the person at the computer first (attended)', 'SCM> restart computer, with the warning IT set'],
            ];
        }

        return [
            'rows' => $rows,
            'computers' => array_values($computers->all()),
            'can_manage' => $viewer->canDo(self::MANAGE),
        ];
    }
}
