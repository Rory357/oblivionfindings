<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\Obli\ObliStaffAccess;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointRemoteSession;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointRemoteSignal;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class EndpointRemoteSessions
{
    public function __construct(
        private readonly EndpointAgentIdentity $identity,
        private readonly EndpointRemoteAccess $access,
        private readonly EndpointRemotePolicy $policies,
        private readonly EndpointCommandSigner $signer,
    ) {}

    private function enabled(): void
    {
        abort_unless(app(EndpointRemoteSupportSettings::class)->sessionBrokerEnabled(), 404);
    }

    /** Read-only connection choices for a scoped technician; policy editing is a separate grant. */
    public function connectionOptions(User $actor, Device $device): array
    {
        $this->enabled();

        return DB::transaction(function () use ($actor, $device): array {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $actor = $this->access->actor($actor->id, $device, 'securityDevices.remote.view');
            $policy = $this->policies->current($device);

            return [
                'actor_id' => $actor->id, 'device_id' => $device->id,
                'policy_version' => $policy['version'], 'enabled' => $policy['enabled'],
                'consent_mode' => $policy['consent_mode'],
                'allow_control' => $policy['allow_control'] && $actor->canDo('securityDevices.remote.control'),
                'max_minutes' => $policy['max_minutes'],
            ];
        });
    }

    public function request(User $actor, Device $device, array $input): array
    {
        $this->enabled();
        abort_if(array_diff(array_keys($input), ['request_uuid', 'expected_actor_id', 'expected_policy_version', 'access_mode', 'purpose', 'ticket_id']) !== [], 422);
        $data = Validator::make($input, [
            'request_uuid' => ['required', 'uuid'],
            'expected_actor_id' => ['required', 'integer', 'min:1'],
            'expected_policy_version' => ['required', 'integer', 'min:1'],
            'access_mode' => ['required', 'in:view,control'],
            'purpose' => ['required', 'string', 'min:10', 'max:500', 'not_regex:/[\p{Cc}\p{Cf}]/u'],
            'ticket_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $data['purpose'] = trim($data['purpose']);
        abort_if(mb_strlen($data['purpose']) < 10, 422);

        return DB::transaction(function () use ($actor, $device, $data): array {
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            $agent = EndpointAgent::query()->where('device_id', $device->id)->lockForUpdate()->firstOrFail();
            $policy = $this->policies->current($device);
            $actor = $this->authorize($actor->id, $device, $data['access_mode'], $policy['consent_mode']);
            abort_unless((int) $data['expected_actor_id'] === (int) $actor->id, 409);
            $this->access->ticket($actor, $device, isset($data['ticket_id']) ? (int) $data['ticket_id'] : null);
            $hash = hash_hmac('sha256', json_encode([
                $actor->id, $device->id, (int) $data['expected_policy_version'], $data['access_mode'], $data['purpose'],
                isset($data['ticket_id']) ? (int) $data['ticket_id'] : null,
            ], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $prior = EndpointRemoteSession::query()->where('request_uuid', $data['request_uuid'])->first();
            if ($prior) {
                abort_unless((int) $prior->actor_user_id === (int) $actor->id && (int) $prior->device_id === (int) $device->id, 404);
                $prior = EndpointRemoteSession::query()->lockForUpdate()->findOrFail($prior->id);
                abort_unless(hash_equals($prior->request_hash, $hash), 409);
                $this->refresh($prior, $agent);

                return $this->present($prior);
            }
            abort_unless($policy['enabled'] && $policy['version'] === (int) $data['expected_policy_version'], 409);
            abort_unless($data['access_mode'] !== 'control' || $policy['allow_control'], 403);
            $this->readyAgent($agent);
            foreach (EndpointRemoteSession::query()->where('device_id', $device->id)->whereNull('ended_at')->lockForUpdate()->get() as $session) {
                $this->refresh($session, $agent);
                abort_unless($session->terminal(), 409);
            }
            // Verify signing configuration before creating a request that cannot be offered.
            $this->signer->publicKey();
            $session = EndpointRemoteSession::query()->create([
                'uuid' => (string) Str::uuid(), 'request_uuid' => $data['request_uuid'], 'request_hash' => $hash,
                'device_id' => $device->id, 'endpoint_agent_id' => $agent->id, 'agent_uuid' => $agent->uuid,
                'actor_user_id' => $actor->id, 'actor_fingerprint' => ObliStaffAccess::fingerprint($actor),
                'it_ticket_id' => $data['ticket_id'] ?? null, 'policy_version' => $policy['version'],
                'access_mode' => $data['access_mode'], 'consent_mode' => $policy['consent_mode'], 'purpose' => $data['purpose'],
                'status' => 'requested', 'consent_challenge' => bin2hex(random_bytes(32)),
                'request_expires_at' => now()->addSeconds(120), 'session_expires_at' => now()->addMinutes($policy['max_minutes']),
                'technician_seen_at' => now(),
            ]);
            $this->transitionEvent($session, 'remote_requested');

            return $this->present($session);
        });
    }

    /** Only the initiating technician can keep their request alive. */
    public function observe(User $actor, string $uuid): array
    {
        $this->enabled();

        return $this->withSession($uuid, function (EndpointRemoteSession $session, EndpointAgent $agent) use ($actor): array {
            abort_unless((int) $session->actor_user_id === (int) $actor->id, 404);
            $device = $agent->device;
            $this->authorize($actor->id, $device, $session->access_mode, $session->consent_mode);
            $this->access->ticket($actor, $device, $session->it_ticket_id);
            // Refresh before updating liveness: a dead session cannot be revived.
            $this->refresh($session, $agent);
            if (! $session->terminal()) {
                $session->forceFill(['technician_seen_at' => now()])->save();
            }

            return $this->present($session);
        });
    }

    /** Stopping one's own request remains possible after a grant or feature is withdrawn. */
    public function stop(User $actor, string $uuid): array
    {
        return $this->withSession($uuid, function (EndpointRemoteSession $session) use ($actor): array {
            abort_unless((int) $session->actor_user_id === (int) $actor->id, 404);
            $this->end($session, 'stopped', 'technician_stopped');

            return ['uuid' => $session->uuid, 'status' => $session->status];
        });
    }

    public function offer(string $credential): array
    {
        $this->enabled();

        return $this->identity->withAuthenticated($credential, function (EndpointAgent $agent): array {
            $session = EndpointRemoteSession::query()->where('endpoint_agent_id', $agent->id)->whereNull('ended_at')->lockForUpdate()->first();
            if (! $session || ! $this->refresh($session, $agent) || $session->status !== 'requested') {
                return ['offer' => null];
            }
            $actor = User::query()->findOrFail($session->actor_user_id);
            $name = trim(preg_replace('/[\p{Cc}\p{Cf}]/u', '', $actor->name) ?? '');
            $name = mb_substr($name, 0, 100);
            abort_if($name === '', 409);

            return ['offer' => $this->signer->sign([
                'type' => 'remote_support_offer', 'version' => 1, 'agent_uuid' => $agent->uuid,
                'session_uuid' => $session->uuid, 'policy_version' => $session->policy_version,
                'technician_name' => $name, 'purpose' => $session->purpose,
                'access_mode' => $session->access_mode, 'consent_mode' => $session->consent_mode,
                'recording' => false, 'challenge' => $session->consent_challenge,
                'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'expires_at' => $session->request_expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ])];
        });
    }

    /** Called only by the enrolled service after verified local-desktop consent or policy. */
    public function decide(string $credential, string $uuid, array $input): array
    {
        $this->enabled();
        abort_if(array_diff(array_keys($input), ['decision', 'challenge', 'desktop_session']) !== [], 422);
        $data = Validator::make($input, [
            'decision' => ['required', 'in:allow,decline'],
            'challenge' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'desktop_session' => ['required', 'uuid'],
        ])->validate();

        return $this->withAgentSession($credential, $uuid, function (EndpointRemoteSession $session, EndpointAgent $agent) use ($data): array {
            if (! $this->refresh($session, $agent)) {
                return $this->present($session);
            }
            abort_unless(hash_equals($session->consent_challenge ?? '', $data['challenge']), 403);
            if (in_array($session->status, ['allowed', 'active'], true)) {
                abort_unless($data['decision'] === 'allow' && $session->desktop_session === $data['desktop_session'], 409);

                return $this->present($session);
            }
            abort_unless($session->status === 'requested', 409);
            $session->desktop_session = $data['desktop_session'];
            if ($data['decision'] === 'decline') {
                $this->end($session, 'declined', 'user_declined');
            } else {
                $session->forceFill(['status' => 'allowed', 'allowed_at' => now(), 'lease_expires_at' => now()->addSeconds(15)])->save();
                $this->transitionEvent($session, 'remote_allowed');
            }

            return $this->present($session);
        });
    }

    /** No desktop/transport can infer a live connection merely from Allow. */
    public function lease(string $credential, string $uuid, array $input): array
    {
        $this->enabled();
        abort_if(array_diff(array_keys($input), ['desktop_session', 'activate']) !== [], 422);
        $data = Validator::make($input, ['desktop_session' => ['required', 'uuid'], 'activate' => ['required', 'boolean:strict']])->validate();

        return $this->withAgentSession($credential, $uuid, function (EndpointRemoteSession $session, EndpointAgent $agent) use ($data): array {
            if (! $this->refresh($session, $agent)) {
                return ['lease' => null, 'status' => $session->status];
            }
            abort_unless(in_array($session->status, ['allowed', 'active'], true) && $session->desktop_session === $data['desktop_session'], 409);
            if ($data['activate'] && $session->status === 'allowed') {
                $session->forceFill(['status' => 'active', 'started_at' => now()])->save();
                $this->transitionEvent($session, 'remote_started');
            }
            $until = now()->addSeconds(15)->min($session->session_expires_at);
            $session->forceFill(['lease_expires_at' => $until])->save();

            return ['status' => $session->status, 'lease' => $this->signer->sign([
                'type' => 'remote_support_lease', 'version' => 1, 'agent_uuid' => $agent->uuid,
                'session_uuid' => $session->uuid, 'desktop_session' => $session->desktop_session,
                'access_mode' => $session->access_mode, 'status' => $session->status, 'recording' => false,
                'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $until->utc()->format('Y-m-d\TH:i:s\Z'),
            ])];
        });
    }

    public function agentStop(string $credential, string $uuid): array
    {
        return $this->withAgentSession($credential, $uuid, function (EndpointRemoteSession $session): array {
            $this->end($session, 'stopped', 'computer_stopped');

            return ['uuid' => $session->uuid, 'status' => $session->status];
        });
    }

    /** Record terminal timeout events even if both participants disappear. */
    public function expireStale(): int
    {
        $count = 0;
        EndpointRemoteSession::query()->whereNull('ended_at')->where(function ($query): void {
            $query->where('session_expires_at', '<=', now())->orWhere('technician_seen_at', '<=', now()->subSeconds(30))
                ->orWhere('lease_expires_at', '<=', now())->orWhere(function ($pending): void {
                    $pending->where('status', 'requested')->where('request_expires_at', '<=', now());
                });
        })->select(['id', 'uuid'])->chunkById(100, function ($sessions) use (&$count): void {
            foreach ($sessions as $candidate) {
                $count += $this->withSession($candidate->uuid, function (EndpointRemoteSession $session, EndpointAgent $agent): int {
                    $already = $session->terminal();
                    $this->refresh($session, $agent);

                    return ! $already && $session->terminal() ? 1 : 0;
                });
            }
        });

        return $count;
    }

    /** The initiating technician alone may retrieve the offer or bind its answer. */
    public function technicianMedia(User $actor, string $uuid, ?array $input = null): array
    {
        $this->enabled();
        if ($input !== null) {
            abort_if(array_diff(array_keys($input), ['offer_uuid', 'viewer_uuid', 'sdp']) !== [], 422);
            Validator::make($input, ['offer_uuid' => ['required', 'uuid'], 'viewer_uuid' => ['required', 'uuid'], 'sdp' => ['required', 'string']])->validate();
            $input['sdp'] = EndpointRemoteDescription::validate($input['sdp']);
        }

        return $this->withSession($uuid, function (EndpointRemoteSession $session, EndpointAgent $agent) use ($actor, $input): array {
            abort_unless((int) $session->actor_user_id === (int) $actor->id, 404);
            $this->authorize($actor->id, $agent->device, $session->access_mode, $session->consent_mode);
            $this->access->ticket($actor, $agent->device, $session->it_ticket_id);
            if (! $this->refresh($session, $agent)) {
                return ['status' => $session->status, 'signal' => null];
            }
            abort_unless(in_array($session->status, ['allowed', 'active'], true), 409);
            $signal = EndpointRemoteSignal::query()->where('endpoint_remote_session_id', $session->id)->lockForUpdate()->first();
            if ($input === null) {
                return ['status' => $session->status, 'signal' => $signal ? [
                    'offer_uuid' => $signal->offer_uuid, 'sdp' => $signal->offer_sdp,
                    'desktop_session' => $session->desktop_session,
                    'access_mode' => $session->access_mode,
                ] : null];
            }
            abort_unless($signal && $signal->offer_uuid === $input['offer_uuid'], 409);
            if ($signal->answer_sdp !== null) {
                abort_unless($signal->viewer_uuid === $input['viewer_uuid'] && hash_equals($signal->answer_sdp, $input['sdp']), 409);
            } else {
                $signal->forceFill(['viewer_uuid' => $input['viewer_uuid'], 'answer_sdp' => $input['sdp']])->save();
            }

            return ['status' => $session->status, 'offer_uuid' => $signal->offer_uuid, 'viewer_uuid' => $signal->viewer_uuid];
        });
    }

    /** Enrolled service publishes one immutable offer and receives a signed answer. */
    public function agentMedia(string $credential, string $uuid, array $input, bool $publish = false): array
    {
        $this->enabled();
        abort_if(array_diff(array_keys($input), $publish ? ['desktop_session', 'offer_uuid', 'sdp'] : ['desktop_session']) !== [], 422);
        Validator::make($input, ['desktop_session' => ['required', 'uuid'],
            'offer_uuid' => [$publish ? 'required' : 'prohibited', 'uuid'],
            'sdp' => [$publish ? 'required' : 'prohibited', 'string'],
        ])->validate();
        if ($publish) {
            $input['sdp'] = EndpointRemoteDescription::validate($input['sdp']);
        }

        return $this->withAgentSession($credential, $uuid, function (EndpointRemoteSession $session, EndpointAgent $agent) use ($input, $publish): array {
            if (! $this->refresh($session, $agent)) {
                return ['status' => $session->status, 'signal' => null];
            }
            abort_unless(in_array($session->status, ['allowed', 'active'], true) && $session->desktop_session === $input['desktop_session'], 409);
            $signal = EndpointRemoteSignal::query()->where('endpoint_remote_session_id', $session->id)->lockForUpdate()->first();
            if ($publish) {
                if ($signal) {
                    abort_unless($signal->offer_uuid === $input['offer_uuid'] && hash_equals($signal->offer_sdp, $input['sdp']), 409);
                } else {
                    $signal = EndpointRemoteSignal::query()->create([
                        'endpoint_remote_session_id' => $session->id, 'offer_uuid' => $input['offer_uuid'], 'offer_sdp' => $input['sdp'],
                    ]);
                }

                return ['status' => $session->status, 'offer_uuid' => $signal->offer_uuid];
            }
            $answer = null;
            if ($signal && $signal->answer_sdp !== null) {
                $answer = $this->signer->sign([
                    'type' => 'remote_media_answer', 'version' => 1,
                    'agent_uuid' => $agent->uuid, 'session_uuid' => $session->uuid, 'desktop_session' => $session->desktop_session,
                    'offer_uuid' => $signal->offer_uuid, 'viewer_uuid' => $signal->viewer_uuid,
                    'access_mode' => $session->access_mode, 'sdp' => $signal->answer_sdp,
                    'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                    'expires_at' => $session->lease_expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
                ]);
            }

            return ['status' => $session->status, 'signal' => $answer];
        });
    }

    public function agentRelay(string $credential, string $uuid, array $input): array
    {
        $this->enabled();
        abort_if(array_diff(array_keys($input), ['desktop_session']) !== [], 422);
        Validator::make($input, ['desktop_session' => ['required', 'uuid']])->validate();

        return $this->withAgentSession($credential, $uuid, function (EndpointRemoteSession $session, EndpointAgent $agent) use ($input): array {
            if (! $this->refresh($session, $agent)) {
                return ['status' => $session->status, 'relay' => null];
            }
            abort_unless(in_array($session->status, ['allowed', 'active'], true)
                && $session->desktop_session === $input['desktop_session'], 409);

            return ['status' => $session->status, 'relay' => $this->signer->sign(
                app(EndpointRemoteRelay::class)->grant($session, 'agent', $agent->uuid)
            )];
        });
    }

    public function technicianRelay(User $actor, string $uuid): array
    {
        $this->enabled();

        return $this->withSession($uuid, function (EndpointRemoteSession $session, EndpointAgent $agent) use ($actor): array {
            abort_unless((int) $session->actor_user_id === (int) $actor->id, 404);
            $this->authorize($actor->id, $agent->device, $session->access_mode, $session->consent_mode);
            $this->access->ticket($actor, $agent->device, $session->it_ticket_id);
            if (! $this->refresh($session, $agent)) {
                return ['status' => $session->status, 'relay' => null];
            }
            abort_unless(in_array($session->status, ['allowed', 'active'], true), 409);

            return ['status' => $session->status, 'relay' => app(EndpointRemoteRelay::class)->grant($session, 'technician', (string) $actor->id)];
        });
    }

    private function withAgentSession(string $credential, string $uuid, callable $operation): mixed
    {
        return $this->identity->withAuthenticated($credential, function (EndpointAgent $agent) use ($uuid, $operation): mixed {
            $session = EndpointRemoteSession::query()->where('uuid', $uuid)->where('endpoint_agent_id', $agent->id)
                ->where('agent_uuid', $agent->uuid)->lockForUpdate()->firstOrFail();

            return $operation($session, $agent);
        });
    }

    private function withSession(string $uuid, callable $operation): mixed
    {
        return DB::transaction(function () use ($uuid, $operation): mixed {
            $candidate = EndpointRemoteSession::query()->where('uuid', $uuid)->firstOrFail();
            $device = Device::withTrashed()->lockForUpdate()->findOrFail($candidate->device_id);
            $agent = EndpointAgent::query()->lockForUpdate()->findOrFail($candidate->endpoint_agent_id);
            $agent->setRelation('device', $device);
            $session = EndpointRemoteSession::query()->lockForUpdate()->findOrFail($candidate->id);

            return $operation($session, $agent);
        });
    }

    private function authorize(int $id, Device $device, string $mode, string $consent): User
    {
        $actor = $this->access->actor($id, $device, 'securityDevices.remote.view');
        abort_unless($mode !== 'control' || $actor->canDo('securityDevices.remote.control'), 403);
        abort_unless($consent !== 'unattended' || $actor->canDo('securityDevices.remote.unattended'), 403);

        return $actor;
    }

    private function readyAgent(EndpointAgent $agent): void
    {
        $this->identity->assertScope($agent);
        abort_unless($agent->enrolled_at && $agent->credential_hash && $agent->last_seen_at
            && $agent->last_seen_at->greaterThan(now()->subSeconds(180))
            && in_array($agent->device->status->value, ['active', 'degraded', 'maintenance'], true), 409);
    }

    /** Every delivery/lease rechecks current authority; terminal transitions commit. */
    private function refresh(EndpointRemoteSession $session, EndpointAgent $agent): bool
    {
        if ($session->terminal()) {
            return false;
        }
        if (($session->status === 'requested' && $session->request_expires_at->lessThanOrEqualTo(now()))
            || $session->session_expires_at->lessThanOrEqualTo(now())
            || $session->technician_seen_at->lessThanOrEqualTo(now()->subSeconds(30))
            || ($session->lease_expires_at && $session->lease_expires_at->lessThanOrEqualTo(now()))) {
            $this->end($session, 'expired', 'time_limit');

            return false;
        }
        try {
            abort_unless(app(EndpointRemoteSupportSettings::class)->sessionBrokerEnabled(), 403);
            $this->readyAgent($agent);
            abort_unless((string) $session->agent_uuid === (string) $agent->uuid, 403);
            $policy = $this->policies->current($agent->device);
            abort_unless($policy['enabled'] && $policy['version'] === $session->policy_version
                && ($session->access_mode !== 'control' || $policy['allow_control']), 403);
            $actor = $this->authorize($session->actor_user_id, $agent->device, $session->access_mode, $session->consent_mode);
            abort_unless(hash_equals($session->actor_fingerprint, ObliStaffAccess::fingerprint($actor)), 403);
            $this->access->ticket($actor, $agent->device, $session->it_ticket_id);
        } catch (\Throwable $error) {
            if (! $error instanceof ModelNotFoundException && (! $error instanceof HttpExceptionInterface || ! in_array($error->getStatusCode(), [401, 403, 404, 409, 422], true))) {
                throw $error;
            }
            $this->end($session, 'stopped', 'access_changed');

            return false;
        }

        return true;
    }

    public function end(EndpointRemoteSession $session, string $status, string $reason): void
    {
        if ($session->terminal()) {
            return;
        }
        EndpointRemoteSignal::query()->where('endpoint_remote_session_id', $session->id)->delete();
        $session->forceFill(['status' => $status, 'end_reason' => $reason, 'ended_at' => now(),
            'lease_expires_at' => null, 'consent_challenge' => null])->save();
        $this->transitionEvent($session, 'remote_'.$status);
    }

    public function event(int $deviceId, string $kind, array $payload): void
    {
        DeviceEvent::query()->create(['device_id' => $deviceId, 'event_type' => $kind, 'severity' => 'info',
            'source' => 'oblivion_remote_support', 'occurred_at' => now(), 'payload' => $payload]);
    }

    private function transitionEvent(EndpointRemoteSession $session, string $kind): void
    {
        $this->event($session->device_id, $kind, [
            'session_uuid' => $session->uuid, 'actor_user_id' => $session->actor_user_id,
            'policy_version' => $session->policy_version, 'access_mode' => $session->access_mode,
            'consent_mode' => $session->consent_mode, 'end_reason' => $session->end_reason,
        ]);
    }

    private function present(EndpointRemoteSession $session): array
    {
        return [
            'uuid' => $session->uuid, 'device_id' => $session->device_id,
            'status' => $session->status, 'access_mode' => $session->access_mode,
            'consent_mode' => $session->consent_mode, 'recording' => false,
            'request_expires_at' => $session->request_expires_at->toISOString(),
            'session_expires_at' => $session->session_expires_at->toISOString(),
            'started_at' => $session->started_at?->toISOString(), 'ended_at' => $session->ended_at?->toISOString(),
            'end_reason' => $session->end_reason,
        ];
    }
}
