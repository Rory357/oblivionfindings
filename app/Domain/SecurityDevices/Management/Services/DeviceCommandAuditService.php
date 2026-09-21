<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandAuditEvent;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

final class DeviceCommandAuditService
{
    /** @param array<string, mixed> $safeContext */
    public function append(
        DeviceCommandRequest $request,
        ?User $actor,
        string $action,
        array $safeContext = [],
    ): DeviceCommandAuditEvent {
        $this->assertSafeContext($safeContext);

        return DB::transaction(function () use ($request, $actor, $action, $safeContext): DeviceCommandAuditEvent {
            // The request row serializes both the first and later appends.
            // A tail range query can lock another request's next-key gap.
            $locked = DeviceCommandRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            if (! hash_equals($locked->command_uuid, $request->command_uuid)) {
                throw new UnexpectedValueException('Audit request identity changed.');
            }
            $previous = $locked->audit_tail_event_id === null ? null
                : DeviceCommandAuditEvent::query()->whereKey($locked->audit_tail_event_id)->lockForUpdate()->first();
            if ($locked->audit_tail_event_id !== null && (! $previous || (int) $previous->device_command_request_id !== (int) $locked->id)) {
                throw new UnexpectedValueException('Audit tail evidence is missing or belongs to another request.');
            }
            $occurredAt = Carbon::now('UTC');
            $context = $this->canonicalJson($safeContext);
            $eventHash = hash('sha256', implode('|', [
                $locked->command_uuid,
                (string) ($previous?->event_hash ?? ''),
                (string) ($actor?->id ?? ''),
                $action,
                $context,
                $occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            ]));

            $event = DeviceCommandAuditEvent::query()->create([
                'device_command_request_id' => $locked->id,
                'actor_user_id' => $actor?->id,
                'action' => $action,
                'safe_context' => $safeContext,
                'previous_hash' => $previous?->event_hash,
                'event_hash' => $eventHash,
                'occurred_at' => $occurredAt,
            ]);
            // Internal metadata only. Preserve terminal models, signed fields,
            // lifecycle timestamps, and the caller's potentially stale instance.
            DB::table('device_command_requests')->where('id', $locked->id)
                ->update(['audit_tail_event_id' => $event->id]);

            return $event;
        });
    }

    public function assertSafeContext(array $context): void
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/password|secret|token|credential|private.?key|raw.?command|clinical/i', $key)) {
                throw new UnexpectedValueException('Sensitive device command data cannot enter the audit summary.');
            }
            if (is_array($value)) {
                $this->assertSafeContext($value);
            } elseif (! is_scalar($value) && $value !== null) {
                throw new UnexpectedValueException('Device command audit context contains an unsupported value.');
            }
        }
    }

    private function canonicalJson(array $value): string
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = json_decode($this->canonicalJson($item), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
