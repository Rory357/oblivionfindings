<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

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

test('rating commands bind the current actor and version and acknowledge only persisted feedback', function () {
    $ticket = csatTicket(['requester_user_id' => $this->worker->id, 'status' => 'resolved', 'resolved_at' => now()]);
    $url = '/it/tickets/'.$ticket->id.'/csat';
    $body = ['actor_user_id' => $this->worker->id, 'expected_version' => $ticket->lock_version,
        'score' => 4, 'comment' => '  Verified restored access.  '];
    $this->actingAs($this->worker)->postJson($url, ['score' => 4])->assertUnprocessable()
        ->assertJsonValidationErrors(['actor_user_id', 'expected_version']);
    $this->postJson($url, [...$body, 'actor_user_id' => $this->agent->id])->assertForbidden();
    $this->postJson($url, $body)->assertOk()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.operation', 'csat.save')->assertJsonPath('data.viewer_user_id', $this->worker->id)
        ->assertJsonPath('data.score', 4)->assertJsonPath('data.comment', 'Verified restored access.')
        ->assertJsonPath('data.lock_version', $ticket->lock_version + 1);
    $stamp = $ticket->fresh()->csat_submitted_at;
    $this->postJson($url, [...$body, 'score' => 1])->assertConflict();
    expect($ticket->fresh()->csat_score)->toBe(4)->and($ticket->events()->where('type', 'csat_updated')->count())->toBe(0);
    // Read-only review does not apply the competing proposal.
    $this->getJson('/it/tickets/'.$ticket->id)->assertOk()->assertJsonPath('can.rate', true)
        ->assertJsonPath('ticket.csat.score', 4);
    $version = $ticket->fresh()->lock_version;
    $this->postJson($url, [...$body, 'expected_version' => $version])->assertOk()
        ->assertJsonPath('data.lock_version', $version);
    expect($ticket->fresh()->lock_version)->toBe($version)
        ->and($ticket->events()->where('type', 'csat_submitted')->count())->toBe(1);
    $this->postJson($url, [...$body, 'expected_version' => $version, 'score' => 3])->assertOk();
    expect($ticket->fresh()->csat_submitted_at->equalTo($stamp))->toBeTrue()
        ->and($ticket->events()->where('type', 'csat_updated')->count())->toBe(1);
});

test('rating audit failure rolls back and a current explicit retry commits once', function () {
    $ticket = csatTicket(['requester_user_id' => $this->worker->id, 'status' => 'resolved', 'resolved_at' => now()]);
    $fail = true;
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit) use (&$fail) {
        if ($fail && $audit->action === 'it.ticket.csat.submitted') {
            throw new RuntimeException('Synthetic rating audit refusal');
        }
    });
    $service = app(ItTicketInteractionService::class);
    expect(fn () => $service->submitCsat($ticket, $this->worker, 4, 'Restored.', $ticket->lock_version))
        ->toThrow(RuntimeException::class);
    expect($ticket->fresh()->csat_score)->toBeNull()->and($ticket->fresh()->lock_version)->toBe($ticket->lock_version)
        ->and($ticket->events()->where('type', 'csat_submitted')->count())->toBe(0);
    $fail = false;
    $service->submitCsat($ticket, $this->worker, 4, 'Restored.', $ticket->lock_version);
    expect($ticket->fresh()->csat_score)->toBe(4)->and($ticket->events()->where('type', 'csat_submitted')->count())->toBe(1);
});

test('direct rating rechecks current approval and requester permission and validates the score', function () {
    $ticket = csatTicket(['requester_user_id' => $this->worker->id, 'status' => 'resolved', 'resolved_at' => now()]);
    $service = app(ItTicketInteractionService::class);
    expect(fn () => $service->submitCsat($ticket, $this->worker, 9, null, $ticket->lock_version))
        ->toThrow(ValidationException::class);
    $merged = csatTicket(['requester_user_id' => $this->worker->id, 'status' => 'resolved', 'resolved_at' => now(), 'merged_into_ticket_id' => $ticket->id]);
    expect($this->worker->can('csat', $merged))->toBeFalse();
    expect(fn () => $service->submitCsat($merged, $this->worker, 4, null, $merged->lock_version))
        ->toThrow(AuthorizationException::class);
    $staleActor = $this->worker->fresh();
    $this->worker->forceFill(['approved_at' => null])->save();
    expect(fn () => $service->submitCsat($ticket, $staleActor, 4, null, $ticket->lock_version))
        ->toThrow(AuthorizationException::class);
    expect($ticket->fresh()->csat_score)->toBeNull()->and($ticket->events()->count())->toBe(0);
});

test('a requester rates their own resolved ticket — score, comment and a single trail entry land', function () {
    $ticket = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $ticket->fresh()->lock_version, 'score' => 5, 'comment' => 'Sorted in minutes — thank you.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->csat_score)->toBe(5);
    expect($ticket->csat_comment)->toBe('Sorted in minutes — thank you.');
    expect($ticket->csat_submitted_at)->not->toBeNull();
    expect($ticket->events()->where('type', 'csat_submitted')->count())->toBe(1);
    expect(AuditLog::query()
        ->where('action', 'it.ticket.csat.submitted')
        ->where('auditable_type', $ticket->getMorphClass())
        ->where('auditable_id', $ticket->id)
        ->count())->toBe(1);
});

test('CSAT is editable while resolved, with one submission and an explicit update trail', function () {
    $ticket = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $ticket->fresh()->lock_version, 'score' => 2])
        ->assertRedirect();
    $firstStamp = $ticket->fresh()->csat_submitted_at;

    $this->travel(10)->minutes();

    // Change of heart — the score updates, the original stamp stays, and the
    // change receives its own integrity trail without duplicating submission.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $ticket->fresh()->lock_version, 'score' => 4, 'comment' => 'Actually great.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->csat_score)->toBe(4);
    expect($ticket->csat_comment)->toBe('Actually great.');
    expect($ticket->csat_submitted_at->equalTo($firstStamp))->toBeTrue();
    expect($ticket->events()->where('type', 'csat_submitted')->count())->toBe(1);
    expect($ticket->events()->where('type', 'csat_updated')->count())->toBe(1);
    expect(AuditLog::query()
        ->where('action', 'it.ticket.csat.updated')
        ->where('auditable_type', $ticket->getMorphClass())
        ->where('auditable_id', $ticket->id)
        ->count())->toBe(1);
});

test('only the requester rates, and only while the ticket is resolved', function () {
    $resolved = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    // Agents never rate — CSAT is the requester's own satisfaction.
    $this->actingAs($this->agent)
        ->post("/it/tickets/{$resolved->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $resolved->fresh()->lock_version, 'score' => 5])
        ->assertForbidden();

    // A different requester cannot discover or rate someone else's ticket.
    $stranger = csatUser('support_worker');
    $this->actingAs($stranger)
        ->post("/it/tickets/{$resolved->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $resolved->fresh()->lock_version, 'score' => 5])
        ->assertNotFound();
    expect($resolved->fresh()->csat_submitted_at)->toBeNull();

    // Nothing to rate before resolution…
    $open = csatTicket(['requester_user_id' => $this->worker->id, 'status' => 'open']);
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$open->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $open->fresh()->lock_version, 'score' => 5])
        ->assertForbidden();

    // …and a close locks the rating in (editable UNTIL closed).
    $closed = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'closed',
        'resolved_at' => now()->subDay(),
        'closed_at' => now(),
    ]);
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$closed->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $closed->fresh()->lock_version, 'score' => 5])
        ->assertForbidden();
});

test('personal list rating controls match canonical requester policy for requested-for participants', function () {
    $own = csatTicket(['requester_user_id' => $this->worker->id, 'status' => 'resolved', 'resolved_at' => now()]);
    $requestedFor = csatTicket([
        'requester_user_id' => $this->agent->id, 'requested_for_user_id' => $this->worker->id,
        'status' => 'resolved', 'resolved_at' => now(),
    ]);
    $open = csatTicket(['requester_user_id' => $this->worker->id, 'status' => 'open']);
    $this->actingAs($this->worker)->get('/it?tab=my-tickets')->assertOk()
        ->assertInertia(fn ($page) => $page->has('myTickets', 3)->where('myTickets', function ($rows) use ($own, $requestedFor, $open): bool {
            $rows = collect($rows)->keyBy('id');

            return $rows[$own->id]['can_rate'] === true
                && $rows[$requestedFor->id]['can_rate'] === false
                && $rows[$open->id]['can_rate'] === false;
        }));
    $this->post("/it/tickets/{$requestedFor->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $requestedFor->fresh()->lock_version, 'score' => 5])->assertForbidden();
    expect($requestedFor->fresh()->csat_submitted_at)->toBeNull();
    $this->post("/it/tickets/{$own->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $own->fresh()->lock_version, 'score' => 5])->assertRedirect();
    expect($own->fresh()->csat_score)->toBe(5);
});

test('the score must be a 1–5 star; the comment is optional', function () {
    $ticket = csatTicket([
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    foreach ([0, 6, 'nope'] as $bad) {
        $this->actingAs($this->worker)
            ->post("/it/tickets/{$ticket->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $ticket->fresh()->lock_version, 'score' => $bad])
            ->assertSessionHasErrors('score');
    }

    // A bare score (no comment) is fine.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/csat", ['actor_user_id' => $this->worker->id, 'expected_version' => $ticket->fresh()->lock_version, 'score' => 3])
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
