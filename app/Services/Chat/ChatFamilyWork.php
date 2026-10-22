<?php

namespace App\Services\Chat;

use App\Jobs\DeliverChatFamilyWorkNotifications;
use App\Models\AuditLog;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Operations\OpsMessageVisibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ChatFamilyWork
{
    public function __construct(private readonly ChatAccess $access, private readonly OpsMessageVisibilityService $staff) {}

    private function authorize(User $actor, OpsConversation $conversation, bool $write = false): void
    {
        $this->access->authorize($actor, $conversation, $write);
        abort_unless($conversation->conversation_type === 'family' && $this->staff->isCurrentStaff($actor)
            && ! $actor->canAccessClientPortal($conversation->client), 403);
    }

    public function page(User $actor, OpsConversation $conversation, array $filters = []): array
    {
        return DB::transaction(function () use ($actor, $conversation, $filters) {
            $current = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            return $this->snapshot($actor, $current, $filters);
        });
    }

    private function snapshot(User $actor, OpsConversation $conversation, array $filters): array
    {
        $this->authorize($actor, $conversation);
        $member = $conversation->participants()->where('user_id', $actor->id)->first();
        $base = DB::table('chat_family_work_events')->where('conversation_id', $conversation->id)
            ->when($member?->history_after_id !== null, fn ($query) => $member->family_work_history_after_id !== null
                ? $query->where('id', '>', $member->family_work_history_after_id) : $query->whereRaw('1 = 0'));
        $through = isset($filters['through']) ? (int) $filters['through'] : null;
        $older = $through && (clone $base)->where('id', '<', $through)->exists() ? $through : null;
        $rows = $base->when($filters['before'] ?? null, fn ($query, $before) => $query->where('id', '<', $before))
            ->when($through, fn ($query) => $query->where('id', '>=', $through))->orderByDesc('id')->limit(31)->get();
        $names = User::whereIn('id', $rows->pluck('actor_id')->merge($rows->pluck('owner_id'))->push($conversation->assigned_to)->filter())->pluck('name', 'id');
        $policy = app(ChatSettings::class)->forConversation($conversation);
        $writable = $policy['enabled'] && $policy['family_enabled'] && ! $conversation->is_archived && $this->access->allows($actor, $conversation, true);
        $events = $rows->take(30)->map(fn ($row) => [
            'id' => (int) $row->id, 'action' => $row->action, 'actor_name' => $names[$row->actor_id] ?? 'Former care-team member',
            'owner_name' => $row->owner_id ? ($names[$row->owner_id] ?? 'Former care-team member') : null,
            'status' => $row->queue_status, 'note' => $row->encrypted_note ? Crypt::decryptString($row->encrypted_note) : null,
            'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toISOString(),
        ])->values();
        $floor = $events->last()['id'] ?? null;

        return ['version' => $conversation->chat_version, 'status' => $conversation->queue_status,
            'assigned_to' => $conversation->assigned_to ? (int) $conversation->assigned_to : null,
            'owner_name' => $conversation->assigned_to ? ($names[$conversation->assigned_to] ?? 'Former care-team member') : null,
            'owners' => $writable ? app(ChatCallbacks::class)->owners($conversation)->reject(fn ($person) => User::find($person['id'])->canAccessClientPortal($conversation->client))->values() : [],
            'can_manage' => $writable, 'response_expectation' => $policy['response_expectation'],
            'can_create_followup' => $writable && app(ChatFamilyFollowups::class)->canCreate($actor, $conversation),
            'response_clock' => app(ChatFamilyResponse::class)->project($conversation, $actor, $policy),
            'events' => $events, 'next_before' => $rows->count() > 30 ? $floor : null, 'scan_floor' => $floor, 'older_before' => $older];
    }

    public function update(User $actor, OpsConversation $input, array $data): void
    {
        $eventId = DB::transaction(function () use ($actor, $input, $data) {
            $conversation = OpsConversation::whereKey($input->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $conversation, true);
            $hash = hash('sha256', json_encode([$conversation->id, $data['version'] ?? null, $data['action'], $data['assigned_to'] ?? null, $data['status'] ?? null, $data['note'] ?? null], JSON_THROW_ON_ERROR));
            $receipt = DB::table('chat_family_work_events')->where('actor_id', $actor->id)->where('mutation_id', $data['mutation_id'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This update reference was already used for different details.');

                return;
            }
            $policy = app(ChatSettings::class)->forConversation($conversation);
            abort_unless($policy['enabled'] && $policy['family_enabled'] && ! $conversation->is_archived, 409, 'This conversation is currently read-only.');
            if ($data['action'] !== 'note') {
                abort_unless($conversation->chat_version === $data['version'], 409, 'This conversation changed. Review the current owner and status before saving again.');
            }
            $previousOwner = $conversation->assigned_to ? (int) $conversation->assigned_to : null;
            $updates = [];
            if (in_array($data['action'], ['assign', 'claim', 'handoff'], true)) {
                $owner = $data['action'] === 'claim' ? $actor->id : ($data['assigned_to'] ?? null);
                abort_if($data['action'] === 'claim' && $conversation->assigned_to && (int) $conversation->assigned_to !== (int) $actor->id, 409, 'Another colleague has claimed this conversation. Review its current owner.');
                if ($owner !== null) {
                    $person = User::find($owner);
                    abort_unless($person && $this->staff->isCurrentStaff($person) && ! $person->canAccessClientPortal($conversation->client) && $this->access->allows($person, $conversation, true), 422, 'Choose a currently authorised care-team member.');
                }
                $updates['assigned_to'] = $owner;
            }
            if ($data['action'] === 'status') {
                $updates['queue_status'] = $data['status'];
                if ($data['status'] !== 'open') {
                    $updates['family_response_started_at'] = null;
                    $updates['family_response_message_id'] = null;
                }
            }
            if ($updates) {
                $conversation->update([...$updates, 'chat_version' => $conversation->chat_version + 1]);
            }
            $id = DB::table('chat_family_work_events')->insertGetId([
                'conversation_id' => $conversation->id, 'actor_id' => $actor->id, 'mutation_id' => $data['mutation_id'], 'request_hash' => $hash,
                'action' => $data['action'], 'owner_id' => $conversation->assigned_to, 'queue_status' => $conversation->queue_status,
                'encrypted_note' => trim($data['note'] ?? '') !== '' ? Crypt::encryptString(trim($data['note'])) : null, 'created_at' => now(),
            ]);
            AuditLog::create(['user_id' => $actor->id, 'client_id' => $conversation->client_id, 'action' => 'chat.family_work.'.$data['action'], 'meta' => ['conversation_id' => $conversation->id, 'event_id' => $id]]);
            app(ChatRealtime::class)->queueConversation($conversation->id, kinds: 'family_work');

            return in_array($data['action'], ['assign', 'handoff'], true)
                && app(ChatFamilyWorkDelivery::class)->enqueue($id, $conversation, (int) $actor->id, $previousOwner) ? $id : null;
        });
        if ($eventId) {
            try {
                Bus::dispatch(new DeliverChatFamilyWorkNotifications($eventId));
            } catch (\Throwable $error) {
                // Ownership is already committed; the scheduler can recover its durable notice.
                report($error);
            }
        }
    }
}
