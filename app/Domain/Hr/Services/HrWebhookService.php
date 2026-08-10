<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Exceptions\UnsafeWebhookHeaders;
use App\Domain\Hr\Jobs\DeliverHrWebhookJob;
use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrWebhookService
{
    public function __construct(
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

    public function queueRetry(HrWebhookDelivery $delivery): HrWebhookDelivery
    {
        return DB::transaction(function () use ($delivery): HrWebhookDelivery {
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

            if (HrWebhookDelivery::query()->where('retry_of_id', $locked->id)->exists()) {
                throw ValidationException::withMessages([
                    'delivery' => 'A retry has already been queued for this delivery.',
                ]);
            }

            $retry = HrWebhookDelivery::query()->create([
                'endpoint_id' => $locked->endpoint_id,
                'retry_of_id' => $locked->id,
                'event_type' => $locked->event_type,
                'event_uuid' => (string) Str::uuid(),
                'payload' => $locked->payload ?? [],
                'status' => HrWebhookDelivery::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => max(1, (int) ($endpoint->retry_limit ?: $locked->max_attempts ?: 3)),
                'queued_at' => now(),
                'idempotency_key' => sha1($locked->endpoint_id.'|retry|'.Str::uuid()),
            ]);

            DeliverHrWebhookJob::dispatch($retry->id)->afterCommit();

            return $retry;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createEndpoint(int $userId, array $attributes): HrWebhookEndpoint
    {
        return $this->persistEndpoint(fn () => HrWebhookEndpoint::query()->create([
            'name' => trim((string) $attributes['name']),
            'target_url' => $attributes['target_url'],
            'signing_secret' => $attributes['signing_secret'] ?? null,
            'event_types' => array_values(array_unique($attributes['event_types'] ?? [])),
            'headers' => $this->normalizedHeaders($attributes['headers'] ?? null),
            'timeout_seconds' => (int) ($attributes['timeout_seconds'] ?? 10),
            'retry_limit' => (int) ($attributes['retry_limit'] ?? 3),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateEndpoint(HrWebhookEndpoint $endpoint, int $userId, array $attributes): HrWebhookEndpoint
    {
        return DB::transaction(function () use ($endpoint, $userId, $attributes): HrWebhookEndpoint {
            $locked = HrWebhookEndpoint::query()->lockForUpdate()->findOrFail($endpoint->id);

            $this->persistEndpoint(fn () => $locked->update([
                'name' => array_key_exists('name', $attributes)
                    ? trim((string) $attributes['name'])
                    : $locked->name,
                'target_url' => $attributes['target_url'] ?? $locked->target_url,
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
                'updated_by' => $userId,
            ]));

            return $locked->fresh();
        });
    }

    /**
     * @return Collection<int, HrWebhookEndpoint>
     */
    public function endpointsForApplication(): Collection
    {
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
}
