<?php

use App\Domain\It\Services\ItTicketDraftService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketDraft;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config(['it.drafts.enabled' => false, 'it.drafts.retention_days' => null, 'it.drafts.terminal_retention_days' => null]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->worker, $this->agent] as $actor) {
        $actor->roles()->syncWithoutDetaching(Role::query()->where('name', $actor->role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->worker->id]);
    $this->candidate = [
        'actor_user_id' => $this->worker->id, 'purpose' => 'requester_intake', 'request_uuid' => (string) Str::uuid(),
        'memory_uuid' => (string) Str::uuid(), 'candidate_uuid' => (string) Str::uuid(),
        'fields' => ['title' => 'Unsent local fixture text', 'site_id' => $this->site->id], 'step_index' => 1,
    ];
    Notification::fake();
});

test('memory recovery proves the exact context with persistence disabled and never touches draft storage', function () {
    DB::enableQueryLog();
    try {
        $response = $this->actingAs($this->worker)->post('/it/drafts/validate-local-candidate', $this->candidate)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('candidate.kind', 'memory')->assertJsonPath('candidate.authorized', true)
            ->assertJsonPath('candidate.actor_user_id', $this->worker->id)
            ->assertJsonPath('candidate.memory_uuid', $this->candidate['memory_uuid'])
            ->assertJsonPath('candidate.candidate_uuid', $this->candidate['candidate_uuid'])
            ->assertJsonPath('candidate.purpose', 'requester_intake')
            ->assertJsonPath('candidate.context_key', 'request:'.$this->candidate['request_uuid'])
            ->assertJsonPath('candidate.capabilities.submit', true)->assertJsonPath('candidate.blocker', null)
            ->assertJsonPath('candidate.base_ticket_version', null)->assertJsonPath('candidate.current_ticket_version', null)
            ->assertJsonMissingPath('draft')->assertJsonMissingPath('payload')->assertJsonMissingPath('candidate.expires_at')
            ->assertDontSee('Unsent local fixture text')->assertSessionMissing('_old_input');
        $queries = array_column(DB::getQueryLog(), 'query');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect(implode("\n", $queries))->not->toContain('it_ticket_drafts');
    expect(app(ItTicketDraftService::class)->enabled())->toBeFalse()
        ->and(ItTicketDraft::query()->count())->toBe(0)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'like', 'it.draft.%')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('memory recovery rejects stale browser actor identity without returning proof', function () {
    $this->actingAs($this->agent)->postJson('/it/drafts/validate-local-candidate', $this->candidate)
        ->assertForbidden()->assertJsonMissingPath('candidate')->assertDontSee('Unsent local fixture text');
});

test('memory recovery checks old selected scopes even after replacement or clearing', function ($replacement) {
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->worker, $other);
    $this->actingAs($this->worker->fresh())->postJson('/it/drafts/validate-local-candidate', [
        ...$this->candidate, 'fields' => ['title' => 'Private old site text', 'site_id' => $replacement ? $other->id : null],
        'bound_scopes' => [['site_id' => $this->site->id], ['site_id' => $other->id]],
    ])->assertNotFound()->assertJsonMissingPath('candidate')->assertDontSee('Private old site text');
})->with([false, true]);

test('a newly selected unapproved Site is denied before a memory proof is issued', function () {
    $other = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->actingAs($this->worker)->postJson('/it/drafts/validate-local-candidate', [
        ...$this->candidate, 'fields' => ['site_id' => $other->id, 'title' => 'Out of scope'],
    ])->assertNotFound()->assertJsonMissingPath('candidate');
});

test('public participant memory does not grant internal note or technician recovery', function ($purpose) {
    $fields = $purpose === 'ticket_edit' ? ['subcategory' => 'Private classification'] : ['body' => 'Private note'];
    $this->actingAs($this->worker)->postJson('/it/drafts/validate-local-candidate', [
        ...$this->candidate, 'request_uuid' => null, 'ticket_id' => $this->ticket->id,
        'purpose' => $purpose, 'fields' => $fields,
    ])->assertNotFound()->assertJsonMissingPath('candidate');
})->with(['internal_note', 'ticket_edit']);

test('authorized public memory can resume without borrowing a newer ticket version', function () {
    $originalVersion = (int) $this->ticket->refresh()->lock_version;
    $this->ticket->forceFill(['subcategory' => 'Newer canonical classification'])->save();
    $currentVersion = (int) $this->ticket->refresh()->lock_version;
    expect($currentVersion)->toBeGreaterThan($originalVersion);
    $this->actingAs($this->agent)->postJson('/it/drafts/validate-local-candidate', [
        ...$this->candidate, 'actor_user_id' => $this->agent->id, 'request_uuid' => null,
        'ticket_id' => $this->ticket->id, 'purpose' => 'public_resolution',
        'fields' => ['note' => 'Original resolution text'], 'base_ticket_version' => $originalVersion,
    ])->assertOk()->assertJsonPath('candidate.authorized', true)
        ->assertJsonPath('candidate.base_ticket_version', $originalVersion)->assertJsonPath('candidate.current_ticket_version', $currentVersion)
        ->assertJsonPath('candidate.capabilities.submit', false)->assertJsonPath('candidate.blocker.code', 'ticket_changed')
        ->assertDontSee('Original resolution text');
    expect($this->ticket->fresh()->lock_version)->toBe($currentVersion);
});

test('settled work can recover authorized memory for review but cannot submit it', function () {
    $this->ticket->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    $this->actingAs($this->worker)->postJson('/it/drafts/validate-local-candidate', [
        ...$this->candidate, 'request_uuid' => null, 'ticket_id' => $this->ticket->id,
        'purpose' => 'public_reply', 'fields' => ['body' => 'Unsent reply'],
    ])->assertOk()->assertJsonPath('candidate.capabilities.submit', false)
        ->assertJsonPath('candidate.blocker.code', 'ticket_settled')->assertDontSee('Unsent reply');
});

test('revoked current ticket access denies a memory proof even for a retained service actor', function () {
    $this->worker->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'it.request')->sole()->id => ['allowed' => false],
        Permission::query()->where('key', 'it.view')->sole()->id => ['allowed' => false],
    ]);
    $this->actingAs($this->worker)->postJson('/it/drafts/validate-local-candidate', [
        ...$this->candidate, 'request_uuid' => null, 'ticket_id' => $this->ticket->id,
        'purpose' => 'public_reply', 'fields' => ['body' => 'Revoked actor text'],
    ])->assertForbidden()->assertJsonMissingPath('candidate')->assertDontSee('Revoked actor text');
});

test('committed intake requires canonical receipt recovery before local memory can be adopted', function () {
    ItTicketCommandReceipt::query()->create([
        'actor_user_id' => $this->worker->id, 'channel' => 'browser', 'operation' => 'ticket.create',
        'request_uuid' => $this->candidate['request_uuid'], 'request_hash' => str_repeat('a', 64),
        'it_ticket_id' => $this->ticket->id, 'committed_at' => now(),
    ]);
    $this->actingAs($this->worker)->postJson('/it/drafts/validate-local-candidate', $this->candidate)
        ->assertConflict()->assertJsonPath('code', 'draft_submission_exists')
        ->assertJsonPath('recovery_url', '/it/ticket-commands/'.$this->candidate['request_uuid'])
        ->assertJsonMissingPath('candidate')->assertDontSee('Unsent local fixture text');
    $this->ticket->forceFill(['requester_user_id' => $this->agent->id])->save();
    $this->postJson('/it/drafts/validate-local-candidate', $this->candidate)
        ->assertNotFound()->assertJsonMissingPath('recovery_url');
});

test('invalid local candidate and scope fields never flash private input or fabricate proof', function ($overrides) {
    $this->actingAs($this->worker)->post('/it/drafts/validate-local-candidate', [...$this->candidate, ...$overrides])
        ->assertUnprocessable()->assertJsonMissingPath('candidate')->assertSessionMissing('_old_input')
        ->assertDontSee('PRIVATE UNAPPROVED')->assertHeader('Cache-Control', 'no-store, private');
})->with([
    [['fields' => ['password' => 'PRIVATE UNAPPROVED']]],
    [['bound_scopes' => [['password' => 'PRIVATE UNAPPROVED']]]],
    [['bound_scopes' => array_fill(0, 101, [])]],
    [['base_ticket_version' => 1]],
    [['ticket_id' => 1]],
    [['memory_uuid' => 'not-a-uuid']],
]);
