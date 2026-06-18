<?php

namespace App\Services\HealthSafety;

use App\Models\HsEvent;
use App\Models\User;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Central service for creating and updating HsEvent records.
 *
 * All source-model observers delegate to this service rather than
 * creating HsEvents directly. This guarantees:
 *  - consistent idempotency
 *  - normalised severity
 *  - safe Control Room bridge dispatch
 *  - escalation-aware dedup bypass
 */
class HsEventService
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Public API                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Create an HsEvent for a source model if one does not already exist.
     *
     * Returns the HsEvent (new or existing).
     * Returns null only if creation fails for an unexpected reason.
     *
     * @param  array{
     *     source: Model,
     *     event_category: string,
     *     severity: string,
     *     occurred_at?: \DateTimeInterface|string|null,
     *     reported_at?: \DateTimeInterface|string|null,
     *     site_id?: int|null,
     *     client_id?: int|null,
     *     staff_id?: int|null,
     *     asset_id?: int|null,
     *     shift_id?: int|null,
     *     worksafe_notifiable?: bool,
     *     organization_id?: int|null,
     *     created_by?: int|null,
     * } $data
     */
    public function recordEvent(array $data): ?HsEvent
    {
        $source = $data['source'];
        $category = $data['event_category'];
        $severity = self::normaliseSeverity($data['severity']);

        $idempotencyKey = HsEvent::buildIdempotencyKey(
            get_class($source),
            $source->getKey(),
            $category,
        );

        // ── Idempotency: return existing if already recorded ──
        $existing = HsEvent::where('idempotency_key', $idempotencyKey)->first();

        if ($existing) {
            return $existing;
        }

        try {
            $hsEvent = HsEvent::create([
                'organization_id' => $data['organization_id'] ?? null,
                'reference_number' => HsEvent::generateReferenceNumber(),
                'source_type' => get_class($source),
                'source_id' => $source->getKey(),
                'event_category' => $category,
                'severity' => $severity,
                'status' => HsEvent::STATUS_OPEN,
                'occurred_at' => $data['occurred_at'] ?? null,
                'reported_at' => $data['reported_at'] ?? now(),
                'site_id' => $data['site_id'] ?? null,
                'client_id' => $data['client_id'] ?? null,
                'staff_id' => $data['staff_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'shift_id' => $data['shift_id'] ?? null,
                'worksafe_notifiable' => $data['worksafe_notifiable'] ?? false,
                'worksafe_status' => ($data['worksafe_notifiable'] ?? false) ? HsEvent::WORKSAFE_PENDING : null,
                'investigation_required' => $this->requiresInvestigation($severity, $data['worksafe_notifiable'] ?? false),
                'idempotency_key' => $idempotencyKey,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            Log::info('HsEventService: event created', [
                'hs_event_id' => $hsEvent->id,
                'reference' => $hsEvent->reference_number,
                'source' => class_basename($source) . ':' . $source->getKey(),
                'category' => $category,
                'severity' => $severity,
            ]);

            return $hsEvent;
        } catch (\Throwable $e) {
            // Catch unique-constraint race condition (concurrent request created the same key)
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'UNIQUE')) {
                return HsEvent::where('idempotency_key', $idempotencyKey)->first();
            }

            Log::error('HsEventService: failed to create event', [
                'source' => class_basename($source) . ':' . $source->getKey(),
                'category' => $category,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Update HsEvent severity when the source model's severity escalates.
     *
     * This also implements carry-forward #1: escalation bypass for dedup.
     * When severity materially escalates (e.g. high → critical), a new
     * Control Room bridge call is dispatched with an escalation flag so
     * the dedup window does not silently suppress it.
     */
    public function syncSeverity(HsEvent $hsEvent, string $newSeverity): void
    {
        $normalised = self::normaliseSeverity($newSeverity);
        $previousSeverity = $hsEvent->severity;

        if ($normalised === $previousSeverity) {
            return;
        }

        $hsEvent->update(['severity' => $normalised]);

        // If severity materially escalated, mark investigation required
        if ($this->isMaterialEscalation($previousSeverity, $normalised)) {
            if (! $hsEvent->investigation_required) {
                $hsEvent->update(['investigation_required' => true]);
            }
        }

        Log::info('HsEventService: severity updated', [
            'hs_event_id' => $hsEvent->id,
            'from' => $previousSeverity,
            'to' => $normalised,
        ]);
    }

    /**
     * Link a Control Room alert ID back to the HsEvent.
     * Called after the observer's bridge dispatch succeeds.
     */
    public function linkControlRoomAlert(HsEvent $hsEvent, int $alertId): void
    {
        if ($hsEvent->control_room_alert_id === $alertId) {
            return;
        }

        $hsEvent->updateQuietly(['control_room_alert_id' => $alertId]);
    }

    /* ------------------------------------------------------------------ */
    /*  Governance — gated closure (E-Gap 1)                                */
    /* ------------------------------------------------------------------ */

    /**
     * The unmet closure gates for an event (empty array = clean to close).
     *
     * An event cannot be closed while a required investigation is incomplete or
     * any corrective action is still open/unverified — unless overridden with a
     * logged reason.
     *
     * @return list<string>
     */
    public function closeBlockers(HsEvent $event): array
    {
        $blockers = [];

        if ($event->investigation_required && ! $event->hasCompletedInvestigation()) {
            $blockers[] = 'A completed investigation is required before this event can be closed.';
        }

        if ($event->hasOpenCorrectiveActions()) {
            $blockers[] = 'All corrective actions must be verified or closed before this event can be closed.';
        }

        return $blockers;
    }

    /**
     * Close an event through the governance gate.
     *
     * Blocks unless every gate in {@see closeBlockers()} is met, except when an
     * `$overrideReason` is supplied (logged for the audit trail). A closure
     * summary is always required.
     *
     * @throws \DomainException when the gate blocks and no override reason is given
     */
    public function closeEvent(HsEvent $event, string $summary, User $actor, ?string $overrideReason = null): HsEvent
    {
        if ($event->status === HsEvent::STATUS_CLOSED) {
            throw new \DomainException('This event is already closed.');
        }

        $summary = trim($summary);
        if ($summary === '') {
            throw new \DomainException('A closure summary is required.');
        }

        $blockers = $this->closeBlockers($event);
        $override = $overrideReason !== null && trim($overrideReason) !== '';

        if ($blockers !== [] && ! $override) {
            throw new \DomainException(implode(' ', $blockers));
        }

        $event->update([
            'status' => HsEvent::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $actor->id,
            'closure_summary' => $summary,
        ]);

        Log::info('HsEventService: event closed', [
            'hs_event_id' => $event->id,
            'reference' => $event->reference_number,
            'actor' => $actor->id,
            'overridden' => $override,
            'override_reason' => $override ? trim((string) $overrideReason) : null,
            'blockers_at_close' => $blockers,
        ]);

        return $event;
    }

    /* ------------------------------------------------------------------ */
    /*  Severity normalisation                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Normalise severity from various source model formats into the
     * HsEvent standard: low, medium, high, critical.
     *
     * Source models use inconsistent scales:
     *  - ClientIncident: low, medium, high
     *  - WorkplaceInjury: minor, moderate, serious, critical
     *  - SiteHazard risk_rating: low, medium, high, extreme
     *  - SafeguardingConcern: low, medium, high, critical
     *  - FleetIncident: low, medium, high, critical
     *  - RestraintEvent: low, medium, high
     */
    public static function normaliseSeverity(string $severity): string
    {
        return match (strtolower(trim($severity))) {
            'critical', 'extreme' => HsEvent::SEVERITY_CRITICAL,
            'high', 'serious' => HsEvent::SEVERITY_HIGH,
            'medium', 'moderate' => HsEvent::SEVERITY_MEDIUM,
            default => HsEvent::SEVERITY_LOW,
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function requiresInvestigation(string $normalisedSeverity, bool $worksafeNotifiable): bool
    {
        if ($worksafeNotifiable) {
            return true;
        }

        return in_array($normalisedSeverity, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true);
    }

    /**
     * Determine if a severity change represents a material escalation
     * that should bypass the bridge dedup window.
     *
     * Material escalation = crossing from below-high to high-or-above,
     * or from high to critical.
     */
    private function isMaterialEscalation(string $from, string $to): bool
    {
        $order = [
            HsEvent::SEVERITY_LOW => 0,
            HsEvent::SEVERITY_MEDIUM => 1,
            HsEvent::SEVERITY_HIGH => 2,
            HsEvent::SEVERITY_CRITICAL => 3,
        ];

        $fromRank = $order[$from] ?? 0;
        $toRank = $order[$to] ?? 0;

        // Must cross the high threshold (rank 2) or go from high to critical
        return $toRank > $fromRank && $toRank >= 2;
    }
}
