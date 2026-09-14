<?php

use App\Domain\It\Services\ItReplyTemplateService;
use App\Domain\It\Services\ItTicketMacroService;
use App\Models\ItTicket;
use App\Models\ItTicketMacro;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->agent->roles()->syncWithoutDetaching(Role::where('name', 'hr')->pluck('id'));
    ensureCanonicalHrStaffProfile($this->agent, $this->site);
    $this->requester = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->requester->roles()->syncWithoutDetaching(Role::where('name', 'support_worker')->pluck('id'));
    ensureCanonicalHrStaffProfile($this->requester, $this->site);
    $this->viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->viewer->roles()->syncWithoutDetaching(Role::where('name', 'support_worker')->pluck('id'));
    $this->viewer->permissionOverrides()->attach(Permission::where('key', 'it.view')->firstOrFail()->id, ['allowed' => true]);
    ensureCanonicalHrStaffProfile($this->viewer, $this->site);
    $this->ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->requester->id,
        'title' => 'Printer offline in the office',
    ]);
    $this->template = app(ItReplyTemplateService::class)->create($this->agent, [
        'name' => 'Working on it', 'audience' => 'public',
        'body' => 'Kia ora {{requester.first_name}}, {{actor.name}} has picked up {{ticket.reference}}.',
    ]);
    $this->macros = app(ItTicketMacroService::class);
});

function macroActions($test): array
{
    return [
        ['type' => 'assign_to_me'],
        ['type' => 'set_priority', 'priority' => 'high', 'reason' => 'Macro escalation for blocked staff.'],
        ['type' => 'add_reply', 'template_id' => $test->template->id],
    ];
}

test('macro management is it.manage work with an action allowlist and versioned edits', function () {
    $this->actingAs($this->agent)->post('/it/setup/macros', [
        'name' => 'Pick up and escalate', 'actions' => macroActions($this),
    ])->assertRedirect()->assertSessionHas('success');
    $macro = ItTicketMacro::query()->sole();
    expect($macro->lock_version)->toBe(1);

    $this->actingAs($this->agent)->post('/it/setup/macros', [
        'name' => 'Bad macro', 'actions' => [['type' => 'run_shell', 'command' => 'rm -rf /']],
    ])->assertSessionHasErrors();
    $this->actingAs($this->agent)->post('/it/setup/macros', [
        'name' => 'Sneaky settle', 'actions' => [['type' => 'set_status', 'status' => 'resolved']],
    ])->assertSessionHasErrors('actions');
    expect(ItTicketMacro::query()->count())->toBe(1);

    $this->actingAs($this->agent)->patch("/it/setup/macros/{$macro->id}", [
        'name' => 'Stale rename', 'actions' => macroActions($this), 'lock_version' => 9,
    ])->assertSessionHasErrors('lock_version');
    $this->actingAs($this->viewer)->post('/it/setup/macros', [
        'name' => 'Not allowed', 'actions' => macroActions($this),
    ])->assertForbidden();
});

test('the preview names every resulting change and reports blockers instead of guessing', function () {
    $macro = $this->macros->create($this->agent, ['name' => 'Pick up and escalate', 'actions' => macroActions($this)]);

    $response = $this->actingAs($this->agent)->getJson("/it/tickets/{$this->ticket->id}/macros")->assertOk();
    $preview = collect($response->json('macros'))->firstWhere('id', $macro->id);
    expect($preview['changes'])->toBe([
        'Assign the ticket to you ('.$this->agent->name.').',
        'Override priority to high (reason: Macro escalation for blocked staff.).',
        'Send a public reply from template "Working on it".',
    ])->and($preview['blockers'])->toBe([])
        ->and($response->json('ticket_version'))->toBe(1);

    app(ItReplyTemplateService::class)->setActive($this->template, $this->agent, false, 1);
    $blocked = collect($this->actingAs($this->agent)->getJson("/it/tickets/{$this->ticket->id}/macros")->json('macros'))
        ->firstWhere('id', $macro->id);
    expect($blocked['blockers'])->toContain('A configured reply template is archived or missing.');

    $this->actingAs($this->viewer)->getJson("/it/tickets/{$this->ticket->id}/macros")->assertForbidden();
});

test('applying a macro routes through the canonical guards and can never double-post its reply', function () {
    $macro = $this->macros->create($this->agent, ['name' => 'Pick up and escalate', 'actions' => macroActions($this)]);
    $uuid = (string) Str::uuid();

    $this->actingAs($this->agent)->post("/it/tickets/{$this->ticket->id}/macros/{$macro->id}/apply", [
        'expected_version' => 1, 'request_uuid' => $uuid,
    ])->assertRedirect()->assertSessionHas('success');

    $ticket = $this->ticket->fresh();
    expect($ticket->assigned_to_user_id)->toBe($this->agent->id)
        ->and($ticket->priority)->toBe('high')
        ->and($ticket->comments()->count())->toBe(1)
        ->and($ticket->comments()->sole()->is_internal)->toBeFalse()
        ->and($ticket->comments()->sole()->body)->toContain('has picked up '.$ticket->reference);

    // A replay of the same application (same identity, stale version) is
    // rejected safely and never posts the reply again.
    $this->actingAs($this->agent)->post("/it/tickets/{$this->ticket->id}/macros/{$macro->id}/apply", [
        'expected_version' => 1, 'request_uuid' => $uuid,
    ])->assertSessionHasErrors();
    expect($this->ticket->fresh()->comments()->count())->toBe(1);

    // Work access is required to apply at all.
    $this->actingAs($this->viewer)->post("/it/tickets/{$this->ticket->id}/macros/{$macro->id}/apply", [
        'expected_version' => 2, 'request_uuid' => (string) Str::uuid(),
    ])->assertForbidden();
    $this->actingAs($this->requester)->post("/it/tickets/{$this->ticket->id}/macros/{$macro->id}/apply", [
        'expected_version' => 2, 'request_uuid' => (string) Str::uuid(),
    ])->assertForbidden();
});

test('a settled ticket blocks macro application with an explanation', function () {
    $macro = $this->macros->create($this->agent, ['name' => 'Pick up and escalate', 'actions' => macroActions($this)]);
    $this->ticket->update(['status' => 'resolved', 'resolved_at' => now()]);

    $this->actingAs($this->agent)->post("/it/tickets/{$this->ticket->id}/macros/{$macro->id}/apply", [
        'expected_version' => 1, 'request_uuid' => (string) Str::uuid(),
    ])->assertSessionHasErrors('macro');
    expect($this->ticket->fresh()->comments()->count())->toBe(0);
});
