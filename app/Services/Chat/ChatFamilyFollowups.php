<?php

namespace App\Services\Chat;

use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\FamilyNote;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class ChatFamilyFollowups
{
    public function __construct(private readonly ChatAccess $access, private readonly ClientFamilyCommunicationAccess $family) {}

    public function canCreate(User $actor, OpsConversation $conversation): bool
    {
        return $conversation->conversation_type === 'family' && $conversation->client
            && ! $actor->canAccessClientPortal($conversation->client)
            && $this->family->canManage($actor, $conversation->client)
            && Gate::forUser($actor)->allows('view', $conversation->client);
    }

    private function authorize(User $actor, OpsConversation $conversation): void
    {
        $this->access->authorize($actor, $conversation, true);
        abort_unless($this->canCreate($actor, $conversation), 403);
    }

    private function audience(OpsConversation $conversation): array
    {
        $client = $conversation->client;
        $people = $client->portalUsers()->orderBy('users.id')->get()->unique('id')
            ->filter(fn ($person) => app(PortalClientSectionAccess::class)->for($person, $client)['show_care_notes'])
            ->map(fn ($person) => ['id' => $person->id, 'name' => $person->name])->values()->all();
        // Family Notes have a shared record audience, distinct from private conversation membership.
        $token = hash('sha256', json_encode([1, $conversation->id, $client->id, $client->site_id, $client->first_name, $client->last_name,
            $people, 'current-and-future-approved-family-notes-readers'], JSON_THROW_ON_ERROR));

        return ['people' => $people, 'token' => $token];
    }

    public function review(User $actor, OpsConversation $conversation): array
    {
        $this->authorize($actor, $conversation);
        $settings = app(ChatSettings::class)->read($conversation->client->site_id);
        $writable = $settings['enabled'] && $settings['family_enabled'] && ! $conversation->is_archived;
        $audience = $this->audience($conversation);
        $owners = $writable ? app(HrCurrentStaffService::class)->currentUsersQuery()->get()
            ->filter(fn ($person) => $this->canCreate($person, $conversation) && $this->access->allows($person, $conversation, true))
            ->map(fn ($person) => ['id' => $person->id, 'name' => $person->name])->values()->all() : [];

        return ['client' => ['id' => $conversation->client_id, 'name' => trim($conversation->client->first_name.' '.$conversation->client->last_name)],
            'audience' => $audience['people'], 'audience_token' => $audience['token'], 'owners' => $owners, 'can_create' => $writable];
    }

    public function create(User $inputActor, OpsConversation $input, array $data): array
    {
        return DB::transaction(function () use ($inputActor, $input, $data) {
            // Serialize this actor's mutation references even across different conversations.
            $actor = User::whereKey($inputActor->id)->lockForUpdate()->firstOrFail();
            $conversation = OpsConversation::whereKey($input->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $conversation);
            $hash = hash('sha256', json_encode([$conversation->id, $data['action'], (int) $data['assigned_to'], $data['due_date'], $data['audience_token']], JSON_THROW_ON_ERROR));
            $receipt = DB::table('chat_family_followups')->where('actor_id', $actor->id)->where('mutation_id', $data['mutation_id'])->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This follow-up reference was already used for different details.');

                return $this->result($conversation, $receipt->family_note_id, true);
            }
            $policy = app(ChatSettings::class)->read($conversation->client->site_id);
            abort_unless($policy['enabled'] && $policy['family_enabled'] && ! $conversation->is_archived, 409, 'This conversation is currently read-only.');
            DB::table('client_portal_users')->where('client_id', $conversation->client_id)->orderBy('id')->lockForUpdate()->get(['id']);
            abort_unless(hash_equals($this->audience($conversation)['token'], $data['audience_token']), 409, 'The Family Notes audience changed. Review the current audience before creating this follow-up.');
            $owner = User::find($data['assigned_to']);
            abort_unless($owner && $this->canCreate($owner, $conversation) && $this->access->allows($owner, $conversation, true), 422, 'Choose a currently authorised care-team member who can manage this client’s Family Notes.');
            $note = FamilyNote::create([
                'client_id' => $conversation->client_id, 'created_by' => $actor->id, 'title' => 'Family follow-up',
                'description' => $data['action'], 'note_type' => 'todo', 'priority' => 'normal', 'status' => 'open',
                'visibility' => 'portal', 'due_date' => $data['due_date'], 'assigned_to_user_id' => $owner->id,
                'meta' => ['chat_conversation_id' => $conversation->id, 'audience_reviewed_by' => $actor->id, 'audience_reviewed_at' => now()->toISOString()],
            ]);
            DB::table('chat_family_followups')->insert(['conversation_id' => $conversation->id, 'actor_id' => $actor->id,
                'mutation_id' => $data['mutation_id'], 'request_hash' => $hash, 'family_note_id' => $note->id, 'created_at' => now()]);
            AuditLog::create(['user_id' => $actor->id, 'client_id' => $conversation->client_id, 'action' => 'chat.family_followup.created',
                'meta' => ['conversation_id' => $conversation->id, 'family_note_id' => $note->id, 'assigned_to_user_id' => $owner->id]]);

            return $this->result($conversation, $note->id, false);
        }, 3);
    }

    private function result(OpsConversation $conversation, int $id, bool $existing): array
    {
        return ['family_note_id' => $id, 'already_created' => $existing,
            'href' => FamilyNote::whereKey($id)->exists() ? route('clients.show', ['client' => $conversation->client_id, 'tab' => 'family_notes'], false) : null];
    }
}
