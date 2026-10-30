<?php

namespace App\Domain\Obli;

use App\Domain\It\Enums\ItTicketCommandChannel;
use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\SecurityDevices\Endpoints\Models\EndpointAgent;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointAgentIdentity;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final readonly class ObliComputerContexts
{
    public function __construct(private ObliSignIn $signIn, private ItWorkAccessService $access, private EndpointAgentIdentity $identity, private EndpointCommandSigner $signer) {}

    public function ready(): bool
    {
        if (! app(EndpointRemoteSupportSettings::class)->endpointEnabled() || ! Schema::hasTable('obli_computer_contexts') || ! Schema::hasTable('endpoint_agents') || ! Schema::hasTable('devices') || ! Schema::hasTable('device_assignments')) {
            return false;
        }
        try {
            $this->signer->publicKey();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function intentHash(array $data): string
    {
        return hash('sha256', json_encode(['request_uuid' => strtolower($data['request_uuid']), 'site_id' => (int) $data['site_id'], 'title' => $data['title'], 'description' => $data['description'], 'category' => $data['category']], JSON_THROW_ON_ERROR));
    }

    public function mint(User $actor, string $sessionHash, array $intent): array
    {
        $this->available();
        $uuid = strtolower($intent['request_uuid']);
        $row = DB::table('obli_computer_contexts')->where('user_id', $actor->id)->where('request_uuid', $uuid)->first();
        if ($row) {
            return $this->withCurrent($actor, $sessionHash, $uuid, function ($current) use ($intent) {
                if (! hash_equals($current->intent_hash, self::intentHash($intent))) {
                    throw new ItTicketCommandConflict;
                }

                return $this->present($current, true);
            }, allowPending: true);
        }
        $this->site($actor, (int) $intent['site_id']);
        $challenge = bin2hex(random_bytes(32));
        $now = now();
        $id = DB::table('obli_computer_contexts')->insertGetId(['user_id' => $actor->id, 'session_hash' => $sessionHash, 'request_uuid' => $uuid, 'intent_hash' => self::intentHash($intent), 'site_id' => (int) $intent['site_id'], 'challenge_hash' => hash('sha256', $challenge), 'challenge_encrypted' => Crypt::encryptString($challenge), 'expires_at' => $now->copy()->addMinutes(5), 'created_at' => $now, 'updated_at' => $now]);

        return $this->present(DB::table('obli_computer_contexts')->where('id', $id)->first(), true);
    }

    public function show(User $actor, string $sessionHash, string $uuid): array
    {
        return $this->withCurrent($actor, $sessionHash, $uuid, fn ($row) => $this->present($row), allowPending: true);
    }

    public function confirm(#[\SensitiveParameter] string $credential, #[\SensitiveParameter] string $challenge): array
    {
        $this->available();
        $hash = hash('sha256', $challenge);
        $candidate = DB::table('obli_computer_contexts')->where('challenge_hash', $hash)->first();
        if (! $candidate) {
            throw new ObliComputerContextUnavailable;
        }
        // Current User/RBAC/profile/approved-Sites and session precede Device/agent, then context/ticket. No network I/O.
        try {
            return $this->signIn->withSessionHash($candidate->session_hash, function (User $actor, string $sessionHash) use ($credential, $hash, $candidate) {
                return $this->identity->withAuthenticated($credential, function (EndpointAgent $agent) use ($actor, $sessionHash, $hash, $candidate) {
                    $row = DB::table('obli_computer_contexts')->where('id', $candidate->id)->where('challenge_hash', $hash)->lockForUpdate()->first();
                    $this->bound($row, $actor, $sessionHash);
                    $this->site($actor, (int) $row->site_id);
                    if ((int) $agent->site_id !== (int) $row->site_id || ($row->agent_uuid !== null && (! hash_equals($row->agent_uuid, $agent->uuid) || (int) $row->device_id !== (int) $agent->device_id || ! hash_equals($row->assignment_fingerprint, $agent->assignment_fingerprint)))) {
                        throw new ObliComputerContextUnavailable;
                    }
                    if (! CarbonImmutable::parse($row->expires_at)->isFuture()) {
                        throw new ObliComputerContextUnavailable(true);
                    }
                    DB::table('obli_computer_contexts')->where('id', $row->id)->update(['device_id' => $agent->device_id, 'agent_uuid' => $agent->uuid, 'assignment_fingerprint' => $agent->assignment_fingerprint, 'confirmed_at' => $row->confirmed_at ?? now(), 'updated_at' => now()]);
                    $issued = now('UTC')->timestamp;

                    return $this->signer->sign(['type' => 'obli_ticket_computer_context', 'challenge_hash' => $hash, 'agent_uuid' => $agent->uuid, 'status' => 'confirmed', 'issued_at' => $issued, 'expires_at' => min($issued + 30, CarbonImmutable::parse($row->expires_at)->timestamp)]);
                });
            });
        } catch (ObliComputerContextUnavailable $error) {
            throw $error;
        } catch (HttpException $error) {
            if ($error->getStatusCode() === 401) {
                throw $error;
            }throw new ObliComputerContextUnavailable;
        }
    }

    public function withIntake(User $actor, string $sessionHash, array $intent, Closure $operation): mixed
    {
        return $this->withCurrent($actor, $sessionHash, $intent['request_uuid'], function ($row, $agent) use ($actor, $intent, $operation) {
            if (! hash_equals($row->intent_hash, self::intentHash($intent))) {
                throw new ItTicketCommandConflict;
            }
            if (! $agent || ! $row->confirmed_at) {
                throw new ObliComputerContextUnavailable;
            }

            return ObliComputerIntakeContext::within($actor, $intent, (int) $row->id, function ($proof) use ($operation, $row) {
                $result = $operation($proof);
                DB::table('obli_computer_contexts')->where('id', $row->id)->update(['ticket_id' => $result->ticket->id, 'updated_at' => now()]);

                return $result;
            });
        });
    }

    /** The typed proof factory resolves server evidence again rather than trusting a passed Device. */
    public function proofDevice(User $actor, array $intent, int $id): Device
    {
        $candidate = DB::table('obli_computer_contexts')->where('id', $id)->first();
        if (! $candidate || (int) $candidate->user_id !== (int) $actor->id) {
            throw new ObliComputerContextUnavailable;
        }

        return $this->signIn->withSessionHash($candidate->session_hash, fn (User $current, string $hash) => $this->withCurrent($current, $hash, $intent['request_uuid'], function ($row, $agent) use ($intent, $id) {
            if ((int) $row->id !== $id || ! hash_equals($row->intent_hash, self::intentHash($intent))) {
                throw new ObliComputerContextUnavailable;
            }

            return $agent->device;
        }));
    }

    public function recoverAuthority(User $actor, string $sessionHash, string $uuid, ItTicket $ticket): void
    {
        $verifiedLink = $ticket->links()->where('relationship', 'affected_device')->where('context->source', 'obli_verified_local_computer')->exists();
        if (! Schema::hasTable('obli_computer_contexts')) {
            if ($verifiedLink) {
                throw new ObliComputerContextUnavailable;
            }

            return;
        }
        $row = DB::table('obli_computer_contexts')->where('user_id', $actor->id)->where('request_uuid', strtolower($uuid))->first();
        if ($verifiedLink && (! $row || $row->ticket_id === null)) {
            throw new ObliComputerContextUnavailable;
        }
        if ($row && $row->ticket_id !== null) {
            $this->withCurrent($actor, $sessionHash, $uuid, function ($current) use ($ticket) {
                if ((int) $current->ticket_id !== (int) $ticket->id) {
                    throw new ObliComputerContextUnavailable;
                }
            });
        }
    }

    private function withCurrent(User $actor, string $sessionHash, string $uuid, Closure $operation, bool $allowPending = false): mixed
    {
        $this->available();
        $candidate = DB::table('obli_computer_contexts')->where('user_id', $actor->id)->where('request_uuid', strtolower($uuid))->first();
        $this->bound($candidate, $actor, $sessionHash);
        $agent = null;
        if ($candidate->device_id !== null) {
            Device::query()->whereKey($candidate->device_id)->lockForUpdate()->first();
            $agent = EndpointAgent::query()->where('device_id', $candidate->device_id)->lockForUpdate()->first();
            if (! $agent || ! $agent->enrolled_at || $agent->revoked_at || ! $agent->credential_hash || ! hash_equals($candidate->agent_uuid, $agent->uuid) || ! hash_equals($candidate->assignment_fingerprint, $agent->assignment_fingerprint)) {
                throw new ObliComputerContextUnavailable;
            }
            try {
                $this->identity->assertScope($agent);
            } catch (Throwable) {
                throw new ObliComputerContextUnavailable;
            }
        }
        $row = DB::table('obli_computer_contexts')->where('id', $candidate->id)->lockForUpdate()->first();
        $this->bound($row, $actor, $sessionHash);
        $this->site($actor, (int) $row->site_id);
        if ($agent && (int) $agent->site_id !== (int) $row->site_id) {
            throw new ObliComputerContextUnavailable;
        }
        if ($row->ticket_id !== null) {
            $ticket = ItTicket::query()->whereKey($row->ticket_id)->lockForUpdate()->first();
            $receipt = ItTicketCommandReceipt::query()->where('actor_user_id', $actor->id)->where('channel', ItTicketCommandChannel::Obli->value)->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)->where('request_uuid', $row->request_uuid)->where('it_ticket_id', $row->ticket_id)->whereNotNull('committed_at')->exists();
            if (! $ticket || ! $receipt || (int) $ticket->requester_user_id !== (int) $actor->id || (int) $ticket->site_id !== (int) $row->site_id || $ticket->isMerged() || ! $this->access->canView($actor, $ticket) || ! $ticket->links()->where('relationship', 'affected_device')->where('linkable_type', $agent?->device->getMorphClass())->where('linkable_id', $row->device_id)->exists()) {
                throw new ObliComputerContextUnavailable;
            }
        } elseif (! CarbonImmutable::parse($row->expires_at)->isFuture()) {
            throw new ObliComputerContextUnavailable(true);
        }
        if (! $allowPending && ! $agent) {
            throw new ObliComputerContextUnavailable;
        }

        return $operation($row, $agent);
    }

    private function bound(?object $row, User $actor, string $sessionHash): void
    {
        if (! $row || (int) $row->user_id !== (int) $actor->id || ! hash_equals($row->session_hash, $sessionHash)) {
            throw new ObliComputerContextUnavailable;
        }
    }

    private function site(User $actor, int $id): void
    {
        if (! in_array($id, $this->access->approvedSiteIds($actor, true), true) || ! Site::query()->whereKey($id)->where('is_active', true)->where(fn ($q) => $q->whereNull('archived')->orWhere('archived', false))->whereNull('archived_at')->lockForUpdate()->exists()) {
            throw new ObliComputerContextUnavailable;
        }
    }

    private function available(): void
    {
        if (! $this->ready()) {
            throw new ObliComputerContextUnavailable;
        }
    }

    private function label(string $value): string
    {
        $label = trim(mb_substr(preg_replace('/[\p{Cc}\s]+/u', ' ', $value), 0, 200));
        if ($label === '') {
            throw new ObliComputerContextUnavailable;
        }

        return $label;
    }

    private function present(object $row, bool $includeChallenge = false): array
    {
        $computer = null;
        if ($row->confirmed_at) {
            $device = Device::query()->find($row->device_id);
            $site = Site::query()->find($row->site_id);
            $computer = ['name' => $this->label($device->name), 'site' => ['id' => (int) $site->id, 'name' => $this->label($site->name)]];
        }
        $result = ['request_uuid' => $row->request_uuid, 'status' => $row->confirmed_at ? 'confirmed' : 'pending', 'expires_at' => CarbonImmutable::parse($row->expires_at)->toIso8601String(), 'computer' => $computer];
        if ($includeChallenge) {
            if (! $row->challenge_encrypted) {
                throw new ObliComputerContextUnavailable(true);
            }$result['challenge'] = Crypt::decryptString($row->challenge_encrypted);
        }

        return $result;
    }
}
