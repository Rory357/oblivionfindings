<?php

namespace App\Services\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Private copied-source boundary. Integration is held until the prospective writer proof is composed. */
final class ChemicalExposureSourceProjectionService
{
    public const PERMISSIONS = ChemicalExposureCommandAccess::PERMISSIONS;

    public function __construct(private readonly ChemicalExposureAccessService $access, private readonly ChemicalExposureSourceService $sources, private readonly ChemicalExposureHsEventDeliveryService $destinations) {}

    /** Candidate discovery never hydrates copied H&S prose, names, medical fields or unrelated exposure rows. */
    public function readableHsEventIdsInContext(User $viewer, Builder $sourceCandidates, HealthSafetyReadContext $context): array
    {
        try {
            return $this->within($viewer, $context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($sourceCandidates, $context): array {
                if (! $this->access->canRead($current)) {
                    return [];
                }
                $events = $this->candidates($sourceCandidates, $reads, $sites);
                $ids = [];
                $events->chunkById(200, function ($rows) use ($current, $reads, $sites, $context, &$ids): void {
                    $sources = $this->access->readableQuery($current, $reads, $sites)
                        ->whereIn('id', $rows->pluck('source_id')->unique()->all())
                        ->get(['id', 'hazardous_substance_id', 'user_id', 'site_id', 'related_incident_id'])->keyBy('id');
                    foreach ($rows as $event) {
                        $source = $sources->get((int) $event->source_id);
                        if ($source !== null && $this->identityMatches($event, $source)) {
                            try {
                                $tuple = $this->sources->sourceProof((int) $source->id, $current, $reads, $sites);
                                if ($this->provenTuple($event, $tuple, $current, $reads, $sites)) {
                                    $ids[] = (int) $event->id;
                                }
                            } catch (ModelNotFoundException) {
                                // A disappearing source cannot keep a previously discovered binding readable.
                            } catch (ValidationException $refusal) {
                                if (! self::isCanonicalSourceRefusal($refusal)) { throw $refusal; }
                                $context->assertActive();
                            } catch (LogicException) {
                                $context->assertActive();
                            } catch (HttpExceptionInterface $denial) {
                                if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                                    throw $denial;
                                }
                            }
                        }
                    }
                }, 'hs_events.id', 'id');
                return array_values(array_unique($ids));
            });
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }
            return [];
        }
    }

    /** Operational H&S/CR authority remains the consuming caller's independent responsibility. */
    public function forHsEvent(int $id, User $viewer, ?HealthSafetyReadContext $context = null): array
    {
        try {
            return $this->within($viewer, $context, function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($id): array {
                // Classify only the literal family before private admission; no worker/source/context IDs or copied body.
                $identity = $reads->query(HsEvent::query())->whereKey($id)->first(['id', 'source_type']);
                if ($identity === null) {
                    return ['access' => 'unavailable', 'source' => null];
                }
                if (! in_array($identity->source_type, $this->sourceTypes(), true)) {
                    return ['access' => 'absent', 'source' => null, 'ignored' => true];
                }
                if (! $this->access->canRead($current)) {
                    return ['access' => 'restricted', 'source' => null];
                }
                $event = $reads->query(HsEvent::query())->whereIn('site_id', $sites)->whereKey($id)->first($this->eventColumns());
                if ($event === null) {
                    return ['access' => 'restricted', 'source' => null];
                }
                if ($event->event_category !== HsEvent::CATEGORY_EXPOSURE || $event->asset_id !== null
                    || ! in_array((int) $event->site_id, $sites, true)) {
                    return ['access' => 'restricted', 'source' => null];
                }
                $source = $this->access->readableQuery($current, $reads, $sites)->whereKey((int) $event->source_id)
                    ->first(['id', 'hazardous_substance_id', 'user_id', 'site_id', 'related_incident_id']);
                if ($source === null) {
                    return ['access' => 'unavailable', 'source' => null];
                }
                if (! $this->identityMatches($event, $source)) {
                    return ['access' => 'restricted', 'source' => null];
                }
                try {
                    $tuple = $this->sources->sourceProof((int) $source->id, $current, $reads, $sites);
                    if ($tuple['attribution_state'] === 'repair_required') {
                        return ['access' => 'unavailable', 'source' => null, 'integrity' => 'repair_required'];
                    }
                    if (! $this->tupleMatches($event,$tuple)) {
                        return ['access'=>'restricted','source'=>null,'integrity'=>'unknown_context'];
                    }
                    if (! $this->provenTuple($event,$tuple,$current,$reads,$sites)) {
                        return ['access'=>'unavailable','source'=>null,'integrity'=>'unconfirmed_projection'];
                    }
                    // Saved medical fields follow original-context and destination admission, never the identity scan.
                    $source = $this->access->assertExposure((int) $source->id, $current, $reads, $sites);
                    if (ChemicalCommandValues::digest($this->sources->sourceProof($source, $current, $reads, $sites))
                        !== ChemicalCommandValues::digest($tuple)) {
                        return ['access' => 'unavailable', 'source' => null, 'integrity' => 'repair_required'];
                    }
                    $worker = $reads->query(User::query())->whereKey($tuple['worker_id'])->first(['id', 'name']);
                    $site = $reads->query(Site::query())->whereKey($tuple['site_id'])->first(['id', 'name']);
                    $substance = $reads->query(HazardousSubstance::query())->whereKey($tuple['substance_id'])->first(['id', 'name']);
                    if (! $worker || ! $site || ! $substance) {
                        return ['access' => 'unavailable', 'source' => null];
                    }
                    return ['access' => 'readable', 'source' => ['id' => (int) $source->id,
                        'source_type' => SubstanceExposureRecord::class, 'worker' => ['id' => (int) $worker->id, 'name' => $worker->name],
                        'site' => ['id' => (int) $site->id, 'name' => $site->name],
                        'substance' => ['id' => (int) $substance->id, 'name' => $substance->name],
                        'exposed_at' => $source->exposed_at?->toISOString(),
                        'exposure_type' => $source->exposure_type, 'attribution_state' => $tuple['attribution_state'],
                        'incident_binding' => $tuple['incident_binding'], 'context_binding' => $tuple['context_binding'],
                        'source_revision' => $tuple['source_revision'], 'source_version_id' => $tuple['source_version_id'], 'href' => null]];
                } catch (ValidationException $refusal) {
                    if (! self::isCanonicalSourceRefusal($refusal)) { throw $refusal; }
                    $reads->assertActive();
                    return ['access' => 'unavailable', 'source' => null, 'integrity' => 'repair_required'];
                } catch (LogicException) {
                    $reads->assertActive();
                    return ['access' => 'unavailable', 'source' => null, 'integrity' => 'repair_required'];
                }
            });
        } catch (ModelNotFoundException) {
            return ['access' => 'unavailable', 'source' => null];
        } catch (HttpExceptionInterface $denial) {
            if (! in_array($denial->getStatusCode(), [403, 404], true)) {
                throw $denial;
            }
            return ['access' => 'restricted', 'source' => null];
        }
    }


    /** Only fixed canonical admission refusals are concealed; unrelated validation remains a caller fault. */
    public static function isCanonicalSourceRefusal(\Illuminate\Validation\ValidationException $error): bool
    {
        $errors = $error->errors();
        return array_keys($errors) === ['source'] && count($errors['source']) === 1
            && in_array($errors['source'][0], [
                'Original legacy association is unproved. Review its source before private body access.',
                'Retained governed receipt identity cannot be relabelled as legacy.',
                'Canonical source provenance requires repair before private body access.',
                'The retained linked legacy origin is unproved. A later association does not authorise its sealed facts.',
                'Current private identity differs from its original acceptance-bound tuple.',
                'Canonical original source identity differs from retained acceptance.',
                'Original association is unknown. No current Client placement supplies it.',
                'Unversioned receipt does not own a legitimate unchanged root command.',
                'Retained unchanged legacy command cannot introduce a different source identity.',
                'Retained linked legacy no-op origin is unproved. Clearing today\'s pointer does not authorise its sealed facts.',
                'Current legacy identity differs from its latest accepted unchanged source tuple.',
            ], true);
    }

    private function candidates(Builder $sourceCandidates, CurrentAuthorizationReads $reads, array $sites): Builder
    {
        return $reads->query((clone $sourceCandidates)->setEagerLoads([])->reorder()->limit(null)->offset(0))
            ->whereIn('hs_events.source_type', $this->sourceTypes())->where('hs_events.event_category', HsEvent::CATEGORY_EXPOSURE)
            ->whereIn('hs_events.site_id', $sites)->whereNotNull('hs_events.source_id')->whereNull('hs_events.asset_id')->whereNull('hs_events.deleted_at')
            ->select($this->eventColumns());
    }

    private function identityMatches(HsEvent $event, SubstanceExposureRecord $source): bool
    {
        return (int) $event->source_id === (int) $source->id && (int) $source->site_id > 0 && (int) $source->user_id > 0
            && (int) $event->site_id === (int) $source->site_id && (int) $event->staff_id === (int) $source->user_id;
    }

    private function tupleMatches(HsEvent $event, array $tuple): bool
    {
        if ($tuple['attribution_state'] === 'repair_required') {
            return false;
        }
        if ($tuple['source_id'] !== (int) $event->source_id || $tuple['site_id'] !== (int) $event->site_id
            || $tuple['worker_id'] !== (int) $event->staff_id || $tuple['event_category'] !== HsEvent::CATEGORY_EXPOSURE
            || $tuple['asset_id'] !== null) {
            return false;
        }
        if ($tuple['attribution_state'] === 'legacy_unknown') {
            // A legacy absent incident pointer does not certify external context completeness.
            // A retained incident pointer with an unknown original tuple is never admitted.
            return $tuple['incident_binding'] === 'absent' && $event->client_id === null && $event->shift_id === null;
        }
        return $tuple['attribution_state'] === 'recorded' && $tuple['context_binding'] === 'recorded'
            && in_array($tuple['incident_binding'], ['absent', 'recorded'], true)
            && in_array($tuple['client_binding'], ['absent', 'recorded'], true)
            && in_array($tuple['shift_binding'], ['absent', 'recorded'], true)
            && $tuple['client_id'] === ($event->client_id === null ? null : (int) $event->client_id)
            && $tuple['shift_id'] === ($event->shift_id === null ? null : (int) $event->shift_id);
    }

    /** Tuple equality alone does not certify a prospective destination accepted by the source owner. */
    private function provenTuple(HsEvent $event, array $tuple, User $current, CurrentAuthorizationReads $reads, array $sites): bool
    {
        if (! $this->tupleMatches($event, $tuple)) { return false; }
        if ($tuple['attribution_state'] === 'legacy_unknown') { return true; }
        $marker = $reads->query(\App\Models\ChemicalExposureHsProjection::query())
            ->where('exposure_id', $tuple['source_id'])->where('hs_event_id', $event->id)
            ->where('source_revision', $tuple['source_revision'])->where('source_version_id', $tuple['source_version_id'])
            ->first(['id']);
        if ($marker === null) { return false; }
        $actual = $this->destinations->sourceProofForAuditSubject((int) $marker->id, $current, $reads, $sites);
        return $actual !== null && ChemicalCommandValues::digest($actual) === ChemicalCommandValues::digest($tuple);
    }

    private function within(User $viewer, ?HealthSafetyReadContext $context, callable $operation): mixed
    {
        if ($context !== null) {
            $context->assertViewer($viewer);
            return $context->consumeSourceViewer(self::PERMISSIONS, fn () => $this->access->withReadContext($context, $operation));
        }
        return HealthSafetyReadContext::within($viewer, self::PERMISSIONS, fn (HealthSafetyReadContext $fresh) => $this->access->withReadContext($fresh, $operation));
    }

    private function sourceTypes(): array
    {
        return array_values(array_unique([SubstanceExposureRecord::class, (new SubstanceExposureRecord)->getMorphClass()]));
    }

    private function eventColumns(): array
    {
        return ['hs_events.id', 'hs_events.source_type', 'hs_events.source_id', 'hs_events.event_category',
            'hs_events.site_id', 'hs_events.staff_id', 'hs_events.client_id', 'hs_events.shift_id', 'hs_events.asset_id'];
    }
}
