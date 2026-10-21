<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Chat\ChatMessageEditing;
use App\Services\Chat\ChatSettings;
use App\Support\SecurityPolicy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Smalot\PdfParser\Parser;

/*
 * Approved 22 October 2026: several conversations granted under one case,
 * and a watermarked, logged PDF export of an active case.
 */

beforeEach(function () {
    config(['chat.shared_experience' => true]);
    SecurityPolicy::flushCache();
    Bus::fake();
    AppSetting::updateOrCreate(['key' => ChatSettings::KEY], ['value' => ['message_change_window_minutes' => 15]]);
});

function chatCaseStaff(Site $site, array $permissions = [], ?string $name = null): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => 'staff', ...($name ? ['name' => $name] : [])]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
    ]);
    if ($permissions) {
        $role = Role::create(['name' => 'chat_case_'.Str::random(8), 'label' => 'Chat case', 'level' => 50, 'type' => 'custom']);
        foreach ($permissions as $key) {
            $role->permissions()->attach(Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'chat', 'module' => 'System'])->id);
        }
        $user->roles()->attach($role->id);
    }

    return $user->fresh();
}

function chatCaseConversation(string $type, User ...$people): OpsConversation
{
    $conversation = OpsConversation::create(['conversation_type' => $type, 'title' => $type === 'group' ? 'Night team' : null]);
    foreach ($people as $index => $person) {
        $conversation->participants()->create(['user_id' => $person->id, 'role' => $type === 'group' && $index === 0 ? 'admin' : 'member']);
    }

    return $conversation;
}

function chatCaseAs($test, User $user)
{
    return $test->actingAs($user)->withHeader('X-Chat-Actor', (string) $user->id);
}

/** @return list<int> grant ids, in the order the conversations were chosen */
function chatCaseGrant($test, User $investigator, array $conversations, int $hours = 24): array
{
    return chatCaseAs($test, $investigator)->postJson(route('settings.chat.investigations.store'), [
        'conversation_ids' => array_map(fn (OpsConversation $conversation) => $conversation->id, $conversations),
        'case_reference' => 'HR-2026-021',
        'reason' => 'Formal complaint covering several night-shift chats.', 'duration_hours' => $hours,
    ])->assertCreated()->json('grant_ids');
}

it('grants several private staff conversations under one case, atomically, with one logged grant per conversation', function () {
    $site = Site::factory()->create();
    $alex = chatCaseStaff($site, name: 'Alex Rangi');
    [$sam, $jo, $kim] = [chatCaseStaff($site), chatCaseStaff($site), chatCaseStaff($site)];
    $investigator = chatCaseStaff($site, ['chat.investigate']);
    $direct = chatCaseConversation('direct', $alex, $sam);
    $group = chatCaseConversation('group', $alex, $jo, $kim);
    $unrelated = chatCaseConversation('direct', $jo, $kim);
    $group->messages()->create(['sender_id' => $alex->id, 'content' => 'Never shown in a search']);

    // The person search lists Alex's conversations as metadata only.
    $lookup = chatCaseAs($this, $investigator)->getJson(route('settings.chat.investigations.lookup', ['q' => 'Alex Ran']))->assertOk();
    expect(collect($lookup->json('conversations'))->pluck('id')->all())->toContain($direct->id, $group->id)->not->toContain($unrelated->id);
    expect(json_encode($lookup->json()))->not->toContain('Never shown');

    $response = chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [
        'conversation_ids' => [$group->id, $direct->id], 'case_reference' => ' HR-2026-021 ',
        'reason' => 'Formal complaint covering several night-shift chats.', 'duration_hours' => 72,
    ])->assertCreated();
    $grantIds = $response->json('grant_ids');
    expect($grantIds)->toHaveCount(2)->and($response->json('grant_id'))->toBe($grantIds[0])
        ->and($response->json('url'))->toBe(route('settings.chat.investigations.show', $grantIds[0]));

    $rows = DB::table('chat_investigation_grants')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('conversation_id')->map(fn ($id) => (int) $id)->all())->toBe([$group->id, $direct->id])
        ->and($rows->pluck('case_batch')->unique()->all())->toBe([$response->json('case_batch')])
        ->and($rows->pluck('case_reference')->unique()->all())->toBe(['HR-2026-021'])
        ->and($rows->pluck('expires_at')->unique())->toHaveCount(1);
    expect(DB::table('chat_investigation_events')->where('action', 'access_granted')->orderBy('id')->get()
        ->map(fn ($event) => [(int) $event->grant_id, (int) $event->conversation_id])->all())
        ->toBe([[$grantIds[0], $group->id], [$grantIds[1], $direct->id]]);

    // The overview links the grants as one case.
    chatCaseAs($this, $investigator)->getJson(route('settings.chat.investigations.overview'))->assertOk()
        ->assertJsonPath('grants.0.case_batch', $response->json('case_batch'))
        ->assertJsonPath('grants.1.case_batch', $response->json('case_batch'));

    // The case view lists every conversation in the case without opening their content.
    chatCaseAs($this, $investigator)->get(route('settings.chat.investigations.show', $grantIds[1]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/chat-investigation')
            ->where('investigation.grant.case_batch', $response->json('case_batch'))
            ->where('investigation.case_conversations.0.grant_id', $grantIds[0])
            ->where('investigation.case_conversations.0.conversation.id', $group->id)
            ->where('investigation.case_conversations.1.grant_id', $grantIds[1])
            ->where('investigation.case_conversations.1.active', true)
            ->missing('investigation.case_conversations.0.messages'));

    // Expiry and ending stay per conversation.
    chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.end', $grantIds[0]))->assertOk();
    chatCaseAs($this, $investigator)->get(route('settings.chat.investigations.show', $grantIds[0]))->assertForbidden();
    chatCaseAs($this, $investigator)->get(route('settings.chat.investigations.show', $grantIds[1]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('investigation.case_conversations.0.active', false));
});

it('refuses the whole case when any conversation is a family conversation, too many are chosen, or one is repeated', function () {
    $site = Site::factory()->create();
    $investigator = chatCaseStaff($site, ['chat.investigate']);
    $staff = chatCaseConversation('direct', chatCaseStaff($site), chatCaseStaff($site));
    $client = Client::factory()->create(['site_id' => $site->id]);
    $family = OpsConversation::create(['conversation_type' => 'family', 'client_id' => $client->id, 'care_team_cover' => true]);
    $base = ['case_reference' => 'HR-9', 'reason' => 'Formal complaint received today.', 'duration_hours' => 24];

    chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'conversation_ids' => [$staff->id, $family->id]])
        ->assertUnprocessable()->assertJsonPath('message', 'Only private staff conversations can be opened here. Family conversations follow client record access.');
    chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'conversation_ids' => [$staff->id, $staff->id]])
        ->assertJsonValidationErrors('conversation_ids.0');
    chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'conversation_ids' => range(1, 21)])
        ->assertJsonValidationErrors('conversation_ids');
    chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.store'), [...$base, 'conversation_ids' => []])
        ->assertJsonValidationErrors('conversation_ids');
    expect(DB::table('chat_investigation_grants')->count())->toBe(0)
        ->and(DB::table('chat_investigation_events')->where('action', 'access_granted')->count())->toBe(0);

    // Settings administrators without the investigator permission cannot grant a case at all.
    $admin = chatCaseStaff($site, ['settings.access.manage', 'sites.viewAll']);
    chatCaseAs($this, $admin)->postJson(route('settings.chat.investigations.store'), [...$base, 'conversation_ids' => [$staff->id]])->assertForbidden();
});

it('exports only the active granted conversations as a watermarked PDF and logs each export with its hash', function () {
    $site = Site::factory()->create();
    [$alex, $sam, $jo] = [chatCaseStaff($site), chatCaseStaff($site), chatCaseStaff($site)];
    $investigator = chatCaseStaff($site, ['chat.investigate'], 'Mere Investigator');
    $first = chatCaseConversation('direct', $alex, $sam);
    $second = chatCaseConversation('group', $alex, $sam, $jo);
    $third = chatCaseConversation('direct', $alex, $jo);
    $notGranted = chatCaseConversation('direct', $sam, $jo);
    $edited = $first->messages()->create(['sender_id' => $alex->id, 'content' => 'Original wording']);
    $removed = $first->messages()->create(['sender_id' => $sam->id, 'content' => 'Removed later']);
    DB::table('chat_attachments')->insert(['message_id' => $removed->id, 'storage_path' => 'chat/private/roster.pdf', 'name' => 'roster.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 2048, 'created_at' => now(), 'updated_at' => now()]);
    $second->messages()->create(['sender_id' => $jo->id, 'content' => 'Group message for the case']);
    // Enough messages for several pages, so the watermark is checked beyond page one.
    foreach (range(1, 70) as $number) {
        $second->messages()->create(['sender_id' => $number % 2 ? $alex->id : $jo->id, 'content' => "Night shift handover note $number"]);
    }
    $third->messages()->create(['sender_id' => $jo->id, 'content' => 'Access to this one ends first']);
    $notGranted->messages()->create(['sender_id' => $sam->id, 'content' => 'Outside the case']);
    $editing = app(ChatMessageEditing::class);
    $editing->edit($alex, $edited->fresh(), ['mutation_id' => (string) Str::uuid(), 'version' => 0, 'content' => 'Edited wording']);
    $editing->remove($sam, $removed->fresh(), ['mutation_id' => (string) Str::uuid(), 'version' => 0]);
    [$firstGrant, $secondGrant, $thirdGrant] = chatCaseGrant($this, $investigator, [$first, $second, $third]);
    chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.end', $thirdGrant))->assertOk();
    $participants = DB::table('ops_conversation_participants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $settingsAudits = AuditLog::count();
    Notification::fake();
    $rendered = [];
    View::composer('chat.investigation-export', function ($view) use (&$rendered): void {
        $rendered[] = $view->getData();
    });

    chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.export', $firstGrant), ['scope' => 'everything'])
        ->assertJsonValidationErrors('scope');
    $response = chatCaseAs($this, $investigator)->post(route('settings.chat.investigations.export', $firstGrant), ['scope' => 'case'],
        ['Accept' => 'application/json, application/pdf'])->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private');
    $pdf = $response->getContent();
    expect($pdf)->toStartWith('%PDF-')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename="chat-investigation-hr-2026-021-')
        ->and($response->headers->get('X-Export-Sha256'))->toBe(hash('sha256', $pdf));

    // The watermark and contents: active granted conversations only, full record, attachment names only.
    expect($rendered)->toHaveCount(1);
    $data = $rendered[0];
    $html = view('chat.investigation-export', $data)->render();
    expect($data['caseReference'])->toBe('HR-2026-021')
        ->and($data['investigator'])->toBe('Mere Investigator')
        ->and($data['exportedAt'])->toMatch('/NZ[DS]T$/')
        ->and(collect($data['sections'])->pluck('conversation.id')->all())->toBe([$first->id, $second->id])
        ->and($data['omitted'])->toBe(1);
    // Every page of the actual PDF carries the watermark and the case/investigator/time stamp.
    $pages = (new Parser)->parseContent($pdf)->getPages();
    expect(count($pages))->toBeGreaterThan(1);
    foreach ($pages as $number => $page) {
        $text = preg_replace('/\s+/u', ' ', $page->getText());
        expect($text)->toContain('Confidential — investigation record')
            ->toContain('HR-2026-021 · Mere Investigator · '.$data['exportedAt'])
            ->toContain('Page '.($number + 1).' of '.count($pages));
    }
    expect($html)->toContain('Confidential — investigation record')
        ->toContain('HR-2026-021 · Mere Investigator · '.$data['exportedAt'])
        ->toContain('Edited wording')->toContain('Original wording')
        ->toContain('Removed later')->toContain('Removed by sender')
        ->toContain('Attachment: roster.pdf')->toContain('contents not included')
        ->toContain('Group message for the case')
        ->not->toContain('Access to this one ends first')
        ->not->toContain('Outside the case');

    // One logged export event per conversation, carrying the file hash and the exported scope.
    $events = DB::table('chat_investigation_events')->where('action', 'case_exported')->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events->pluck('grant_id')->map(fn ($id) => (int) $id)->all())->toBe([$firstGrant, $secondGrant])
        ->and($events->pluck('conversation_id')->map(fn ($id) => (int) $id)->all())->toBe([$first->id, $second->id])
        ->and($events->every(fn ($event) => $event->actor_id == $investigator->id && $event->case_reference === 'HR-2026-021'))->toBeTrue();
    $meta = json_decode($events[0]->meta, true);
    expect($meta['sha256'])->toBe(hash('sha256', $pdf))
        ->and($meta['conversation_ids'])->toBe([$first->id, $second->id])
        ->and($meta['scope'])->toBe('case')
        ->and($meta['export_id'])->toBe($response->headers->get('X-Export-Reference'))
        ->and(json_decode($events[1]->meta, true)['export_id'])->toBe($meta['export_id']);

    // A single-conversation export from the second grant contains only that conversation.
    chatCaseAs($this, $investigator)->post(route('settings.chat.investigations.export', $secondGrant), ['scope' => 'conversation'],
        ['Accept' => 'application/json'])->assertOk();
    expect(collect(end($rendered)['sections'])->pluck('conversation.id')->all())->toBe([$second->id]);

    // Exporting never joins, reads, notifies or touches the settings audit.
    expect(DB::table('ops_conversation_participants')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($participants);
    Notification::assertNothingSent();
    expect(AuditLog::count())->toBe($settingsAudits);
});

it('refuses exports after expiry or ending, for other investigators and for non-investigators, and rate-limits them', function () {
    $site = Site::factory()->create();
    [$investigator, $colleague] = [chatCaseStaff($site, ['chat.investigate']), chatCaseStaff($site, ['chat.investigate'])];
    $admin = chatCaseStaff($site, ['settings.access.manage', 'sites.viewAll']);
    $conversation = chatCaseConversation('direct', chatCaseStaff($site), chatCaseStaff($site));
    $conversation->messages()->create(['sender_id' => $conversation->participants()->first()->user_id, 'content' => 'Hello']);
    [$grant] = chatCaseGrant($this, $investigator, [$conversation], 1);
    $export = fn (User $user, int $id) => chatCaseAs($this, $user)->postJson(route('settings.chat.investigations.export', $id), ['scope' => 'conversation']);

    $export($admin, $grant)->assertForbidden();
    $export($colleague, $grant)->assertForbidden();
    $this->travel(61)->minutes();
    $export($investigator, $grant)->assertForbidden()
        ->assertJsonPath('message', 'This case access has expired or was ended. Record a new case reason to continue.');
    $refusal = DB::table('chat_investigation_events')->where('grant_id', $grant)->where('action', 'access_refused_expired')->first();
    expect(json_decode($refusal->meta, true))->toBe(['attempt' => 'export']);

    [$ended] = chatCaseGrant($this, $investigator, [$conversation], 8);
    chatCaseAs($this, $investigator)->postJson(route('settings.chat.investigations.end', $ended))->assertOk();
    $export($investigator, $ended)->assertForbidden();
    expect(DB::table('chat_investigation_events')->where('action', 'case_exported')->count())->toBe(0);

    [$active] = chatCaseGrant($this, $investigator, [$conversation], 8);
    // The refused attempts above count towards the limit; start a fresh window.
    $this->travel(11)->minutes();
    $limited = collect(range(1, 6))->map(fn () => $export($investigator, $active)->getStatusCode())->all();
    expect(array_slice($limited, 0, 5))->toBe([200, 200, 200, 200, 200])->and($limited[5])->toBe(429);
    expect(DB::table('chat_investigation_events')->where('action', 'case_exported')->count())->toBe(5);
});

