<?php

use App\Jobs\DispatchItTicketNotifications;
use App\Models\ItCatalogItem;
use App\Models\ItCatalogSubmission;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('a newly submitted Hub catalogue request persists once and follows canonical public replies and resolution', function () {
    // Only isolated test users receive delivery intents; no delivery job runs.
    Notification::fake();
    Bus::fake([DispatchItTicketNotifications::class]);
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $worker->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $site = ensureCanonicalHrStaffProfile($worker);
    $agent = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $agent->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($agent, $site);
    $item = ItCatalogItem::factory()->create(['name' => 'Hub equipment help', 'site_scope' => [$site->id], 'form_schema' => ['fields' => [
        ['key' => 'details', 'label' => 'What do you need?', 'type' => 'textarea', 'required' => true, 'max' => 2000],
    ]]]);
    $this->actingAs($worker)->get('/company-hub?view=services')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('services.items', 1)->where('services.items.0.id', $item->id)->has('services.requests', 0));
    $input = ['actor_user_id' => $worker->id, 'schema_version' => 1, 'idempotency_key' => (string) Str::uuid(), 'site_id' => $site->id, 'values' => ['details' => 'Synthetic test: equipment help from the Company Hub.']];
    $submitUrl = '/it/catalog/'.$item->id.'/submissions';
    $this->postJson($submitUrl, $input)->assertCreated();
    $ticket = ItTicket::query()->sole();
    expect($ticket->requester_user_id)->toBe($worker->id)->and($ticket->site_id)->toBe($site->id)
        ->and($ticket->description)->toContain($input['values']['details']);
    $this->postJson($submitUrl, $input)->assertOk()->assertJsonPath('data.id', $ticket->id);
    expect(ItTicket::count())->toBe(1)->and(ItCatalogSubmission::count())->toBe(1);
    $this->get('/company-hub?view=services')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('services.requests', 1)->where('services.requests.0.id', $ticket->id)
        ->where('services.requests.0.reference', $ticket->reference)->where('services.requests.0.status', 'open'));

    $reply = ['request_uuid' => (string) Str::uuid(), 'actor_user_id' => $agent->id, 'expected_version' => $ticket->fresh()->lock_version,
        'body' => 'Synthetic public update: we are checking the equipment.', 'is_internal' => false];
    $this->actingAs($agent)->postJson('/it/tickets/'.$ticket->id.'/comments', $reply)->assertCreated();
    $this->postJson('/it/tickets/'.$ticket->id.'/comments', [...$reply, 'request_uuid' => (string) Str::uuid(), 'expected_version' => $ticket->fresh()->lock_version,
        'body' => 'Synthetic internal technician-only investigation', 'is_internal' => true])->assertCreated();
    $this->actingAs($worker)->getJson('/it/tickets/'.$ticket->id)->assertOk()
        ->assertJsonCount(1, 'comments')->assertJsonPath('comments.0.body', $reply['body'])
        ->assertJsonPath('ticket.conversation.state', 'awaiting_requester')->assertDontSee('Synthetic internal technician-only investigation');
    expect($ticket->events()->where('type', 'created')->exists())->toBeTrue();
    $this->actingAs($agent)->post('/it/tickets/'.$ticket->id.'/resolve', ['resolution_code' => 'restored',
        'resolution_verification' => 'Synthetic equipment check passed.', 'expected_version' => $ticket->fresh()->lock_version,
        'note' => 'Synthetic test: the equipment is working again.'])->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($worker)->get('/company-hub?view=services')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('services.requests', 1)->where('services.requests.0.status', 'resolved'));
    $this->getJson('/it/tickets/'.$ticket->id)->assertOk()->assertJsonPath('ticket.status', 'resolved')->assertJsonCount(2, 'comments')
        ->assertSee('Synthetic equipment check passed.')->assertDontSee('Synthetic internal technician-only investigation');
    expect($ticket->events()->where('type', 'resolved')->exists())->toBeTrue();

    $unrelated = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $unrelated->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($unrelated, $site);
    $this->actingAs($unrelated)->get('/company-hub?view=services')->assertOk()->assertInertia(fn (Assert $page) => $page->has('services.requests', 0));
    $this->getJson('/it/tickets/'.$ticket->id)->assertNotFound();
});
