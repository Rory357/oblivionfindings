<?php

namespace App\Services\Chat;

use App\Models\AuditLog;
use App\Models\ChatCall;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ChatConversationManagement
{
    public function __construct(private readonly ChatAccess $access) {}

    public function capabilities(User $actor, OpsConversation $conversation): array
    {
        $members = $conversation->participants->where('role', '!=', OpsConversationParticipant::COVER_READER);
        $member = $members->firstWhere('user_id', $actor->id);
        $staffGroup = $conversation->conversation_type === 'group' && ! $conversation->client_id;
        $allowed = $staffGroup && ! $conversation->is_archived && $this->access->allows($actor, $conversation, true);
        $owner = $member?->role === 'admin';
        $manager = $allowed && ($conversation->is_managed_channel ? $actor->canDo('settings.access.manage') : $owner);
        $canTransfer = $allowed && $owner && (! $conversation->is_managed_channel || $manager);
        $mustTransfer = $allowed && $owner && $members->where('role', 'admin')->count() === 1;
        $familyAllowed = $conversation->conversation_type === 'family' && $this->access->canManageParticipants($actor, $conversation);
        $familyRemovable = $familyAllowed ? DB::table('chat_invitations')->where('conversation_id', $conversation->id)->where('status', 'accepted')
            ->when($actor->canAccessClientPortal($conversation->client) && (int) $conversation->chat_created_by !== $actor->id,
                fn ($query) => $query->where('invited_by', $actor->id))
            ->whereIn('user_id', $members->where('role', 'family')->pluck('user_id'))->where('user_id', '!=', $actor->id)
            ->when($conversation->chat_created_by, fn ($query) => $query->where('user_id', '!=', $conversation->chat_created_by))
            ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];

        return [
            'owner_ids' => $staffGroup ? $members->where('role', 'admin')->pluck('user_id')->values()->all() : [],
            'can_rename' => $manager,
            'can_remove' => $manager || count($familyRemovable) > 0,
            'removable_ids' => $familyAllowed ? $familyRemovable : ($manager ? $members->where('role', '!=', 'admin')->where('user_id', '!=', $actor->id)->pluck('user_id')->values()->all() : []),
            'can_transfer' => $canTransfer,
            'can_leave' => ($allowed && (bool) $member && (! $mustTransfer || $canTransfer))
                || ($familyAllowed && (bool) $member && $actor->canAccessClientPortal($conversation->client)),
            'must_transfer_to_leave' => $mustTransfer,
            'eligible_owner_ids' => $canTransfer ? $members->pluck('user')->filter(fn ($person) => $person && $person->id !== $actor->id && $this->access->allows($person, $conversation, true) && (! $conversation->is_managed_channel || $person->canDo('settings.access.manage')))->pluck('id')->values()->all() : [],
        ];
    }

    public function change(User $actor, OpsConversation $conversation, array $data): void
    {
        DB::transaction(function () use ($actor, $conversation, $data) {
            $conversation = OpsConversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->access->authorize($actor, $conversation, true);
            abort_unless($conversation->chat_version === $data['version'], 409, 'The conversation changed. Reload its members and review your action again.');
            $conversation->load('participants.user');
            $capabilities = $this->capabilities($actor, $conversation);
            $action = $data['action'];
            abort_unless($capabilities['can_'.$action] ?? false, 403);
            $affected = null;
            $oldTitle = $conversation->title;
            if ($action === 'rename') {
                $title = trim($data['title']);
                abort_if($title === '', 422, 'Give the conversation a name.');
                $conversation->title = $title;
            } elseif ($action === 'transfer' || ($action === 'leave' && ($data['user_id'] ?? null))) {
                abort_unless($capabilities['can_transfer'] && in_array($data['user_id'], $capabilities['eligible_owner_ids'], true), 403);
                $affected = $data['user_id'];
                $conversation->participants()->where('role', 'admin')->update(['role' => 'member']);
                $conversation->participants()->where('user_id', $affected)->update(['role' => 'admin']);
            }
            if ($action === 'remove') {
                $target = $conversation->participants->firstWhere('user_id', $data['user_id']);
                abort_unless(in_array($data['user_id'], $capabilities['removable_ids'], true), 403);
                abort_unless($target && $target->user_id !== $actor->id && $target->role !== 'admin', 422, 'Choose another member. Transfer ownership before removing an owner.');
                $affected = $target->user_id;
                $target->delete();
            } elseif ($action === 'leave') {
                abort_if($capabilities['must_transfer_to_leave'] && ! $affected, 422, 'Choose an eligible new owner before leaving.');
                $conversation->participants()->where('user_id', $actor->id)->delete();
            }
            $conversation->chat_version++;
            $conversation->save();
            AuditLog::create(['user_id' => $actor->id, 'action' => 'chat.conversation.'.$action, 'meta' => [
                'conversation_id' => $conversation->id, 'participant_id' => $affected,
                'previous_title' => $action === 'rename' ? $oldTitle : null,
                'title' => $action === 'rename' ? $conversation->title : null,
            ]]);
            if (in_array($action, ['remove', 'leave'], true)) {
                $removed = $action === 'leave' ? $actor->id : $affected;
                DB::table('chat_invitations')->where('conversation_id', $conversation->id)->where('user_id', $removed)->where('status', 'accepted')
                    ->update(['status' => 'removed', 'updated_at' => now()]);
                app(ChatInvitations::class)->pending($conversation->id)->where('invited_by', $removed)
                    ->update(['status' => 'cancelled', 'updated_at' => now()]);
                // Revoke ongoing audio before committing membership loss. A media failure
                // rolls back this command so the UI cannot falsely report removal.
                foreach (ChatCall::where('conversation_id', $conversation->id)->whereNull('ended_at')->get() as $call) {
                    app(ChatCallReconciler::class)->reconcile($call);
                }
            }
            foreach ($conversation->participants->pluck('user_id')->push($actor->id)->unique() as $id) {
                Cache::forget("user:{$id}:ops-unread:v1");
            }
        });
    }
}
