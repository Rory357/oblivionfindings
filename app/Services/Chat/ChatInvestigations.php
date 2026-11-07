<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\User;
use App\Services\Operations\OpsMessageVisibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Case-based, time-limited, read-only inspection of private staff chats.
 *
 * Only holders of `chat.investigate` (assigned to named people, never a
 * role default) may use it, and settings access alone never implies it.
 * Every lookup, grant, view, export, revocation and refused expired access
 * is written to `chat_investigation_events`, a separate audit domain from
 * the settings audit. Inspection never creates a participant row, a read
 * cursor, a notification or a realtime event.
 *
 * One case may cover several conversations (approved 22 October 2026):
 * they are granted together under one case reference, reason and access
 * period, but each keeps its own grant row so expiry, ending and logging
 * stay per conversation. `case_batch` links the rows granted together.
 */
final class ChatInvestigations
{
    public const PERMISSION = 'chat.investigate';

    /** Allowed access windows, in hours. */
    public const DURATIONS = [1, 8, 24, 72, 168];

    public const PAGE = 100;

    /** Most conversations one case grant may cover in a single logged step. */
    public const MAX_CONVERSATIONS = 20;

    public function __construct(private readonly OpsMessageVisibilityService $staff) {}

    public function isInvestigator(?User $user): bool
    {
        return $user !== null && $user->canDo(self::PERMISSION) && $this->staff->isCurrentStaff($user);
    }

    public function authorizeInvestigator(?User $user): void
    {
        abort_unless($this->isInvestigator($user), 403, 'Chat investigation needs the restricted investigator permission.');
    }

    /** Family conversations are client records with their own access rules. */
    public function assertInvestigable(OpsConversation $conversation): void
    {
        abort_if($conversation->conversation_type === 'family' || $conversation->client_id !== null, 422,
            'Only private staff conversations can be opened here. Family conversations follow client record access.');
    }

    public function describe(OpsConversation $conversation): array
    {
        $members = $conversation->participants->where('role', '!=', OpsConversationParticipant::COVER_READER);

        return [
            'id' => (int) $conversation->id,
            'type' => $conversation->is_managed_channel ? 'channel' : ($conversation->conversation_type === 'group' ? 'group' : 'direct'),
            'title' => $conversation->title,
            'members' => $members->map(fn ($member) => $member->user?->name ?? 'Former staff member')->sort()->values()->all(),
            'created_at' => $conversation->created_at?->toISOString(),
            'held' => $this->held((int) $conversation->id),
        ];
    }

    /** Find private staff conversations by a member's name. Metadata only, never content. */
    public function lookup(User $actor, string $query, ?string $ip = null): array
    {
        $this->authorizeInvestigator($actor);
        $query = trim($query);
        abort_if(mb_strlen($query) < 2, 422, 'Enter at least two letters of a staff member’s name.');
        $people = User::query()->where('name', 'like', '%'.addcslashes($query, '%_\\').'%')->orderBy('name')->limit(10)->pluck('id');
        $conversations = OpsConversation::query()
            ->where('conversation_type', '!=', 'family')->whereNull('client_id')
            ->whereHas('participants', fn ($members) => $members->whereIn('user_id', $people)->where('role', '!=', OpsConversationParticipant::COVER_READER))
            ->with('participants.user:id,name')->orderByDesc('updated_at')->limit(30)->get();
        $this->log($actor, 'conversation_lookup', null, null, meta: ['matched_people' => $people->count(), 'conversations' => $conversations->pluck('id')->all()], ip: $ip);

        return $conversations->map(fn (OpsConversation $conversation) => $this->describe($conversation))->values()->all();
    }

    /**
     * Grants one or more private staff conversations under one case
     * reference, reason and access period. All rows are created, or none.
     *
     * @return array{grant_ids: list<int>, case_batch: string}
     */
    public function grant(User $actor, array $data, ?string $ip = null): array
    {
        $ids = collect($data['conversation_ids'] ?? [$data['conversation_id'] ?? null])
            ->filter()->map(fn ($id) => (int) $id)->unique()->values();
        abort_if($ids->isEmpty(), 422, 'Choose at least one conversation.');
        abort_if($ids->count() > self::MAX_CONVERSATIONS, 422, 'Choose at most '.self::MAX_CONVERSATIONS.' conversations for one case.');

        return DB::transaction(function () use ($actor, $data, $ip, $ids): array {
            $this->authorizeInvestigator($actor);
            $conversations = OpsConversation::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($conversations->count() === $ids->count(), 404, 'One of the chosen conversations no longer exists.');
            $conversations->each(fn (OpsConversation $conversation) => $this->assertInvestigable($conversation));
            abort_unless(in_array((int) $data['duration_hours'], self::DURATIONS, true), 422, 'Choose an approved access period.');
            $case = trim($data['case_reference']);
            $reason = trim($data['reason']);
            abort_if($case === '' || $reason === '', 422, 'A case reference and a reason are required.');
            $now = now();
            $batch = (string) Str::uuid();
            $encrypted = Crypt::encryptString($reason);
            $grantIds = [];
            // The investigator's chosen order is kept, so the case lists them as picked.
            foreach ($ids as $conversationId) {
                $id = DB::table('chat_investigation_grants')->insertGetId([
                    'investigator_id' => $actor->id, 'conversation_id' => $conversationId,
                    'case_reference' => $case, 'case_batch' => $batch, 'reason' => $encrypted,
                    'expires_at' => $now->copy()->addHours((int) $data['duration_hours']),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->log($actor, 'access_granted', $conversationId, $case, grantId: $id,
                    meta: ['duration_hours' => (int) $data['duration_hours'], 'case_batch' => $batch, 'conversations_in_case' => $ids->count()], ip: $ip);
                $grantIds[] = $id;
            }

            return ['grant_ids' => $grantIds, 'case_batch' => $batch];
        });
    }

    /** The investigator's own case access, newest first. */
    public function grants(User $actor): array
    {
        $this->authorizeInvestigator($actor);
        $rows = DB::table('chat_investigation_grants')->where('investigator_id', $actor->id)->orderByDesc('id')->limit(30)->get();
        $conversations = OpsConversation::whereIn('id', $rows->pluck('conversation_id'))->with('participants.user:id,name')->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'case_reference' => $row->case_reference,
            'case_batch' => $row->case_batch,
            'conversation' => $conversations->has($row->conversation_id) ? $this->describe($conversations[$row->conversation_id]) : null,
            'expires_at' => CarbonImmutable::parse($row->expires_at, 'UTC')->toISOString(),
            'revoked_at' => $row->revoked_at ? CarbonImmutable::parse($row->revoked_at, 'UTC')->toISOString() : null,
            'active' => $this->active($row),
        ])->values()->all();
    }

    /**
     * The actor's own active grant. Someone else's grant is refused; an
     * expired or ended one is refused and the refusal is logged.
     */
    public function activeGrant(User $actor, int $grantId, ?string $ip = null, string $attempt = 'view'): object
    {
        $this->authorizeInvestigator($actor);
        $grant = DB::table('chat_investigation_grants')->where('id', $grantId)->first();
        abort_unless($grant && (int) $grant->investigator_id === (int) $actor->id, 403, 'This case access belongs to another investigator.');
        if (! $this->active($grant)) {
            $this->log($actor, 'access_refused_expired', (int) $grant->conversation_id, $grant->case_reference, grantId: $grantId,
                meta: $attempt === 'view' ? [] : ['attempt' => $attempt], ip: $ip);
            abort(403, 'This case access has expired or was ended. Record a new case reason to continue.');
        }

        return $grant;
    }

    /**
     * The actor's grants that were made together with this one, in the
     * order they were granted. A grant made on its own is its own case.
     */
    public function caseGrants(User $actor, object $grant): Collection
    {
        if ($grant->case_batch === null) {
            return collect([$grant]);
        }

        return DB::table('chat_investigation_grants')->where('case_batch', $grant->case_batch)
            ->where('investigator_id', $actor->id)->orderBy('id')->get();
    }

    /** Opens an active grant. Expired, revoked or someone else's grants are refused and the refusal is logged. */
    public function open(User $actor, int $grantId, ?int $before = null, ?string $ip = null): array
    {
        $grant = $this->activeGrant($actor, $grantId, $ip);
        $conversation = OpsConversation::whereKey($grant->conversation_id)->with('participants.user:id,name')->firstOrFail();
        $this->assertInvestigable($conversation);
        $page = $this->messages($conversation, $before);
        $this->log($actor, $before ? 'messages_viewed' : 'conversation_viewed', $conversation->id, $grant->case_reference, grantId: $grantId,
            meta: ['message_ids' => array_column($page['messages'], 'id')], ip: $ip);
        $siblings = $this->caseGrants($actor, $grant);
        $siblingConversations = OpsConversation::whereIn('id', $siblings->pluck('conversation_id'))->with('participants.user:id,name')->get()->keyBy('id');

        return [
            'grant' => [
                'id' => (int) $grant->id, 'case_reference' => $grant->case_reference,
                'case_batch' => $grant->case_batch,
                'reason' => Crypt::decryptString($grant->reason),
                'expires_at' => CarbonImmutable::parse($grant->expires_at, 'UTC')->toISOString(),
            ],
            'conversation' => $this->describe($conversation),
            // The other conversations granted under this case: metadata only.
            'case_conversations' => $siblings->map(fn ($row) => [
                'grant_id' => (int) $row->id,
                'conversation' => $siblingConversations->has($row->conversation_id) ? $this->describe($siblingConversations[$row->conversation_id]) : null,
                'active' => $this->active($row),
            ])->values()->all(),
            'holds' => DB::table('chat_preservation_holds')->where('conversation_id', $conversation->id)->orderByDesc('id')->get()
                ->map(fn ($hold) => ['id' => (int) $hold->id, 'case_reference' => $hold->case_reference, 'active' => $hold->released_at === null,
                    'placed_at' => CarbonImmutable::parse($hold->placed_at, 'UTC')->toISOString()])->values()->all(),
            ...$page,
        ];
    }

    public function revoke(User $actor, int $grantId, ?string $ip = null): void
    {
        DB::transaction(function () use ($actor, $grantId, $ip): void {
            $this->authorizeInvestigator($actor);
            $grant = DB::table('chat_investigation_grants')->where('id', $grantId)->lockForUpdate()->first();
            abort_unless($grant && (int) $grant->investigator_id === (int) $actor->id, 403);
            if ($grant->revoked_at !== null) {
                return;
            }
            DB::table('chat_investigation_grants')->where('id', $grantId)->update(['revoked_at' => now(), 'revoked_by' => $actor->id, 'updated_at' => now()]);
            $this->log($actor, 'access_ended', (int) $grant->conversation_id, $grant->case_reference, grantId: $grantId, ip: $ip);
        });
    }

    /** The investigation audit: visible to investigators only, never mixed into the settings audit. */
    public function events(User $actor): array
    {
        $this->authorizeInvestigator($actor);
        $rows = DB::table('chat_investigation_events')->orderByDesc('id')->limit(100)->get();
        $names = User::whereIn('id', $rows->pluck('actor_id')->unique())->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id, 'action' => $row->action, 'actor' => $names[$row->actor_id] ?? 'Former staff member',
            'case_reference' => $row->case_reference, 'conversation_id' => $row->conversation_id ? (int) $row->conversation_id : null,
            'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toISOString(),
        ])->values()->all();
    }

    public function log(User $actor, string $action, ?int $conversationId, ?string $case, ?int $grantId = null, ?int $holdId = null, array $meta = [], ?string $ip = null): void
    {
        DB::table('chat_investigation_events')->insert([
            'actor_id' => $actor->id, 'conversation_id' => $conversationId, 'grant_id' => $grantId, 'hold_id' => $holdId,
            'action' => $action, 'case_reference' => $case, 'meta' => $meta ? json_encode($meta, JSON_THROW_ON_ERROR) : null,
            'ip_address' => $ip, 'created_at' => now(),
        ]);
    }

    /**
     * The whole canonical record of one conversation, oldest first, for a
     * controlled export. Null when it is longer than $limit messages.
     */
    public function fullRecord(OpsConversation $conversation, int $limit): ?array
    {
        $query = OpsMessage::withTrashed()->where('conversation_id', $conversation->id)->whereNull('client_id');
        if ((clone $query)->count() > $limit) {
            return null;
        }

        return $this->present($query->orderBy('id')->get());
    }

    private function active(object $grant): bool
    {
        return $grant->revoked_at === null && CarbonImmutable::parse($grant->expires_at, 'UTC')->isFuture();
    }

    private function held(int $conversationId): bool
    {
        return DB::table('chat_preservation_holds')->where('conversation_id', $conversationId)->whereNull('released_at')->exists();
    }

    /** The complete canonical record, including removed messages and earlier versions. */
    private function messages(OpsConversation $conversation, ?int $before): array
    {
        $rows = OpsMessage::withTrashed()->where('conversation_id', $conversation->id)->whereNull('client_id')
            ->when($before, fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')->limit(self::PAGE + 1)->get();
        $page = $rows->take(self::PAGE);

        return [
            'messages' => $this->present($page->reverse()->values()),
            'next_before' => $rows->count() > self::PAGE ? (int) $page->last()->id : null,
        ];
    }

    /** Messages with sender, removal, attachment names and earlier versions, in the given order. */
    private function present(Collection $messages): array
    {
        $ids = $messages->pluck('id');
        $names = User::whereIn('id', $messages->pluck('sender_id')->filter()->unique())->pluck('name', 'id');
        $attachments = DB::table('chat_attachments')->whereIn('message_id', $ids)->orderBy('id')->get(['message_id', 'name', 'mime_type', 'size_bytes'])->groupBy('message_id');
        $revisions = DB::table('chat_message_revisions')->whereIn('message_id', $ids)->orderBy('version')->get(['message_id', 'version', 'previous_state', 'created_at'])->groupBy('message_id');

        return $messages->map(fn (OpsMessage $message) => [
            'id' => (int) $message->id,
            'sender' => $names[$message->sender_id] ?? 'Former staff member',
            'content' => (string) $message->content,
            'created_at' => $message->created_at?->toISOString(),
            'edited_at' => $message->edited_at ? CarbonImmutable::parse($message->edited_at)->toISOString() : null,
            'removed_at' => $message->deleted_at?->toISOString(),
            'attachments' => ($attachments[$message->id] ?? collect())->map(fn ($file) => ['name' => $file->name, 'mime_type' => $file->mime_type, 'size_bytes' => (int) $file->size_bytes])->values()->all(),
            'previous_versions' => $this->previousVersions($revisions[$message->id] ?? collect()),
        ])->values()->all();
    }

    private function previousVersions(Collection $revisions): array
    {
        return $revisions->map(function ($revision) {
            try {
                $state = json_decode(Crypt::decryptString($revision->previous_state), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $state = ['content' => null];
            }

            return ['version' => (int) $revision->version, 'content' => $state['content'] ?? null,
                'replaced_at' => CarbonImmutable::parse($revision->created_at, 'UTC')->toISOString()];
        })->values()->all();
    }
}
