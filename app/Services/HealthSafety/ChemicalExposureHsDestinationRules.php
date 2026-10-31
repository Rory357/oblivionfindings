<?php

namespace App\Services\HealthSafety;

use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\HsEvent;
use App\Models\SubstanceExposureRecord;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The exposure → H&S event rules shared by Codex's manager delivery and the automatic delivery (job 3), so both write
 * and check exactly the same projection, marker audit and response floor.
 */
final class ChemicalExposureHsDestinationRules
{
    /** A manager's delivery (Codex) and the automatic delivery after the save. Both prove the same marker. */
    public const MANAGER_AUDIT_ACTION = 'chemical.exposure.deliver_health_safety';
    public const AUTOMATIC_AUDIT_ACTION = 'chemical.exposure.deliver_health_safety_automatically';
    /** The one-off adoption of an H&S event the restored observer created, after an exact match (job 3). */
    public const ADOPTION_AUDIT_ACTION = 'chemical.exposure.adopt_health_safety_event';
    public const MARKER_AUDIT_ACTIONS = [self::MANAGER_AUDIT_ACTION, self::AUTOMATIC_AUDIT_ACTION, self::ADOPTION_AUDIT_ACTION];

    private const SEVERITIES = [HsEvent::SEVERITY_LOW, HsEvent::SEVERITY_MEDIUM, HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL];

    public static function projection(ChemicalExposureSourceVersion $version, ChemicalExposureSourceVersion $first): array
    {
        $snapshot = $version->content['root_after'];
        $context = $snapshot['original_context'];
        return ['source_type' => SubstanceExposureRecord::class, 'source_id' => (int) $version->exposure_id,
            'event_category' => HsEvent::CATEGORY_EXPOSURE, 'site_id' => (int) $version->site_id,
            'staff_id' => (int) $version->worker_id, 'client_id' => $context['client_id'], 'shift_id' => $context['shift_id'],
            'asset_id' => null, 'occurred_at' => CarbonImmutable::parse($snapshot['exposed_at'])->utc()->format('Y-m-d H:i:s'),
            'reported_at' => $first->recorded_at->copy()->utc()->format('Y-m-d H:i:s'),
            'idempotency_key' => HsEvent::buildIdempotencyKey(SubstanceExposureRecord::class, $version->exposure_id, HsEvent::CATEGORY_EXPOSURE)];
    }

    public static function eventProjection(HsEvent $event): array
    {
        $values = array_intersect_key($event->getAttributes(), array_flip(['source_type', 'source_id', 'event_category',
            'site_id', 'staff_id', 'client_id', 'shift_id', 'asset_id', 'idempotency_key']));
        foreach (['source_id', 'site_id', 'staff_id', 'client_id', 'shift_id', 'asset_id'] as $key) {
            $values[$key] = $event->getAttribute($key) === null ? null : (int) $event->getAttribute($key);
        }
        foreach (['occurred_at', 'reported_at'] as $key) {
            $values[$key] = $event->getAttribute($key)?->copy()->utc()->format('Y-m-d H:i:s');
        }
        return $values;
    }

    /** Only bounded scalar acceptance evidence reaches the protected source audit. */
    public static function markerAuditMeta(ChemicalExposureHsProjection $marker, ChemicalExposureSourceVersion $version): array
    {
        return ['actor_id' => $marker->recorded_by, 'root_type' => 'exposure', 'root_id' => $marker->exposure_id,
            'source_type' => 'exposure', 'source_id' => $marker->exposure_id, 'expected_version' => $version->expected_version,
            'version' => $marker->source_revision, 'source_version_id' => $marker->source_version_id,
            'idempotency_key' => $version->idempotency_key, 'changed' => true,
            'source_content_digest' => $marker->source_content_digest,
            'source_tuple_digest' => ChemicalCommandValues::digest(['hs_event_id' => $marker->hs_event_id,
                'projection' => $marker->projection, 'recorded_at' => $marker->recorded_at->copy()->utc()->format('Y-m-d H:i:s.u')])];
    }

    public static function severityRank(string $severity): int
    {
        $rank = array_search($severity, self::SEVERITIES, true);
        if ($rank === false) { throw new LogicException('Exposure destination severity is unavailable.'); }
        return $rank;
    }

    public static function meetsSeverityFloor(HsEvent $event, string $floor): bool
    {
        return self::severityRank($event->severity) >= self::severityRank($floor)
            && (! in_array($floor, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true) || $event->investigation_required);
    }

    public static function raiseResponseFloor(HsEvent $event, string $floor): void
    {
        if (self::severityRank($floor) > self::severityRank($event->severity)) { $event->severity = $floor; }
        if (in_array($floor, [HsEvent::SEVERITY_HIGH, HsEvent::SEVERITY_CRITICAL], true)) { $event->investigation_required = true; }
    }

    /** The reported treatment's response floor, from the accepted reported-fact contract of this exact version. */
    public static function responseFloor(SubstanceExposureRecord $root, ChemicalExposureSourceVersion $version): string
    {
        $facts = $version->content['facts'] ?? [];
        $snapshot = $version->content['root_after'];
        $contract = is_array($facts) && array_key_exists('reported_facts', $facts) ? $facts['reported_facts']
            : ChemicalExposureReportedFacts::legacy($snapshot);
        if (! is_array($contract)) { throw new LogicException('Exposure reported-fact contract is damaged.'); }
        ChemicalExposureReportedFacts::assertContract($contract, $snapshot);
        $response = ChemicalExposureReportedFacts::response($contract, ['exposure_id' => (int) $root->id,
            'worker_id' => (int) $root->user_id, 'site_id' => (int) $root->site_id, 'substance_id' => (int) $root->hazardous_substance_id]);
        $floor = $response['response_floor'] ?? null;
        if (($response['harm_assessment'] ?? null) !== 'unassessed' || ! in_array($floor, self::SEVERITIES, true)) {
            throw new LogicException('Exposure operational response lacks exact reported-fact proof.');
        }
        return $floor;
    }

    /**
     * True when a correction of the exposure's facts was accepted after the event's WorkSafe decision was signed, in
     * the revisions this delivery carries (after the previous marker, up to and including this version).
     *
     * @param  ChemicalExposureSourceVersion[]  $history
     */
    public static function correctedAfterDecision(HsEvent $event, array $history, int $previousRevision,
        ChemicalExposureSourceVersion $version): bool
    {
        if (! $event->hasSignedWorksafeDecision()) { return false; }
        foreach ($history as $step) {
            if ($step->aggregate_revision > $previousRevision && $step->aggregate_revision <= $version->aggregate_revision
                // The same instant counts as after: when the order can't be told, a person looks again.
                && $step->command === 'correct_exposure' && $step->recorded_at->greaterThanOrEqualTo($event->worksafe_decided_at)) {
                return true;
            }
        }
        return false;
    }
}
