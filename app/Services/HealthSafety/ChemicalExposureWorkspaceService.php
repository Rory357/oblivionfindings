<?php

namespace App\Services\HealthSafety;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\ChemicalExposureEvidence;
use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureReview;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Support\HealthSafety\ChemicalExposureReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Unmounted private workspace: canonical source proof precedes every narrative/history projection. */
final class ChemicalExposureWorkspaceService
{
    public const PAGES = ['history_page', 'reviews_page', 'followups_page', 'evidence_page'];
    public const ROOT_FACTS = ['user_id', 'site_id', 'exposed_at', 'exposure_type', 'exposure_duration',
        'circumstances', 'symptoms', 'first_aid_given', 'medical_attention_sought', 'medical_treatment',
        'medical_outcome', 'incident_reported', 'related_incident_id'];

    public function __construct(private readonly ChemicalExposureReadService $reader,
        private readonly ChemicalExposureAccessService $access, private readonly ChemicalExposureCommandAccess $commands,
        private readonly ChemicalExposureSourceService $source, private readonly ChemicalExposureLedger $ledger,
        private readonly ChemicalExposureEvidenceStorage $storage) {}

    private function within(User $viewer, callable $operation): mixed
    {
        return HealthSafetyReadContext::within($viewer, ChemicalExposureCommandAccess::PERMISSIONS,
            fn (HealthSafetyReadContext $context) => $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS,
                fn ($current, $reads, $sites) => $operation($context, $current, $reads, $sites)));
    }

    public function index(User $viewer, array $filters = []): array
    {
        return $this->within($viewer, function ($context, $current, $reads, $sites) use ($filters) {
            abort_unless($current->canDo('hazards.view'), 403);
            $capture = $current->canDo('hazards.create') || $current->canDo('hazards.manage');
            if (! $this->access->canRead($current)) { return $this->withheldIndex('restricted', $capture); }
            $f = $this->filters($filters);
            if ($f['site_id'] !== null) { $this->access->assertSite($f['site_id'], $reads, $sites); }
            if ($f['substance_id'] !== null) { $this->access->assertSubstance($f['substance_id'], $current, $reads); }
            $query = $this->access->readableQuery($current, $reads, $sites)
                ->when($f['site_id'] !== null, fn ($q) => $q->where('site_id', $f['site_id']))
                ->when($f['substance_id'] !== null, fn ($q) => $q->where('hazardous_substance_id', $f['substance_id']));
            // Exact identity discovery only. Date/filter/order/medical predicates wait for original association admission.
            $identities = $query->orderBy('id')->get(ChemicalExposureSourceService::BODY_ADMISSION_FIELDS);
            $rawRoots = []; $snapshots = []; $matching = []; $awaiting = 0; $repair = false; $pendingUnknown = false;
            try {
                foreach ($identities as $identity) {
                    if ($identity->source_schema_version === 0 && $identity->source_revision === 0
                        && $identity->related_incident_id !== null && $identity->original_context === null
                        && ! $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $identity->id)->exists()) {
                        return $this->withheldIndex('association_unconfirmed', $capture);
                    }
                    $this->source->admitExposureIdentityBeforeBody($identity, $current, $reads, $sites);
                    [$root, $proof] = $this->admittedRootInReads((int) $identity->id, $current, $reads, $sites);
                    $fields = array_flip(ChemicalExposureSourceService::BODY_ADMISSION_FIELDS);
                    if (ChemicalCommandValues::digest(array_intersect_key($root->attributesToArray(), $fields))
                        !== ChemicalCommandValues::digest(array_intersect_key($identity->attributesToArray(), $fields))) {
                        throw new LogicException('Exposure index source identity changed after admission.');
                    }
                    $rawRoots[(int) $root->id] = $root->getRawOriginal();
                    $snapshots[(int) $root->id] = ['digest' => ChemicalCommandValues::digest($this->ledger->snapshot($root)),
                        'integrity' => $proof['attribution_state']];
                    // Worker identity is accepted original metadata; damaged date/body never exclude or sort a repair row.
                    if ($f['worker_id'] !== null && (int) $root->user_id !== $f['worker_id']) { continue; }
                    if ($proof['attribution_state'] === 'repair_required') {
                        $repair = true;
                        $matching[] = ['repair' => true, 'instant' => null, 'record' => [
                            'id' => (int) $root->id, 'attribution_state' => 'repair_required', 'record' => null, 'href' => null]];
                        continue;
                    }
                    if (! $this->indexDateMatches($root, $f)) { continue; }
                    $record = $this->record($root, $proof, $current, $reads, $sites);
                    $record['href'] = ChemicalExposureReturn::record((int) $root->id);
                    $matching[] = ['repair' => false, 'instant' => $root->exposed_at?->copy()->utc()->format('Y-m-d H:i:s.u'),
                        'record' => $record];
                    if ($root->medical_outcome === null && $record['fact_states']['medical_attention_sought']['state'] !== 'reported') { $pendingUnknown = true; }
                    elseif ($record['medical_attention_sought'] === true && $root->medical_outcome === null) { $awaiting++; }
                }
                $siteOptions = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereIn('id', $sites)
                    ->orderBy('name')->orderBy('id')->get(['id', 'name'])->map(fn ($site) => ['id' => (int) $site->id, 'name' => $site->name])->all();
                foreach ($snapshots as $id => $snapshot) {
                    [$root, $proof] = $this->admittedRootInReads($id, $current, $reads, $sites);
                    if (ChemicalCommandValues::digest($this->ledger->snapshot($root)) !== $snapshot['digest']
                        || $proof['attribution_state'] !== $snapshot['integrity']) {
                        throw new LogicException('Exposure index facts changed during materialization.');
                    }
                }
                $finalIdentities = (clone $query)->reorder('id')->get(ChemicalExposureSourceService::BODY_ADMISSION_FIELDS);
                $digest = fn ($rows) => ChemicalCommandValues::digest($rows->map(fn ($row) => $row->attributesToArray())->all());
                if ($digest($identities) !== $digest($finalIdentities)) {
                    throw new LogicException('Exposure index membership changed during materialization.');
                }
                $this->assertIndexFinalScope($query, $rawRoots, $current, $reads);
            } catch (ModelNotFoundException) { return $this->withheldIndex('original_context_restricted', $capture); }
            catch (HttpExceptionInterface $denial) {
                if (in_array($denial->getStatusCode(), [403, 404], true)) {
                    return $this->withheldIndex('original_context_restricted', $capture);
                }
                throw $denial;
            } catch (\Illuminate\Validation\ValidationException $invalid) {
                if (array_keys($invalid->errors()) !== ['source']) { throw $invalid; }
                return $this->withheldIndex('repair_required', $capture);
            } catch (LogicException) { return $this->withheldIndex('repair_required', $capture); }
            usort($matching, fn ($a, $b) => ($b['repair'] <=> $a['repair'])
                ?: strcmp($b['instant'] ?? '', $a['instant'] ?? '') ?: ($b['record']['id'] <=> $a['record']['id']));
            $total = count($matching);
            return ['exposure_access' => 'readable', 'records' => array_column(array_slice($matching, ($f['page'] - 1) * 25, 25), 'record'),
                'counts' => ['exposures' => $repair ? null : $total, 'awaiting_medical_outcome' => ($repair || $pendingUnknown) ? null : $awaiting],
                'count_integrity' => ['awaiting_medical_outcome' => $repair ? 'repair_required' : ($pendingUnknown ? 'reported_answer_unknown' : 'confirmed')],
                'source_integrity' => $repair ? 'repair_required' : 'admitted',
                'page' => ['current_page' => $f['page'], 'last_page' => $repair ? null : max(1, (int) ceil($total / 25)),
                    'total' => $repair ? null : $total, 'per_page' => 25, 'has_more' => $repair ? null : $total > $f['page'] * 25],
                // recover: exposure managers can open their evidence recovery page (it checks again itself).
                'filters' => $f, 'sites' => $siteOptions, 'can' => ['capture' => $capture, 'recover' => $current->canDo('hazards.manage')]];
        });
    }

    private function withheldIndex(string $state, bool $capture): array
    {
        return ['exposure_access' => $state === 'restricted' ? 'restricted' : 'unavailable', 'source_integrity' => $state,
            'records' => null, 'counts' => null, 'page' => null, 'filters' => null, 'sites' => null, 'can' => ['capture' => $capture]];
    }

    private function indexDateMatches(SubstanceExposureRecord $root, array $f): bool
    {
        $instant = $root->exposed_at?->copy()->utc()->format('Y-m-d H:i:s.u');
        return ($f['from'] === null || ($instant !== null && $instant >= $f['from']))
            && ($f['to'] === null || ($instant !== null && $instant <= $f['to']));
    }

    private function assertIndexFinalScope(Builder $scope, array $rawRoots, User $current, CurrentAuthorizationReads $reads): void
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
        // These remain query nodes in the active lock tree; no eager subquery compilation or escaping handle.
        $complete = $reads->query(User::query())->whereKey($current->id)->whereNotExists($unknown->toBase())
            ->where(new \Illuminate\Database\Query\Expression((string) count($rawRoots)), '=', $exact->toBase())->exists();
        if (! $complete) { throw new LogicException('Exposure index final facts or membership changed.'); }
    }
    public function detail(User $viewer, int $id, array $pages = [], ?int $followupId = null): array
    {
        $pages = $this->pages($pages);
        if ($followupId !== null) { $this->positive($followupId, 'followup'); }
        return $this->within($viewer, function ($context, $current, $reads, $sites) use ($id, $pages, $followupId) {
            [$root, $proof] = $this->admittedRootInReads($id, $current, $reads, $sites);
            $can = ['correct' => false, 'review' => false, 'assign_followup' => false, 'upload_evidence' => false];
            if ($proof['attribution_state'] === 'repair_required') {
                return ['exposure_access' => 'readable', 'id' => $id, 'record' => null, 'source_integrity' => 'repair_required',
                    'command_context' => null, 'history' => $this->unavailable(), 'reviews' => $this->unavailable(),
                    'followups' => $this->unavailable(), 'attachments' => $this->unavailable(),
                    'delivery' => ['state' => 'repair_required', 'attempts' => null], 'can' => $can,
                    'focused_followup_id' => null, 'followup_focus_state' => $followupId === null ? 'not_requested' : 'unavailable'];
            }
            $record = $this->record($root, $proof, $current, $reads, $sites);
            $record['attribution_state'] = $proof['attribution_state'];
            $record['href'] = ChemicalExposureReturn::record($id);
            $record['incident_connection'] = ['state' => $proof['incident_binding'],
                'id' => $proof['incident_binding'] === 'recorded' ? $proof['incident_id'] : null,
                'href' => $proof['incident_binding'] === 'recorded' ? '/incidents/'.$proof['incident_id'] : null,
                'reason' => $proof['incident_binding'] === 'unknown' ? 'Original association evidence is unknown.' : null];
            $manage = $current->canDo('hazards.manage');
            $can = array_fill_keys(array_keys($can), $manage);
            if ($proof['incident_binding'] === 'unknown' && $root->related_incident_id !== null) { $can['assign_followup'] = false; }
            $focus = $followupId === null ? null : $this->resolveFollowupFocus($root, $followupId, $current, $reads, $sites);
            if ($focus !== null) { $pages['followups_page'] = $focus['page']; }
            $reviews = $this->children($root, 'review', $pages['reviews_page'], $current, $reads, $sites);
            $followups = $this->children($root, 'followup', $pages['followups_page'], $current, $reads, $sites);
            $detail = ['exposure_access' => 'readable', 'id' => $id, 'record' => $record, 'source_integrity' => $proof['attribution_state'],
                'command_context' => ['root_type' => 'exposure', 'root_id' => $id, 'expected_version' => (int) $root->source_revision,
                    'source_version_id' => $proof['source_version_id']],
                'reviews' => $reviews, 'followups' => $followups,
                'attachments' => $this->children($root, 'evidence', $pages['evidence_page'], $current, $reads, $sites),
                'history' => $this->history($root, $pages['history_page'], $current, $reads, $sites),
                // Source acceptance/outbox membership is not proof of destination availability or dispatch.
                'delivery' => ['state' => 'unconfirmed', 'attempts' => null], 'can' => $can];
            $this->assertRootStillCurrent($root, $current, $reads, $sites);
            $detail['focused_followup_id'] = $this->focusedFollowupId($root, $focus, $followups, $current, $reads, $sites);
            $detail['followup_focus_state'] = $followupId === null ? 'not_requested' : ($detail['focused_followup_id'] === null ? 'unavailable' : 'available');
            $this->assertRootStillCurrent($root, $current, $reads, $sites);
            return $detail;
        });
    }

    public function capture(User $viewer, int $substanceId, array $filters = []): array
    {
        return $this->commands->withCurrentViewer($viewer, function ($current, $reads, $sites) use ($substanceId, $filters) {
            $this->commands->assertOperation($current, $reads, 'capture');
            $substance = $this->commands->substance($substanceId, $reads);
            $f = $this->optionFilters($filters);
            if ($f['site_id'] !== null) { $this->commands->site($f['site_id'], $reads, $sites); }
            $siteRows = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereIn('id', $sites)
                ->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all();
            return ['substance' => ['id' => (int) $substance->id, 'name' => $substance->name], 'sites' => $siteRows,
                'incidents' => $f['site_id'] === null ? ['access' => 'not_selected', 'items' => [], 'page' => null]
                    : $this->incidents($f['site_id'], $f, $current, $reads, 'capture'),
                'staff' => $f['site_id'] === null ? ['items' => [], 'next_cursor' => null]
                    : $this->staff($f['site_id'], $f, $current, $reads, false),
                'options' => ['exposure_types' => ['inhalation', 'skin_contact', 'eye_contact', 'ingestion', 'injection', 'other'],
                    'medical_treatments' => ['none', 'first_aid', 'medical', 'hospitalisation', 'death']],
                'can' => ['create' => true, 'read_saved' => $this->access->canRead($current)]];
        });
    }

    public function commandOptions(User $viewer, int $id, array $filters = []): array
    {
        return $this->within($viewer, function ($context, $current, $reads, $sites) use ($id, $filters) {
            $this->commands->assertOperation($current, $reads, 'manage');
            [$root, $proof] = $this->admittedRootInReads($id, $current, $reads, $sites);
            if ($proof['attribution_state'] === 'repair_required') { throw new LogicException('Exposure source requires repair before command choices.'); }
            $f = $this->optionFilters($filters);
            $targetSite = $f['site_id'] ?? (int) $root->site_id;
            $this->commands->site($targetSite, $reads, $sites);
            return ['sites' => $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')
                    ->whereIn('id', $sites)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->all(),
                'staff' => $this->staff($targetSite, $f, $current, $reads, false),
                'incidents' => $this->incidents($targetSite, $f, $current, $reads),
                'receivers' => $proof['incident_binding'] === 'unknown'
                    ? ['items' => null, 'next_cursor' => null, 'blocked_reason' => 'Review the original association before assigning private work.']
                    : $this->staff((int) $root->site_id, $f, $current, $reads, true, $root)];
        });
    }

    /** Optional readable link never grants enduring read to an original capture-only actor. */
    public function href(User $viewer, int $id): ?string
    {
        try {
            return $this->within($viewer, function ($context, $current, $reads, $sites) use ($id) {
                $proof = $this->source->sourceProof($id, $current, $reads, $sites);
                return $proof['attribution_state'] === 'repair_required' ? null : ChemicalExposureReturn::record($id);
            });
        } catch (ModelNotFoundException) { return null; }
        catch (HttpExceptionInterface $denial) {
            if (in_array($denial->getStatusCode(), [403, 404], true)) { return null; }
            throw $denial;
        }
    }

    /** Capture hints are parent-bound without exposing saved medical data or trusting session acceptance. */
    public function confirmation(User $viewer, string $key, ?int $id = null, ?int $substanceId = null): ?array
    {
        $receipt = $this->source->confirmationForKey($viewer, $key, $id);
        if ($receipt === null || $substanceId === null) { return $receipt; }
        return $this->commands->withCurrentViewer($viewer, function ($current, $reads) use ($receipt, $substanceId) {
            $this->commands->assertOperation($current, $reads, 'capture');
            $identity = $reads->query(\App\Models\ChemicalExposureCommandReceipt::query())->select(['id', 'intent_id'])
                ->where('actor_id', $current->id)->where('exposure_id', $receipt['root_id'])
                ->where('command', 'create_exposure')->where('idempotency_key', $receipt['idempotency_key'])->first();
            if ($identity === null) { return null; }
            $parent = $reads->query(\App\Models\ChemicalCommandIntent::query())->where('actor_id', $current->id)
                ->where('source_type', 'exposure')->where('command', 'create_exposure')->whereKey($identity->intent_id)->value('parent_id');
            return (int) $parent === $substanceId ? $receipt : null;
        });
    }

    /** Outbox status is reported metadata, not destination proof or recipient dispatch. */
    public function deliveryAttempts(User $viewer, int $id): array
    {
        $result = app(ChemicalExposureDeliveryService::class)->attemptsForViewer($viewer, $id);
        return ['state' => $result['state'], 'destination_confirmation' => 'not_checked',
            'attempts' => $result['attempts'] === null ? null : array_map(fn ($attempt) => [
                'id' => $attempt['id'], 'kind' => $attempt['kind'], 'reported_state' => $attempt['state'],
                'source_revision' => $attempt['source_revision'], 'attempts' => $attempt['attempts'], 'can_retry' => false,
            ], $result['attempts'])];
    }

    public function recoveryAdmission(User $viewer): array
    {
        return $this->commands->withCurrentViewer($viewer, function ($current, $reads) {
            $this->commands->assertOperation($current, $reads, 'manage');
            return ['can' => ['inspect' => true, 'retry' => true]];
        });
    }

    /** Certify precisely the canonical model that will be shaped; a separate re-read's proof cannot bless it. */
    /** Exact child identity first; page resolution is navigation, never a work/action grant. */
    private function resolveFollowupFocus(SubstanceExposureRecord $root, int $id, User $current,
        CurrentAuthorizationReads $reads, array $sites): ?array
    {
        $identity = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->whereKey($id)
            ->first(['id', 'exposure_id', 'source_schema_version', 'source_revision']);
        if ($identity === null) { return null; }
        try {
            $this->source->assertSourceIdentityBeforeBody($root, $identity, 'followup', $reads, $current, $sites);
            $acceptedId = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                ->where('source_type', 'followup')->where('source_id', $id)->orderByDesc('aggregate_revision')->value('id');
            if ($acceptedId === null) { return null; } // Legacy work remains discoverable, not certified as an accepted task focus.
            $this->acceptedVersionInReads((int) $acceptedId, $current, $reads, $sites);
            $child = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->whereKey($id)
                ->where('source_schema_version', $identity->source_schema_version)->where('source_revision', $identity->source_revision)->firstOrFail();
            if ($child->source_schema_version !== $identity->source_schema_version || $child->source_revision !== $identity->source_revision) {
                throw new LogicException('Requested followup changed after its identity admission.');
            }
            $version = $this->source->currentVersion($root, $child, 'followup', $reads, $current, $sites);
            if ($version === null) { return null; }
            $before = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->where('id', '>', $id)->count();
            return ['id' => $id, 'page' => intdiv($before, 25) + 1,
                'digest' => ChemicalCommandValues::digest($this->ledger->snapshot($child))];
        } catch (ModelNotFoundException) { return null; }
        catch (LogicException) { return null; }
    }

    private function focusedFollowupId(SubstanceExposureRecord $root, ?array $focus, array $section,
        User $current, CurrentAuthorizationReads $reads, array $sites): ?int
    {
        if ($focus === null) { return null; }
        $item = null;
        foreach ($section['items'] ?? [] as $candidate) {
            if ($candidate['id'] === $focus['id'] && ($candidate['attribution_state'] ?? null) === 'recorded'
                && array_key_exists('instruction', $candidate)) { $item = $candidate; break; }
        }
        if ($item === null) { return null; } // Only the actual returned admitted page can supply focus.
        $child = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->whereKey($focus['id'])->firstOrFail();
        $this->source->currentVersion($root, $child, 'followup', $reads, $current, $sites);
        if (ChemicalCommandValues::digest($this->ledger->snapshot($child)) !== $focus['digest']
            || $item['instruction'] !== $child->instruction || $item['assigned_to']['id'] !== $child->assigned_to
            || $item['due_at'] !== $child->due_at?->toISOString()
            || $item['completed_at'] !== $child->completed_at?->toISOString()
            || $item['completion_notes'] !== $child->completion_notes) {
            throw new LogicException('Requested followup changed during its returned page projection.');
        }
        $exact = $reads->query(ChemicalExposureFollowup::query())->where('exposure_id', $root->id)->whereKey($child->id);
        foreach ($child->getRawOriginal() as $field => $value) {
            if (in_array($field, ['created_at', 'updated_at'], true)) { continue; }
            if ($value === null) { $exact->whereNull($field); }
            elseif (is_string($value)) {
                $quoted = $exact->getQuery()->getGrammar()->wrap($field);
                $expression = $field === 'assignee_snapshot'
                    ? 'CAST('.$quoted.' AS CHAR CHARACTER SET utf8mb4)' : $quoted;
                $exact->whereRaw('CAST('.$expression.' AS BINARY) = CAST(? AS BINARY)', [$value]);
            } else { $exact->where($field, $value); }
        }
        $page = $section['page']['current_page'];
        $samePage = ChemicalExposureFollowup::query()->from('chemical_exposure_followups as focus_newer')
            ->where('focus_newer.exposure_id', $root->id)
            ->whereColumn('focus_newer.id', '>', 'chemical_exposure_followups.id')
            ->selectRaw('CASE WHEN COUNT(*) >= ? AND COUNT(*) < ? THEN 1 ELSE 0 END', [($page - 1) * 25, $page * 25]);
        // One WHERE-tree statement binds exact current facts and the correlated page interval.
        // The count remains one query block, not two separately observed scalar subqueries.
        if (! $exact->where(new \Illuminate\Database\Query\Expression('1'), '=', $samePage->toBase())->exists()) {
            throw new LogicException('Requested followup no longer belongs to this exact admitted page.');
        }
        return (int) $child->id;
    }

    private function admittedRootInReads(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $root = $this->access->assertExposure($id, $current, $reads, $sites);
        try {
            $version = $this->source->currentVersion($root, $root, 'exposure', $reads, $current, $sites);
            $presented = $this->access->assertExposure($id, $current, $reads, $sites);
            if (ChemicalCommandValues::digest($this->ledger->snapshot($presented)) !== ChemicalCommandValues::digest($this->ledger->snapshot($root))) {
                throw new LogicException('Exposure source changed between discovery and presentation.');
            }
            $version = $this->source->currentVersion($presented, $presented, 'exposure', $reads, $current, $sites);
            $root = $presented;
            $context = $version === null ? null : $root->original_context;
            if ($context !== null && (! is_array($context) || ($context['site_id'] ?? null) !== (int) $root->site_id
                || ($context['incident_id'] ?? null) !== $root->related_incident_id)) {
                throw new LogicException('Exposure original context differs from this presented source.');
            }
            if ($context !== null) { $this->source->assertOriginalContext($context, $current, $reads, (int) $root->site_id); }
            $recordedContext = is_array($context) && ($context['schema_version'] ?? null) === 1;
            return [$root, ['attribution_state' => $version === null ? 'legacy_unknown' : 'recorded', 'source_id' => (int) $root->id,
                'source_revision' => (int) $root->source_revision, 'source_version_id' => $version?->id,
                'incident_binding' => $recordedContext ? ($context['incident_id'] === null ? 'absent' : 'recorded')
                    : ($root->related_incident_id === null ? 'absent' : 'unknown'),
                'incident_id' => $recordedContext ? $context['incident_id'] : null]];
        } catch (LogicException) { return [$root, ['attribution_state' => 'repair_required', 'source_id' => (int) $root->id]]; }
    }

    private function record(SubstanceExposureRecord $root, array $proof, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $reported = $this->source->reportedFactsForSnapshot($root, $current, $reads, $sites);
        $worker = $reads->query(User::query())->whereKey($root->user_id)->firstOrFail(['id', 'name']);
        $site = $reads->query(Site::query())->whereKey($root->site_id)->firstOrFail(['id', 'name']);
        $substance = $reads->query(\App\Models\HazardousSubstance::query())->whereKey($root->hazardous_substance_id)->firstOrFail(['id', 'name']);
        $record = ['id' => (int) $root->id, 'substance' => ['id' => (int) $substance->id, 'name' => $substance->name],
            'worker' => ['id' => (int) $worker->id, 'name' => $worker->name], 'site' => ['id' => (int) $site->id, 'name' => $site->name],
            'exposed_at' => $root->exposed_at?->copy()->utc()->toIso8601String(), 'exposure_type' => $root->exposure_type,
            'exposure_duration' => $root->exposure_duration, 'circumstances' => $root->circumstances, 'symptoms' => $root->symptoms,
            'first_aid_given' => $root->first_aid_given, ...$reported, 'medical_outcome' => $root->medical_outcome,
            'recorded_by' => $this->actor($root->created_by, $reads),
            'updated_by' => $this->actor($root->updated_by, $reads), 'created_at' => $root->created_at?->toISOString(),
            'updated_at' => $root->updated_at?->toISOString(), 'attribution_state' => $proof['attribution_state']];
        $this->source->reportedFactsForSnapshot($root, $current, $reads, $sites);
        return $record;
    }

    private function children(SubstanceExposureRecord $root, string $kind, int $page, User $current,
        CurrentAuthorizationReads $reads, array $sites): array
    {
        $model = match ($kind) { 'review' => ChemicalExposureReview::class, 'followup' => ChemicalExposureFollowup::class,
            'evidence' => ChemicalExposureEvidence::class };
        $identities = $reads->query($model::query())->where('exposure_id', $root->id)->orderByDesc('id')
            ->get(['id', 'exposure_id', 'source_schema_version', 'source_revision']);
        $damage = [];
        foreach ($identities as $identity) {
            try {
                $this->source->assertSourceIdentityBeforeBody($root, $identity, $kind, $reads, $current, $sites);
                $versionId = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
                    ->where('source_type', $kind)->where('source_id', $identity->id)->orderByDesc('aggregate_revision')->value('id');
                if ($versionId !== null) { $this->acceptedVersionInReads((int) $versionId, $current, $reads, $sites); }
                elseif ((int) $identity->source_schema_version !== 0 || (int) $identity->source_revision !== 0) {
                    throw new LogicException('Typed exposure child lacks its retained original acceptance.');
                }
            } catch (LogicException) { $damage[(int) $identity->id] = true; }
        }
        $items = [];
        foreach ($identities->slice(($page - 1) * 25, 25) as $identity) {
            if (isset($damage[(int) $identity->id])) { $items[] = $this->damagedChild((int) $identity->id); continue; }
            // Body load follows both original root and this exact child's accepted historical context.
            $this->assertRootStillCurrent($root, $current, $reads, $sites);
            try {
                $this->source->assertSourceIdentityBeforeBody($root, $identity, $kind, $reads, $current, $sites);
                $child = $reads->query($model::query())->where('exposure_id', $root->id)->whereKey($identity->id)
                    ->where('source_schema_version', $identity->source_schema_version)->where('source_revision', $identity->source_revision)->firstOrFail();
                if ((int) $child->exposure_id !== (int) $identity->exposure_id
                    || (int) $child->source_schema_version !== (int) $identity->source_schema_version
                    || (int) $child->source_revision !== (int) $identity->source_revision) {
                    throw new LogicException('Exposure child identity changed during presentation.');
                }
                $version = $this->source->currentVersion($root, $child, $kind, $reads, $current, $sites);
                $presented = $reads->query($model::query())->where('exposure_id', $root->id)->whereKey($child->id)->firstOrFail();
                if (ChemicalCommandValues::digest($this->ledger->snapshot($presented)) !== ChemicalCommandValues::digest($this->ledger->snapshot($child))) {
                    throw new LogicException('Exposure child changed between proof and presentation.');
                }
                $version = $this->source->currentVersion($root, $presented, $kind, $reads, $current, $sites);
                $child = $presented;
                $this->assertRootStillCurrent($root, $current, $reads, $sites);
                $state = $version === null ? 'legacy_unknown' : 'recorded';
                if ($kind === 'review') {
                    $items[] = ['id' => (int) $child->id, 'reviewed_at' => $child->reviewed_at?->toISOString(),
                        'outcome' => $child->outcome, 'notes' => $child->notes, 'recorded_by' => $this->actor($child->recorded_by, $reads),
                        'attribution_state' => $state];
                } elseif ($kind === 'followup') {
                    $items[] = ['id' => (int) $child->id, 'assigned_to' => $this->actor($child->assigned_to, $reads),
                        'instruction' => $child->instruction, 'due_at' => $child->due_at?->toISOString(),
                        'completed_at' => $child->completed_at?->toISOString(), 'completion_notes' => $child->completion_notes,
                        'recorded_by' => $this->actor($child->recorded_by, $reads), 'completed_by' => $this->actor($child->completed_by, $reads),
                        'assignment_attribution_state' => $version !== null && in_array($version->command, ['assign_followup', 'reassign_followup'], true)
                            ? 'recorded' : (($version?->content['facts']['assignment_attribution_state'] ?? null) === 'recorded' ? 'recorded' : 'legacy_unknown'),
                        'completion_attribution_state' => $child->completed_at === null ? null
                            : ($version?->command === 'complete_followup' ? 'recorded' : 'legacy_unknown'),
                        'attribution_state' => $state, 'can_reassign' => $current->canDo('hazards.manage') && $child->completed_at === null
                            && ! ($root->related_incident_id !== null && ! is_array($root->original_context)),
                        'can_complete' => $current->canDo('hazards.manage')
                            && (int) $child->assigned_to === (int) $current->id && $child->completed_at === null
                            && ($version?->recorded_at !== null || $child->created_at !== null)];
                } else {
                    if ($version === null) {
                        $items[] = ['id' => (int) $child->id, 'attribution_state' => 'legacy_unknown', 'available' => false,
                            'original_name' => null, 'description' => null, 'href' => null, 'can_remove' => false];
                        continue;
                    }
                    $facts = $version->content['facts']['file_evidence'] ?? null;
                    if ($child->disk !== 'private' || ! is_array($facts) || ($facts['sha256'] ?? null) !== $child->sha256
                        || ($facts['size'] ?? null) !== $child->size) { throw new LogicException('Exposure evidence retained byte proof conflicts.'); }
                    $actual = $this->storage->bytes($child->path);
                    if ($actual !== null && $actual !== ['sha256' => $child->sha256, 'size' => $child->size]) {
                        throw new LogicException('Exposure evidence bytes changed outside the governed upload.');
                    }
                    $items[] = ['id' => (int) $child->id, 'attribution_state' => 'recorded', 'available' => $actual !== null,
                        'original_name' => $child->original_name, 'description' => $child->description,
                        'mime' => $child->mime, 'size' => (int) $child->size, 'uploaded_by' => $this->actor($child->uploaded_by, $reads),
                        'source_version_id' => (int) $version->id, 'source_revision' => (int) $child->source_revision,
                        'href' => $actual === null ? null : ChemicalExposureReturn::BASE.'/'.$root->id.'/evidence/'.$child->id.'/download',
                        'can_remove' => $current->canDo('hazards.manage')];
                }
            } catch (LogicException) { $damage[(int) $identity->id] = true; $items[] = $this->damagedChild((int) $identity->id); }
        }
        return ['items' => $items, 'page' => $this->identityPage($identities->count(), $page, $damage !== []),
            'source_integrity' => $damage === [] ? 'admitted' : 'repair_required'];
    }

    private function assertRootStillCurrent(SubstanceExposureRecord $presented, User $current,
        CurrentAuthorizationReads $reads, array $sites): void
    {
        $canonical = $this->access->assertExposure((int) $presented->id, $current, $reads, $sites);
        if (ChemicalCommandValues::digest($this->ledger->snapshot($canonical)) !== ChemicalCommandValues::digest($this->ledger->snapshot($presented))) {
            throw new LogicException('Exposure root changed during its child presentation.');
        }
        $this->source->currentVersion($canonical, $canonical, 'exposure', $reads, $current, $sites);
    }
    private function damagedChild(int $id): array
    {
        return ['id' => $id, 'attribution_state' => 'repair_required', 'record' => null,
            'available' => false, 'href' => null, 'can_complete' => false, 'can_reassign' => false, 'can_remove' => false];
    }

    /** Metadata admission alone is insufficient: prove the exact retained receipt/intent before its body projection. */
    private function acceptedVersionInReads(int $id, User $current, CurrentAuthorizationReads $reads, array $sites): ChemicalExposureSourceVersion
    {
        $admitted = $this->source->admitVersionMetadata($id, $current, $reads, $sites);
        $proof = $this->source->sourceProofForAuditSubject(ChemicalExposureSourceVersion::class, $id, $current, $reads, $sites);
        if (($proof['attribution_state'] ?? null) !== 'recorded') { throw new LogicException('Historical exposure acceptance requires repair.'); }
        $version = $reads->query(ChemicalExposureSourceVersion::query())->where('site_id', $admitted['site_id'])->whereKey($id)->firstOrFail();
        $this->source->assertAdmittedMetadata($version, $admitted); $this->ledger->assertDigest($version);
        $receipt = $reads->query(\App\Models\ChemicalExposureCommandReceipt::query())->where('exposure_id', $version->exposure_id)
            ->where('source_version_id', $version->id)->firstOrFail();
        $intent = $reads->query(\App\Models\ChemicalCommandIntent::query())->where('source_type', 'exposure')->whereKey($receipt->intent_id)->firstOrFail();
        $this->ledger->assertAcceptance($receipt, $intent, (new SubstanceExposureRecord)->getMorphClass(), $reads, $current, $sites);
        if ($version->content_digest !== $receipt->source_content_digest) {
            throw new LogicException('This presented historical version differs from its original acceptance.');
        }
        return $version;
    }

    private function history(SubstanceExposureRecord $root, int $page, User $current, CurrentAuthorizationReads $reads, array $sites): array
    {
        $identities = $reads->query(ChemicalExposureSourceVersion::query())->where('exposure_id', $root->id)
            ->orderByDesc('aggregate_revision')->get(['id']);
        // Original Site/Client withdrawal on an off-page version cannot leak a concealed historical total.
        $versions = [];
        foreach ($identities as $identity) { $versions[(int) $identity->id] = $this->acceptedVersionInReads((int) $identity->id, $current, $reads, $sites); }
        $items = [];
        foreach ($identities->slice(($page - 1) * 25, 25) as $identity) {
            $version = $versions[(int) $identity->id]; $content = $version->content;
            $items[] = ['id' => (int) $version->id, 'revision' => (int) $version->aggregate_revision,
                'source_type' => $version->source_type, 'source_id' => (int) $version->source_id,
                'command' => $version->command, 'recorded_at' => $version->recorded_at->toISOString(),
                'recorded_by' => array_intersect_key($version->actor_snapshot, ['id' => true, 'name' => true]),
                'reason' => $content['facts']['reason'] ?? null,
                'before' => is_array($content['root_before'] ?? null) ? array_intersect_key($content['root_before'], array_flip(self::ROOT_FACTS)) : null,
                'after' => array_intersect_key($content['root_after'], array_flip(self::ROOT_FACTS)), 'attribution_state' => 'recorded'];
        }
        return ['items' => $items, 'page' => $this->identityPage($identities->count(), $page),
            'source_integrity' => $identities->count() === 0 ? 'legacy_unknown' : 'recorded'];
    }

    private function identityPage(int $total, int $page, bool $damaged = false): array
    {
        return ['current_page' => $page, 'last_page' => $damaged ? null : max(1, (int) ceil($total / 25)),
            'total' => $damaged ? null : $total, 'per_page' => 25, 'has_more' => $total > $page * 25];
    }

    private function actor(?int $id, CurrentAuthorizationReads $reads): ?array
    {
        if ($id === null) { return null; }
        $actor = $reads->query(User::query())->whereKey($id)->first(['id', 'name']);
        return $actor === null ? null : ['id' => (int) $actor->id, 'name' => $actor->name];
    }

    private function staff(int $siteId, array $f, User $current, CurrentAuthorizationReads $reads, bool $receiver, ?SubstanceExposureRecord $root = null): array
    {
        $rows = $reads->query(app(HrCurrentStaffService::class)->currentUsersQuery())
            ->where('external_clinical_account', false)->whereHas('hrEmployeeProfile', fn (Builder $profile) => $profile
                ->where(fn (Builder $scope) => $scope->where('primary_site_id', $siteId)->orWhereJsonContains('secondary_site_ids', $siteId)))
            ->where('users.id', '>', $f['after_id'])->when($f['search'] !== '', fn ($q) => $q->where('name', 'like', '%'.$f['search'].'%'))
            ->orderBy('users.id')->limit(26)->get(['users.id']);
        $items = [];
        foreach ($rows->take(25) as $row) {
            try {
                $eligible = $receiver ? $this->source->assertFollowupAssignee($root ?? throw new LogicException('Receiver choices require a canonical source.'), (int) $row->id, $reads)
                    : $this->commands->staff((int) $row->id, $siteId, $reads);
                $items[] = ['id' => (int) $eligible->id, 'name' => $eligible->name];
            } catch (ModelNotFoundException) { continue; }
            catch (HttpExceptionInterface $denial) {
                if (in_array($denial->getStatusCode(), [403, 404], true)) { continue; } throw $denial;
            }
        }
        return ['items' => $items, 'next_cursor' => $rows->count() > 25 ? (int) $rows[24]->id : null];
    }

    /** Optional association candidates never gain Client/incident scope from chemical capture. */
    private function incidents(int $siteId, array $filters, User $current, CurrentAuthorizationReads $reads, string $purpose = 'read'): array
    {
        if ((! $current->canDo('incidents.viewAny') && ! $current->canDo('incidents.viewAssigned'))
            || (! $current->canDo('clients.viewAny') && ! $current->canDo('clients.viewAssigned'))) {
            return ['access' => 'restricted', 'items' => null, 'page' => null];
        }
        $identities = app(\App\Services\Incidents\IncidentAccessService::class)->apply(\App\Models\ClientIncident::query(), $current, $reads)
            ->where('site_id', $siteId)->when($filters['incident_search'] !== '',
                fn ($q) => $q->where('reference_number', 'like', '%'.$filters['incident_search'].'%'))->orderByDesc('id')
            ->get(['id', 'site_id', 'client_id', 'shift_id', 'service_context_id', 'respite_stay_id']);
        $eligible = [];
        foreach ($identities as $identity) {
            $context = ['schema_version' => 1, 'site_id' => $siteId, 'incident_id' => (int) $identity->id,
                'client_id' => (int) $identity->client_id, 'shift_id' => $identity->shift_id === null ? null : (int) $identity->shift_id,
                'service_context_id' => $identity->service_context_id === null ? null : (int) $identity->service_context_id,
                'stay_id' => $identity->respite_stay_id === null ? null : (int) $identity->respite_stay_id];
            try { $this->source->assertOriginalContext($context, $current, $reads, $siteId, $purpose); }
            catch (LogicException|ModelNotFoundException) { continue; }
            catch (HttpExceptionInterface $denial) {
                if (in_array($denial->getStatusCode(), [403, 404], true)) { continue; } throw $denial;
            }
            $eligible[(int) $identity->id] = $context;
        }
        $items = [];
        foreach (array_slice($eligible, ($filters['incident_page'] - 1) * 25, 25, true) as $id => $context) {
            $this->source->assertOriginalContext($context, $current, $reads, $siteId, $purpose);
            $incident = app(\App\Services\Incidents\IncidentAccessService::class)->apply(\App\Models\ClientIncident::query(), $current, $reads)
                ->where('site_id', $siteId)->where('client_id', $context['client_id'])->where('shift_id', $context['shift_id'])
                ->where('service_context_id', $context['service_context_id'])->where('respite_stay_id', $context['stay_id'])
                ->whereKey($id)->firstOrFail(['id', 'client_id', 'reference_number', 'occurred_at']);
            $this->source->assertOriginalContext($context, $current, $reads, $siteId, $purpose);
            $client = $reads->query(\App\Models\Client::query())->whereKey($incident->client_id)->firstOrFail(['id', 'site_id', 'first_name', 'last_name']);
            abort_unless(app(\App\Policies\ClientPolicy::class)->viewFromCurrentEvidence($current, $client, $reads), 403);
            $items[] = ['id' => (int) $incident->id, 'reference' => $incident->reference_number,
                'occurred_at' => $incident->occurred_at?->toISOString(),
                'client' => ['id' => (int) $client->id, 'name' => $client->full_name]];
        }
        return ['access' => 'readable', 'items' => $items, 'page' => $this->identityPage(count($eligible), $filters['incident_page'])];
    }

    private function unavailable(): array { return ['items' => null, 'page' => null, 'source_integrity' => 'repair_required']; }

    private function positive(mixed $value, string $field, bool $zero = false): int
    {
        if ((! is_int($value) && ! is_string($value)) || preg_match($zero ? '/^(0|[1-9]\d*)$/D' : '/^[1-9]\d*$/D', (string) $value) !== 1
            || strlen((string) $value) > 10 || (float) $value > 2147483647) { ChemicalCommandValues::invalid($field, 'Use an exact positive identity or page.'); }
        return (int) $value;
    }
    private function pages(array $values): array
    {
        if (array_diff(array_keys($values), self::PAGES)) { ChemicalCommandValues::invalid('pages', 'Use only the supported workspace pages.'); }
        $pages = array_fill_keys(self::PAGES, 1);
        foreach ($values as $key => $value) { $pages[$key] = $this->positive($value, $key); }
        return $pages;
    }
    private function filters(array $values): array
    {
        if (array_diff(array_keys($values), ['site_id', 'worker_id', 'substance_id', 'from', 'to', 'page'])) { ChemicalCommandValues::invalid('filters', 'Use only the supported private exposure filters.'); }
        $f = ['site_id' => null, 'worker_id' => null, 'substance_id' => null, 'from' => null, 'to' => null, 'page' => 1];
        foreach (['site_id', 'worker_id', 'substance_id', 'page'] as $key) {
            if (isset($values[$key])) { $f[$key] = $this->positive($values[$key], $key); }
        }
        foreach (['from', 'to'] as $key) {
            if (isset($values[$key])) { $f[$key] = ChemicalCommandValues::instant($values[$key], $key, false); }
        }
        if ($f['from'] !== null && $f['to'] !== null && $f['to'] < $f['from']) { ChemicalCommandValues::invalid('to', 'The period must end after it starts.'); }
        return $f;
    }
    private function optionSearch(mixed $value, string $field): string
    {
        if (! is_string($value) || strlen($value) > 200) { ChemicalCommandValues::invalid($field, 'Use a short reference search.'); }
        return $value;
    }
    private function optionFilters(array $values): array
    {
        if (array_diff(array_keys($values), ['site_id', 'after_id', 'search', 'incident_search', 'incident_page'])) { ChemicalCommandValues::invalid('filters', 'Use only the supported staff choices.'); }
        $search = $values['search'] ?? '';
        if (! is_string($search) || strlen($search) > 200) { ChemicalCommandValues::invalid('search', 'Use a short staff search.'); }
        return ['site_id' => isset($values['site_id']) ? $this->positive($values['site_id'], 'site_id') : null,
            'after_id' => isset($values['after_id']) ? $this->positive($values['after_id'], 'after_id', true) : 0, 'search' => $search,
            'incident_search' => $this->optionSearch($values['incident_search'] ?? '', 'incident_search'),
            'incident_page' => isset($values['incident_page']) ? $this->positive($values['incident_page'], 'incident_page') : 1];
    }
}
