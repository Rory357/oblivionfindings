<?php

namespace App\Domain\It\Services;

use App\Domain\It\Data\ItObliDiagnosticBinding;
use App\Domain\It\Exceptions\ItObliSourceUnavailable;
use App\Domain\Obli\ObliDiagnostics;
use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointAgentIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteAccess;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItTicket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final readonly class ItObliDiagnosticSource
{
    public function __construct(private ObliStaffAccess $staff, private EndpointRemoteAccess $access, private EndpointAgentIdentity $identity) {}

    public function ready(): bool
    {
        return config('endpoint_management.enabled') === true && config('endpoint_management.obli.technician_enabled', false) === true
            && Schema::hasTable('endpoint_agents') && Schema::hasColumns('endpoint_agents', ['uuid', 'device_id', 'site_id', 'platform', 'enrolled_at', 'revoked_at', 'credential_hash', 'assignment_fingerprint', 'heartbeat_hash', 'sequence', 'inventory', 'observed_at', 'last_seen_at']);
    }

    /** Current common authority, also for assist-only questions. Never call a provider in this callback. */
    public function withAuthorized(User $actor, int $deviceId, ?int $ticketId, Closure $operation): mixed
    {
        if ($deviceId < 1 || ($ticketId !== null && $ticketId < 1) || ! $this->ready()) {
            throw new ItObliSourceUnavailable;
        }
        try {
            return DB::transaction(function () use ($actor, $deviceId, $ticketId, $operation) {
                // Shared staff/User/Site order; never block while acquiring the inverse Device chain.
                $current = $this->staff->actor((int) $actor->id);
                $device = Device::query()->whereKey($deviceId)->lock('for update nowait')->first();
                if (! $device) {
                    throw new ItObliSourceUnavailable;
                }
                $current = $this->access->actor((int) $current->id, $device, 'securityDevices.devices.view');
                $agent = EndpointAgent::query()->where('device_id', $deviceId)->lock('for update nowait')->first();
                if (! $agent || ! $agent->enrolled_at || $agent->revoked_at || ! $agent->credential_hash || ! in_array($device->status->value, ['active', 'degraded', 'maintenance'], true)) {
                    throw new ItObliSourceUnavailable;
                }
                try {
                    $this->identity->assertScope($agent);
                } catch (HttpExceptionInterface) {
                    throw new ItObliSourceUnavailable;
                }
                if ($ticketId !== null) {
                    if (! ItTicket::query()->whereKey($ticketId)->lock('for update nowait')->first()) {
                        throw new ItObliSourceUnavailable;
                    }
                    $this->access->ticket($current, $device, $ticketId);
                }

                return $operation($current, ['actor_id' => (int) $current->id, 'device_id' => $deviceId, 'ticket_id' => $ticketId, 'agent_uuid' => $agent->uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint]);
            });
        } catch (QueryException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                throw new ItObliSourceUnavailable('context_busy');
            }
            throw $error;
        }
    }

    public function preview(User $actor, int $deviceId, ?int $ticketId, array $groups = ObliDiagnostics::CATEGORIES): array
    {
        $groups = self::groups($groups);

        return $this->withAuthorized($actor, $deviceId, $ticketId, function (User $current, array $authority) use ($groups) {
            $this->diagnosticsGrant($current);
            $source = $this->latest($authority);

            return ['actor_id' => $authority['actor_id'], 'device_id' => $authority['device_id'], 'ticket_id' => $authority['ticket_id'], 'snapshot_version' => $source['version'], 'observed_at' => $source['observed_at'], 'received_at' => $source['received_at'], 'fresh_until' => $source['fresh_until'], 'groups' => $groups, 'snapshot' => $this->selected($source['snapshot'], $groups, true), 'completeness' => $this->selected($source['completeness'], $groups)];
        });
    }

    public function capture(User $actor, int $deviceId, ?int $ticketId, array $groups, string $expectedVersion): ItObliDiagnosticBinding
    {
        $groups = self::groups($groups);
        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedVersion)) {
            throw new ItObliSourceUnavailable('source_changed');
        }

        return $this->withAuthorized($actor, $deviceId, $ticketId, function (User $current, array $authority) use ($groups, $expectedVersion) {
            $this->diagnosticsGrant($current);
            $source = $this->latest($authority);
            if (! hash_equals($source['version'], $expectedVersion)) {
                throw new ItObliSourceUnavailable('source_changed');
            }
            $data = ['schema' => 1, ...$authority, 'snapshot_version' => $source['version'], 'observed_at' => $source['observed_at'], 'received_at' => $source['received_at'], 'groups' => $groups, 'snapshot' => $this->selected($source['snapshot'], $groups, true), 'completeness' => $this->selected($source['completeness'], $groups)];
            $key = config('app.key');
            if (! is_string($key) || $key === '') {
                throw new ItObliSourceUnavailable;
            }
            $data['seal'] = hash_hmac('sha256', ItObliDiagnosticBinding::SEAL_DOMAIN.json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key);

            return ItObliDiagnosticBinding::fromStorage($data);
        });
    }

    /** Historical source: routine heartbeats and observation age do not revoke an already captured answer. */
    public function assertCurrent(User $actor, ItObliDiagnosticBinding $binding): void
    {
        $data = $binding->toStorage();
        if ((int) $actor->id !== $data['actor_id']) {
            throw new ItObliSourceUnavailable;
        }
        $this->withAuthorized($actor, $data['device_id'], $data['ticket_id'], function (User $current, array $authority) use ($data) {
            $this->diagnosticsGrant($current);
            foreach ($authority as $field => $value) {
                if ($data[$field] !== $value) {
                    throw new ItObliSourceUnavailable;
                }
            }
        });
    }

    private function diagnosticsGrant(User $current): void
    {
        abort_unless($current->canDo('securityDevices.endpoints.diagnostics'), 403);
    }

    private static function groups(array $groups): array
    {
        if (! array_is_list($groups) || $groups === [] || count($groups) > 3 || count(array_filter($groups, 'is_string')) !== count($groups) || count(array_unique($groups, SORT_REGULAR)) !== count($groups) || array_diff($groups, ObliDiagnostics::CATEGORIES) !== []) {
            abort(422);
        }

        return array_values(array_intersect(ObliDiagnostics::CATEGORIES, $groups));
    }

    private function selected(array $data, array $groups, bool $snapshot = false): array
    {
        $out = $snapshot ? ['schema' => 1, 'observed_at' => $data['observed_at']] : [];
        foreach ($groups as $group) {
            $out[$group] = $data[$group];
        }

        return $out;
    }

    private function latest(array $authority): array
    {
        $agent = EndpointAgent::query()->where('device_id', $authority['device_id'])->lock('for update nowait')->first();
        $now = CarbonImmutable::now('UTC');
        if (! $agent || ! is_array($agent->inventory) || ! $agent->observed_at || ! $agent->last_seen_at || $agent->sequence < 1 || ! is_string($agent->heartbeat_hash) || ! preg_match('/\A[a-f0-9]{64}\z/', $agent->heartbeat_hash)
            || $agent->observed_at->lessThan($now->subSeconds(360)) || $agent->observed_at->greaterThan($now->addSeconds(30)) || $agent->last_seen_at->lessThanOrEqualTo($now->subSeconds(180)) || $agent->last_seen_at->greaterThan($now->addSeconds(30)) || strlen(json_encode($agent->inventory, JSON_THROW_ON_ERROR)) > 1048576) {
            throw new ItObliSourceUnavailable;
        }
        $observed = $agent->observed_at->utc()->format('Y-m-d\TH:i:s\Z');
        $received = $agent->last_seen_at->utc()->format('Y-m-d\TH:i:s\Z');
        $projected = ItObliDiagnosticProjection::project($agent->inventory);
        $snapshot = ObliDiagnostics::normalize(['schema' => 1, 'observed_at' => $observed, ...$projected['snapshot']], ObliDiagnostics::CATEGORIES, true);
        $freshUntil = $agent->observed_at->addSeconds(360)->min($agent->last_seen_at->addSeconds(180))->utc()->format('Y-m-d\TH:i:s\Z');
        $version = hash('sha256', 'obli-technician-source-v1:'.json_encode([$authority, $agent->sequence, $agent->heartbeat_hash, $observed, $received, $freshUntil, $snapshot, $projected['completeness']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return ['version' => $version, 'observed_at' => $observed, 'received_at' => $received, 'fresh_until' => $freshUntil, 'snapshot' => $snapshot, 'completeness' => $projected['completeness']];
    }
}
