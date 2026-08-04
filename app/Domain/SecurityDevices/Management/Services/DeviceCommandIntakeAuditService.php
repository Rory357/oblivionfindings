<?php

namespace App\Domain\SecurityDevices\Management\Services;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandIntakeAudit;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class DeviceCommandIntakeAuditService
{
    public function recordAllowed(DeviceCommandRequest $command, User $actor): DeviceCommandIntakeAudit
    {
        return DeviceCommandIntakeAudit::query()->create([
            'device_command_request_id' => $command->id,
            'actor_user_id' => $actor->id,
            'outcome' => 'allowed',
            'safe_reason_code' => $command->wasRecentlyCreated ? 'request_created' : 'idempotent_request_returned',
            'target_fingerprint' => $this->fingerprint('device', (string) $command->device_id),
            'capability' => $command->capability,
            'capability_fingerprint' => $this->fingerprint('capability', $command->capability),
            'occurred_at' => CarbonImmutable::now('UTC')->startOfSecond(),
        ]);
    }

    public function recordDenied(Request $request, Throwable $exception): DeviceCommandIntakeAudit
    {
        return $this->recordDeniedWithCode($request, $this->reasonCode($exception));
    }

    public function recordDeniedResponse(Request $request, int $status): DeviceCommandIntakeAudit
    {
        return $this->recordDeniedWithCode($request, match ($status) {
            403 => 'authorization_denied',
            404 => 'target_not_found',
            422 => 'validation_failed',
            429 => 'rate_limited',
            default => 'request_rejected',
        });
    }

    private function recordDeniedWithCode(Request $request, string $reasonCode): DeviceCommandIntakeAudit
    {
        $targetKey = $this->targetKey($request);
        $capability = $request->input('capability');
        $boundedCapability = is_string($capability)
            && preg_match('/^[A-Za-z0-9_.:-]{1,120}$/', $capability) === 1
                ? $capability
                : null;

        return DeviceCommandIntakeAudit::query()->create([
            'actor_user_id' => $request->user()?->id,
            'outcome' => 'denied',
            'safe_reason_code' => $reasonCode,
            'target_fingerprint' => $this->fingerprint('device', $targetKey),
            'capability' => null,
            'capability_fingerprint' => $boundedCapability === null
                ? null
                : $this->fingerprint('capability', $boundedCapability),
            'occurred_at' => CarbonImmutable::now('UTC')->startOfSecond(),
        ]);
    }

    private function targetKey(Request $request): string
    {
        $target = $request->route('device');
        if (is_object($target) && method_exists($target, 'getRouteKey')) {
            return (string) $target->getRouteKey();
        }
        if (is_scalar($target)) {
            return (string) $target;
        }

        $deviceIds = $request->input('device_ids');
        if (! is_array($deviceIds)) {
            return 'unresolved';
        }
        $bounded = collect($deviceIds)
            ->filter(fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->take(100)
            ->values();

        return $bounded->isEmpty()
            ? 'unresolved'
            : 'batch:'.hash('sha256', $bounded->implode(','));
    }

    private function reasonCode(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return 'validation_failed';
        }
        if ($exception instanceof AuthorizationException) {
            return 'authorization_denied';
        }
        if ($exception instanceof ModelNotFoundException) {
            return 'target_not_found';
        }
        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                403 => 'authorization_denied',
                404 => 'target_not_found',
                429 => 'rate_limited',
                default => 'request_rejected',
            };
        }

        return 'request_failed';
    }

    private function fingerprint(string $namespace, string $value): string
    {
        $key = hash('sha256', (string) config('app.key'), true);

        return hash_hmac('sha256', $namespace.'|'.$value, $key);
    }
}
