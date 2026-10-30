<?php

namespace App\Domain\Obli;

use App\Domain\It\Data\ItObliDiagnosticBinding;
use App\Domain\It\Services\ItObliDiagnosticSource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ObliTechnicianChatScope implements ObliChatScope
{
    private ?array $authority = null;

    public function __construct(private ItObliDiagnosticSource $source, private ObliAiSettings $settings, private User $actor, private int $deviceId, private ?int $ticketId, private int $expectedActorId) {}

    public function kind(): string
    {
        return 'technician';
    }

    public function identity(): array
    {
        return ['technician', (int) $this->actor->id, $this->deviceId, $this->ticketId];
    }

    public function within(Closure $operation): mixed
    {
        if ((int) $this->actor->id !== $this->expectedActorId) {
            throw new ObliChatRejected('source_unavailable', 404);
        }

        return $this->source->withAuthorized($this->actor, $this->deviceId, $this->ticketId, function (User $current, array $authority) use ($operation) {
            $this->authority = $authority;
            if (! $current->canDo('securityDevices.obli.assist')) {
                throw new ObliChatRejected('source_unavailable', 404);
            }
            if (! $this->settings->current(true)['technician_enabled']) {
                throw new ObliChatRejected('chat_unavailable', 503);
            }

            return $operation($current);
        });
    }

    public function prepare(User $actor, #[\SensitiveParameter] array $messages, array $policy): array
    {
        $rendered = [];
        $bindings = [];
        foreach ($messages as $message) {
            $entry = ['role' => $message['role'], 'content' => $message['content']];
            if (isset($message['computer_diagnostics'])) {
                $reference = $message['computer_diagnostics'];
                if (array_diff($reference['groups'], $policy['diagnostic_categories']) !== []) {
                    throw new ObliChatRejected('invalid_request', 422);
                }
                $binding = isset($reference['source_request_uuid']) ? $this->historical($actor, $reference) : $this->source->capture($actor, $this->deviceId, $this->ticketId, $reference['groups'], $reference['snapshot_version']);
                $entry['content'] .= $binding->providerText();
                $stored = $binding->toStorage();
                $encoded = json_encode($stored, JSON_THROW_ON_ERROR);
                if (strlen($encoded) > 6144) {
                    throw new ObliChatRejected('invalid_request', 422);
                }
                $bindings[$binding->version().':'.implode(',', $binding->groups())] = $stored;
            }
            $rendered[] = $entry;
        }
        if (array_sum(array_map(fn ($message) => strlen($message['content']), $rendered)) > 12000) {
            throw new ObliChatRejected('invalid_request', 422);
        }
        $context = ['actor_fingerprint' => ObliStaffAccess::fingerprint($actor), 'device_id' => $this->deviceId, 'ticket_id' => $this->ticketId, 'authority' => $this->authority, 'sources' => array_values($bindings)];
        if (count($bindings) > 8 || strlen(json_encode($context, JSON_THROW_ON_ERROR)) > 32768) {
            throw new ObliChatRejected('invalid_request', 422);
        }

        return [$rendered, [], $context];
    }

    public function revalidate(User $actor, object $row): void
    {
        $context = $this->context($actor, $row);
        foreach ($context['sources'] as $stored) {
            $this->source->assertCurrent($actor, ItObliDiagnosticBinding::fromStorage($stored));
        }
    }

    private function historical(User $actor, array $reference): ItObliDiagnosticBinding
    {
        $row = DB::table('obli_ai_requests')->where('user_id', $actor->id)->where('context_kind', 'technician')->where('request_uuid', strtolower($reference['source_request_uuid']))->first();
        if (! $row || ! CarbonImmutable::parse($row->expires_at)->isFuture()) {
            throw new ObliChatRejected('source_unavailable', 404);
        }
        $context = $this->context($actor, $row);
        foreach ($context['sources'] as $stored) {
            $binding = ItObliDiagnosticBinding::fromStorage($stored);
            if (hash_equals($binding->version(), $reference['snapshot_version']) && $binding->groups() === $reference['groups']) {
                $this->source->assertCurrent($actor, $binding);

                return $binding;
            }
        }
        throw new ObliChatRejected('source_unavailable', 404);
    }

    private function context(User $actor, object $row): array
    {
        try {
            if (($row->context_kind ?? null) !== 'technician' || ! is_string($row->investigation_context_encrypted)) {
                throw new ObliChatRejected('source_unavailable', 404);
            }
            $encoded = Crypt::decryptString($row->investigation_context_encrypted);
            if (strlen($encoded) > 32768) {
                throw new ObliChatRejected('source_unavailable', 404);
            }
            $data = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($data) || array_keys($data) !== ['actor_fingerprint', 'device_id', 'ticket_id', 'authority', 'sources'] || ! is_string($data['actor_fingerprint']) || ! hash_equals($data['actor_fingerprint'], ObliStaffAccess::fingerprint($actor)) || $data['device_id'] !== $this->deviceId || $data['ticket_id'] !== $this->ticketId || $data['authority'] !== $this->authority || ! is_array($data['sources']) || ! array_is_list($data['sources']) || count($data['sources']) > 8) {
                throw new ObliChatRejected('source_unavailable', 404);
            }

            return $data;
        } catch (Throwable) {
            throw new ObliChatRejected('source_unavailable', 404);
        }
    }
}
