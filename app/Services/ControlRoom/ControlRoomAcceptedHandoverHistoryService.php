<?php

namespace App\Services\ControlRoom;

use App\Models\ControlRoom\Shift;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only integrity proof for an already accepted transfer; never rebuilds or repairs it. */
final class ControlRoomAcceptedHandoverHistoryService
{
    public const UNAVAILABLE_MESSAGE = 'The saved handover or acceptance details are incomplete or inconsistent. Ask a Control Room administrator to review this record.';

    public function __construct(private readonly ControlRoomPreparedHandoverSnapshotService $preparedSnapshots) {}

    /** @return array{snapshot: array<string, mixed>, acceptance: array<string, mixed>} */
    /** Static lookup holds no original graph, viewer, query, token or context strongly. */
    private static ?\WeakMap $originalReadOwners = null;

    private function originalReadOwner(\App\Services\HealthSafety\HealthSafetyReadContext $context): \stdClass
    {
        $context->assertActive();
        self::$originalReadOwners ??= new \WeakMap;
        if (isset(self::$originalReadOwners[$context])) {
            $owner = self::$originalReadOwners[$context]->get();
            if ($owner === null) { throw new \LogicException('The original read owner cannot be replaced.'); }
            return $owner;
        }
        $owner = (object) ['frames' => []];
        $context->retainControlRoomOriginalReadOwner($owner);
        self::$originalReadOwners[$context] = \WeakReference::create($owner);
        return $owner;
    }

    /** The accepted transfer checks require incoming metadata and fingerprints, never its snapshot or Notes. */
    public function validated(Shift $outgoing, HealthSafetyReadContext $context, object $savedReference): array
    {
        return $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (\App\Models\User $current, \App\Services\CurrentAuthorizationReads $reads) use ($outgoing, $context, $savedReference): array {
                $owner = app(ControlRoomHandoverReadAccessService::class);
                $owner->verifySavedProjection($current, $savedReference, $context, $outgoing);
                $originalProjection = $outgoing;
                // Cached casts or getter mutations cannot supply transfer facts.
                $outgoing = (new Shift)->setRawAttributes($outgoing->getRawOriginal(), true);
                if ($outgoing->status !== 'completed' || $outgoing->handover_status !== Shift::HANDOVER_ACCEPTED) { $this->unavailable(); }
                $snapshot = $this->preparedSnapshots->validated($outgoing);
                $raw = $outgoing->handover_snapshot; $actor = $raw['accepted_by'] ?? null;
                if (! is_array($actor) || ! is_string($actor['name'] ?? null) || trim($actor['name']) === '') { $this->unavailable(); }
                $actorId = $this->id($actor['id'] ?? null); $incomingId = $this->id(data_get($raw, 'incoming_shift.id'));
                $acceptedAt = $this->timestamp($raw['accepted_at'] ?? null); $preparedAt = $this->timestamp($snapshot['prepared_at']);
                $this->timestamp($snapshot['criteria_at']); $this->timestamp($snapshot['next_expected_shift_at']);
                $this->timestamp($snapshot['carry_forward_acknowledged_at']);
                if ($actorId !== (int) $outgoing->handed_over_to_user_id || $actorId !== $snapshot['incoming_shift']['lead']['id']
                    || $incomingId === (int) $outgoing->id || $outgoing->handover_version < 1 || $outgoing->starts_at === null
                    || ! $outgoing->ends_at?->equalTo($acceptedAt) || ! $outgoing->handed_over_at?->equalTo($acceptedAt)
                    || ! $outgoing->handover_accepted_at?->equalTo($acceptedAt) || $acceptedAt->lessThan($preparedAt)
                    || $acceptedAt->lessThan($outgoing->starts_at)) { $this->unavailable(); }
                
                $frames = $this->originalReadOwner($context)->frames['originalReads'] ?? []; $key = spl_object_id($savedReference);
                if (isset($frames[$key])) {
                    $this->verifyFrame($frames[$key], $current, $reads, $context, $savedReference);
                } else {
                    $authority = $context->originalSourceAuthorityQuery(ControlRoomGovernanceReadService::PERMISSIONS, []);
                    $authority->toSql();
                    $incomingQuery = $reads->query(Shift::query()->whereKey($incomingId)
                        ->whereExists($authority->selectRaw('1')->toBase()));
                    $incoming = (clone $incomingQuery)->select(['id', 'starts_at', 'shift_lead_user_id'])->toBase()->first();
                    if ($incoming === null || $incoming->starts_at === null || ! Carbon::parse($incoming->starts_at)->equalTo($acceptedAt)
                        || (int) $incoming->shift_lead_user_id !== $actorId) { $this->unavailable(); }
                    foreach ((array) $incoming as $column => $value) {
                        $value === null ? $incomingQuery->whereNull($column)
                            : $incomingQuery->whereRaw('CAST(control_room_shifts.'.$column.' AS BINARY) = CAST(? AS BINARY)', [$value]);
                    }
                    // Only typed acceptance metadata and whole-receipt fingerprints cross the SQL boundary.
                    $auditQuery = $reads->query(DB::table('audit_logs')
                        ->whereRaw('BINARY action = BINARY ?', ['controlRoom.shift.handoverAccepted'])
                        ->whereRaw('BINARY auditable_type = BINARY ?', [$outgoing->getMorphClass()])
                        ->where('auditable_id', $outgoing->id)->where('user_id', $actorId));
                    foreach (['actor_id' => $actorId, 'outgoing_shift_id' => (int) $outgoing->id,
                        'incoming_shift_id' => $incomingId, 'incoming_lead_user_id' => $actorId,
                        'handover_version' => $outgoing->handover_version] as $field => $value) {
                        $auditQuery->whereRaw("JSON_TYPE(JSON_EXTRACT(meta,'$.{$field}')) = 'INTEGER'")
                            ->whereRaw("JSON_EXTRACT(meta,'$.{$field}') = ?", [$value]);
                    }
                    $auditSet = $this->acceptanceSet($auditQuery);
                    if (count($auditSet) !== 1) { $this->unavailable(); }
                    $frames[$key] = ['reference' => $savedReference, 'context_id' => spl_object_id($context), 'reads' => $reads,
                        'viewer_id' => (int) $current->id, 'incoming_query' => $incomingQuery, 'audit_query' => $auditQuery,
                        'audit_set' => $auditSet, 'outgoing_id' => (int) $outgoing->id];
                    $this->verifyFrame($frames[$key], $current, $reads, $context, $savedReference);
                    $this->originalReadOwner($context)->frames['originalReads'] = $frames;
                }
                $result = ['snapshot' => $snapshot, 'acceptance' => [
                    'accepted_by' => ['id' => $actorId, 'name' => $actor['name']], 'accepted_at' => $raw['accepted_at'],
                    'handover_version' => $outgoing->handover_version,
                    'outgoing_duty' => ['id' => (int) $outgoing->id, 'name' => $outgoing->name, 'href' => "/control-room/shifts/{$outgoing->id}/handover"],
                    'incoming_duty' => ['id' => $incomingId, 'name' => $snapshot['incoming_shift']['name'], 'href' => "/control-room/shifts/{$incomingId}/handover"],
                ]];
                $owner->verifySavedProjection($current, $savedReference, $context, $originalProjection);
                $this->verifyFrame($frames[$key], $current, $reads, $context, $savedReference);
                return $result;
            });
    }

    /** Controller repeats the original incoming/receipt proof after all shared shaping and note callbacks. */
    public function verifyOriginal(Shift $outgoing, \App\Models\User $viewer, HealthSafetyReadContext $context, object $savedReference): void
    {
        app(ControlRoomHandoverReadAccessService::class)->verifySavedProjection($viewer, $savedReference, $context, $outgoing);
        $context->consumeSourceViewer(ControlRoomGovernanceReadService::PERMISSIONS,
            function (\App\Models\User $current, \App\Services\CurrentAuthorizationReads $reads) use ($context, $savedReference, $outgoing): void {
                $frame = $this->originalReadOwner($context)->frames['originalReads'][spl_object_id($savedReference)] ?? null;
                if ($frame === null || $frame['outgoing_id'] !== (int) $outgoing->getRawOriginal('id')) {
                    throw new \LogicException('Use the original accepted transfer read inside its own active context.');
                }
                $this->verifyFrame($frame, $current, $reads, $context, $savedReference);
            });
    }

    /** Reuse the actual first incoming-duty/typed acceptance witnesses; no recapture or snapshot body. */
    public function consumeOriginalAcceptedReadConstraints(\App\Models\User $current, \App\Services\CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, callable $consume): void
    {
        $context->assertViewer($current);$reads->assertActive();$constraints=[];
        foreach($this->originalReadOwner($context)->frames['originalReads']??[]as$frame){
            if($frame['context_id']!==spl_object_id($context)||$frame['reads']!==$reads||$frame['viewer_id']!==(int)$current->id){
                throw new \LogicException('Use the original accepted transfer context.');
            }
            $transfer=clone$frame['incoming_query'];
            foreach($frame['audit_set']as$receipt){
                $present=(clone$frame['audit_query'])->where('id',$receipt['id'])->where('user_id',$receipt['user_id'])
                    ->whereRaw('created_at <=> ?',[$receipt['created_at']])
                    ->whereRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) <=> ?',[$receipt['sha256']]);
                $transfer->whereExists($present->selectRaw('1'));
            }
            $extras=(clone$frame['audit_query'])->whereNotIn('id',array_column($frame['audit_set'],'id'));
            $transfer->whereNotExists($extras->selectRaw('1'));$constraints[]=$transfer;
        }
        $consume($constraints);
    }

    private function verifyFrame(array $frame, \App\Models\User $viewer, \App\Services\CurrentAuthorizationReads $reads,
        HealthSafetyReadContext $context, object $reference): void
    {
        $context->assertViewer($viewer); $reads->assertActive();
        if ($frame['reference'] !== $reference || $frame['context_id'] !== spl_object_id($context)
            || $frame['reads'] !== $reads || $frame['viewer_id'] !== (int) $viewer->id) {
            throw new \LogicException('Accepted transfer original read identity changed.');
        }
        app(ControlRoomHandoverReadAccessService::class)->verifySavedReference($viewer, $reference, $context);
        if (! (clone $frame['incoming_query'])->exists() || $this->acceptanceSet($frame['audit_query']) !== $frame['audit_set']) {
            $this->unavailable();
        }
        // One narrowed outgoing proof checks incoming metadata and the exact receipt set after audit listeners.
        $transfer = clone $frame['incoming_query'];
        foreach ($frame['audit_set'] as $receipt) {
            $present = (clone $frame['audit_query'])->where('id', $receipt['id'])->where('user_id', $receipt['user_id'])
                ->whereRaw('created_at <=> ?', [$receipt['created_at']])
                ->whereRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) <=> ?', [$receipt['sha256']]);
            $transfer->whereExists($present->selectRaw('1'));
        }
        $extras = (clone $frame['audit_query'])->whereNotIn('id', array_column($frame['audit_set'], 'id'));
        $transfer->whereNotExists($extras->selectRaw('1'));
        app(ControlRoomHandoverReadAccessService::class)->verifySavedReference($viewer, $reference, $context, $transfer);
    }

    private function acceptanceSet(\Illuminate\Database\Query\Builder $query): array
    {
        return (clone $query)->select(['id', 'user_id', 'created_at'])
            ->selectRaw('SHA2(CAST(meta AS CHAR CHARACTER SET utf8mb4),256) as acceptance_sha256')->orderBy('id')->get()
            ->map(fn ($row): array => ['id' => (int) $row->id, 'user_id' => (int) $row->user_id,
                'created_at' => $row->created_at, 'sha256' => $row->acceptance_sha256])->all();
    }

    private function id(mixed $value): int
    {
        if ((is_int($value) && $value > 0) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/D', $value))) {
            return (int) $value;
        }
        $this->unavailable();
    }

    private function timestamp(mixed $value): Carbon
    {
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $value)) {
            $this->unavailable();
        }
        try {
            $parsed = Carbon::parse($value);
            if ($parsed->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
                $this->unavailable();
            }

            return $parsed;
        } catch (\Throwable) {
            $this->unavailable();
        }
    }

    private function unavailable(): never
    {
        throw ValidationException::withMessages(['handover' => self::UNAVAILABLE_MESSAGE]);
    }
}
