<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Jobs\DeliverHrWebhookJob;
use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HrWebhookService
{
    public const SUPPORTED_EVENTS = [
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
     * @param array<string, mixed> $payload
     */
    public function publish(?int $tenantId, string $eventType, array $payload): int
    {
        $endpoints = HrWebhookEndpoint::query()
            ->forTenant($tenantId)
            ->active()
            ->where(function ($query) use ($eventType) {
                $query->whereJsonContains('event_types', $eventType)
                    ->orWhereJsonContains('event_types', '*');
            })
            ->get();

        try {
            app(HrAutomationService::class)->handleEvent($tenantId, $eventType, $payload);
        } catch (\Throwable) {
            // Do not block webhook publishing if automation execution fails.
        }

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $eventUuid = (string) Str::uuid();

        $deliveries = $endpoints->map(function (HrWebhookEndpoint $endpoint) use ($tenantId, $eventType, $payload, $eventUuid) {
            $delivery = HrWebhookDelivery::query()->create([
                'endpoint_id' => $endpoint->id,
                'tenant_id' => $tenantId,
                'event_type' => $eventType,
                'event_uuid' => $eventUuid,
                'payload' => $payload,
                'status' => HrWebhookDelivery::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => max(1, (int) $endpoint->retry_limit),
                'queued_at' => now(),
                'idempotency_key' => sha1($endpoint->id . '|' . $eventUuid),
            ]);

            DeliverHrWebhookJob::dispatch($delivery->id);

            return $delivery;
        });

        return $deliveries->count();
    }

    public function queueRetry(HrWebhookDelivery $delivery): HrWebhookDelivery
    {
        $endpoint = $delivery->endpoint;

        $retry = HrWebhookDelivery::query()->create([
            'endpoint_id' => $delivery->endpoint_id,
            'tenant_id' => $delivery->tenant_id,
            'event_type' => $delivery->event_type,
            'event_uuid' => (string) Str::uuid(),
            'payload' => $delivery->payload ?? [],
            'status' => HrWebhookDelivery::STATUS_PENDING,
            'attempts' => 0,
            'max_attempts' => max(1, (int) ($endpoint?->retry_limit ?? $delivery->max_attempts ?: 3)),
            'queued_at' => now(),
            'idempotency_key' => sha1($delivery->endpoint_id . '|retry|' . Str::uuid()),
        ]);

        DeliverHrWebhookJob::dispatch($retry->id);

        return $retry;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function createEndpoint(?int $tenantId, int $userId, array $attributes): HrWebhookEndpoint
    {
        return HrWebhookEndpoint::query()->create([
            'tenant_id' => $tenantId,
            'name' => $attributes['name'],
            'target_url' => $attributes['target_url'],
            'signing_secret' => $attributes['signing_secret'] ?? null,
            'event_types' => array_values(array_unique($attributes['event_types'] ?? [])),
            'headers' => $attributes['headers'] ?? null,
            'timeout_seconds' => (int) ($attributes['timeout_seconds'] ?? 10),
            'retry_limit' => (int) ($attributes['retry_limit'] ?? 3),
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function updateEndpoint(HrWebhookEndpoint $endpoint, int $userId, array $attributes): HrWebhookEndpoint
    {
        $endpoint->update([
            'name' => $attributes['name'] ?? $endpoint->name,
            'target_url' => $attributes['target_url'] ?? $endpoint->target_url,
            'signing_secret' => array_key_exists('signing_secret', $attributes)
                ? ($attributes['signing_secret'] ?: null)
                : $endpoint->signing_secret,
            'event_types' => array_key_exists('event_types', $attributes)
                ? array_values(array_unique($attributes['event_types'] ?? []))
                : $endpoint->event_types,
            'headers' => array_key_exists('headers', $attributes) ? ($attributes['headers'] ?: null) : $endpoint->headers,
            'timeout_seconds' => (int) ($attributes['timeout_seconds'] ?? $endpoint->timeout_seconds),
            'retry_limit' => (int) ($attributes['retry_limit'] ?? $endpoint->retry_limit),
            'is_active' => array_key_exists('is_active', $attributes)
                ? (bool) $attributes['is_active']
                : $endpoint->is_active,
            'updated_by' => $userId,
        ]);

        return $endpoint->fresh();
    }

    /**
     * @return Collection<int, HrWebhookEndpoint>
     */
    public function endpointsForTenant(?int $tenantId): Collection
    {
        return HrWebhookEndpoint::query()
            ->forTenant($tenantId)
            ->withCount([
                'deliveries',
                'deliveries as failed_deliveries_count' => fn ($query) => $query->where('status', HrWebhookDelivery::STATUS_FAILED),
            ])
            ->orderBy('name')
            ->get();
    }
}
