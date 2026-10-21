<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Operations\OpsMessageVisibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ChatCallbacks
{
    public const ACTIVE = ['requested', 'assigned', 'scheduled', 'attempted'];

    public function __construct(private readonly ChatAccess $access, private readonly OpsMessageVisibilityService $staff) {}

    public function visible(OpsConversation $conversation, User $actor): Builder
    {
        $this->access->authorize($actor, $conversation);
        abort_unless($conversation->conversation_type === 'family', 403);
        $member = $conversation->participants()->where('user_id', $actor->id)->first();

        return DB::table('chat_callbacks')->where('conversation_id', $conversation->id)
            ->when($member?->history_after_id !== null, fn ($query) => $member->callback_history_after_id !== null
                ? $query->where('id', '>', $member->callback_history_after_id)
                : $query->whereRaw('1 = 0'));
    }

    public function owners(OpsConversation $conversation): Collection
    {
        // Current staff shortlisted in SQL (members + cover candidates); allows() stays the authority.
        return $this->access->staffCandidates($conversation, true)->get()
            ->filter(fn ($person) => $this->access->allows($person, $conversation, true))
            ->map(fn ($person) => ['id' => $person->id, 'name' => $person->name])->values();
    }

    public function page(OpsConversation $conversation, User $actor, array $filters): array
    {
        $base = $this->visible($conversation, $actor);
        $current = (clone $base)->whereIn('status', self::ACTIVE)->orderByDesc('id')->first();
        $through = isset($filters['through']) ? (int) $filters['through'] : null;
        $older = $through && (clone $base)->where('id', '<', $through)->exists() ? $through : null;
        $rows = $base->when($filters['before'] ?? null, fn ($query, $before) => $query->where('id', '<', $before))
            ->when($through, fn ($query) => $query->where('id', '>=', $through))->orderByDesc('id')->limit(31)->get();
        $staff = $this->staff->isCurrentStaff($actor);
        $all = $rows->concat($current ? [$current] : [])->unique('id');
        $owners = $staff ? $this->owners($conversation) : collect();
        $boundaries = $conversation->participants()->whereNotNull('history_after_id')->pluck('callback_history_after_id', 'user_id');
        $names = User::whereIn('id', $all->pluck('assigned_to')->filter())->pluck('name', 'id');
        $events = DB::table('chat_callback_events')->whereIn('callback_id', $all->pluck('id'))->where('action', 'attempt')->orderBy('id')->get()->groupBy('callback_id');
        $policy = app(ChatSettings::class)->forConversation($conversation);
        $writable = $policy['enabled'] && $policy['family_enabled'] && $this->access->allows($actor, $conversation, true);
        $project = fn ($row) => [
            'id' => (int) $row->id, 'requested_by' => (int) $row->requested_by,
            'assigned_to' => $row->assigned_to ? (int) $row->assigned_to : null,
            'owner_name' => $row->assigned_to ? ($names[$row->assigned_to] ?? 'Former care-team member') : null,
            'status' => $row->status, 'preferred_time' => $row->preferred_time,
            'agreed_time' => $row->agreed_time, 'request_note' => $row->request_note,
            'attempts' => (int) $row->attempts, 'version' => (int) $row->version, 'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toISOString(),
            'attempt_history' => ($events[$row->id] ?? collect())->map(fn ($event) => ['id' => (int) $event->id, 'created_at' => CarbonImmutable::parse($event->created_at, 'UTC')->toISOString()])->values(),
            'eligible_owner_ids' => $owners->filter(fn ($person) => ! $boundaries->has($person['id']) || ($boundaries[$person['id']] !== null && $row->id > $boundaries[$person['id']]))->pluck('id')->values(),
            'can_manage' => $writable && $staff && in_array($row->status, self::ACTIVE, true),
            'can_cancel' => $writable && ($staff || (int) $row->requested_by === (int) $actor->id) && in_array($row->status, self::ACTIVE, true),
        ];
        $items = $rows->take(30)->map($project)->values();
        $floor = $items->last()['id'] ?? null;

        return ['callbacks' => $items, 'next_before' => $rows->count() > 30 ? $floor : null, 'scan_floor' => $floor, 'older_before' => $older,
            'owners' => $owners, 'current_callback' => $current ? $project($current) : null, 'can_request' => $writable && ! $conversation->is_archived && ! $current];
    }

    public function request(OpsConversation $input, User $actor, array $data): array
    {
        return DB::transaction(function () use ($input, $actor, $data) {
            $conversation = OpsConversation::whereKey($input->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $conversation, true);
            $hash = hash('sha256', json_encode(['request', $conversation->id, $data['preferred_time'], $data['request_note'] ?? null], JSON_THROW_ON_ERROR));
            if ($id = $this->receipt($actor, $data['mutation_id'], $hash)) {
                abort_unless($this->visible($conversation, $actor)->where('id', $id)->exists(), 403);

                return ['id' => $id, 'already_requested' => true];
            }
            $this->writable($conversation);
            abort_if($conversation->is_archived, 409, 'This conversation is archived.');
            // Reuse visible open work without revealing history excluded by an invitation.
            $existing = $this->visible($conversation, $actor)->whereIn('status', self::ACTIVE)->orderByDesc('id')->first();
            $id = $existing?->id ?? DB::table('chat_callbacks')->insertGetId([
                'conversation_id' => $conversation->id, 'requested_by' => $actor->id,
                'preferred_time' => trim($data['preferred_time']), 'request_note' => $data['request_note'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->recordReceipt($actor, $data['mutation_id'], $hash, $id);
            if (! $existing) {
                $this->event($conversation, $actor, $id, 'request');
            }

            return ['id' => (int) $id, 'already_requested' => (bool) $existing];
        });
    }

    public function update(int $id, User $actor, array $data): void
    {
        $input = DB::table('chat_callbacks')->where('id', $id)->first();
        abort_unless($input, 404);
        DB::transaction(function () use ($input, $actor, $data) {
            // Consistent lock order with creation and membership changes.
            $conversation = OpsConversation::whereKey($input->conversation_id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $conversation, true);
            $row = $this->visible($conversation, $actor)->where('id', $input->id)->lockForUpdate()->first();
            abort_unless($row, 404);
            $staff = $this->staff->isCurrentStaff($actor);
            abort_unless($staff || ($data['action'] === 'cancel' && (int) $row->requested_by === (int) $actor->id), 403);
            $hash = hash('sha256', json_encode(['update', $row->id, (int) $data['version'], $data['action'], $data['assigned_to'] ?? null, $data['agreed_time'] ?? null], JSON_THROW_ON_ERROR));
            if ($this->receipt($actor, $data['mutation_id'], $hash)) {
                return;
            }
            $this->writable($conversation);
            abort_unless((int) $row->version === (int) $data['version'], 409, 'This callback changed. Review the current owner and agreed time before saving again.');
            abort_unless(in_array($row->status, self::ACTIVE, true), 409, 'This callback is already closed.');
            $updates = ['version' => (int) $row->version + 1, 'updated_at' => now()];
            switch ($data['action']) {
                case 'assign':
                    $owner = $data['assigned_to'] ?? null;
                    abort_unless($owner === null || $this->owners($conversation)->contains('id', (int) $owner), 422, 'Choose a currently authorised care-team member.');
                    if ($owner !== null) {
                        abort_unless($this->visible($conversation, User::findOrFail($owner))->where('id', $row->id)->exists(), 422, 'That person cannot access this callback history.');
                    }
                    $updates += ['assigned_to' => $owner, 'status' => $owner ? ($row->agreed_time ? 'scheduled' : 'assigned') : 'requested'];
                    break;
                case 'schedule':
                    abort_unless($row->assigned_to && $this->owners($conversation)->contains('id', (int) $row->assigned_to), 409, 'Assign a currently authorised owner before agreeing a time.');
                    $updates += ['agreed_time' => trim($data['agreed_time']), 'status' => 'scheduled'];
                    break;
                case 'cancel':
                    $updates['status'] = 'cancelled';
                    break;
                default:
                    abort_unless((int) $row->assigned_to === (int) $actor->id, 409, 'Assign this callback to yourself before recording its outcome.');
                    $updates['status'] = match ($data['action']) {
                        'attempt' => 'attempted', 'complete' => 'completed', 'unreachable' => 'unreachable'
                    };
                    if ($data['action'] === 'attempt') {
                        $updates['attempts'] = (int) $row->attempts + 1;
                    }
            }
            DB::table('chat_callbacks')->where('id', $row->id)->update($updates);
            $this->recordReceipt($actor, $data['mutation_id'], $hash, $row->id);
            $this->event($conversation, $actor, $row->id, $data['action']);
        });
    }

    private function writable(OpsConversation $conversation): void
    {
        $policy = app(ChatSettings::class)->forConversation($conversation);
        abort_unless($policy['enabled'] && $policy['family_enabled'], 409, 'Chat is currently read-only.');
    }

    private function receipt(User $actor, string $mutation, string $hash): ?int
    {
        $receipt = DB::table('chat_callback_mutations')->where('actor_id', $actor->id)->where('mutation_id', $mutation)->first();
        if (! $receipt) {
            return null;
        }
        abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This update reference was already used for different callback details.');

        return (int) $receipt->callback_id;
    }

    private function recordReceipt(User $actor, string $mutation, string $hash, int $id): void
    {
        DB::table('chat_callback_mutations')->insert(['actor_id' => $actor->id, 'mutation_id' => $mutation, 'callback_id' => $id, 'request_hash' => $hash, 'created_at' => now()]);
    }

    private function event(OpsConversation $conversation, User $actor, int $id, string $action): void
    {
        DB::table('chat_callback_events')->insert(['callback_id' => $id, 'actor_id' => $actor->id, 'action' => $action, 'created_at' => now()]);
        AuditLog::create(['user_id' => $actor->id, 'client_id' => $conversation->client_id, 'action' => 'chat.callback.'.$action, 'meta' => ['callback_id' => $id, 'conversation_id' => $conversation->id]]);
        app(ChatRealtime::class)->queueConversation($conversation->id);
    }
}
