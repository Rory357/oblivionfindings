<?php

namespace App\Services\Incidents;

use App\Models\AuditLog;
use App\Models\ClientIncident;
use App\Models\HealthSafetySettingChange;
use App\Models\IncidentOwnerChange;
use App\Models\IncidentRoutingDecision;
use App\Models\IncidentRoutingIntent;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Internal typed-producer evidence only. Callers own actor eligibility; no source/body handle leaves their callback. */
final class IncidentTypedNotificationReadService
{
    private const FAMILIES = [
        'change' => [IncidentOwnerChange::class, ['id', 'incident_id', 'revision', 'previous_owner_user_id', 'previous_owner_name', 'owner_user_id', 'owner_name', 'actor_user_id', 'actor_name', 'reason', 'source', 'routing_version', 'request_uuid', 'request_hash', 'created_at', 'source_schema_version', 'audit_log_id']],
        'intent' => [IncidentRoutingIntent::class, ['id', 'incident_id', 'owner_revision', 'kind', 'request_uuid', 'source_tuple', 'source_digest', 'trigger_actor_id', 'trigger_actor_name', 'state', 'attempts', 'failure_code', 'evaluated_at', 'created_at', 'updated_at']],
        'decision' => [IncidentRoutingDecision::class, ['id', 'intent_id', 'attempt', 'state', 'reason_code', 'source_tuple', 'source_digest', 'setting_change_id', 'configuration_revision', 'configuration_digest', 'configuration', 'owner_change_id', 'audit_log_id', 'recorded_at', 'escalate_at']],
        'audit' => [AuditLog::class, ['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'meta']],
        'setting' => [HealthSafetySettingChange::class, ['id', 'setting_key', 'revision', 'schema_version', 'action', 'origin', 'before_value', 'after_value', 'before_digest', 'after_digest', 'actor_id', 'actor_snapshot', 'recorded_at', 'reason', 'audit_id', 'command_key', 'fingerprint', 'accepted_result']],
    ];

    /** Additive verifier seam: default queries retain their exact policy; a supplied parent requires the matching active handle. */
    public function constrain(Builder $query, ?Builder $protectedSource = null, ?CurrentAuthorizationReads $reads = null): Builder
    {
        if ($protectedSource === null && $reads === null) { return $query; }
        if ($protectedSource === null || $reads === null) { throw new LogicException('Protected incident verification requires both parent and current reads.'); }
        $reads->assertActive();
        if (! $protectedSource->getModel() instanceof ClientIncident || $protectedSource->getQuery()->from !== 'client_incidents') {
            throw new LogicException('Protected incident verification requires the canonical source query.');
        }
        return $reads->query($query->whereExists((clone $protectedSource)->reorder()->select('client_incidents.id')->toBase()));
    }
    public function source(User $current, int $id, CurrentAuthorizationReads $reads, string $purpose = 'notification'): Builder
    {
        $ordinary = app(IncidentAccessService::class)->apply(ClientIncident::query()->whereKey($id), $current, $reads);
        return app(IncidentIndependenceIntakeService::class)->applySourceScopeInReads($ordinary, $current, $reads, $purpose);
    }

    /** Use only AFTER the current recipient source gate. Fingerprint is captured with the body actually given to canonical proof. */
    public function row(string $family, int $id, CurrentAuthorizationReads $reads, Builder $protectedSource, ?Model $identity = null, array $identityColumns = []): Model
    {
        [$class] = self::FAMILIES[$family] ?? throw new LogicException('Unsupported typed incident notification evidence.');
        $reads->assertActive();
        if (! $protectedSource->getModel() instanceof ClientIncident || $protectedSource->getQuery()->from !== 'client_incidents') {
            throw new LogicException('Typed incident body reads require the canonical protected parent query.');
        }
        $parents = (clone $protectedSource)->reorder()->select('client_incidents.id');
        $query = $class::query()->whereKey($id)->whereExists((clone $parents)->toBase());
        if (in_array($family, ['change', 'intent'], true)) { $query->whereIn('incident_id', clone $parents); }
        if ($family === 'decision') { $query->whereIn('intent_id', IncidentRoutingIntent::query()->whereIn('incident_id', clone $parents)->select('id')); }
        if ($identity !== null) { $this->identity($query, $identity, $identityColumns); }
        return $reads->query($query->select($query->getModel()->getTable().'.*')
            ->selectRaw($this->fingerprint($family, $query->getModel()->getTable()).' AS notification_fp'))->firstOrFail();
    }

    /** Final source/current graph plus proof-used typed row snapshots, never replacement authority. */
    public function protect(Builder $source, array $rows, CurrentAuthorizationReads $reads): Builder
    {
        $query = clone $source;
        foreach ($rows as $family => $members) {
            [$class] = self::FAMILIES[$family] ?? throw new LogicException('Unsupported typed incident notification evidence.');
            foreach (is_array($members) ? $members : [$members] as $row) {
                if (! $row instanceof $class || ! is_string($row->getRawOriginal('notification_fp'))) {
                    throw new LogicException('Typed incident notification proof lacks its selected evidence snapshot.');
                }
                $table = $row->getTable();
                $query->whereExists(fn ($guard) => $guard->selectRaw('1')->from($table)
                    ->where($table.'.id', $row->id)->whereRaw($this->fingerprint($family, $table).' = ?', [$row->getRawOriginal('notification_fp')]));
            }
        }
        return $reads->query($query);
    }

    public function identity(Builder $query, Model $observed, array $columns): Builder
    {
        foreach ($columns as $column) {
            $value = $observed->getRawOriginal($column); $qualified = $query->qualifyColumn($column);
            if ($value === null) { $query->whereNull($qualified); }
            elseif (is_string($value)) { $query->whereRaw('BINARY '.$query->getQuery()->getGrammar()->wrap($qualified).' = BINARY ?', [$value]); }
            else { $query->where($qualified, $value); }
        }
        return $query;
    }

    private function fingerprint(string $family, string $table): string
    {
        [, $columns] = self::FAMILIES[$family] ?? throw new LogicException('Unsupported typed incident notification evidence.');
        $class = self::FAMILIES[$family][0]; $expected = (new $class)->getTable();
        if ($table !== $expected) { throw new LogicException('Typed incident notification evidence uses a different table.'); }
        $parts = array_map(fn ($column) => "'".$column."', `".$table.'`.`'.$column.'`', $columns);
        return 'SHA2(CAST(JSON_OBJECT('.implode(', ', $parts).') AS CHAR CHARACTER SET utf8mb4), 256)';
    }
}
