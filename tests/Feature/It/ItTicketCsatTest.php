<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

function csatUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->worker = csatUser('support_worker'); // the requester
    $this->agent = csatUser('hr');              // it.manage
    $this->site = Site::factory()->create();
    foreach ([$this->worker, $this->agent] as $user) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => null,
        ]);
    }
});

function csatTicket(array $overrides = []): ItTicket
{
    return ItTicket::factory()->create([
        'site_id' => test()->site->id,
        ...$overrides,
    ]);
}

test('a requester rates their own resolved ticket — score, comment and a single trail entry land', function () {
    $ticket = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/csat", ['score' => 5, 'comment' => 'Sorted in minutes — thank you.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->csat_score)->toBe(5);
    expect($ticket->csat_comment)->toBe('Sorted in minutes — thank you.');
    expect($ticket->csat_submitted_at)->not->toBeNull();
    expect($ticket->events()->where('type', 'csat_submitted')->count())->toBe(1);
});

test('CSAT is editable while resolved, but a re-rate never duplicates the event or moves the stamp', function () {
    $ticket = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/csat", ['score' => 2])
        ->assertRedirect();
    $firstStamp = $ticket->fresh()->csat_submitted_at;

    $this->travel(10)->minutes();

    // Change of heart — the score updates, the stamp and the event do not.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/csat", ['score' => 4, 'comment' => 'Actually great.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->csat_score)->toBe(4);
    expect($ticket->csat_comment)->toBe('Actually great.');
    expect($ticket->csat_submitted_at->equalTo($firstStamp))->toBeTrue();
    expect($ticket->events()->where('type', 'csat_submitted')->count())->toBe(1);
});

test('only the requester rates, and only while the ticket is resolved', function () {
    $resolved = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    // Agents never rate — CSAT is the requester's own satisfaction.
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$resolved->id}/csat", ['score' => 5])
        ->assertForbidden();

    // A different requester cannot discover or rate someone else's ticket.
    $stranger = csatUser('support_worker');
    $this->actingAs($stranger)
        ->post("/it/tickets/{$resolved->id}/csat", ['score' => 5])
        ->assertNotFound();
    expect($resolved->fresh()->csat_submitted_at)->toBeNull();

    // Nothing to rate before resolution…
    $open = csatTicket(['requester_user_id' => $this->worker->id, 'status' => 'open']);
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$open->id}/csat", ['score' => 5])
        ->assertForbidden();

    // …and a close locks the rating in (editable UNTIL closed).
    $closed = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'closed',
        'resolved_at' => now()->subDay(),
        'closed_at' => now(),
    ]);
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$closed->id}/csat", ['score' => 5])
        ->assertForbidden();
});

test('the score must be a 1–5 star; the comment is optional', function () {
    $ticket = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    foreach ([0, 6, 'nope'] as $bad) {
        $this->actingAs($this->worker)
            ->post("/it/tickets/{$ticket->id}/csat", ['score' => $bad])
            ->assertSessionHasErrors('score');
    }

    // A bare score (no comment) is fine.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/csat", ['score' => 3])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($ticket->fresh()->csat_score)->toBe(3);
});

test('the workspace rail and My-tickets row carry the CSAT prompt then the result', function () {
    $ticket = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    // Before rating: the requester is invited to rate; no result yet.
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->where('can.rate', true)
            ->where('ticket.csat', null));
    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page
            ->where('myTickets.0.can_rate', true)
            ->where('myTickets.0.csat_score', null));

    // An agent viewing the same ticket is never prompted to rate.
    $this->actingAs($this->agent)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page->where('can.rate', false));

    $ticket->forceFill(['csat_score' => 5, 'csat_comment' => 'Ka pai.', 'csat_submitted_at' => now()])->save();

    // After rating: the result surfaces in both payloads.
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->where('ticket.csat.score', 5)
            ->where('ticket.csat.comment', 'Ka pai.'));
    $this->actingAs($this->worker)
        ->get('/it')
        ->assertInertia(fn ($page) => $page->where('myTickets.0.csat_score', 5));
});
