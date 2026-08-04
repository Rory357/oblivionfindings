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
            $previous = DeviceCommandAuditEvent::query()
                ->where('device_command_request_id', $request->id)
                ->latest('id')
                ->lockForUpdate()
                ->first();
            $occurredAt = Carbon::now('UTC');
            $context = $this->canonicalJson($safeContext);
            $eventHash = hash('sha256', implode('|', [
                $request->command_uuid,
                (string) ($previous?->event_hash ?? ''),
                (string) ($actor?->id ?? ''),
                $action,
                $context,
                $occurredAt->format('Y-m-d\TH:i:s.u\Z'),
            ]));

            return DeviceCommandAuditEvent::query()->create([
                'device_command_request_id' => $request->id,
                'actor_user_id' => $actor?->id,
                'action' => $action,
                'safe_context' => $safeContext,
                'previous_hash' => $previous?->event_hash,
                'event_hash' => $eventHash,
                'occurred_at' => $occurredAt,
            ]);
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
