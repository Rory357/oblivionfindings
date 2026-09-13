<?php

use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    config(['it.drafts.enabled' => false]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->original = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->current = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->original, $this->current] as $actor) {
        $actor->roles()->syncWithoutDetaching(Role::query()->where('name', 'hr')->pluck('id'));
        ensureCanonicalHrStaffProfile($actor, $this->site);
    }
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->current->id]);
    $this->input = ['title' => 'Synthetic private browser buffer', 'category' => 'hardware', 'priority' => 'normal',
        'site_id' => $this->site->id, 'request_uuid' => (string) Str::uuid()];
    Notification::fake();
});

test('a new login cannot submit the previous actors create edit or resolution buffer while drafts are disabled', function () {
    $this->actingAs($this->current);
    $oldActor = ['actor_user_id' => $this->original->id];
    $this->postJson('/it/tickets', [...$this->input, ...$oldActor])->assertForbidden()->assertJsonMissingPath('data');
    $this->patchJson('/it/tickets/'.$this->ticket->id, [...$oldActor, 'expected_version' => 1, 'subcategory' => 'Previous actor private edit'])
        ->assertForbidden()->assertJsonMissingPath('data');
    $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', ['resolution_code' => 'restored', 'resolution_verification' => 'Synthetic verification confirmed the expected result.', ...$oldActor, 'expected_version' => 1, 'note' => 'Previous actor private resolution'])
        ->assertForbidden()->assertJsonMissingPath('data');

    expect(ItTicket::query()->count())->toBe(1)->and(ItTicketCommandReceipt::query()->count())->toBe(0)
        ->and($this->ticket->fresh()->lock_version)->toBe(1)->and($this->ticket->fresh()->subcategory)->toBeNull()
        ->and($this->ticket->fresh()->status)->toBe('open')->and($this->ticket->comments()->count())->toBe(0)
        ->and($this->ticket->events()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('matching current actor commits and command recovery acknowledge that viewer', function () {
    $actorId = (int) $this->current->id;
    $this->actingAs($this->current)->postJson('/it/tickets', [...$this->input, 'actor_user_id' => (string) $actorId])
        ->assertCreated()->assertJsonPath('data.viewer_user_id', $actorId);
    $this->getJson('/it/ticket-commands/'.$this->input['request_uuid'].'?actor_user_id='.$actorId)
        ->assertOk()->assertJsonPath('data.viewer_user_id', $actorId)->assertJsonPath('data.replayed', true);
    // Even a receipt owned by the new login must not be offered to an old tab.
    $this->getJson('/it/ticket-commands/'.$this->input['request_uuid'].'?actor_user_id='.$this->original->id)
        ->assertForbidden()->assertJsonMissingPath('data');
    $this->patchJson('/it/tickets/'.$this->ticket->id, ['actor_user_id' => $actorId, 'expected_version' => 1, 'subcategory' => 'Current actor edit'])
        ->assertOk()->assertJsonPath('data.viewer_user_id', $actorId)->assertJsonPath('status', 'committed');
    $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', ['resolution_code' => 'restored', 'resolution_verification' => 'Synthetic verification confirmed the expected result.', 'actor_user_id' => $actorId,
        'expected_version' => $this->ticket->fresh()->lock_version, 'note' => 'Current actor verified the repair', 'notify_requester' => false])
        ->assertOk()->assertJsonPath('data.viewer_user_id', $actorId)->assertJsonPath('status', 'committed');
    expect($this->ticket->fresh()->status)->toBe('resolved')->and($this->ticket->comments()->sole()->body)->toBe("Current actor verified the repair\n\nHow it was checked: Synthetic verification confirmed the expected result.");
});

test('malformed supplied actor bindings fail closed without coercing them to a current login', function ($actorId) {
    $this->actingAs($this->current)->postJson('/it/tickets', [...$this->input, 'actor_user_id' => $actorId])->assertForbidden();
    expect(ItTicket::query()->count())->toBe(1)->and(ItTicketCommandReceipt::query()->count())->toBe(0);
})->with([[null], [true], [[1]], ['1e0'], [0]]);
