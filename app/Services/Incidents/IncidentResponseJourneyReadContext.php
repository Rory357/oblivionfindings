<?php

namespace App\Services\Incidents;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Internal one-command lifetime, not transferable source authority or a report version. */
final class IncidentResponseJourneyReadContext
{
    public const METADATA = [...IncidentIndependenceIntakeService::ROOT_COLUMNS, 'is_hr_confidential', 'hs_event_id', 'control_room_alert_id', 'type'];
    private const HS_METADATA = ['id', 'source_type', 'source_id', 'event_category', 'idempotency_key', 'client_id', 'site_id', 'control_room_alert_id', 'deleted_at'];
    private const RECOVERY_ACTION = 'incidents.response.siteRecovered';
    private bool $active = true;
    private array $original;
    private ?array $recovery = null;
    private ?int $hsId;
    private ?int $alertId;
    private ?array $hsProof = null;
    private bool $hsDiscovered = false;

    private function __construct(private readonly CurrentAuthorizationReads $reads, private readonly Builder $originalSource,
        ClientIncident $identity, private readonly User $actor, private readonly array $responseSites)
    {
        $reads->assertActive();
        $this->original = array_intersect_key($identity->getRawOriginal(), array_flip(self::METADATA));
        if (count($this->original) !== count(self::METADATA)) { throw new LogicException('Response source metadata is incomplete.'); }
        $this->hsId = $identity->hs_event_id === null ? null : (int) $identity->hs_event_id;
        $this->alertId = $identity->control_room_alert_id === null ? null : (int) $identity->control_room_alert_id;
    }

    public static function within(CurrentAuthorizationReads $reads, Builder $protectedSource, ClientIncident $identity, User $actor, array $responseSites, callable $operation): mixed
    {
        $context = new self($reads, clone $protectedSource, $identity, $actor, $responseSites);
        try { return $operation($context); }
        finally { $context->active = false; }
    }

    public function __serialize(): array
    {
        throw new LogicException('Incident response source authority cannot be serialized.');
    }

    public function assertActive(): void
    {
        $this->reads->assertActive();
        if (! $this->active) { throw new LogicException('Incident response source has left its command callback.'); }
    }

    public function assertFor(ClientIncident $incident, ?User $actor = null): void
    {
        $this->assertActive();
        if ((int) $incident->id !== (int) $this->original['id'] || ($actor !== null && (int) $actor->id !== (int) $this->actor->id)) {
            throw new LogicException('Incident response source belongs to a different actor or record.');
        }
    }

    public function incidentQuery(Builder $query, bool $command = false): Builder
    {
        $this->assertActive();
        if (! $query->getModel() instanceof ClientIncident || $query->getQuery()->from !== 'client_incidents') {
            throw new LogicException('Response source requires the canonical incident query.');
        }
        $query->whereKey((int) $this->original['id'])->whereExists($this->predicate()->select('client_incidents.id')->toBase());
        $this->physicalFacts($query->getQuery(), 'client_incidents');
        return $this->current($query, $command);
    }

    /** Private destination bodies remain conjunctive with the current ORIGINAL parent proof. */
    public function payloadQuery(Builder $query, bool $command = true): Builder
    {
        $this->assertActive();
        $query->whereExists($this->predicate()->select('client_incidents.id')->toBase());
        return $this->current($query, $command);
    }

    public function assertCurrent(): void
    {
        abort_unless($this->incidentQuery(ClientIncident::query())->exists(), 403, 'The original incident source changed during the response.');
    }

    private function current(Builder $query, bool $command): Builder
    {
        $query->whereRaw(new class($this) extends Expression {
            public function __construct(private readonly IncidentResponseJourneyReadContext $context) { parent::__construct('1 = 1'); }
            public function getValue(Grammar $grammar) { $this->context->assertCompilerEvidence(); return parent::getValue($grammar); }
        });
        $query = $this->reads->query($query);
        if ($command) { $query->getQuery()->beforeQuery(fn (QueryBuilder $compiled) => $compiled->lock('for update nowait')); }
        return $query;
    }

    /** Reconsume the ORIGINAL compiler guard after outer callbacks, never new authority. */
    public function assertCompilerEvidence(): void
    {
        $this->assertActive();
        (clone $this->originalSource)->toSql();
    }

    private function predicate(): Builder
    {
        $this->assertActive(); $source = clone $this->originalSource;
        if ($this->recovery !== null) {
            // Metadata ONLY. The inner physical block is explicitly current-read locked before
            // fromSub compiles it; CurrentReads.lockTree does not traverse a fromSub expression.
            $inner = DB::table('client_incidents as response_physical');
            $this->physicalFacts($inner, 'response_physical'); $this->recoveryGuards($inner);
            foreach (self::METADATA as $column) {
                if (in_array($column, ['site_id', 'hs_event_id', 'control_room_alert_id'], true)) {
                    $inner->selectRaw('? as '.$inner->getGrammar()->wrap($column), [$this->original[$column]]);
                } else { $inner->addSelect('response_physical.'.$column); }
            }
            $inner = $this->reads->query($inner);
            $source->fromSub($inner, 'client_incidents');
        }
        // No where/fingerprint/binding is removed or rewritten from the original policy.
        $this->hsMembership($source->getQuery());
        return $this->current($source, false);
    }

    private function physicalFacts(QueryBuilder $query, string $alias): void
    {
        foreach ($this->original as $column => $value) {
            $value = match ($column) {
                'site_id' => $this->recovery['site_id'] ?? $value,
                'hs_event_id' => $this->hsId,
                'control_room_alert_id' => $this->alertId,
                default => $value,
            };
            $this->exact($query, $alias.'.'.$column, $value);
        }
    }

    private function exact(QueryBuilder $query, string $column, mixed $value): void
    {
        if ($value === null) { $query->whereNull($column); }
        elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getGrammar()->wrap($column).' = BINARY ?', [$value]); }
        else { $query->where($column, $value); }
    }

    public function discoverHsMetadata(): ?HsEvent
    {
        $this->assertCurrent();
        $category = $this->original['type'] === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT;
        $key = HsEvent::buildIdempotencyKey(ClientIncident::class, (int) $this->original['id'], $category);
        $query = HsEvent::query()->where(function (Builder $rows) use ($key): void {
            $rows->where(fn ($source) => $source->where('source_type', ClientIncident::class)->where('source_id', $this->original['id']))
                ->orWhere('idempotency_key', $key);
            if ($this->hsId !== null) { $rows->orWhere($rows->getModel()->getQualifiedKeyName(), $this->hsId); }
        });
        $rows = $this->payloadQuery($query)->orderBy('id')->limit(2)->get(self::HS_METADATA);
        if ($rows->count() > 1) { throw new \DomainException('Incident journey conflict: multiple H&S events claim the incident source tuple.'); }
        $row = $rows->first();
        if ($row === null) {
            if ($this->hsProof !== null || $this->hsId !== null) { abort(403, 'The retained H&S source disappeared.'); }
            $this->hsDiscovered = true; return null;
        }
        if ($this->hsDiscovered && $this->hsProof === null) { abort(403, 'A novel H&S source appeared during the response.'); }
        if ($row->source_type !== ClientIncident::class || (int) $row->source_id !== (int) $this->original['id']
            || $row->event_category !== $category || $row->idempotency_key !== $key
            || $row->client_id !== ($this->original['client_id'] === null ? null : (int) $this->original['client_id'])
            || ($this->original['site_id'] !== null && (int) $row->site_id !== (int) $this->original['site_id'])
            || ($this->hsId !== null && (int) $row->id !== $this->hsId)
            || ($this->alertId !== null && $row->control_room_alert_id !== null && (int) $row->control_room_alert_id !== $this->alertId)) {
            throw new \DomainException('Incident journey conflict: the H&S source metadata is not the canonical incident tuple.');
        }
        if ($this->hsProof !== null && IncidentIndependenceValues::digest($this->hsProof) !== IncidentIndependenceValues::digest($row->getRawOriginal())) { abort(403, 'The retained H&S metadata changed during the response.'); }
        if ($this->payloadQuery(ClientIncident::query()->where('hs_event_id', $row->id)->where('id', '!=', $this->original['id']))->exists()) {
            throw new \DomainException('Incident journey conflict: the H&S event is directly linked to a different incident.');
        }
        $this->hsProof = $row->getRawOriginal(); $this->hsDiscovered = true;
        return $row;
    }

    private function hsMembership(QueryBuilder $query): void
    {
        if (! $this->hsDiscovered) { return; }
        $category = $this->original['type'] === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT;
        $key = HsEvent::buildIdempotencyKey(ClientIncident::class, (int) $this->original['id'], $category);
        if ($this->hsProof !== null) {
            $query->whereExists(function ($known): void {
                $known->selectRaw('1')->from('hs_events as response_known_event');
                foreach ($this->hsProof as $column => $value) { $this->exact($known, 'response_known_event.'.$column, $value); }
            });
            $query->whereNotExists(fn ($claim) => $claim->selectRaw('1')->from('client_incidents as response_foreign_hs_link')
                ->where('response_foreign_hs_link.hs_event_id', $this->hsProof['id'])->where('response_foreign_hs_link.id', '!=', $this->original['id']));
        }
        $query->whereNotExists(function ($extra) use ($key): void {
            $extra->selectRaw('1')->from('hs_events as response_novel_event')->where(function ($claimed) use ($key): void {
                $claimed->where('response_novel_event.idempotency_key', $key)
                    ->orWhere(fn ($source) => $source->where('response_novel_event.source_type', ClientIncident::class)->where('response_novel_event.source_id', $this->original['id']));
                if ($this->hsId !== null) { $claimed->orWhere('response_novel_event.id', $this->hsId); }
            });
            if ($this->hsProof !== null) { $extra->where('response_novel_event.id', '!=', $this->hsProof['id']); }
        });
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public function assertEventInput(array $data): void
    {
        $this->assertActive(); $this->assertCurrent();
        $source = $data['source'] ?? null;
        if (! $source instanceof ClientIncident || get_class($source) !== ClientIncident::class) { throw new LogicException('Response event requires the literal incident source.'); }
        $this->assertFor($source);
        $category = $this->original['type'] === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT;
        if (($data['event_category'] ?? null) !== $category || $this->nullableId($data['site_id'] ?? null) !== $this->nullableId($this->recovery['site_id'] ?? $this->original['site_id'])
            || $this->nullableId($data['client_id'] ?? null) !== $this->nullableId($this->original['client_id']) || $this->nullableId($data['staff_id'] ?? null) !== $this->nullableId($this->original['reported_by'])
            || $this->nullableId($data['shift_id'] ?? null) !== $this->nullableId($this->original['shift_id'])) { throw new LogicException('Response event input changed the admitted source tuple.'); }
    }

    /** LIMIT prevents derived-table merge back into the UPDATE target; no target-table SQL surgery. */
    private function guardedUpdate(Builder $protected, array $attributes): int
    {
        $table = $protected->getModel()->getTable();
        $ids = $protected->reorder()->select($table.'.id')->limit(1);
        // fromSub compiles now; the actual nested predicate blocks already have explicit current locks.
        $derived = DB::query()->fromSub($ids, 'response_update_identity')->select('response_update_identity.id');
        $target = $protected->getModel()->newQuery()->whereIn($table.'.id', $derived);
        return $this->current($target, false)->update($attributes);
    }
    /** Only the actual writer-created model can extend formerly absent destination membership. */
    public function registerCreatedEvent(HsEvent $event): void
    {
        $this->assertActive();
        $category = $this->original['type'] === 'near_miss' ? HsEvent::CATEGORY_NEAR_MISS : HsEvent::CATEGORY_INCIDENT;
        if (! $event->wasRecentlyCreated || $event->source_type !== ClientIncident::class || (int) $event->source_id !== (int) $this->original['id']
            || $event->event_category !== $category || $event->idempotency_key !== HsEvent::buildIdempotencyKey(ClientIncident::class, (int) $this->original['id'], $category)
            || (int) $event->site_id !== (int) ($this->recovery['site_id'] ?? $this->original['site_id'])
            || $event->client_id !== ($this->original['client_id'] === null ? null : (int) $this->original['client_id']) || $this->hsProof !== null) {
            throw new LogicException('Only the exact new canonical event can extend response membership.');
        }
        $this->hsProof = array_intersect_key($event->getRawOriginal(), array_flip(self::HS_METADATA));
        $this->hsProof['deleted_at'] = null; $this->hsDiscovered = true;
        $this->assertCurrent();
    }

    public function existingEvent(): ?HsEvent
    {
        $identity = $this->discoverHsMetadata();
        if ($identity === null) { return null; }
        $row = $this->hsPayloadQuery(HsEvent::query()->whereKey($identity->id), $identity)->first();
        abort_unless($row, 403, 'The canonical response event changed before its body read.');
        return $row;
    }

    public function persistEvent(HsEvent $event, array $attributes): void
    {
        $identity = $this->discoverHsMetadata();
        if ($identity === null || (int) $identity->id !== (int) $event->id) { abort(403); }
        $event->forceFill($attributes);
        if ($event->isDirty()) { abort_unless($this->guardedUpdate($this->hsPayloadQuery(HsEvent::query()->whereKey($event->id), $identity), array_intersect_key($event->getAttributes(), $attributes)) === 1, 403); }
        else { $this->assertCurrent(); }
        if (array_key_exists('control_room_alert_id', $attributes)) { $this->hsProof['control_room_alert_id'] = $attributes['control_room_alert_id']; }
        $event->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));
        $this->assertCurrent();
    }
    public function hsPayloadQuery(Builder $query, HsEvent $identity): Builder
    {
        $this->assertActive();
        if ($this->hsProof === null || (int) $identity->id !== (int) $this->hsProof['id']) { throw new LogicException('Response H&S payload requires its exact metadata proof.'); }
        foreach ($this->hsProof as $column => $value) { $this->exact($query->getQuery(), 'hs_events.'.$column, $value); }
        $category = $this->hsProof['event_category']; $key = $this->hsProof['idempotency_key'];
        $query->whereNotExists(fn ($extra) => $extra->selectRaw('1')->from('hs_events as response_other_hs')->where('response_other_hs.id', '!=', $identity->id)
            ->where(fn ($claims) => $claims->where('response_other_hs.idempotency_key', $key)
                ->orWhere(fn ($source) => $source->where('response_other_hs.source_type', ClientIncident::class)->where('response_other_hs.source_id', $this->original['id']))));
        return $this->payloadQuery($query);
    }

    /** Only this narrowly admitted legacy repair can change the original Site. No old facts are certified. */
    public function recoverSite(ClientIncident $incident, ?HsEvent $hsIdentity): void
    {
        $this->assertFor($incident); $this->assertCurrent();
        if ($incident->site_id !== null) { return; }
        if ($this->original['site_id'] !== null || $this->recovery !== null || (int) $this->original['independence_schema_version'] !== 0
            || (int) $this->original['independence_revision'] !== 0 || (bool) $this->original['independent_handling_requested']) {
            abort(403, 'The original incident Site requires independent source reconciliation.');
        }
        // Original predicate already closes the retained declaration/acceptance/audit graph.
        if ($this->reads->query(DB::table('audit_logs')->where('action', self::RECOVERY_ACTION)->where('auditable_id', $incident->id)
            ->where('auditable_type', $incident->getMorphClass()))->exists()) { abort(403, 'Retained Site recovery requires repair.'); }
        $kind = 'canonical_hs_source'; $reference = null;
        if ($hsIdentity !== null && $hsIdentity->site_id !== null) { $siteId = (int) $hsIdentity->site_id; $reference = $this->hsProof; }
        else {
            if ($hsIdentity !== null) { throw new \DomainException('Incident journey conflict: retained H&S source has no canonical Site.'); }
            $kind = 'current_client_review';
            $client = $this->payloadQuery(Client::query()->whereKey($this->original['client_id']))->first(['id', 'site_id']);
            if ($client?->site_id === null) { throw new \DomainException('A canonical Site is required before an incident can enter the H&S journey.'); }
            $siteId = (int) $client->site_id; $reference = $client->getRawOriginal();
        }
        abort_unless(in_array($siteId, $this->responseSites, true), 403, 'The recovered Site is outside the original response authority.');
        $site = $this->reads->query(DB::table('sites')->where('id', $siteId))->first(['id', 'is_active', 'archived', 'archived_at']);
        abort_unless($site !== null && (bool) $site->is_active && ! (bool) $site->archived && $site->archived_at === null, 403);
        $prospective = ['site_id' => $siteId, 'kind' => $kind, 'reference' => $reference, 'site' => (array) $site];
        $before = $this->incidentQuery(ClientIncident::query());
        $this->recoveryEvidence($before->getQuery(), $prospective);
        abort_unless($this->guardedUpdate($before, ['site_id' => $siteId]) === 1, 403, 'The original Site recovery evidence changed.');
        $incident->site_id = $siteId; $this->recovery = $prospective;
        $meta = ['actor_id' => (int) $this->actor->id, 'incident_id' => (int) $incident->id, 'before_site_id' => null,
            'recovered_site_id' => $siteId, 'recovery_kind' => $kind, 'reference_id' => (int) $reference['id'], 'schema_version' => 1];
        $request = clone request(); $request->setUserResolver(fn () => $this->actor);
        AuditLogger::logOrFail(self::RECOVERY_ACTION, $incident, $meta, $request);
        $audits = $this->payloadQuery(AuditLog::query()->where('action', self::RECOVERY_ACTION)->where('auditable_id', $incident->id)
            ->where('auditable_type', $incident->getMorphClass())->where('user_id', $this->actor->id))->select(['id', 'meta'])
            ->selectRaw(IncidentIndependenceBindingSql::fingerprint('audit', 'audit_logs').' as recovery_fp')->get();
        if ($audits->count() !== 1 || IncidentIndependenceValues::digest($audits->sole()->meta) !== IncidentIndependenceValues::digest($meta)) {
            throw new LogicException('Incident Site recovery requires one exact persisted provenance audit.');
        }
        $this->recovery['audit_id'] = (int) $audits->sole()->id; $this->recovery['audit_fp'] = $audits->sole()->recovery_fp;
        $this->assertCurrent();
    }

    private function recoveryGuards(QueryBuilder $query): void
    {
        $this->recoveryEvidence($query, $this->recovery);
        if (isset($this->recovery['audit_id'])) {
            $query->whereExists(fn ($audit) => $audit->selectRaw('1')->from('audit_logs as response_recovery_audit')->where('response_recovery_audit.id', $this->recovery['audit_id'])
                ->whereRaw(IncidentIndependenceBindingSql::fingerprint('audit', 'response_recovery_audit').' = ?', [$this->recovery['audit_fp']]))
                ->whereNotExists(fn ($audit) => $audit->selectRaw('1')->from('audit_logs as other_response_recovery_audit')
                    ->where('other_response_recovery_audit.action', self::RECOVERY_ACTION)->where('other_response_recovery_audit.auditable_id', $this->original['id'])
                    ->where('other_response_recovery_audit.auditable_type', (new ClientIncident)->getMorphClass())->where('other_response_recovery_audit.id', '!=', $this->recovery['audit_id']));
        }
    }

    private function recoveryEvidence(QueryBuilder $query, array $proof): void
    {
        $query->whereExists(function ($site) use ($proof): void {
            $site->selectRaw('1')->from('sites as response_recovered_site');
            foreach ($proof['site'] as $column => $value) { $this->exact($site, 'response_recovered_site.'.$column, $value); }
        });
        if ($proof['kind'] === 'canonical_hs_source') {
            $query->whereExists(function ($hs) use ($proof): void {
                $hs->selectRaw('1')->from('hs_events as response_recovery_hs');
                foreach ($proof['reference'] as $column => $value) {
                    // The legitimate response backlink is separately constrained by persistLinks.
                    if ($column !== 'control_room_alert_id') { $this->exact($hs, 'response_recovery_hs.'.$column, $value); }
                }
                $this->exact($hs, 'response_recovery_hs.control_room_alert_id', $this->hsProof['control_room_alert_id']);
            });
        } else {
            $query->whereExists(function ($client) use ($proof): void {
                $client->selectRaw('1')->from('clients as response_recovery_client');
                foreach ($proof['reference'] as $column => $value) { $this->exact($client, 'response_recovery_client.'.$column, $value); }
            });
        }
        $query->whereNotExists(fn ($hs) => $hs->selectRaw('1')->from('hs_events as response_extra_hs')
            ->where('response_extra_hs.source_type', ClientIncident::class)->where('response_extra_hs.source_id', $this->original['id'])
            ->when($this->hsProof !== null, fn ($known) => $known->where('response_extra_hs.id', '!=', $this->hsProof['id'])));
    }

    public function persist(ClientIncident $incident, array $attributes): void
    {
        $this->assertFor($incident);
        if (array_diff(array_keys($attributes), ['metadata', 'reference_number']) !== []) { throw new LogicException('Unexpected protected response source mutation.'); }
        $incident->forceFill($attributes);
        if ($incident->isDirty()) { abort_unless($this->guardedUpdate($this->incidentQuery(ClientIncident::query()), array_intersect_key($incident->getAttributes(), $attributes)) === 1, 403); }
        else { $this->assertCurrent(); }
        $incident->syncOriginalAttributes(array_keys($attributes));
    }

    public function persistLinks(ClientIncident $incident, HsEvent $event, ?ControlRoomAlert $alert): void
    {
        $this->assertFor($incident); $this->assertCurrent();
        $identity = $this->discoverHsMetadata();
        if ($identity === null || (int) $identity->id !== (int) $event->id) { throw new LogicException('Response link requires the canonical H&S source metadata.'); }
        if ($alert !== null && ($alert->client_id === null || (int) $alert->client_id !== (int) $this->original['client_id']
            || (int) $alert->site_id !== (int) ($this->recovery['site_id'] ?? $this->original['site_id']))) { throw new LogicException('Response alert link does not match the admitted source.'); }
        $attributes = ['hs_event_id' => (int) $event->id];
        if ($alert !== null) { $attributes['control_room_alert_id'] = (int) $alert->id; }
        $incident->forceFill($attributes);
        if ($incident->isDirty()) { abort_unless($this->guardedUpdate($this->incidentQuery(ClientIncident::query()), array_intersect_key($incident->getAttributes(), $attributes)) === 1, 403); }
        else { $this->assertCurrent(); }
        $this->hsId = (int) $event->id; if ($alert !== null) { $this->alertId = (int) $alert->id; }
        $incident->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));
        $this->assertCurrent();
    }
}
