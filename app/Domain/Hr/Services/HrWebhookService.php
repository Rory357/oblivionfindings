<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Exceptions\UnsafeWebhookDestination;
use App\Domain\Hr\Exceptions\UnsafeWebhookHeaders;
use App\Domain\Hr\Jobs\DeliverHrWebhookJob;
use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrWebhookService
{
    public function __construct(
        private readonly HrWebhookDestinationPolicy $destinationPolicy,
        private readonly HrWebhookHeaderPolicy $headerPolicy,
    ) {}

    public const SUPPORTED_EVENTS = [
        'employee.created',
        'employee.rehired',
        'leave.request.submitted',
        'leave.request.approved',
        'leave.request.declined',
        'leave.request.escalated',
        'recruitment.offer.approved',
        'recruitment.offer.sent',
        'recruitment.offer.responded',
        'recruitment.offer.converted',
        'onboarding.checklist.completed',
        'offboarding.checklist.completed',
        'payroll.run.locked',
        'payroll.run.exported',
        'payroll.run.paid',
    ];

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function eventOptions(): array
    {
        return collect(self::SUPPORTED_EVENTS)
            ->map(fn (string $event) => [
                'value' => $event,
                'label' => str_replace('.', ' -> ', $event),
            ])
            ->values()
            ->all();
    }

    /**
     * Publish an application-global event without carrying the legacy storage
     * marker as event identity or filtering endpoints by it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publishApplicationEvent(string $eventType, array $payload): int
    {
        $endpoints = HrWebhookEndpoint::query()
            ->active()
            ->where(function ($query) use ($eventType) {
                $query->whereJsonContains('event_types', $eventType)
                    ->orWhereJsonContains('event_types', '*');
            })
            ->get();

        try {
            app(HrAutomationService::class)->handleApplicationEvent($eventType, $payload);
        } catch (\Throwable) {
            // Do not block webhook publishing if automation execution fails.
        }

        return $this->createDeliveries($endpoints, $eventType, $payload);
    }

    /**
     * @param  Collection<int, HrWebhookEndpoint>  $endpoints
     * @param  array<string, mixed>  $payload
     */
    private function createDeliveries(
        Collection $endpoints,
        string $eventType,
        array $payload,
    ): int {
        if ($endpoints->isEmpty()) {
            return 0;
        }

        $eventUuid = (string) Str::uuid();

        $deliveries = $endpoints->map(function (HrWebhookEndpoint $endpoint) use ($eventType, $payload, $eventUuid) {
            $delivery = HrWebhookDelivery::query()->create([
                'endpoint_id' => $endpoint->id,
                'event_type' => $eventType,
                'event_uuid' => $eventUuid,
                'payload' => $payload,
                'status' => HrWebhookDelivery::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => max(1, (int) $endpoint->retry_limit),
                'queued_at' => now(),
                'idempotency_key' => sha1($endpoint->id.'|'.$eventUuid),
            ]);
            DeliverHrWebhookJob::dispatch($delivery->id)->afterCommit();

            return $delivery;
        });

        return $deliveries->count();
    }

    public function queueRetry(User $actor, HrWebhookDelivery $delivery): HrWebhookDelivery
    {
        $this->assertActorCanManage($actor);

        $preflightEndpoint = HrWebhookEndpoint::query()->find($delivery->endpoint_id);
        if (! $preflightEndpoint || ! $preflightEndpoint->is_active) {
            throw ValidationException::withMessages([
                'delivery' => 'Resume the webhook endpoint before retrying this delivery.',
            ]);
        }
        $preflightTargetUrl = (string) $preflightEndpoint->target_url;
        try {
            $this->destinationPolicy->authorize($preflightTargetUrl);
        } catch (UnsafeWebhookDestination) {
            throw ValidationException::withMessages([
                'delivery' => 'The webhook destination is not approved. Update the endpoint before retrying.',
            ]);
        }

        return DB::transaction(function () use ($delivery, $preflightTargetUrl): HrWebhookDelivery {
            $locked = HrWebhookDelivery::query()
                ->lockForUpdate()
                ->findOrFail($delivery->id);

            if ($locked->status !== HrWebhookDelivery::STATUS_FAILED) {
                throw ValidationException::withMessages([
                    'delivery' => 'Only a failed webhook delivery can be retried.',
                ]);
            }

            $endpoint = HrWebhookEndpoint::query()
                ->lockForUpdate()
                ->find($locked->endpoint_id);
            if (! $endpoint || ! $endpoint->is_active) {
                throw ValidationException::withMessages([
                    'delivery' => 'Resume the webhook endpoint before retrying this delivery.',
                ]);
            }
            if (! hash_equals($preflightTargetUrl, (string) $endpoint->target_url)) {
                throw ValidationException::withMessages([
                    'delivery' => 'The webhook endpoint changed while the retry was being queued. Try again.',
                ]);
            }

            if (HrWebhookDelivery::query()->where('retry_of_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'delivery' => 'A retry has already been queued for this delivery.',
                ]);
            }

            $logicalDelivery = $this->logicalDelivery($locked);

            $retry = HrWebhookDelivery::query()->create([
                'endpoint_id' => $locked->endpoint_id,
                'retry_of_id' => $locked->id,
                'event_type' => $logicalDelivery->event_type,
                'event_uuid' => $logicalDelivery->event_uuid,
                'payload' => $logicalDelivery->payload ?? [],
                'status' => HrWebhookDelivery::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => max(1, (int) ($endpoint->retry_limit ?: $locked->max_attempts ?: 3)),
                'queued_at' => now(),
                'idempotency_key' => sha1($logicalDelivery->idempotency_key.'|manual-retry|'.$locked->id),
            ]);

            DeliverHrWebhookJob::dispatch($retry->id)->afterCommit();

            return $retry;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createEndpoint(User $actor, array $attributes): HrWebhookEndpoint
    {
        $this->assertActorCanManage($actor);
        $targetUrl = $this->normalizedDestination((string) $attributes['target_url']);

        return $this->persistEndpoint(fn () => HrWebhookEndpoint::query()->create([
            'name' => trim((string) $attributes['name']),
            'target_url' => $targetUrl,
            'signing_secret' => $attributes['signing_secret'] ?? null,
            'event_types' => array_values(array_unique($attributes['event_types'] ?? [])),
            'headers' => $this->normalizedHeaders($attributes['headers'] ?? null),
            'timeout_seconds' => (int) ($attributes['timeout_seconds'] ?? 10),
            'retry_limit' => (int) ($attributes['retry_limit'] ?? 3),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateEndpoint(
        User $actor,
        HrWebhookEndpoint $endpoint,
        array $attributes,
    ): HrWebhookEndpoint {
        $this->assertActorCanManage($actor);
        $targetWasProvided = array_key_exists('target_url', $attributes);
        $preflightTargetUrl = (string) $endpoint->target_url;
        $preflightIsActive = (bool) $endpoint->is_active;
        $willBeActive = array_key_exists('is_active', $attributes)
            ? (bool) $attributes['is_active']
            : $preflightIsActive;
        $normalizedTargetUrl = null;
        if ($targetWasProvided || $willBeActive) {
            $normalizedTargetUrl = $this->normalizedDestination(
                $targetWasProvided ? (string) $attributes['target_url'] : $preflightTargetUrl,
            );
        }

        return DB::transaction(function () use (
            $endpoint,
            $actor,
            $attributes,
            $targetWasProvided,
            $willBeActive,
            $preflightTargetUrl,
            $preflightIsActive,
            $normalizedTargetUrl,
        ): HrWebhookEndpoint {
            $locked = HrWebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);
            if (! array_key_exists('is_active', $attributes)
                && $preflightIsActive !== (bool) $locked->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'The webhook endpoint state changed while it was being updated. Try again.',
                ]);
            }
            if ($willBeActive && ! $targetWasProvided
                && ! hash_equals($preflightTargetUrl, (string) $locked->target_url)) {
                throw ValidationException::withMessages([
                    'target_url' => 'The webhook endpoint changed while it was being updated. Try again.',
                ]);
            }
            $targetUrl = ($targetWasProvided || $willBeActive) && $normalizedTargetUrl !== null
                ? $normalizedTargetUrl
                : (string) $locked->target_url;

            $this->persistEndpoint(fn () => $locked->update([
                'name' => array_key_exists('name', $attributes)
                    ? trim((string) $attributes['name'])
                    : $locked->name,
                'target_url' => $targetUrl,
                'signing_secret' => array_key_exists('signing_secret', $attributes)
                    ? ($attributes['signing_secret'] ?: null)
                    : $locked->signing_secret,
                'event_types' => array_key_exists('event_types', $attributes)
                    ? array_values(array_unique($attributes['event_types'] ?? []))
                    : $locked->event_types,
                'headers' => array_key_exists('headers', $attributes)
                    ? $this->normalizedHeaders($attributes['headers'] ?: null)
                    : $locked->headers,
                'timeout_seconds' => (int) ($attributes['timeout_seconds'] ?? $locked->timeout_seconds),
                'retry_limit' => (int) ($attributes['retry_limit'] ?? $locked->retry_limit),
                'is_active' => array_key_exists('is_active', $attributes)
                    ? (bool) $attributes['is_active']
                    : $locked->is_active,
                'updated_by' => $actor->id,
            ]));

            return $locked->fresh();
        });
    }

    /**
     * @return Collection<int, HrWebhookEndpoint>
     */
    public function endpointsForApplication(User $actor): Collection
    {
        $this->assertActorCanManage($actor);

        return HrWebhookEndpoint::query()
            ->withCount([
                'deliveries',
                'deliveries as failed_deliveries_count' => fn ($query) => $query->where('status', HrWebhookDelivery::STATUS_FAILED),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function persistEndpoint(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'hr_webhook_endpoints_name_key_uq')
                || str_contains($message, 'hr_webhook_endpoints.application_name_key')) {
                throw ValidationException::withMessages([
                    'name' => 'A webhook endpoint with this name already exists.',
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<array-key, mixed>|null  $headers
     * @return array<string, string>|null
     */
    private function normalizedHeaders(?array $headers): ?array
    {
        try {
            return $this->headerPolicy->normalize($headers);
        } catch (UnsafeWebhookHeaders) {
            throw ValidationException::withMessages([
                'headers' => 'Custom headers must use safe names and values and cannot replace delivery authentication headers.',
            ]);
        }
    }

    private function normalizedDestination(string $url): string
    {
        try {
            return $this->destinationPolicy->authorize($url)->url;
        } catch (UnsafeWebhookDestination) {
            throw ValidationException::withMessages([
                'target_url' => 'Use a public HTTPS webhook URL. Private, local, reserved, or credential-bearing destinations are not allowed.',
            ]);
        }
    }

    private function logicalDelivery(HrWebhookDelivery $delivery): HrWebhookDelivery
    {
        $logicalDelivery = $delivery;
        $seen = [];

        while ($logicalDelivery->retry_of_id !== null) {
            if (isset($seen[$logicalDelivery->id])) {
                throw ValidationException::withMessages([
                    'delivery' => 'The webhook delivery lineage is invalid.',
                ]);
            }
            $seen[$logicalDelivery->id] = true;

            $parent = HrWebhookDelivery::query()->find($logicalDelivery->retry_of_id);
            if (! $parent || (int) $parent->endpoint_id !== (int) $delivery->endpoint_id) {
                throw ValidationException::withMessages([
                    'delivery' => 'The webhook delivery lineage is invalid.',
                ]);
            }
            $logicalDelivery = $parent;
        }

        return $logicalDelivery;
    }

    private function assertActorCanManage(User $actor): void
    {
        abort_unless($actor->canDo('hr.settings.manage'), 403);
    }
}
