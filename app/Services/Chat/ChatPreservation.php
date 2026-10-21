<?php

namespace App\Services\Chat;

use App\Models\OpsConversation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Preservation holds keep a conversation's record intact for a case: while a
 * hold is active, senders cannot edit or remove messages and any disposal or
 * retention process must skip the conversation. Membership can still change;
 * that never removes messages.
 */
final class ChatPreservation
{
    public function __construct(private readonly ChatInvestigations $investigations) {}

    public function isHeld(int $conversationId): bool
    {
        return DB::table('chat_preservation_holds')->where('conversation_id', $conversationId)->whereNull('released_at')->exists();
    }

    /**
     * Conversations a future retention or disposal job may consider. Any
     * such job must start from this query so held records are never touched.
     *
     * @return Builder<OpsConversation>
     */
    public function disposableConversations(): Builder
    {
        return OpsConversation::query()->whereNotExists(fn ($held) => $held->selectRaw('1')->from('chat_preservation_holds')
            ->whereColumn('chat_preservation_holds.conversation_id', 'ops_conversations.id')->whereNull('released_at'));
    }

    public function holds(User $actor): array
    {
        $this->investigations->authorizeInvestigator($actor);
        $rows = DB::table('chat_preservation_holds')->orderByRaw('released_at is null desc')->orderByDesc('id')->limit(50)->get();
        $names = User::whereIn('id', $rows->pluck('placed_by')->merge($rows->pluck('released_by'))->filter()->unique())->pluck('name', 'id');
        $conversations = OpsConversation::whereIn('id', $rows->pluck('conversation_id'))->with('participants.user:id,name')->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id,
            'conversation' => $conversations->has($row->conversation_id) ? $this->investigations->describe($conversations[$row->conversation_id]) : null,
            'case_reference' => $row->case_reference,
            'reason' => Crypt::decryptString($row->reason),
            'placed_by' => $names[$row->placed_by] ?? 'Former staff member',
            'placed_at' => CarbonImmutable::parse($row->placed_at, 'UTC')->toISOString(),
            'released_by' => $row->released_by ? ($names[$row->released_by] ?? 'Former staff member') : null,
            'released_at' => $row->released_at ? CarbonImmutable::parse($row->released_at, 'UTC')->toISOString() : null,
            'active' => $row->released_at === null,
        ])->values()->all();
    }

    public function place(User $actor, array $data, ?string $ip = null): int
    {
        return DB::transaction(function () use ($actor, $data, $ip): int {
            $this->investigations->authorizeInvestigator($actor);
            $conversation = OpsConversation::whereKey($data['conversation_id'])->lockForUpdate()->firstOrFail();
            $this->investigations->assertInvestigable($conversation);
            abort_if($this->isHeld($conversation->id), 409, 'This conversation already has an active preservation hold.');
            $now = now();
            $id = DB::table('chat_preservation_holds')->insertGetId([
                'conversation_id' => $conversation->id, 'case_reference' => trim($data['case_reference']),
                'reason' => Crypt::encryptString(trim($data['reason'])), 'placed_by' => $actor->id, 'placed_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->investigations->log($actor, 'hold_placed', $conversation->id, trim($data['case_reference']), holdId: $id, ip: $ip);

            return $id;
        });
    }

    public function release(User $actor, int $holdId, string $reason, ?string $ip = null): void
    {
        DB::transaction(function () use ($actor, $holdId, $reason, $ip): void {
            $this->investigations->authorizeInvestigator($actor);
            $hold = DB::table('chat_preservation_holds')->where('id', $holdId)->lockForUpdate()->first();
            abort_unless($hold, 404);
            abort_if($hold->released_at !== null, 409, 'This hold has already been released.');
            DB::table('chat_preservation_holds')->where('id', $holdId)->update([
                'released_by' => $actor->id, 'released_at' => now(),
                'release_reason' => Crypt::encryptString(trim($reason)), 'updated_at' => now(),
            ]);
            $this->investigations->log($actor, 'hold_released', (int) $hold->conversation_id, $hold->case_reference, holdId: $holdId, ip: $ip);
        });
    }
}
