<?php

namespace App\Services\Integration;

use App\Domain\Monitoring\Exceptions\RuntimePayloadInvalid;
use App\Domain\Monitoring\Exceptions\RuntimeSiteScopeViolation;
use App\Models\Integration\IntegrationEvent;
use App\Models\Integration\IntegrationSiteConfig;
use App\Services\Integration\Data\VerifiedWebhookBinding;
use App\Services\Integration\Exceptions\WebhookBindingUnavailable;
use Carbon\CarbonImmutable;

final readonly class ProviderEventProjector
{
    private const ALLOWED_KEYS = [
        'event_family',
        'site_id',
        'provider',
        'source_app',
        'source_event_id',
        'occurred_at',
        'severity',
        'event_type',
        'normalized_payload',
        'body_hash',
        'webhook_binding',
    ];

    public function __construct(
        private AlertRoutingService $routing,
        private ProviderWebhookBindingGuard $bindings,
    ) {}

    /** @param array<string|int, mixed> $payload */
    public function project(
        array $payload,
        ?int $trustedSiteId,
        bool $requireWebhookBinding = false,
    ): void {
        if (array_diff(array_keys($payload), self::ALLOWED_KEYS) !== []) {
            throw new RuntimePayloadInvalid('Provider event payload is invalid.');
        }

        $siteId = $payload['site_id'] ?? null;
        $provider = $payload['provider'] ?? null;
        $sourceApp = $payload['source_app'] ?? null;
        $sourceEventId = $payload['source_event_id'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;
        $severity = $payload['severity'] ?? null;
        $eventType = $payload['event_type'] ?? null;
        $normalized = $payload['normalized_payload'] ?? null;
        $bodyHash = $payload['body_hash'] ?? null;
        $webhookBinding = $payload['webhook_binding'] ?? null;

        if (! is_int($siteId) || $siteId < 1
            || ! is_string($provider) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $provider) !== 1
            || ! $this->boundedText($sourceApp, 64)
            || ! $this->boundedText($sourceEventId, 255)
            || ! $this->boundedText($eventType, 255)
            || ! is_string($severity) || ! in_array($severity, ['info', 'warn', 'critical'], true)
            || ! is_string($occurredAt) || strlen($occurredAt) > 64
            || ! is_array($normalized) || ! $this->safeValue($normalized, 0)
            || ! is_string($bodyHash) || preg_match('/^[a-f0-9]{64}$/', $bodyHash) !== 1) {
            throw new RuntimePayloadInvalid('Provider event payload is invalid.');
        }

        if ($trustedSiteId !== null && $trustedSiteId !== $siteId) {
            throw new RuntimeSiteScopeViolation('Provider event Site does not match trusted routing context.');
        }

        if ($requireWebhookBinding && ! is_array($webhookBinding)) {
            throw new RuntimePayloadInvalid('Provider webhook binding is invalid.');
        }

        $canonicalDeviceId = null;
        if ($webhookBinding !== null) {
            if (! is_array($webhookBinding)) {
                throw new RuntimePayloadInvalid('Provider webhook binding is invalid.');
            }
            try {
                [$providerConnectionId, $binding] = VerifiedWebhookBinding::fromRuntimePayload($webhookBinding);
            } catch (\InvalidArgumentException $exception) {
                throw new RuntimePayloadInvalid('Provider webhook binding is invalid.', previous: $exception);
            }
            try {
                $this->bindings->assertActive($provider, $providerConnectionId, $siteId, $binding);
            } catch (WebhookBindingUnavailable $exception) {
                throw new RuntimeSiteScopeViolation(
                    'Provider webhook binding no longer matches its canonical target.',
                    previous: $exception,
                );
            }
            $canonicalDeviceId = $binding->canonicalDeviceId;
        } elseif (! IntegrationSiteConfig::query()
            ->forProvider($provider)
            ->active()
            ->where('site_id', $siteId)
            ->whereHas('site', fn ($site) => $site
                ->where('is_active', true)
                ->where(fn ($operational) => $operational->whereNull('archived')->orWhere('archived', false))
                ->whereNull('archived_at'))
            ->exists()) {
            throw new RuntimeSiteScopeViolation('Provider event Site is not actively mapped.');
        }

        try {
            $timestamp = CarbonImmutable::parse($occurredAt)->utc();
        } catch (\Throwable $exception) {
            throw new RuntimePayloadInvalid('Provider event timestamp is invalid.', previous: $exception);
        }

        $evidence = [...$normalized, 'evidence_hash' => $bodyHash];
        $existing = IntegrationEvent::query()
            ->where('provider', $provider)
            ->where('source_event_id', $sourceEventId)
            ->first();

        if ($existing !== null) {
            if ((int) $existing->site_id !== $siteId
                || ($existing->canonical_device_id === null ? null : (int) $existing->canonical_device_id) !== $canonicalDeviceId
                || $existing->source_app !== $sourceApp
                || $existing->severity !== $severity
                || $existing->event_type !== $eventType
                || ($existing->normalized_payload ?? []) !== $evidence) {
                throw new RuntimePayloadInvalid('Provider event identity was reused with different content.');
            }

            // A legacy or interrupted projection may have persisted the source
            // before routing completed. Routing is deterministic and signal
            // ingestion is idempotent, so replay safely converges here.
            $this->routing->processEvent($existing);

            return;
        }

        $event = IntegrationEvent::query()->create([
            'site_id' => $siteId,
            'canonical_device_id' => $canonicalDeviceId,
            'provider' => $provider,
            'source_app' => $sourceApp,
            'source_event_id' => $sourceEventId,
            'occurred_at' => $timestamp,
            'received_at' => now(),
            'severity' => $severity,
            'event_type' => $eventType,
            'normalized_payload' => $evidence,
            'raw_payload' => null,
        ]);

        $this->routing->processEvent($event);
    }

    private function boundedText(mixed $value, int $maximum): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= $maximum;
    }

    private function safeValue(mixed $value, int $depth): bool
    {
        if ($depth > 4) {
            return false;
        }

        if (is_array($value)) {
            if (count($value) > 100) {
                return false;
            }

            foreach ($value as $key => $child) {
                if (is_string($key)
                    && (strlen($key) > 64
                        || preg_match('/password|secret|token|credential|authorization|cookie|^raw_/i', $key) === 1)) {
                    return false;
                }
                if (! $this->safeValue($child, $depth + 1)) {
                    return false;
                }
            }

            return true;
        }

        return $value === null || is_bool($value) || is_int($value) || is_float($value)
            || (is_string($value) && mb_strlen($value) <= 1024);
    }
}
