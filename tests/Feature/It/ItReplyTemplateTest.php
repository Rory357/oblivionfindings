<?php

use App\Domain\It\Services\ItReplyTemplateService;
use App\Models\ItReplyTemplate;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    foreach (['agent' => 'hr', 'requester' => 'support_worker'] as $name => $role) {
        $actor = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $actor->roles()->syncWithoutDetaching(Role::where('name', $role)->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
        $this->{$name} = $actor;
    }
    $this->viewer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->viewer->roles()->syncWithoutDetaching(Role::where('name', 'support_worker')->pluck('id'));
    $this->viewer->permissionOverrides()->attach(Permission::where('key', 'it.view')->firstOrFail()->id, ['allowed' => true]);
    ensureCanonicalHrStaffProfile($this->viewer, $this->site);
    $this->ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->requester->id,
        'assigned_to_user_id' => $this->agent->id,
        'title' => 'Printer offline in the office',
    ]);
});

function replyTemplateData(array $replace = []): array
{
    return array_replace([
        'name' => 'Password reset confirmation',
        'audience' => 'public',
        'body' => 'Kia ora {{requester.first_name}}, we have reset access for {{ticket.reference}}. — {{actor.name}}',
    ], $replace);
}

test('managing agents create update and version templates while stale edits are rejected', function () {
    $this->actingAs($this->agent)->post('/it/setup/reply-templates', replyTemplateData())
        ->assertRedirect()->assertSessionHas('success');
    $template = ItReplyTemplate::query()->sole();
    expect($template->lock_version)->toBe(1)
        ->and($template->versions()->count())->toBe(1)
        ->and($template->owner_user_id)->toBe($this->agent->id);

    $this->actingAs($this->agent)->patch("/it/setup/reply-templates/{$template->id}", replyTemplateData([
        'name' => 'Password reset confirmed', 'lock_version' => 1,
    ]))->assertRedirect()->assertSessionHas('success');
    $template->refresh();
    expect($template->lock_version)->toBe(2)
        ->and($template->name)->toBe('Password reset confirmed')
        ->and($template->versions()->pluck('version')->all())->toBe([1, 2]);

    $this->actingAs($this->agent)->patch("/it/setup/reply-templates/{$template->id}", replyTemplateData([
        'name' => 'A stale rename', 'lock_version' => 1,
    ]))->assertSessionHasErrors('lock_version');
    expect($template->fresh()->name)->toBe('Password reset confirmed');

    expect(fn () => $template->versions()->first()->update(['body' => 'tampered']))
        ->toThrow(LogicException::class);
});

test('a template body cannot save an unknown placeholder and never renders one', function () {
    $this->actingAs($this->agent)->post('/it/setup/reply-templates', replyTemplateData([
        'body' => 'Hello {{requester.password}} and {{ticket.reference}}',
    ]))->assertSessionHasErrors('body');
    expect(ItReplyTemplate::query()->count())->toBe(0);
});

test('template management requires it.manage while agents with it.view can only list active templates', function () {
    $this->actingAs($this->viewer)->post('/it/setup/reply-templates', replyTemplateData())->assertForbidden();
    $this->actingAs($this->requester)->get('/it/reply-templates')->assertForbidden();

    $active = app(ItReplyTemplateService::class)->create($this->agent, replyTemplateData());
    $archived = app(ItReplyTemplateService::class)->create($this->agent, replyTemplateData(['name' => 'Retired guidance']));
    app(ItReplyTemplateService::class)->setActive($archived, $this->agent, false, 1);

    $this->actingAs($this->viewer)->getJson('/it/reply-templates')->assertOk()
        ->assertJsonCount(1, 'templates')
        ->assertJsonPath('templates.0.id', $active->id)
        ->assertJsonPath('templates.0.audience', 'public');
});

test('rendering substitutes real ticket context for an authorized agent', function () {
    $template = app(ItReplyTemplateService::class)->create($this->agent, replyTemplateData([
        'body' => '{{ticket.reference}} · {{ticket.title}} · {{requester.first_name}} · {{site.name}} · {{assignee.name}} · {{actor.name}}',
    ]));

    $response = $this->actingAs($this->agent)
        ->getJson("/it/tickets/{$this->ticket->id}/reply-templates/{$template->id}/render")
        ->assertOk();
    $firstName = explode(' ', trim($this->requester->name))[0];
    expect($response->json('body'))->toBe(implode(' · ', [
        $this->ticket->reference, 'Printer offline in the office', $firstName,
        $this->site->name, $this->agent->name, $this->agent->name,
    ]));
});

test('a placeholder without a ticket value blocks insertion instead of leaking the token', function () {
    $this->ticket->update(['assigned_to_user_id' => null]);
    $template = app(ItReplyTemplateService::class)->create($this->agent, replyTemplateData([
        'body' => 'Your technician {{assignee.name}} will follow up.',
    ]));

    $this->actingAs($this->agent)
        ->getJson("/it/tickets/{$this->ticket->id}/reply-templates/{$template->id}/render")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('template');
});

test('internal templates render only for actors with work access and archived templates disappear', function () {
    $internal = app(ItReplyTemplateService::class)->create($this->agent, replyTemplateData([
        'name' => 'Internal escalation note', 'audience' => 'internal',
        'body' => 'Escalating {{ticket.reference}} internally.',
    ]));

    $this->actingAs($this->agent)
        ->getJson("/it/tickets/{$this->ticket->id}/reply-templates/{$internal->id}/render")->assertOk();
    $this->actingAs($this->viewer)
        ->getJson("/it/tickets/{$this->ticket->id}/reply-templates/{$internal->id}/render")->assertForbidden();
    $this->actingAs($this->requester)
        ->getJson("/it/tickets/{$this->ticket->id}/reply-templates/{$internal->id}/render")->assertForbidden();

    app(ItReplyTemplateService::class)->setActive($internal, $this->agent, false, 1);
    $this->actingAs($this->agent)
        ->getJson("/it/tickets/{$this->ticket->id}/reply-templates/{$internal->id}/render")->assertNotFound();
});
