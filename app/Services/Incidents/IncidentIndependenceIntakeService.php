<?php

namespace App\Services\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HealthSafetySettingChange;
use App\Models\IncidentIndependentHandlingDeclaration as Declaration;
use App\Models\IncidentIndependentHandlingExclusion as Exclusion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** New reported independence intent only. Shared read/notification/routing mounts remain separate requirements. */
final class IncidentIndependenceIntakeService
{
    public const ROOT_COLUMNS = ['id', 'client_id', 'site_id', 'shift_id', 'reported_by', 'report_request_uuid', 'source', 'status',
        'service_context_id', 'respite_stay_id', 'independent_handling_requested', 'independence_schema_version', 'independence_revision'];

    public function __construct(private readonly IncidentIndependencePolicySettings $policy, private readonly IncidentIndependenceCaptureAccess $capture,
        private readonly UserSiteAccessService $sites) {}

    public function captureOptions(User $actor, array $context, ?string $reportUuid = null): array
    {
        $context = $this->context($context);
        $uuid = $reportUuid === null ? null : IncidentIndependenceValues::uuid($reportUuid, 'report_request_uuid');
        return $this->policy->withLockedPolicy(fn ($snapshot) => CurrentAuthorizationReads::within(function ($reads) use ($actor, $context, $uuid, $snapshot): array {
            $current = $this->capture->current($actor, $reads); $this->capture->assertContext($current, $context, $reads);
            $root = $uuid === null ? null : $this->ownedRoot($current, $uuid, $context, $reads);
            $proof = $root ? $this->proof($root, $reads) : null;
            $selected = $proof['policy'] ?? $snapshot;
            $this->policy->assertSnapshot($selected);
            return ['viewer_id' => (int) $current->id, 'capture_context' => $context, 'report_request_uuid' => $uuid,
                'expected_revision' => $root ? (int) $root->independence_revision : 0,
                'policy' => array_intersect_key($selected, array_flip(['mode', 'policy_revision', 'policy_digest', 'configured', 'configuration_valid', 'origin']))];
        }), beforeRead: fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $context): void {
            $this->capture->assertContext($this->capture->current($actor, $reads), $context, $reads);
        }));
    }

    public function captureCandidates(User $actor, array $context, string $search = '', ?int $selectedId = null): array
    {
        $context = $this->context($context);
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $context, $search, $selectedId): array {
            $current = $this->capture->current($actor, $reads);
            $ids = $this->capture->rosterIds($current, $context, $reads, $search, $selectedId);
            return ['viewer_id' => (int) $current->id, 'capture_context' => $context,
                'candidates' => $reads->query(User::query()->whereIn('id', $ids)->orderBy('id'))->get(['id', 'name'])->map(fn ($user) => ['id' => (int) $user->id, 'name' => $user->name])->all()];
        }));
    }

    /** Internal server callback returns only the persisted incident ID, inside this physical transaction.
     * Caller must preserve its normal report validation/save/audit and stage Journey AFTER this acceptance.
     */
    public function intake(User $actor, array $input, callable $persistIncident): array
    {
        return IncidentIndependenceValues::uniqueReceipt(fn () => $this->acceptIntake($actor, $input, $persistIncident));
    }

    private function acceptIntake(User $actor, array $input, callable $persistIncident): array
    {
        $values = IncidentIndependenceValues::intakeCommand($input);
        return $this->policy->withLockedPolicy(fn ($snapshot) => CurrentAuthorizationReads::within(function ($reads) use ($actor, $values, $persistIncident, $snapshot): array {
            $current = $this->capture->current($actor, $reads); $context = $values['capture_context'];
            $this->capture->assertContext($current, $context, $reads);
            $root = $this->ownedRoot($current, $values['report_request_uuid'], $context, $reads, true);
            $previous = $root ? $this->proof($root, $reads) : null;
            $fingerprint = IncidentIndependenceValues::digest(['actor_id' => (int) $current->id, 'command' => 'declare_independent_handling', 'values' => $values]);
            // Identity-only discovery precedes private acceptance materialisation, including cross-domain collisions.
            $identity = $reads->query(HealthSafetySettingChange::query()->where('command_key', $values['request_uuid']))->first(['id', 'setting_key', 'actor_id']);
            if ($identity) {
                if (! $root || $identity->setting_key !== $this->key((int) $root->id) || (int) $identity->actor_id !== (int) $current->id) {
                    IncidentIndependenceValues::fail('request_uuid', 'This identity belongs to different accepted work.');
                }
                $row = $reads->query(HealthSafetySettingChange::query()->whereKey($identity->id))->firstOrFail();
                if ($row->fingerprint !== $fingerprint) { IncidentIndependenceValues::fail('request_uuid', 'This identity belongs to different report intent.'); }
                return $this->assertAcceptance($row, $root, $reads);
            }
            if ($root && $root->status !== 'draft') { IncidentIndependenceValues::fail('independent_handling', 'The submitted declaration is retained. A separate attributable review is required for later changes.'); }
            $revision = $root ? (int) $root->independence_revision : 0;
            if ($revision !== $values['expected_revision'] || $revision >= 2147483646) { IncidentIndependenceValues::fail('expected_revision', 'The declaration changed. Review current work; your entries are retained.'); }
            $selectedPolicy = $previous['policy'] ?? $snapshot;
            $this->policy->assertSnapshot($selectedPolicy);
            if ($values['expected_policy_revision'] !== $selectedPolicy['policy_revision']) { IncidentIndependenceValues::fail('expected_policy_revision', 'Review the current capture policy before submitting this request.'); }
            $this->capture->assertNamed($current, $context, $values['independent_handling']['involved_user_ids'], $reads);
            $id = $persistIncident();
            if (! is_int($id) || $id < 1) { throw new LogicException('Incident intake requires one exact persisted root identity.'); }
            $admitted = $this->ownedRoot($current, $values['report_request_uuid'], $context, $reads, true);
            if (! $admitted || (int) $admitted->id !== $id || ($root && (int) $root->id !== $id)
                || $admitted->status !== ($values['intent'] === 'draft' ? 'draft' : 'submitted')) { throw new LogicException('Report callback changed the canonical source or intent.'); }
            $root = $admitted; $tuple = $this->tuple($root);
            $latest = $previous['latest'] ?? null;
            $same = $latest && IncidentIndependenceValues::digest($latest->handling) === IncidentIndependenceValues::digest($values['independent_handling']);
            $recorded = now('UTC')->toImmutable(); $actorSnapshot = ['id' => (int) $current->id, 'name' => $current->name];
            if (! $same) {
                $latest = new Declaration(['incident_id' => $id, 'revision' => $revision + 1, 'source_tuple' => $tuple, 'handling' => $values['independent_handling'],
                    'policy_snapshot' => $selectedPolicy, 'actor_id' => $current->id, 'actor_snapshot' => $actorSnapshot, 'recorded_at' => $recorded]);
                $latest->content_digest = $this->declarationDigest($latest); IncidentRoutingSettings::saved($latest);
                foreach ($latest->handling['involved_user_ids'] as $userId) {
                    $old = $reads->query(DB::table('incident_owner_exclusions')->where('incident_id', $id)->where('user_id', $userId))->first(['id']);
                    $exclusionId = $old?->id ?? DB::table('incident_owner_exclusions')->insertGetId(['incident_id' => $id, 'user_id' => $userId, 'recorded_by' => $current->id,
                        'reason_code' => 'reported_involvement', 'created_at' => $recorded, 'updated_at' => $recorded]);
                    if (! $exclusionId) { throw new LogicException('Reported ownership exclusion was not persisted.'); }
                    IncidentRoutingSettings::saved(new Exclusion(['declaration_id' => $latest->id, 'user_id' => $userId, 'exclusion_id' => $exclusionId,
                        'prior_existing' => $old !== null, 'recorded_at' => $recorded]));
                }
                $root->independent_handling_requested = true; $root->independence_schema_version = 1; $root->independence_revision = $latest->revision;
                IncidentRoutingSettings::saved($root);
            }
            $result = ['accepted' => true, 'request_uuid' => $values['request_uuid'], 'report_request_uuid' => $values['report_request_uuid'], 'incident_id' => $id,
                'declaration_id' => (int) $latest->id, 'declaration_revision' => $latest->revision, 'mode' => $selectedPolicy['mode'],
                'actor_id' => (int) $current->id, 'intent' => $values['intent'], 'action' => $same ? 'kept' : 'recorded'];
            $key = $this->key($id); $sequence = (int) (HealthSafetySettingChange::query()->where('setting_key', $key)->max('revision') ?? 0) + 1;
            $before = $previous ? $this->reference($previous['latest']) : null; $after = $this->reference($latest);
            $meta = $result + ['fingerprint' => $fingerprint, 'declaration_digest' => $latest->content_digest, 'exclusions_digest' => $this->exclusionsDigest($latest, $reads),
                'before_digest' => $before === null ? null : IncidentIndependenceValues::digest($before), 'after_digest' => IncidentIndependenceValues::digest($after),
                'acceptance_sequence' => $sequence, 'actor_snapshot_digest' => IncidentIndependenceValues::digest($actorSnapshot), 'recorded_at' => $recorded->format('Y-m-d\TH:i:s.u\Z')];
            $request = clone request(); $request->setUserResolver(fn () => $current);
            AuditLogger::logOrFail('incidents.independence.declaration.'.$result['action'], $latest, $meta, $request);
            $audits = AuditLog::query()->where('user_id', $current->id)->where('auditable_type', $latest->getMorphClass())->where('auditable_id', $latest->id)
                ->where('action', 'incidents.independence.declaration.'.$result['action'])->where('meta->request_uuid', $values['request_uuid'])->lockForUpdate()->get();
            if ($audits->count() !== 1 || IncidentIndependenceValues::digest($audits->sole()->meta) !== IncidentIndependenceValues::digest($meta)) { throw new LogicException('Declaration acceptance requires one exact persisted audit.'); }
            $acceptance = new HealthSafetySettingChange(['setting_key' => $key, 'revision' => $sequence, 'schema_version' => 1, 'action' => $result['action'],
                'origin' => 'incident_independent_handling', 'before_value' => $before, 'after_value' => $after, 'before_digest' => $meta['before_digest'], 'after_digest' => $meta['after_digest'],
                'actor_id' => $current->id, 'actor_snapshot' => $actorSnapshot, 'recorded_at' => $recorded, 'reason' => $values['independent_handling']['reason_code'],
                'audit_id' => $audits->sole()->id, 'command_key' => $values['request_uuid'], 'fingerprint' => $fingerprint, 'accepted_result' => $result]);
            IncidentRoutingSettings::saved($acceptance);
            if (! $same) { $latest->acceptance_id = $acceptance->id; IncidentRoutingSettings::saved($latest); }
            return $this->assertAcceptance($acceptance, $root, $reads);
        }), beforeRead: fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $values): void {
            $this->capture->assertContext($this->capture->current($actor, $reads), $values['capture_context'], $reads);
        }));
    }

    public function confirmationForKey(User $actor, string $uuid): ?array
    {
        $uuid = IncidentIndependenceValues::uuid($uuid);
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function ($reads) use ($actor, $uuid): ?array {
            $current = $this->capture->current($actor, $reads);
            $identity = $reads->query(HealthSafetySettingChange::query()->where('command_key', $uuid)->where('actor_id', $current->id)
                ->where('origin', 'incident_independent_handling'))->first(['id', 'setting_key']);
            if (! $identity || ! preg_match('/^'.preg_quote(IncidentIndependencePolicySettings::DECLARATION_PREFIX, '/').'([1-9][0-9]*)$/D', $identity->setting_key, $match)) { return null; }
            $root = $reads->query(ClientIncident::query()->whereKey((int) $match[1])->where('reported_by', $current->id))->first(self::ROOT_COLUMNS);
            if (! $root) { return null; }
            $this->capture->assertContext($current, $this->rootContext($root), $reads); $this->proof($root, $reads);
            return $this->assertAcceptance($reads->query(HealthSafetySettingChange::query()->whereKey($identity->id))->firstOrFail(), $root, $reads);
        }));
    }

    /** Internal gate metadata, never a general-page DTO. Must run before private incident body/copies. */
    public function protectionInReads(int $incidentId, User $current, CurrentAuthorizationReads $reads): array
    {
        $reads->assertActive();
        $query = app(IncidentAccessService::class)->applySiteScope(ClientIncident::query()->whereKey($incidentId), $current, $reads);
        $root = $reads->query($query)->firstOrFail(self::ROOT_COLUMNS);
        $proof = $this->proof($root, $reads);
        return $proof === null ? ['state' => 'legacy_unknown', 'requested' => false, 'block_user_ids' => [], 'excluded_user_ids' => [], 'restricted_routing' => false]
            : ['state' => 'recorded', 'requested' => true, 'block_user_ids' => $proof['policy']['mode'] === 'block_involved_access' ? $proof['ids'] : [],
                'excluded_user_ids' => $proof['ids'], 'restricted_routing' => true, 'identification' => $proof['unknown'] ? 'unknown' : 'named', 'declaration_revision' => $proof['latest']->revision];
    }

    /** One additional source boundary for direct reads, copied sources, audit, Tasks and notification admission.
     * It confers NO ordinary incident/module/source capability. Caller must retain that independent gate.
     * Generic operational alert response fields need not become inaccessible when this private source is concealed.
     */
    public function admitSourceInReads(int $incidentId, User $current, CurrentAuthorizationReads $reads, string $purpose = 'read'): array
    {
        if (! in_array($purpose, ['read', 'notification', 'assignment'], true)) { throw new LogicException('Unsupported independent-handling admission purpose.'); }
        $protection = $this->protectionInReads($incidentId, $current, $reads);
        $denied = $purpose === 'assignment' ? $protection['excluded_user_ids'] : $protection['block_user_ids'];
        abort_if(in_array((int) $current->id, $denied, true), 404);
        return ['state' => $protection['state'], 'requested' => $protection['requested'], 'restricted_routing' => $protection['restricted_routing'],
            'identification' => $protection['identification'] ?? null, 'declaration_revision' => $protection['declaration_revision'] ?? null];
    }

    /** Additional boundary BEFORE collection counts/search/body, never a replacement for the caller's read grant.
     * The caller supplies its independently permitted canonical incident query and fresh actor. All qualification
     * and final SQL remain in this same read lifetime. No private narrative, DTO or transferable token is returned.
     * Direct/copy consumers can use the same method with whereKey(originalIncidentId), before hydrating the source.
     */
    public function applySourceScopeInReads(Builder $query, User $current, CurrentAuthorizationReads $reads, string $purpose = 'read'): Builder
    {
        $reads->assertActive();
        if (! $query->getModel() instanceof ClientIncident || $query->getQuery()->from !== 'client_incidents'
            || $query->getQuery()->groups || $query->getQuery()->havings || $query->getQuery()->unions
            || ! in_array($purpose, ['read', 'notification', 'assignment'], true)) {
            throw new LogicException('Independent-handling scope requires the canonical incident source and supported purpose.');
        }
        $query = app(IncidentAccessService::class)->applySiteScope($query, $current, $reads);
        $identities = (clone $query)->withoutEagerLoads()->reorder();
        $identities->getQuery()->limit = null; $identities->getQuery()->offset = null;
        $identities->select(array_map(fn ($column) => 'client_incidents.'.$column, self::ROOT_COLUMNS))
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('root', 'client_incidents').' AS independence_binding');
        $admitted = [];
        foreach ($reads->query($identities)->get() as $root) {
            try {
                $membership = []; $proof = $this->proof($root, $reads, $membership);
                $denied = $proof === null ? [] : ($purpose === 'assignment' || $proof['policy']['mode'] === 'block_involved_access' ? $proof['ids'] : []);
                if (in_array((int) $current->id, $denied, true)) { continue; }
                $graph = $this->bindingGraph($root, $proof, $reads);
                $this->assertProvedMembership($graph, $proof, $membership);
                $admitted[] = ['id' => (int) $root->id, 'root' => $root->getRawOriginal('independence_binding'),
                    'proof_guards' => $proof['guards'] ?? [],
                    'graph' => $graph];
            } catch (\Illuminate\Validation\ValidationException $error) {
                // Only this row's retained evidence failure is concealed. Healthy unrelated rows remain usable.
                if (! in_array(array_keys($error->errors()), [['independent_handling'], ['policy']], true)) { throw $error; }
            }
        }
        if ($admitted === []) { return $reads->query($query->whereRaw('1 = 0')); }
        return $reads->query($query->where(function (Builder $allowed) use ($admitted): void {
            foreach ($admitted as $binding) {
                $allowed->orWhere(function (Builder $exact) use ($binding): void {
                    $exact->where('client_incidents.id', $binding['id'])
                        ->whereRaw(IncidentIndependenceBindingSql::fingerprint('root', 'client_incidents').' = ?', [$binding['root']]);
                    // These fingerprints were selected alongside the exact rows consumed by canonical proof,
                    // rather than re-reading a potentially changed state as a new accepted baseline.
                    foreach ($binding['proof_guards'] as $guard) {
                        $table = IncidentIndependenceBindingSql::table($guard['family']);
                        $alias = 'independence_proof_'.$guard['family'];
                        $exact->whereExists(DB::table($table.' as '.$alias)->selectRaw('1')->where($alias.'.id', $guard['id'])
                            ->whereRaw(IncidentIndependenceBindingSql::fingerprint($guard['family'], $alias).' = ?', [$guard['fingerprint']]));
                    }
                    foreach ($binding['graph'] as $family => $graph) {
                        $table = IncidentIndependenceBindingSql::table($family);
                        $alias = 'independence_'.$family;
                        // No late, moved or newly associated evidence may be treated as the admitted graph.
                        $extra = DB::table($table.' as '.$alias)->selectRaw('1');
                        $this->graphSelector($extra, $family, $alias, $graph['selector']);
                        if ($graph['rows'] !== []) { $extra->whereNotIn($alias.'.id', array_column($graph['rows'], 'id')); }
                        $exact->whereNotExists($extra);
                        foreach ($graph['rows'] as $row) {
                            $exact->whereExists(DB::table($table.' as '.$alias)->selectRaw('1')->where($alias.'.id', $row['id'])
                                ->whereRaw(IncidentIndependenceBindingSql::fingerprint($family, $alias).' = ?', [$row['fingerprint']]));
                        }
                    }
                });
            }
        }));
    }

    /** Exact closed graph facts remain private to this invocation; SQL uses the same fixed-column fingerprints. */
    private function bindingGraph(ClientIncident $root, ?array $proof, CurrentAuthorizationReads $reads): array
    {
        $selector = ['incident_id' => (int) $root->id, 'declaration_ids' => [], 'exclusion_ids' => [],
            'acceptance_ids' => [], 'audit_ids' => [], 'policy_id' => $proof['policy']['setting_change_id'] ?? null];
        $graph = [];
        foreach (['declaration', 'exclusion', 'owner_exclusion', 'acceptance', 'audit', 'policy_mutex'] as $family) {
            $table = IncidentIndependenceBindingSql::table($family);
            $query = DB::table($table)->select($table.'.id')
                ->selectRaw(IncidentIndependenceBindingSql::fingerprint($family, $table).' AS evidence_fingerprint');
            $this->graphSelector($query, $family, $table, $selector);
            // Relationships needed to close the next family use only identity columns, not private body projection.
            $extra = match ($family) {
                'declaration' => ['acceptance_id'], 'exclusion' => ['exclusion_id'], 'acceptance' => ['audit_id'], default => [],
            };
            $query->addSelect(array_map(fn ($column) => $table.'.'.$column, $extra));
            $rows = $reads->query($query)->orderBy($table.'.id')->get();
            $graph[$family] = ['selector' => $selector, 'rows' => $rows->map(fn ($row) => ['id' => (int) $row->id, 'fingerprint' => $row->evidence_fingerprint])->all()];
            if ($family === 'declaration') { $selector['declaration_ids'] = $rows->pluck('id')->map(fn ($id) => (int) $id)->all(); $selector['acceptance_ids'] = $rows->pluck('acceptance_id')->filter()->map(fn ($id) => (int) $id)->all(); }
            if ($family === 'exclusion') { $selector['exclusion_ids'] = $rows->pluck('exclusion_id')->map(fn ($id) => (int) $id)->all(); }
            if ($family === 'acceptance') { $selector['audit_ids'] = $rows->pluck('audit_id')->filter()->map(fn ($id) => (int) $id)->all(); }
        }
        return $graph;
    }

    /** A newly observed member is not permission evidence. Bind both membership AND absence used by proof. */
    private function assertProvedMembership(array $graph, ?array $proof, array $membership): void
    {
        foreach ($graph as $family => $captured) {
            $expected = $membership[$family] ?? [];
            foreach ($proof['guards'] ?? [] as $guard) {
                if ($guard['family'] === $family) { $expected[] = $guard['id']; }
            }
            $expected = array_values(array_unique($expected)); sort($expected);
            $actual = array_column($captured['rows'], 'id'); sort($actual);
            if ($actual !== $expected) { $this->repair(); }
        }
    }

    private function graphSelector(QueryBuilder $query, string $family, string $alias, array $selector): void
    {
        $id = $selector['incident_id'];
        match ($family) {
            'declaration' => $query->where(fn ($row) => $row->where($alias.'.incident_id', $id)->orWhere($alias.'.source_tuple->incident_id', $id)),
            'exclusion' => $query->whereIn($alias.'.declaration_id', $selector['declaration_ids']),
            'owner_exclusion' => $query->whereIn($alias.'.id', $selector['exclusion_ids']),
            'acceptance' => $query->where(function ($row) use ($alias, $selector, $id): void {
                $row->where($alias.'.setting_key', $this->key($id))->orWhereIn($alias.'.id', $selector['acceptance_ids'])
                    ->orWhere(fn ($source) => $source->where($alias.'.origin', 'incident_independent_handling')->where(fn ($tuple) =>
                        $tuple->where($alias.'.after_value->incident_id', $id)->orWhere($alias.'.accepted_result->incident_id', $id)));
                if ($selector['policy_id'] !== null) { $row->orWhere($alias.'.id', $selector['policy_id']); }
            }),
            'audit' => $query->where(function ($row) use ($alias, $selector, $id): void {
                $row->whereIn($alias.'.id', $selector['audit_ids'])->orWhere(fn ($source) => $source
                    ->where($alias.'.action', 'like', 'incidents.independence.declaration.%')
                    ->where(fn ($tuple) => $tuple->where($alias.'.meta->incident_id', $id)->orWhere(fn ($decl) => $decl
                        ->where($alias.'.auditable_type', (new Declaration)->getMorphClass())->whereIn($alias.'.auditable_id', $selector['declaration_ids']))));
            }),
            'policy_mutex' => $selector['policy_id'] === null ? $query->whereRaw('1 = 0') : $query->where($alias.'.key', IncidentIndependencePolicySettings::REVISION_KEY),
            default => throw new LogicException('Unsupported incident independence graph selector.'),
        };
    }
    private function proof(ClientIncident $root, CurrentAuthorizationReads $reads, ?array &$membership = null): ?array
    {
        $identities = $reads->query(Declaration::query()->where(fn ($q) => $q->where('incident_id', $root->id)->orWhere('source_tuple->incident_id', (int) $root->id))
            ->orderBy('revision'))->get(['id', 'incident_id', 'revision', 'source_tuple']);
        foreach ($identities as $identity) {
            if ($identity->incident_id !== (int) $root->id || IncidentIndependenceValues::digest($identity->source_tuple) !== IncidentIndependenceValues::digest($this->tuple($root))) { $this->repair(); }
        }
        $membership = ['declaration' => $identities->pluck('id')->map(fn ($id) => (int) $id)->all()];
        $rows = $reads->query(Declaration::query()->where('incident_id', $root->id)->whereIn('id', $identities->pluck('id'))->orderBy('revision')
            ->select('incident_independent_handling_declarations.*')->selectRaw(IncidentIndependenceBindingSql::fingerprint('declaration', 'incident_independent_handling_declarations').' AS binding_fingerprint'))->get();
        $receipts = $reads->query(HealthSafetySettingChange::query()->where(fn ($q) => $q->where('setting_key', $this->key((int) $root->id))
            ->orWhere(fn ($decl) => $decl->where('origin', 'incident_independent_handling')->where(fn ($tuple) =>
                $tuple->where('after_value->incident_id', (int) $root->id)->orWhere('accepted_result->incident_id', (int) $root->id)))))->get(['id']);
        $membership['acceptance'] = $receipts->pluck('id')->map(fn ($id) => (int) $id)->all();
        $auditClaims = $reads->query(AuditLog::query()->where('action', 'like', 'incidents.independence.declaration.%')
            ->where(fn ($tuple) => $tuple->where('meta->incident_id', (int) $root->id)->orWhere(fn ($decl) => $decl
                ->where('auditable_type', (new Declaration)->getMorphClass())->whereIn('auditable_id', $membership['declaration']))))->get(['id']);
        $membership['audit'] = $auditClaims->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($rows->isEmpty()) {
            if ($receipts->isNotEmpty() || $auditClaims->isNotEmpty() || $root->independent_handling_requested || (int) $root->independence_schema_version !== 0 || (int) $root->independence_revision !== 0) { $this->repair(); }
            return null;
        }
        if (! $root->independent_handling_requested || (int) $root->independence_schema_version !== 1 || (int) $root->independence_revision !== $rows->last()->revision) { $this->repair(); }
        $ids = []; $unknown = false; $policy = $rows->first()->policy_snapshot; $guards = [];
        foreach ($rows as $index => $row) {
            if ($row->revision !== $index + 1 || IncidentIndependenceValues::digest($row->source_tuple) !== IncidentIndependenceValues::digest($this->tuple($root))
                || IncidentIndependenceValues::digest($row->policy_snapshot) !== IncidentIndependenceValues::digest($policy)) { $this->repair(); }
            $guards[] = ['family' => 'declaration', 'id' => (int) $row->id, 'fingerprint' => $row->getRawOriginal('binding_fingerprint')];
            $acceptance = $reads->query(HealthSafetySettingChange::query()->whereKey($row->acceptance_id)->where('setting_key', $this->key((int) $root->id))
                ->select('health_safety_setting_changes.*')->selectRaw(IncidentIndependenceBindingSql::fingerprint('acceptance', 'health_safety_setting_changes').' AS binding_fingerprint'))->first();
            if (! $acceptance) { $this->repair(); }
            $evidence = []; $this->assertAcceptance($acceptance, $root, $reads, $evidence);
            $guards = [...$guards, ...$evidence, ['family' => 'acceptance', 'id' => (int) $acceptance->id, 'fingerprint' => $acceptance->getRawOriginal('binding_fingerprint')]];
            $ids = [...$ids, ...$row->handling['involved_user_ids']]; $unknown = $unknown || $row->handling['identification'] === 'unknown';
        }
        $ids = array_values(array_unique($ids)); sort($ids);
        return ['latest' => $rows->last(), 'policy' => $policy, 'ids' => $ids, 'unknown' => $unknown, 'guards' => $guards];
    }

    private function assertAcceptance(HealthSafetySettingChange $row, ClientIncident $root, CurrentAuthorizationReads $reads, ?array &$bindingProof = null): array
    {
        $after = $row->after_value;
        if (! is_array($after) || ! is_int($after['declaration_id'] ?? null)) { $this->repair(); }
        $declaration = $reads->query(Declaration::query()->whereKey($after['declaration_id'])->where('incident_id', $root->id)
            ->select('incident_independent_handling_declarations.*')->selectRaw(IncidentIndependenceBindingSql::fingerprint('declaration', 'incident_independent_handling_declarations').' AS binding_fingerprint'))->first();
        if (! $declaration) { $this->repair(); }
        if (! is_array($declaration->policy_snapshot) || ! is_array($declaration->handling)) { $this->repair(); }
        $bindingProof = [...$this->policy->assertSnapshot($declaration->policy_snapshot),
            ['family' => 'declaration', 'id' => (int) $declaration->id, 'fingerprint' => $declaration->getRawOriginal('binding_fingerprint')]];
        try { IncidentIndependenceValues::handling($declaration->handling); }
        catch (\Illuminate\Validation\ValidationException $error) {
            if (! in_array(array_keys($error->errors()), [['independent_handling'], ['independent_handling.involved_user_ids']], true)) { throw $error; }
            $this->repair();
        }
        if ($declaration->content_digest !== $this->declarationDigest($declaration) || IncidentIndependenceValues::digest($declaration->source_tuple) !== IncidentIndependenceValues::digest($this->tuple($root))) { $this->repair(); }
        $stored = $row->accepted_result;
        $result = ['accepted' => true, 'request_uuid' => $row->command_key, 'report_request_uuid' => strtolower((string) $root->report_request_uuid), 'incident_id' => (int) $root->id,
            'declaration_id' => (int) $declaration->id, 'declaration_revision' => $declaration->revision, 'mode' => $declaration->policy_snapshot['mode'],
            'actor_id' => (int) $row->actor_id, 'intent' => $stored['intent'] ?? null, 'action' => $row->action];
        $exclusionGuards = [];
        $meta = $result + ['fingerprint' => $row->fingerprint, 'declaration_digest' => $declaration->content_digest, 'exclusions_digest' => $this->exclusionsDigest($declaration, $reads, $exclusionGuards), 'before_digest' => $row->before_digest,
            'after_digest' => $row->after_digest, 'acceptance_sequence' => $row->revision, 'actor_snapshot_digest' => IncidentIndependenceValues::digest($row->actor_snapshot),
            'recorded_at' => $row->recorded_at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z')];
        $audit = $reads->query(AuditLog::query()->whereKey($row->audit_id)->select('audit_logs.*')
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('audit', 'audit_logs').' AS binding_fingerprint'))->first();
        if ($row->setting_key !== $this->key((int) $root->id) || $row->origin !== 'incident_independent_handling' || $row->schema_version !== 1 || $row->revision < 1
            || $row->actor_id !== (int) $root->reported_by || ! in_array($row->action, ['recorded', 'kept'], true) || ! in_array($result['intent'], ['draft', 'submit'], true)
            || $row->after_digest !== IncidentIndependenceValues::digest($after) || IncidentIndependenceValues::digest($after) !== IncidentIndependenceValues::digest($this->reference($declaration))
            || $row->before_digest !== ($row->before_value === null ? null : IncidentIndependenceValues::digest($row->before_value))
            || IncidentIndependenceValues::digest($stored) !== IncidentIndependenceValues::digest($result) || ! preg_match('/^[a-f0-9]{64}$/D', (string) $row->fingerprint)
            || ! $audit || $audit->action !== 'incidents.independence.declaration.'.$row->action || (int) $audit->user_id !== $row->actor_id
            || $audit->auditable_type !== $declaration->getMorphClass() || (int) $audit->auditable_id !== (int) $declaration->id
            || IncidentIndependenceValues::digest($audit->meta) !== IncidentIndependenceValues::digest($meta)) { $this->repair(); }
        $bindingProof = [...$bindingProof, ...$exclusionGuards,
            ['family' => 'audit', 'id' => (int) $audit->id, 'fingerprint' => $audit->getRawOriginal('binding_fingerprint')]];
        $bindings = $reads->query(Exclusion::query()->where('declaration_id', $declaration->id)->select('incident_independent_handling_exclusions.*')
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('exclusion', 'incident_independent_handling_exclusions').' AS binding_fingerprint'))->get();
        if ($bindings->count() !== count($declaration->handling['involved_user_ids'])) { $this->repair(); }
        foreach ($declaration->handling['involved_user_ids'] as $userId) {
            $binding = $bindings->firstWhere('user_id', $userId);
            if (! $binding) { $this->repair(); }
            $ownerLink = $reads->query(DB::table('incident_owner_exclusions')->where('id', $binding->exclusion_id)->where('incident_id', $root->id)->where('user_id', $userId)
                ->select('id')->selectRaw(IncidentIndependenceBindingSql::fingerprint('owner_exclusion', 'incident_owner_exclusions').' AS binding_fingerprint'))->first();
            if (! $ownerLink) { $this->repair(); }
            $bindingProof[] = ['family' => 'exclusion', 'id' => (int) $binding->id, 'fingerprint' => $binding->getRawOriginal('binding_fingerprint')];
            $bindingProof[] = ['family' => 'owner_exclusion', 'id' => (int) $ownerLink->id, 'fingerprint' => $ownerLink->binding_fingerprint];
        }
        return $result;
    }

    private function ownedRoot(User $current, string $uuid, array $context, CurrentAuthorizationReads $reads, bool $write = false): ?ClientIncident
    {
        $query = $reads->query(ClientIncident::query()->where('report_request_uuid', $uuid));
        if ($write) { $query->getQuery()->beforeQuery(fn ($compiled) => $compiled->lock('for update nowait')); }
        $root = $query->first(self::ROOT_COLUMNS);
        if ($root) {
            abort_unless((int) $root->reported_by === (int) $current->id && $this->rootContext($root) === $context, 404);
            // This initial intake path has no canonical ServiceContext/Respite declaration adapter yet.
            abort_unless($root->service_context_id === null && $root->respite_stay_id === null, 404);
        }
        return $root;
    }
    private function context(array $context): array
    {
        IncidentRoutingValues::keys($context, ['client_id', 'site_id', 'shift_id'], 'capture_context');
        return ['client_id' => IncidentIndependenceValues::integer($context['client_id'] ?? null, 'capture_context.client_id'),
            'site_id' => IncidentIndependenceValues::integer($context['site_id'] ?? null, 'capture_context.site_id'),
            'shift_id' => ($context['shift_id'] ?? null) === null ? null : IncidentIndependenceValues::integer($context['shift_id'], 'capture_context.shift_id')];
    }
    private function rootContext(ClientIncident $root): array { return ['client_id' => (int) $root->client_id, 'site_id' => (int) $root->site_id, 'shift_id' => $root->shift_id === null ? null : (int) $root->shift_id]; }
    private function tuple(ClientIncident $root): array
    {
        return ['schema_version' => 1, 'incident_id' => (int) $root->id, ...$this->rootContext($root), 'reported_by' => (int) $root->reported_by,
            'report_request_uuid' => strtolower((string) $root->report_request_uuid), 'source' => $root->source, 'service_context_id' => $root->service_context_id === null ? null : (int) $root->service_context_id,
            'respite_stay_id' => $root->respite_stay_id === null ? null : (int) $root->respite_stay_id];
    }
    private function declarationDigest(Declaration $row): string
    {
        return IncidentIndependenceValues::digest(['incident_id' => (int) $row->incident_id, 'revision' => $row->revision, 'source_tuple' => $row->source_tuple,
            'handling' => $row->handling, 'policy_snapshot' => $row->policy_snapshot, 'actor_id' => (int) $row->actor_id, 'actor_snapshot' => $row->actor_snapshot,
            'recorded_at' => $row->recorded_at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z')]);
    }
    private function exclusionsDigest(Declaration $row, CurrentAuthorizationReads $reads, ?array &$bindingProof = null): string
    {
        $bindings = $reads->query(Exclusion::query()->where('declaration_id', $row->id)->orderBy('user_id')->select('incident_independent_handling_exclusions.*')
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('exclusion', 'incident_independent_handling_exclusions').' AS binding_fingerprint'))->get();
        $bindingProof = $bindings->map(fn ($binding) => ['family' => 'exclusion', 'id' => (int) $binding->id, 'fingerprint' => $binding->getRawOriginal('binding_fingerprint')])->all();
        return IncidentIndependenceValues::digest($bindings->map(fn ($binding) => ['id' => (int) $binding->id, 'declaration_id' => $binding->declaration_id,
            'user_id' => $binding->user_id, 'exclusion_id' => $binding->exclusion_id, 'prior_existing' => $binding->prior_existing,
            'recorded_at' => $binding->recorded_at?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z')])->all());
    }
    private function reference(Declaration $row): array { return ['schema_version' => 1, 'incident_id' => $row->incident_id, 'declaration_id' => (int) $row->id, 'declaration_revision' => $row->revision, 'declaration_digest' => $row->content_digest]; }
    private function key(int $id): string { return IncidentIndependencePolicySettings::DECLARATION_PREFIX.$id; }
    private function repair(): never { IncidentIndependenceValues::fail('independent_handling', 'Retained independent-handling evidence needs repair. The existing protection must not be loosened.'); }
}
