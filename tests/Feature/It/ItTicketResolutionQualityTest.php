<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTicketResolutionInput;
use App\Domain\It\Services\ItTicketInteractionService;
use App\Models\AuditLog;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    Notification::fake();
    $this->site = Site::factory()->create();
    foreach (['agent' => 'hr', 'requester' => 'support_worker'] as $key => $role) {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->sole()->id]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'is_active' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
        ]);
        $this->{$key} = $user;
    }
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->requester->id]);
    $this->actingAs($this->agent);
    $this->resolution = [
        'actor_user_id' => $this->agent->id, 'expected_version' => $this->ticket->lock_version,
        'resolution_code' => 'restored', 'note' => ' Restored the local service. ',
        'resolution_verification' => ' Checked two connections successfully. ', 'notify_requester' => false,
    ];
});

test('explicit outcomes save public verification and exact persisted evidence', function (string $outcome) {
    $response = $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', [...$this->resolution, 'resolution_code' => $outcome]);
    $response->assertOk()->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.resolution.code', $outcome)
        ->assertJsonPath('data.resolution.summary', 'Restored the local service.')
        ->assertJsonPath('data.resolution.verification', 'Checked two connections successfully.');
    $saved = $this->ticket->fresh();
    expect($saved->status)->toBe('resolved')->and($saved->resolution_code)->toBe($outcome)
        ->and($saved->resolution_verification)->toBe('Checked two connections successfully.');
    $comment = $saved->comments()->sole();
    expect($comment->is_internal)->toBeFalse()
        ->and($comment->body)->toBe("Restored the local service.\n\nHow it was checked: Checked two connections successfully.");
    $event = $saved->events()->where('type', 'resolved')->sole();
    expect($event->payload['resolution']['resolution_code'])->toBe($outcome)
        ->and($event->payload['resolution']['resolution_verification'])->toBe('Checked two connections successfully.');
})->with(ItTicketResolutionInput::OUTCOMES);

test('missing or invalid evidence does not settle work or invent a restored outcome', function (array $changes, string $field) {
    $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', [...$this->resolution, ...$changes])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->ticket->fresh()->status)->toBe('open')
        ->and($this->ticket->fresh()->resolution_code)->toBeNull()
        ->and($this->ticket->comments()->count())->toBe(0);
})->with([
    [['resolution_code' => null], 'resolution_code'],
    [['resolution_code' => 'invented'], 'resolution_code'],
    [['resolution_verification' => '   '], 'resolution_verification'],
    [['note' => '   '], 'note'],
]);

test('direct writers require the same evidence and audit failure rolls back before an explicit retry', function () {
    $service = app(ItTicketInteractionService::class);
    expect(fn () => $service->resolveWithPublicNote($this->ticket, $this->agent, 'Note only.', $this->ticket->lock_version))
        ->toThrow(ValidationException::class);
    $fail = true;
    Event::listen('eloquent.creating: '.AuditLog::class, function (AuditLog $audit) use (&$fail) {
        if ($fail && $audit->action === 'it.ticket.resolved') {
            throw new RuntimeException('Synthetic resolution audit failure');
        }
    });
    $resolve = fn () => $service->resolveWithPublicNote($this->ticket, $this->agent, $this->resolution['note'], $this->ticket->lock_version, resolution: $this->resolution);
    expect($resolve)->toThrow(RuntimeException::class);
    expect($this->ticket->fresh()->status)->toBe('open')->and($this->ticket->fresh()->lock_version)->toBe($this->ticket->lock_version)
        ->and($this->ticket->comments()->count())->toBe(0)->and($this->ticket->events()->where('type', 'resolved')->count())->toBe(0);
    $fail = false;
    $resolve();
    expect($this->ticket->comments()->count())->toBe(1)->and($this->ticket->events()->where('type', 'resolved')->count())->toBe(1);
});

test('reopening preserves the resolution evidence in canonical history and clears only the current resolution', function () {
    $this->postJson('/it/tickets/'.$this->ticket->id.'/resolve', $this->resolution)->assertOk();
    $first = $this->ticket->events()->where('type', 'resolved')->sole();
    $this->actingAs($this->requester)->postJson('/it/tickets/'.$this->ticket->id.'/reopen', [
        'actor_user_id' => $this->requester->id, 'expected_version' => $this->ticket->fresh()->lock_version, 'reason' => 'The same connection failed again.',
    ])->assertOk();
    expect($this->ticket->fresh()->resolution_verification)->toBeNull()
        ->and($first->fresh()->payload)->toBe($first->payload)
        ->and($this->ticket->events()->where('type', 'reopened')->sole()->payload['previous_resolution']['resolution_verification'])
        ->toBe('Checked two connections successfully.');
});

test('requesters cannot read private historical resolution metadata from the generic ticket payload', function () {
    $this->ticket->forceFill(['resolution_code' => 'legacy', 'resolution_summary' => 'Private historical investigation.', 'resolution_verification' => null])->save();
    $this->actingAs($this->requester)->getJson('/it/tickets/'.$this->ticket->id)
        ->assertOk()->assertJsonMissingPath('ticket.resolution');
});

test('generic ticket settlement enforces and publishes the same checked evidence', function (string $workType, string $from, string $to) {
    $this->ticket->forceFill(['work_type' => $workType, 'workflow_state' => $from, 'status' => 'in_progress'])->save();
    $input = [
        'actor_user_id' => $this->agent->id, 'expected_version' => $this->ticket->fresh()->lock_version,
        'workflow_state' => $to, 'resolution_code' => 'restored', 'resolution_summary' => 'Restored the service.',
    ];
    $url = '/it/tickets/'.$this->ticket->id.'/transitions';
    $this->postJson($url, $input)->assertUnprocessable()->assertJsonValidationErrors('resolution_verification');
    expect($this->ticket->fresh()->status)->toBe('in_progress')->and($this->ticket->comments()->count())->toBe(0);
    $this->postJson($url, [...$input, 'resolution_verification' => 'Checked the restored service.'])->assertOk();
    $saved = $this->ticket->fresh();
    expect($saved->status)->toBe('resolved')->and($saved->workflow_state)->toBe($to)
        ->and($saved->resolution_verification)->toBe('Checked the restored service.')
        ->and($saved->comments()->sole()->body)->toBe("Restored the service.\n\nHow it was checked: Checked the restored service.")
        ->and($saved->comments()->sole()->speaker_side)->toBe('it');
    $this->postJson($url, [...$input, 'resolution_verification' => 'Do not replace the saved check.'])->assertConflict();
    expect($saved->comments()->count())->toBe(1);
})->with([
    ['incident', 'in_progress', 'resolved'],
    ['service_request', 'fulfilling', 'fulfilled'],
    ['security_request', 'fulfilling', 'fulfilled'],
]);
