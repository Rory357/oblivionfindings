<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatMessageEditing;
use App\Services\Chat\ChatPreservation;
use App\Services\Chat\ChatSettings;
use App\Support\SecurityPolicy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    // SecurityPolicy keeps a static copy; a timeout set by an earlier test must not sign this one out.
    SecurityPolicy::flushCache();
    Bus::fake();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatGovStaff(Site $site, array $permissions = []): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);
    if ($permissions) {
        $role = Role::create(['name' => 'chat_gov_'.Str::random(8), 'label' => 'Chat governance', 'level' => 50, 'type' => 'custom']);
        foreach ($permissions as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'chat', 'module' => 'System'])->id);
        }
        $user->roles()->attach($role->id);
    }

    return $user->fresh();
}

function chatGovConversation(string $type, User ...$people): OpsConversation
{
    $conversation = OpsConversation::create(['conversation_type' => $type, 'title' => $type === 'group' ? 'Night team' : null]);
    foreach ($people as $index => $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => $type === 'group' && $index === 0 ? 'admin' : 'member']);
    }

    return $conversation;
}

function chatGovAs($test, User $user)
{
    return $test->actingAs($user)->withHeader('X-Chat-Actor', (string) $user->id);
}

function chatGovGrant($test, User $investigator, OpsConversation $conversation, int $hours = 24): int
{
    return chatGovAs($test, $investigator)->postJson(route('settings.chat.investigations.store'), [
        'conversation_id' => $conversation->id, 'case_reference' => 'HR-2026-014',
        'reason' => 'Formal complaint about messages sent on the night shift.', 'duration_hours' => $hours,
    ])->assertCreated()->json('grant_id');
}

it('keeps private chat inspection away from settings administrators and managers without the investigator permission', function () {
    $site = Site::factory()->create();
    $admin = chatGovStaff($site, ['settings.access.manage', 'sites.viewAll', 'family_portal.manage']);
    $conversation = chatGovConversation('direct', chatGovStaff($site), chatGovStaff($site));
    $investigator = chatGovStaff($site, ['chat.investigate']);
    $grant = chatGovGrant($this, $investigator, $conversation);

    chatGovAs($this, $admin)->get(route('settings.chat.investigations'))->assertForbidden();
    chatGovAs($this, $admin)->get(route('settings.chat.investigations.show', $grant))->assertForbidden();
    chatGovAs($this, $admin)->getJson(route('settings.chat.investigations.lookup', ['q' => 'an']))->assertForbidden();
    chatGovAs($this, $admin)->postJson(route('settings.chat.investigations.store'), [
        'conversation_id' => $conversation->id, 'case_reference' => 'X', 'reason' => 'A reason that is long enough', 'duration_hours' => 24,
    ])->assertForbidden();
    chatGovAs($this, $admin)->postJson(route('settings.chat.investigations.holds.store'), [
        'conversation_id' => $conversation->id, 'case_reference' => 'X', 'reason' => 'A reason that is long enough', 'authority_reviewed' => true,
    ])->assertForbidden();
    chatGovAs($this, $admin)->get(route('settings.chat'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/chat')->where('chatInvestigator', false));

    // Admin role never holds the key, and the grant migration gave it to no role.
    expect(Permission::where('key', 'chat.investigate')->first()->roles()->where('name', 'admin')->exists())->toBeFalse();
    // A former investigator loses access with their employment.
    $investigator->hrEmployeeProfile()->update(['end_date' => now()->subDays(2)->toDateString()]);
    chatGovAs($this, $investigator->fresh())->get(route('settings.chat.investigations.show', $grant))->assertForbidden();
});

it('requires a case reference, a reason and an approved access period, and only opens private staff conversations', function () {
    $site = Site::factory()->create();
    $investigator = chatGovStaff($site, ['chat.investigate']);
    $conversation = chatGovConversation('direct', chatGovStaff($site), chatGovStaff($site));
    $base = ['conversation_id' => $conversation->id, 'case_reference' => 'HR-1', 'reason' => 'Formal complaint received today.', 'duration_hours' => 24];

    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'reason' => ''])->assertJsonValidationErrors('reason');
    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'reason' => 'short'])->assertJsonValidationErrors('reason');
    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'case_reference' => ''])->assertJsonValidationErrors('case_reference');
    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'duration_hours' => 9999])->assertJsonValidationErrors('duration_hours');
    $client = Client::factory()->create(['site_id' => $site->id]);
    $family = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'conversation_id' => $family->id])->assertUnprocessable();
    expect(DB::table('chat_investigation_grants')->count())->toBe(0);
});

it('opens a logged read-only investigation view without joining, reading or notifying', function () {
    $site = Site::factory()->create();
    [$alex, $sam] = [chatGovStaff($site), chatGovStaff($site)];
    $investigator = chatGovStaff($site, ['chat.investigate']);
    $conversation = chatGovConversation('direct', $alex, $sam);
    $first = $conversation->messages()->create(['sender_id' => $alex->id, 'content' => 'Original wording']);
    $removed = $conversation->messages()->create(['sender_id' => $sam->id, 'content' => 'Removed later']);
    $editing = app(ChatMessageEditing::class);
    $editing->edit($alex, $first->fresh(), ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Edited wording']);
    $editing->remove($sam, $removed->fresh(), ['mutation_id' => (string) Str::uuid(), 'version' => 0]);
    $conversation->participants()->where('user_id', $alex->id)->update(['last_read_message_id' => $first->id, 'last_read_at' => now()->subHour()]);
    $before = DB::table('ops_conversation_participants')->where('conversation_id', $conversation->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $settingsAudits = AuditLog::count();
    Notification::fake();
    Bus::fake();

    $lookup = chatGovAs($this, $investigator)->getJson(route('settings.chat.investigations.lookup', ['q' => mb_substr($alex->name, 0, 4)]))->assertOk();
    expect(collect($lookup->json('conversations'))->pluck('id'))->toContain($conversation->id);
    expect(json_encode($lookup->json()))->not->toContain('Edited wording');
    $grant = chatGovGrant($this, $investigator, $conversation);

    chatGovAs($this, $investigator)->get(route('settings.chat.investigations.show', $grant))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page->component('settings/chat-investigation')
            ->where('investigation.grant.case_reference', 'HR-2026-014')
            ->where('investigation.messages.0.content', 'Edited wording')
            ->where('investigation.messages.0.previous_versions.0.content', 'Original wording')
            ->where('investigation.messages.1.content', 'Removed later')
            ->whereNot('investigation.messages.1.removed_at', null));

    // Not a participant, no read cursor or archive change, nothing sent, no write access.
    $after = DB::table('ops_conversation_participants')->where('conversation_id', $conversation->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    expect($after)->toBe($before);
    expect($conversation->participants()->where('user_id', $investigator->id)->exists())->toBeFalse();
    Notification::assertNothingSent();
    Bus::assertNothingDispatched();
    chatGovAs($this, $investigator)->getJson(route('chat.messages', $conversation))->assertForbidden();
    chatGovAs($this, $investigator)->postJson(route('chat.send', $conversation), ['mutation_id' => (string) Str::uuid(), 'content' => 'Hello'])->assertForbidden();
    chatGovAs($this, $investigator)->postJson(route('chat.read', $conversation), ['message_id' => $first->id])->assertForbidden();
    expect(OpsMessage::withTrashed()->where('conversation_id', $conversation->id)->count())->toBe(2);

    // The access log is its own domain: nothing reaches the settings audit.
    expect(DB::table('chat_investigation_events')->where('grant_id', $grant)->pluck('action')->all())->toBe(['access_granted', 'conversation_viewed']);
    expect(DB::table('chat_investigation_events')->where('action', 'conversation_lookup')->where('actor_id', $investigator->id)->exists())->toBeTrue();
    expect(AuditLog::count())->toBe($settingsAudits);
    expect(DB::table('chat_investigation_grants')->value('reason'))->not->toContain('night shift');
});

it('refuses expired, ended and other investigators’ case access and logs the refusal', function () {
    $site = Site::factory()->create();
    [$investigator, $colleague] = [chatGovStaff($site, ['chat.investigate']), chatGovStaff($site, ['chat.investigate'])];
    $conversation = chatGovConversation('direct', chatGovStaff($site), chatGovStaff($site));
    $grant = chatGovGrant($this, $investigator, $conversation, 1);

    chatGovAs($this, $colleague)->get(route('settings.chat.investigations.show', $grant))->assertForbidden();
    $this->travel(61)->minutes();
    chatGovAs($this, $investigator)->get(route('settings.chat.investigations.show', $grant))->assertForbidden();
    chatGovAs($this, $investigator)->getJson(route('settings.chat.investigations.messages', [$grant, 'before' => 99]))->assertForbidden();
    expect(DB::table('chat_investigation_events')->where('grant_id', $grant)->where('action', 'access_refused_expired')->count())->toBe(2);

    $second = chatGovGrant($this, $investigator, $conversation, 8);
    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.end', $second))->assertOk()
        ->assertJsonPath('grants.0.active', false);
    chatGovAs($this, $investigator)->get(route('settings.chat.investigations.show', $second))->assertForbidden();
    expect(DB::table('chat_investigation_events')->where('grant_id', $second)->pluck('action')->all())->toBe(['access_granted', 'access_ended', 'access_refused_expired']);
});

it('preserves a held conversation from edits, removals and disposal until an investigator releases the hold', function () {
    $site = Site::factory()->create();
    [$alex, $sam] = [chatGovStaff($site), chatGovStaff($site)];
    $investigator = chatGovStaff($site, ['chat.investigate']);
    $conversation = chatGovConversation('direct', $alex, $sam);
    $message = $conversation->messages()->create(['sender_id' => $alex->id, 'content' => 'Kept for the case']);
    $other = chatGovConversation('direct', $alex, chatGovStaff($site));
    $payload = ['conversation_id' => $conversation->id, 'case_reference' => 'HS-2026-3', 'reason' => 'Preserve while the complaint is reviewed.'];

    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.holds.store'), $payload)->assertJsonValidationErrors('authority_reviewed');
    $holds = chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.holds.store'), [...$payload, 'authority_reviewed' => true])->assertCreated();
    $hold = $holds->json('holds.0.id');
    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.holds.store'), [...$payload, 'authority_reviewed' => true])->assertConflict();

    $capabilities = app(ChatMessageEditing::class)->capabilities($message->fresh(), $alex);
    expect($capabilities['can_edit'])->toBeFalse()->and($capabilities['can_remove'])->toBeFalse()
        ->and($capabilities['change_unavailable_reason'])->toContain('preserved');
    chatGovAs($this, $alex)->patchJson(route('chat.messages.edit', $message), ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Changed', 'mentions' => []])->assertConflict();
    chatGovAs($this, $alex)->postJson(route('chat.action', $message), ['action' => 'delete', 'mutation_id' => (string) Str::uuid(), 'version' => 0])->assertConflict();
    expect($message->fresh()->content)->toBe('Kept for the case')->and($message->fresh()->trashed())->toBeFalse();
    expect(app(ChatPreservation::class)->disposableConversations()->pluck('id')->all())->not->toContain($conversation->id)->toContain($other->id);

    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.holds.release', $hold), ['reason' => 'short'])->assertJsonValidationErrors('reason');
    chatGovAs($this, $investigator)->postJson(route('settings.chat.investigations.holds.release', $hold), ['reason' => 'Case closed by the HR panel.'])->assertOk()
        ->assertJsonPath('holds.0.active', false);
    expect(app(ChatMessageEditing::class)->capabilities($message->fresh(), $alex)['can_edit'])->toBeTrue();
    expect(DB::table('chat_investigation_events')->where('hold_id', $hold)->pluck('action')->all())->toBe(['hold_placed', 'hold_released']);
});

it('offboards a former staff member without deleting records and leaves current staff untouched', function () {
    $site = Site::factory()->create();
    $admin = chatGovStaff($site, ['settings.access.manage']);
    [$leaver, $stays, $third] = [chatGovStaff($site), chatGovStaff($site), chatGovStaff($site)];
    $group = chatGovConversation('group', $leaver, $stays, $third);
    $direct = chatGovConversation('direct', $leaver, $stays);
    $message = $direct->messages()->create(['sender_id' => $leaver->id, 'content' => 'Handover notes']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $family = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true, 'assigned_to' => $leaver->id, 'queue_status' => 'open']);
    $callback = DB::table('chat_callbacks')->insertGetId(['conversation_id' => $family->id, 'requested_by' => $stays->id, 'assigned_to' => $leaver->id,
        'status' => 'assigned', 'created_at' => now(), 'updated_at' => now()]);

    chatGovAs($this, $leaver)->getJson(route('settings.chat.administration.offboarding.preview', $leaver))->assertForbidden();
    chatGovAs($this, $admin)->getJson(route('settings.chat.administration.offboarding.preview', $leaver))->assertOk()
        ->assertJsonPath('current_staff', true)->assertJsonPath('actionable', false)->assertJsonPath('kept', 2);
    chatGovAs($this, $admin)->postJson(route('settings.chat.administration.offboarding.complete', $leaver), ['owner' => 'care_team'])->assertUnprocessable();
    expect($group->participants()->count())->toBe(3);

    $leaver->hrEmployeeProfile()->update(['end_date' => now()->subDays(2)->toDateString()]);
    chatGovAs($this, $admin)->getJson(route('settings.chat.administration.offboarding.preview', $leaver))->assertOk()
        ->assertJsonPath('current_staff', false)->assertJsonPath('ending.staff', 2)
        ->assertJsonPath('owned_groups.0.next_owner', $stays->name)->assertJsonPath('callbacks', 1)->assertJsonPath('family_work', 1);
    chatGovAs($this, $admin)->postJson(route('settings.chat.administration.offboarding.complete', $leaver), ['owner' => 'person', 'owner_id' => $third->id])->assertUnprocessable();
    chatGovAs($this, $admin)->postJson(route('settings.chat.administration.offboarding.complete', $leaver), ['owner' => 'care_team'])->assertOk()
        ->assertJsonPath('summary.memberships_ended', 2)->assertJsonPath('summary.ownership_transferred', 1)
        ->assertJsonPath('summary.callbacks_returned', 1)->assertJsonPath('summary.family_work_returned', 1)
        ->assertJsonPath('preview.actionable', false);

    expect($group->participants()->where('user_id', $leaver->id)->exists())->toBeFalse()
        ->and($group->participants()->where('user_id', $stays->id)->value('role'))->toBe('admin')
        ->and($message->fresh())->not->toBeNull()
        ->and(DB::table('chat_callbacks')->where('id', $callback)->first())->assigned_to->toBeNull()->status->toBe('requested')
        ->and($family->fresh()->assigned_to)->toBeNull()
        ->and(AuditLog::where('action', 'chat.offboarding.completed')->where('user_id', $admin->id)->exists())->toBeTrue();
    // The settings audit shows the offboarding but never investigation records.
    chatGovAs($this, $admin)->getJson(route('settings.chat.administration.audit'))->assertOk()->assertJsonPath('entries.0.kind', 'offboarding');
    chatGovAs($this, $stays)->getJson(route('settings.chat.administration.audit'))->assertForbidden();
});

it('shows approved relatives, named workers, cover and effective policy for one authorised client only', function () {
    $site = Site::factory()->create();
    $elsewhere = Site::factory()->create();
    $admin = chatGovStaff($site, ['settings.access.manage', 'clients.viewAny', 'clients.update']);
    $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Sam', 'last_name' => 'Taylor']);
    $otherClient = Client::factory()->create(['site_id' => $site->id]);
    $hidden = Client::factory()->create(['site_id' => $elsewhere->id]);
    $role = Role::firstOrCreate(['name' => 'next_of_kin'], ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system']);
    $relative = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now(), 'name' => 'Jo Taylor']);
    $relative->roles()->attach($role->id);
    $client->portalUsers()->attach($relative->id, ['relation' => 'next_of_kin']);
    $stranger = User::factory()->create(['role' => 'next_of_kin', 'approved_at' => now(), 'name' => 'Other Family']);
    $stranger->roles()->attach($role->id);
    $otherClient->portalUsers()->attach($stranger->id, ['relation' => 'next_of_kin']);
    $cover = chatGovStaff($site, ['family_portal.manage']);
    $settings = AppSetting::where('key', ChatSettings::KEY)->first();
    $settings->update(['value' => [...$settings->value, 'site_overrides' => [(string) $site->id => ['family_calls_enabled' => false, 'response_expectation' => 'Within a working day']]]]);

    chatGovAs($this, chatGovStaff($site, ['clients.viewAny']))->getJson(route('settings.chat.administration.relationships.show', $client))->assertForbidden();
    chatGovAs($this, $admin)->getJson(route('settings.chat.administration.relationships.show', $hidden))->assertForbidden();
    $search = chatGovAs($this, $admin)->getJson(route('settings.chat.administration.relationships.search', ['q' => 'Sam']))->assertOk();
    expect(collect($search->json('clients'))->pluck('id')->all())->toContain($client->id)->not->toContain($hidden->id);

    $view = chatGovAs($this, $admin)->getJson(route('settings.chat.administration.relationships.show', $client))->assertOk()
        ->assertJsonPath('client.name', 'Sam Taylor')
        ->assertJsonPath('relatives.0.name', 'Jo Taylor')->assertJsonPath('relatives.0.status', 'approved')
        ->assertJsonPath('policy.source', 'site_override')->assertJsonPath('policy.scope', (string) $site->id)
        ->assertJsonPath('policy.response_expectation', 'Within a working day')
        ->assertJsonPath('links.portal_users', route('clients.portal_users.edit', $client));
    expect(collect($view->json('cover'))->pluck('id')->all())->toContain($cover->id);
    expect(json_encode($view->json()))->not->toContain('Other Family');
    expect(DB::table('client_portal_users')->count())->toBe(2);
});
