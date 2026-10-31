<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Management\Services\CommandAssignmentFingerprint;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnexpectedValueException;

/** Per-computer script policy. Saving configuration never dispatches a command. */
final class EndpointScriptPolicy
{
    public const MANAGE = 'securityDevices.endpoints.scriptPolicyManage';

    public const RUN = 'securityDevices.endpoints.scriptRun';

    public const UNATTENDED = 'securityDevices.endpoints.scriptUnattended';

    private const TABLE = 'endpoint_script_policy_revisions';

    public static function defaults(): array
    {
        return ['version' => 0, 'enabled' => false, 'consent_mode' => 'attended'];
    }

    public function available(): bool
    {
        // Do not derive launch availability from a release toggle alone.
        // Canonical command integration and the installed executor are unfinished.
        return false;
    }

    public function show(User $actor, Device $device, int $expected): array
    {
        return $this->within($actor, $device, $expected, fn (User $current, Device $locked): array => $this->dto($current, $locked));
    }

    public function beginIdentity(Request $request, User $actor, Device $device, int $expected): void
    {
        $this->within($actor, $device, $expected, function (User $current, Device $locked) use ($request): void {
            abort_unless($current->canDo(self::MANAGE) || $current->canDo(self::RUN), 403);
            app(EndpointScriptIdentity::class)->begin($request, $current);
            $request->session()->put('url.intended', '/it/remote-support?device='.$locked->id);
        });
    }

    public function save(User $actor, Device $device, array $input): array
    {
        $data = $this->input($input);

        return $this->within($actor, $device, $data['expected_actor_id'], function (User $current, Device $locked) use ($data): array {
            abort_unless($current->canDo(self::MANAGE) && app(EndpointScriptIdentity::class)->confirmed($current), 403);
            abort_unless(! $data['enabled'] || $data['consent_mode'] !== 'unattended' || $current->canDo(self::UNATTENDED), 403);
            $hash = $this->requestHash((int) $locked->id, $data);
            // Actor mutex + unique actor/key means a key cannot be rebound to a different computer.
            $prior = DB::table(self::TABLE)->where('actor_user_id', $current->id)->where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($prior) {
                abort_unless((int) $prior->device_id === (int) $locked->id && hash_equals($prior->request_hash, $hash), 409);
                $receipt = $this->receipt($prior);
                abort_unless(hash_equals($prior->assignment_fingerprint, $this->assignment($locked)), 404);

                return $this->dto($current, $locked) + ['receipt' => $receipt];
            }
            $before = $this->current($locked);
            abort_unless($before['policy']['version'] === $data['expected_version'] && $data['expected_version'] < 4294967295, 409);
            $receipt = [
                'device_id' => (int) $locked->id, 'actor_id' => (int) $current->id, 'request_key' => $data['request_key'],
                'assignment_fingerprint' => $this->assignment($locked),
                'policy' => ['version' => $data['expected_version'] + 1, 'enabled' => $data['enabled'], 'consent_mode' => $data['consent_mode']],
                'reason' => $data['reason'], 'recorded_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
            $json = EndpointScriptContract::encode($receipt);
            DB::table(self::TABLE)->insert([
                'device_id' => $locked->id, 'version' => $receipt['policy']['version'], 'actor_user_id' => $current->id,
                'request_key' => $data['request_key'], 'request_hash' => $hash, 'assignment_fingerprint' => $receipt['assignment_fingerprint'],
                'receipt_json' => $json, 'receipt_hash' => hash('sha256', $json), 'created_at' => now(),
            ]);
            DeviceEvent::query()->create([
                'device_id' => $locked->id, 'event_type' => 'endpoint_script_policy_changed', 'severity' => 'info',
                'source' => 'oblivion_endpoint', 'occurred_at' => now(),
                // The general device timeline must not bypass private receipt access.
                'payload' => ['actor_user_id' => (int) $current->id, 'version' => $receipt['policy']['version']],
            ]);

            return $this->dto($current, $locked) + ['receipt' => $receipt];
        });
    }

    public function recover(User $actor, Device $device, int $expected, string $key): array
    {
        abort_unless($this->uuid($key), 422);

        return $this->within($actor, $device, $expected, function (User $current, Device $locked) use ($key): array {
            // Recovery does not mutate or renew authority. Only the original author may read the receipt.
            abort_unless($current->canDo(self::MANAGE), 403);
            $row = DB::table(self::TABLE)->where('device_id', $locked->id)->where('actor_user_id', $current->id)->where('request_key', $key)->lockForUpdate()->first();
            abort_unless($row && hash_equals($row->assignment_fingerprint, $this->assignment($locked)), 404);

            return $this->dto($current, $locked) + ['receipt' => $this->receipt($row)];
        });
    }

    /** Caller owns canonical Device and current authorization locks. No mutable shadow head. */
    public function current(Device $device): array
    {
        abort_unless(Schema::hasTable(self::TABLE), 503);
        $row = DB::table(self::TABLE)->where('device_id', $device->id)->orderByDesc('version')->lockForUpdate()->first();
        if (! $row) {
            return ['policy' => self::defaults(), 'assignment_matches' => true];
        }
        $receipt = $this->receipt($row);
        $matches = hash_equals($row->assignment_fingerprint, $this->assignment($device));

        // A reassignment cannot carry enabled or unattended policy into the new Site.
        // Keep only the revision needed to explicitly author a new scoped configuration.
        return ['policy' => $matches ? $receipt['policy'] : [...self::defaults(), 'version' => (int) $row->version], 'assignment_matches' => $matches];
    }

    private function dto(User $actor, Device $device): array
    {
        return [
            'actor_id' => (int) $actor->id, 'device_id' => (int) $device->id,
            ...$this->current($device), 'available' => $this->available(),
            'unavailable_reason' => 'script_execution_not_implemented',
            'can_manage' => $actor->canDo(self::MANAGE), 'can_run' => $actor->canDo(self::RUN),
            'can_unattended' => $actor->canDo(self::UNATTENDED),
            'identity_confirmed' => app(EndpointScriptIdentity::class)->confirmed($actor),
        ];
    }

    private function within(User $actor, Device $device, int $expected, Closure $callback): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $device, $expected, $callback): mixed {
                $current = app(ObliStaffAccess::class)->actor((int) $actor->id);
                abort_unless($expected === (int) $current->id, 409);
                // Script authority is stronger than the deployment-wide optional MFA setting.
                abort_unless($current->two_factor_confirmed_at, 403);
                $locked = Device::query()->whereKey($device->id)->lock('for update nowait')->firstOrFail();
                $current = app(EndpointRemoteAccess::class)->actor((int) $current->id, $locked, 'securityDevices.devices.view');
                abort_unless($current->canDo(self::MANAGE) || $current->canDo(self::RUN), 403);
                abort_unless(Schema::hasTable(self::TABLE), 503);

                return $callback($current, $locked);
            });
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw new HttpException(503);
            }
            throw $error;
        }
    }

    private function input(array $input): array
    {
        $keys = ['expected_actor_id', 'expected_version', 'request_key', 'enabled', 'consent_mode', 'reason'];
        abort_unless(count($input) === count($keys) && array_diff(array_keys($input), $keys) === [], 422);
        $data = Validator::make($input, [
            'expected_actor_id' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'expected_version' => ['required', 'integer:strict', 'min:0', 'max:4294967295'],
            'request_key' => ['required', 'string'],
            'enabled' => ['required', 'boolean:strict'], 'consent_mode' => ['required', 'in:attended,unattended'],
            'reason' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}\x{FFFD}]/u'],
        ])->validate();
        abort_unless($this->uuid($data['request_key']) && mb_check_encoding($data['reason'], 'UTF-8') && mb_strlen(trim($data['reason'])) >= 10, 422);

        // Stable key order makes equivalent JSON property order the same request.
        return array_replace(array_fill_keys($keys, null), $data);
    }

    private function uuid(string $key): bool
    {
        return (bool) preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $key);
    }

    private function requestHash(int $device, array $input): string
    {
        return hash('sha256', EndpointScriptContract::encode(['device_id' => $device, 'input' => $input]));
    }

    private function assignment(Device $device): string
    {
        try {
            return CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads): string => app(CommandAssignmentFingerprint::class)->forDevice($device, null, $reads));
        } catch (UnexpectedValueException $error) {
            throw new HttpException(404, 'Current computer assignment is unavailable.', $error);
        }
    }

    private function receipt(stdClass $row): array
    {
        abort_unless(hash_equals($row->receipt_hash, hash('sha256', $row->receipt_json)), 409);
        try {
            $receipt = get_object_vars(EndpointScriptContract::decode($row->receipt_json, 8192));
            $policy = $receipt['policy'] ?? null;
            abort_unless($policy instanceof stdClass && array_keys(get_object_vars($policy)) === ['version', 'enabled', 'consent_mode'], 409);
            $receipt['policy'] = get_object_vars($policy);
            abort_unless(array_keys($receipt) === ['device_id', 'actor_id', 'request_key', 'assignment_fingerprint', 'policy', 'reason', 'recorded_at']
                && $receipt['device_id'] === (int) $row->device_id && $receipt['actor_id'] === (int) $row->actor_user_id
                && $receipt['request_key'] === $row->request_key && $policy->version === (int) $row->version && $policy->version >= 1
                && is_string($receipt['recorded_at']) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $receipt['recorded_at'])
                && preg_match('/\A[0-9a-f]{64}\z/', $row->assignment_fingerprint) && $receipt['assignment_fingerprint'] === $row->assignment_fingerprint, 409);
            $input = $this->input([
                'expected_actor_id' => $receipt['actor_id'], 'expected_version' => $policy->version - 1,
                'request_key' => $receipt['request_key'], 'enabled' => $policy->enabled,
                'consent_mode' => $policy->consent_mode, 'reason' => $receipt['reason'],
            ]);
            abort_unless(hash_equals($row->request_hash, $this->requestHash((int) $row->device_id, $input)), 409);
        } catch (InvalidArgumentException|ValidationException $error) {
            throw new HttpException(409, 'Saved script policy evidence is invalid.', $error);
        } catch (HttpException $error) {
            throw new HttpException(409, 'Saved script policy evidence is invalid.', $error);
        }

        return $receipt;
    }
}
