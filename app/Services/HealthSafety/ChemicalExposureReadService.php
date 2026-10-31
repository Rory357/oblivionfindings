<?php

namespace App\Services\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Validation\ValidationException;

/** Prospective private source reads; no route, source command, clinical decision or delivery is implied. */
final class ChemicalExposureReadService
{
    public function __construct(private readonly ChemicalExposureAccessService $access,
        private readonly ChemicalExposureSourceService $source,
        private readonly ChemicalExposureLedger $ledger) {}

    public function detail(User $viewer, int $id): array
    {
        return $this->access->withCurrentViewer($viewer,
            fn ($current, $reads, $sites) => $this->detailInReads($id, $current, $reads, $sites));
    }

    public function detailInContext(User $viewer, int $id, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->access->withReadContext($context,
            fn ($current, $reads, $sites) => $this->detailInReads($id, $current, $reads, $sites));
    }

    private function detailInReads(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $row = $this->access->assertExposure($id, $current, $reads, $sites);

        $record = $this->record($row, $current, $reads, $sites);
        $this->source->reportedFactsForSnapshot($row, $current, $reads, $sites);
        return ['exposure_access' => 'readable', 'record' => $record,
            'history' => null, 'history_state' => 'legacy_unknown', 'attachments' => null, 'evidence_state' => 'not_recorded',
            'delivery' => ['state' => 'unconfirmed', 'health_safety_href' => null],
            'can' => ['correct' => false, 'review' => false, 'followup' => false, 'upload_evidence' => false]];
    }

    public function index(User $viewer, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer,
            fn ($current, $reads, $sites) => $this->collection(null, $filters, $current, $reads, $sites));
    }

    public function indexInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->access->withReadContext($context,
            fn ($current, $reads, $sites) => $this->collection(null, $filters, $current, $reads, $sites));
    }

    public function forSubstance(User $viewer, int $id, array $filters = []): array
    {
        return $this->access->withCurrentViewer($viewer,
            fn ($current, $reads, $sites) => $this->collection($id, $filters, $current, $reads, $sites));
    }

    public function forSubstanceInContext(User $viewer, int $id, array $filters, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->access->withReadContext($context,
            fn ($current, $reads, $sites) => $this->collection($id, $filters, $current, $reads, $sites));
    }

    private function collection(?int $substanceId, array $filters, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        abort_unless($current->canDo('hazards.view'), 403);
        if (! $this->access->canRead($current)) { return $this->unavailableCollection('restricted'); }
        $normalized = $this->filters($filters);
        if ($substanceId !== null) { $this->access->assertSubstance($substanceId, $current, $reads); }
        if ($normalized['site_id'] !== null) { $this->access->assertSite($normalized['site_id'], $reads, $sites); }
        // Discovery uses only canonical identity scope. No medical predicate, date ordering, name search, count or body yet.
        $query = $this->access->readableQuery($current, $reads, $sites)
            ->when($substanceId !== null, fn ($q) => $q->where('hazardous_substance_id', $substanceId))
            ->when($normalized['site_id'] !== null, fn ($q) => $q->where('site_id', $normalized['site_id']));
        $identities = $query->orderBy('id')->get(ChemicalExposureSourceService::BODY_ADMISSION_FIELDS);
        $admitted = []; $rawRoots = []; $matching = []; $awaiting = 0; $pendingUnknown = false;
        try {
            foreach ($identities as $identity) {
                if ($identity->source_schema_version === 0 && $identity->source_revision === 0
                    && $identity->related_incident_id !== null && $identity->original_context === null
                    && ! $reads->query(\App\Models\ChemicalExposureSourceVersion::query())->where('exposure_id', $identity->id)->exists()) {
                    return $this->unavailableCollection('association_unconfirmed');
                }
                $this->source->admitExposureIdentityBeforeBody($identity, $current, $reads, $sites);
                $root = $this->access->assertExposure((int) $identity->id, $current, $reads, $sites, $substanceId);
                $this->assertSameIdentity($identity, $root);
                $version = $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
                $admitted[(int) $root->id] = ChemicalCommandValues::digest($this->ledger->snapshot($root));
                $rawRoots[(int) $root->id] = $root->getRawOriginal();
                // Apply private fact refinements only to this exact admitted/proved root.
                if (! $this->matches($root, $normalized)) { continue; }
                $record = $this->record($root, $current, $reads, $sites);
                $record['attribution_state'] = $version === null ? 'legacy_unknown' : 'recorded';
                $matching[] = ['instant' => $root->exposed_at?->copy()->utc()->format('Y-m-d H:i:s.u'), 'record' => $record];
                if ($root->medical_outcome === null && $record['fact_states']['medical_attention_sought']['state'] !== 'reported') { $pendingUnknown = true; }
                elseif ($record['medical_attention_sought'] === true && $root->medical_outcome === null) { $awaiting++; }
            }
            // Re-prove exact models after name/materialization queries; no ID->proof cache certifies a changed revision/body.
            foreach ($admitted as $id => $digest) {
                $root = $this->access->assertExposure($id, $current, $reads, $sites, $substanceId);
                $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
                if (ChemicalCommandValues::digest($this->ledger->snapshot($root)) !== $digest) {
                    throw new \LogicException('Exposure collection changed during materialization.');
                }
                $this->assertPersistedRoot($root, $current, $reads, $sites);
            }
            $finalIdentities = (clone $query)->reorder('id')->get(ChemicalExposureSourceService::BODY_ADMISSION_FIELDS);
            $identityDigest = fn ($rows) => ChemicalCommandValues::digest($rows->map(fn ($row) => $row->attributesToArray())->all());
            if ($identityDigest($identities) !== $identityDigest($finalIdentities)) {
                throw new \LogicException('Exposure collection membership changed during materialization.');
            }
            $this->assertFinalRootSet($query, $rawRoots, $current, $reads);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { return $this->unavailableCollection('restricted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $denial) {
            if (in_array($denial->getStatusCode(), [403, 404], true)) { return $this->unavailableCollection('restricted'); }
            throw $denial;
        } catch (ValidationException $invalid) {
            if (array_keys($invalid->errors()) !== ['source']) { throw $invalid; }
            return $this->unavailableCollection('repair_required');
        } catch (\LogicException) { return $this->unavailableCollection('repair_required'); }
        usort($matching, fn ($a, $b) => strcmp($b['instant'] ?? '', $a['instant'] ?? '')
            ?: ($b['record']['id'] <=> $a['record']['id']));
        $total = count($matching);
        $records = array_column(array_slice($matching, ($normalized['page'] - 1) * 25, 25), 'record');
        return ['exposure_access' => 'readable', 'records' => $records,
            'counts' => ['exposures' => $total, 'awaiting_medical_outcome' => $pendingUnknown ? null : $awaiting],
            'count_integrity' => ['awaiting_medical_outcome' => $pendingUnknown ? 'reported_answer_unknown' : 'confirmed'],
            'page' => ['current_page' => $normalized['page'], 'last_page' => max(1, (int) ceil($total / 25)),
                'total' => $total, 'per_page' => 25, 'has_more' => $total > $normalized['page'] * 25],
            'source_integrity' => 'admitted', 'history' => null, 'history_state' => 'legacy_unknown', 'filters' => $normalized];
    }

    private function unavailableCollection(string $state): array
    {
        return ['exposure_access' => $state === 'restricted' ? 'restricted' : 'unavailable',
            'records' => null, 'counts' => null, 'page' => null, 'history' => null,
            'history_state' => $state, 'source_integrity' => $state, 'filters' => null];
    }

    private function assertSameIdentity(SubstanceExposureRecord $identity, SubstanceExposureRecord $root): void
    {
        $fields = array_flip(ChemicalExposureSourceService::BODY_ADMISSION_FIELDS);
        if (ChemicalCommandValues::digest(array_intersect_key($root->attributesToArray(), $fields))
            !== ChemicalCommandValues::digest(array_intersect_key($identity->attributesToArray(), $fields))) {
            throw new \LogicException('Exposure identity changed between association admission and body.');
        }
    }

    private function assertPersistedRoot(SubstanceExposureRecord $root, User $current, CurrentAuthorizationReads $reads, array $sites): void
    {
        $query = $this->access->readableQuery($current, $reads, $sites)->whereKey($root->id);
        foreach ($root->getRawOriginal() as $field => $value) {
            if (in_array($field, ['created_at', 'updated_at'], true)) { continue; }
            if ($value === null) { $query->whereNull($field); }
            elseif (is_string($value)) {
                $quoted = $query->getQuery()->getGrammar()->wrap($field);
                $expression = $field === 'original_context' ? 'CAST('.$quoted.' AS CHAR CHARACTER SET utf8mb4)' : $quoted;
                $query->whereRaw('CAST('.$expression.' AS BINARY) = CAST(? AS BINARY)', [$value]);
            } else { $query->where($field, $value); }
        }
        if (! $query->exists()) { throw new \LogicException('Exposure persisted facts changed after proof.'); }
    }

    private function assertFinalRootSet(\Illuminate\Database\Eloquent\Builder $scope, array $rawRoots,
        User $current, CurrentAuthorizationReads $reads): void
    {
        $unknown = (clone $scope)->reorder()->select('id')->whereNotIn('id', array_keys($rawRoots));
        $exact = (clone $scope)->reorder()->where(function ($disjunction) use ($rawRoots) {
            foreach ($rawRoots as $id => $attributes) {
                $disjunction->orWhere(function ($row) use ($id, $attributes) {
                    $row->whereKey($id);
                    foreach ($attributes as $field => $value) {
                        if (in_array($field, ['created_at', 'updated_at'], true)) { continue; }
                        if ($value === null) { $row->whereNull($field); }
                        elseif (is_string($value)) {
                            $quoted = $row->getQuery()->getGrammar()->wrap($field);
                            $expression = $field === 'original_context' ? 'CAST('.$quoted.' AS CHAR CHARACTER SET utf8mb4)' : $quoted;
                            $row->whereRaw('CAST('.$expression.' AS BINARY) = CAST(? AS BINARY)', [$value]);
                        } else { $row->where($field, $value); }
                    }
                });
            }
        });
        if ($rawRoots === []) { $exact->whereRaw('0 = 1'); }
        $exact->select([])->selectRaw('COUNT(*)');
        // Both subqueries remain in the current-read WHERE tree, not eagerly compiled/raw SQL or escaping builders.
        // One final statement proves known facts AND absence of unknown scoped IDs, including initially empty scope.
        $complete = $reads->query(User::query())->whereKey($current->id)->whereNotExists($unknown->toBase())
            ->where(new \Illuminate\Database\Query\Expression((string) count($rawRoots)), '=', $exact->toBase())->exists();
        if (! $complete) {
            throw new \LogicException('Exposure root set changed after collection proof.');
        }
    }

    private function matches(SubstanceExposureRecord $root, array $filters): bool
    {
        if ($filters['worker_id'] !== null && (int) $root->user_id !== $filters['worker_id']) { return false; }
        $instant = $root->exposed_at?->copy()->utc()->format('Y-m-d H:i:s.u');
        return ($filters['from'] === null || ($instant !== null && $instant >= $filters['from']))
            && ($filters['to'] === null || ($instant !== null && $instant <= $filters['to']));
    }
    private function record(SubstanceExposureRecord $row, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $reported = $this->source->reportedFactsForSnapshot($row, $current, $reads, $sites);
        $worker = $reads->query(User::query())->whereKey($row->user_id)->firstOrFail(['id', 'name']);
        $site = $reads->query(Site::query())->whereKey($row->site_id)->firstOrFail(['id', 'name']);
        $substance = $reads->query(HazardousSubstance::query())->whereKey($row->hazardous_substance_id)->firstOrFail(['id', 'name']);

        return ['id' => (int) $row->id, 'substance' => ['id' => (int) $substance->id, 'name' => $substance->name],
            'worker' => ['id' => (int) $worker->id, 'name' => $worker->name], 'site' => ['id' => (int) $site->id, 'name' => $site->name],
            'exposed_at' => $row->exposed_at?->copy()->timezone('Pacific/Auckland')->toIso8601String(),
            'exposure_type' => $row->exposure_type, 'exposure_duration' => $row->exposure_duration,
            'circumstances' => $row->circumstances, 'symptoms' => $row->symptoms, 'first_aid_given' => $row->first_aid_given,
            ...$reported, 'medical_outcome' => $row->medical_outcome,
            'incident_connection' => ['state' => $row->related_incident_id === null ? 'absent' : 'unknown', 'id' => null, 'href' => null,
                'reason' => $row->related_incident_id === null ? null : 'The original Client association is not retained on this legacy exposure source.'],
            'recorded_by' => $this->actor($row->created_by, $reads), 'updated_by' => $this->actor($row->updated_by, $reads),
            'created_at' => $row->created_at?->toISOString(), 'updated_at' => $row->updated_at?->toISOString(),
            'attribution_state' => 'legacy_unknown', 'href' => null];
    }

    private function actor(?int $id, CurrentAuthorizationReads $reads): ?array
    {
        $row = $id === null ? null : $reads->query(User::query())->whereKey($id)->first(['id', 'name']);

        return $row ? ['id' => (int) $row->id, 'name' => $row->name] : null;
    }

    private function filters(array $filters): array
    {
        if (array_diff(array_keys($filters), ['site_id', 'worker_id', 'from', 'to', 'page'])) {
            throw ValidationException::withMessages(['filters' => 'Use only the supported private exposure filters.']);
        }
        $normalized = ['site_id' => null, 'worker_id' => null, 'from' => null, 'to' => null, 'page' => 1];
        foreach (['site_id', 'worker_id', 'page'] as $key) {
            if (isset($filters[$key])) {
                $value = $filters[$key];
                if ((! is_int($value) && ! is_string($value)) || ! preg_match('/^[1-9]\d*$/D', (string) $value)
                    || strlen((string) $value) > 10 || (float) $value > 2147483647) {
                    throw ValidationException::withMessages([$key => 'Use a positive integer identity or page.']);
                }
                $normalized[$key] = (int) $value;
            }
        }
        foreach (['from', 'to'] as $key) {
            if (isset($filters[$key])) {
                $value = $filters[$key];
                if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                    throw ValidationException::withMessages([$key => 'Use an actual timestamp with its timezone offset.']);
                }
                if (preg_match('/[+-](\d{2}):(\d{2})$/D', $value, $offset) && ((int) $offset[1] > 23 || (int) $offset[2] > 59)) {
                    throw ValidationException::withMessages([$key => 'Use a valid timezone offset.']);
                }
                try {
                    $date = \Carbon\Carbon::parse($value);
                    if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                        throw new \InvalidArgumentException('Invalid calendar timestamp.');
                    }
                    $normalized[$key] = $date->utc()->format('Y-m-d H:i:s.u');
                } catch (\Throwable) {
                    throw ValidationException::withMessages([$key => 'Use a valid calendar timestamp.']);
                }
            }
        }
        if ($normalized['from'] !== null && $normalized['to'] !== null && $normalized['to'] < $normalized['from']) {
            throw ValidationException::withMessages(['to' => 'The end of this period must follow its start.']);
        }

        return $normalized;
    }
}
