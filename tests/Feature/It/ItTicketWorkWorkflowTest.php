<?php

use App\Domain\It\Services\ItTicketBookingService;
use App\Domain\It\Services\ItTicketWorkService;
use App\Domain\It\Services\ItTicketWorkTime;
use App\Models\ItEmailDelivery;
use App\Models\ItTicket;
use App\Models\ItTicketBooking;
use App\Models\ItTicketCost;
use App\Models\ItTicketDraft;
use App\Models\ItTicketTimeEntry;
use App\Models\ItTicketWorkRevision;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->requester = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'work_phone' => '09 555 0101']);
    $this->tech = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->reviewer = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    foreach ([$this->requester, $this->tech, $this->reviewer] as $person) {
        $person->roles()->syncWithoutDetaching(Role::where('name', $person->role)->pluck('id'));
        ensureCanonicalHrStaffProfile($person, $this->site);
    }
    $this->ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->requester->id,
        'assigned_to_user_id' => $this->tech->id, 'status' => 'open', 'work_type' => 'incident', 'workflow_state' => 'submitted']);
    Notification::fake();
    Bus::fake();
    $this->period = ['starts_at' => now()->subHours(3)->utc()->toIso8601String(), 'ends_at' => now()->subHours(2)->utc()->toIso8601String(), 'break_minutes' => 10, 'work_type' => 'remote', 'after_hours' => true];
    $this->commentInput = fn ($work) => ['request_uuid' => (string) Str::uuid(), 'actor_user_id' => $this->tech->id,
        'expected_version' => $this->ticket->refresh()->lock_version, 'body' => 'Private diagnostic note.', 'is_internal' => true,
        'work_payload' => json_encode($work, JSON_THROW_ON_ERROR)];
    $this->command = fn ($operation, $payload, $actor = null) => ['request_uuid' => (string) Str::uuid(),
        'actor_user_id' => ($actor ?? $this->tech)->id, 'expected_version' => $this->ticket->refresh()->lock_version, 'operation' => $operation, 'payload' => $payload];
});

test('work note saves once with actual net time and no public disclosure', function () {
    $input = ($this->commentInput)(['periods' => [$this->period]]);
    $path = "/it/tickets/{$this->ticket->id}/comments";
    $this->actingAs($this->tech)->postJson($path, $input)->assertCreated();
    $this->postJson($path, $input)->assertOk()->assertJsonPath('data.replayed', true);
    expect(ItTicketTimeEntry::where('ticket_id', $this->ticket->id)->count())->toBe(1)
        ->and(ItTicketTimeEntry::where('ticket_id', $this->ticket->id)->first()->minutes)->toBe(50)
        ->and($this->ticket->comments()->count())->toBe(1);
    $this->actingAs($this->requester)->getJson("/it/tickets/{$this->ticket->id}/work/people?kind=technician")->assertNotFound();
    $this->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('context', ['reason' => 'Forged contact update'], $this->requester))->assertNotFound();
});

test('invalid later entry rolls the entire note and time write back', function () {
    $input = ($this->commentInput)(['periods' => [$this->period, [...$this->period, 'ends_at' => $this->period['starts_at']]]]);
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/comments", $input)->assertUnprocessable();
    expect($this->ticket->comments()->count())->toBe(0)->and(ItTicketTimeEntry::where('ticket_id', $this->ticket->id)->count())->toBe(0);
});

test('stale versions and changed retry payloads cannot add duplicate work', function () {
    $input = ($this->commentInput)(['periods' => [$this->period]]);
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/comments", $input)->assertCreated();
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", [...$input, 'work_payload' => json_encode(['periods' => [[...$this->period, 'break_minutes' => 5]]])])->assertConflict();
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", [...$input, 'request_uuid' => (string) Str::uuid()])->assertConflict();
    expect(ItTicketTimeEntry::where('ticket_id', $this->ticket->id)->count())->toBe(1);
});

test('overlapping actual work is rejected across ticket boundaries', function () {
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/comments", ($this->commentInput)(['periods' => [$this->period]]))->assertCreated();
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", ($this->commentInput)(['periods' => [$this->period]]))->assertUnprocessable();
    expect($this->ticket->comments()->count())->toBe(1);
});

test('a running timer cannot be forged into a completed work note', function () {
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/comments", ($this->commentInput)(['timer' => ['started' => 123], 'periods' => [$this->period]]))->assertUnprocessable();
    expect($this->ticket->comments()->count())->toBe(0);
});

test('review requires the assigned independent person and corrections retain history', function () {
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/comments", ($this->commentInput)(['periods' => [$this->period], 'require_approval' => true, 'approver_user_id' => $this->reviewer->id]))->assertCreated();
    $entry = ItTicketTimeEntry::where('ticket_id', $this->ticket->id)->firstOrFail();
    $review = ['type' => 'time', 'id' => $entry->id, 'decision' => 'approved', 'reason' => 'Verified the work and time'];
    $this->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('review', $review))->assertForbidden();
    $this->actingAs($this->reviewer)->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('review', $review, $this->reviewer))->assertOk();
    $change = [...$this->period, 'id' => $entry->id, 'break_minutes' => 5, 'reason' => 'Corrected the break duration'];
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('correct_time', $change))->assertUnprocessable();
    $this->actingAs($this->reviewer)->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('review', [...$review, 'decision' => 'changes_requested'], $this->reviewer))->assertOk();
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('correct_time', $change))->assertOk();
    expect($entry->refresh()->minutes)->toBe(55)->and($entry->approval_status)->toBe('pending')
        ->and(ItTicketWorkRevision::where('record_type', 'time')->where('record_id', $entry->id)->where('action', 'corrected')->first()->evidence['before']['minutes'])->toBe(50);
});

test('multi technician bookings are atomic and acceptance belongs to the booked person', function () {
    $booking = ['starts_at' => now()->addDays(3)->utc()->toIso8601String(), 'ends_at' => now()->addDays(3)->addHour()->utc()->toIso8601String(),
        'technician_user_ids' => [$this->tech->id, $this->reviewer->id], 'brief' => 'Verify the replacement network equipment', 'location' => 'Office'];
    $input = ($this->command)('book', $booking);
    $path = "/it/tickets/{$this->ticket->id}/work";
    $this->actingAs($this->tech)->postJson($path, $input)->assertOk();
    $this->postJson($path, $input)->assertOk()->assertJsonPath('replayed', true);
    expect(ItTicketBooking::where('ticket_id', $this->ticket->id)->count())->toBe(2);
    $record = ItTicketBooking::where('ticket_id', $this->ticket->id)->where('technician_user_id', $this->reviewer->id)->first();
    $response = ['id' => $record->id, 'action' => 'accepted', 'reason' => 'I can attend this visit'];
    $this->postJson($path, ($this->command)('booking', $response))->assertForbidden();
    $this->actingAs($this->reviewer)->postJson($path, ($this->command)('booking', $response, $this->reviewer))->assertOk();
    $this->actingAs($this->tech)->postJson($path, ($this->command)('book', $booking))->assertUnprocessable();
    expect(ItTicketBooking::where('ticket_id', $this->ticket->id)->count())->toBe(2);
});

test('costs use integer arithmetic and real configured review thresholds', function () {
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('context', ['cost_review_threshold_cents' => 10000, 'reason' => 'Review significant parts costs']))->assertOk();
    $payload = ['kind' => 'part', 'incurred_on' => today()->toDateString(), 'description' => 'Replacement part', 'quantity_hundredths' => 200,
        'unit_cost_cents' => 7500, 'approver_user_id' => $this->reviewer->id];
    $this->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('cost', $payload))->assertOk();
    $cost = ItTicketCost::where('ticket_id', $this->ticket->id)->first();
    expect($cost->total_cents)->toBe(15000)->and($cost->approval_status)->toBe('pending');
    expect(fn () => app(ItTicketWorkService::class)->guardSettlement($this->ticket))->toThrow(ValidationException::class);
});

test('local overnight periods and daylight saving ambiguity are validated', function () {
    $time = app(ItTicketWorkTime::class);
    expect($time->period(['starts_at' => '2026-07-01T23:30', 'ends_at' => '2026-07-02T00:30', 'break_minutes' => 10])['minutes'])->toBe(50);
    expect(fn () => $time->instant('2026-04-05T02:30', 'starts_at'))->toThrow(ValidationException::class);
    expect(fn () => $time->instant('2026-09-27T02:30', 'starts_at'))->toThrow(ValidationException::class);
    expect($time->instant('2026-04-05T02:30:00+13:00', 'starts_at')->toIso8601String())->toBe('2026-04-04T13:30:00+00:00');
    expect($time->period(['starts_at' => '2026-07-01T00:00:00.000Z', 'ends_at' => '2026-07-01T01:00:00.000000Z'])['minutes'])->toBe(60);
});

test('work drafts are encrypted and consumed atomically with the exact work command', function () {
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $command = ($this->command)('context', ['impact_summary' => 'Private diagnosis that must survive refresh', 'reason' => 'Added diagnostic evidence']);
    $draft = $this->actingAs($this->tech)->postJson('/it/drafts/context', ['actor_user_id' => $this->tech->id, 'purpose' => 'ticket_work', 'ticket_id' => $this->ticket->id])->assertOk()->json('draft');
    $fields = ['work_form' => json_encode(['proposal' => ['title' => 'Context', 'operation' => 'context', 'payload' => $command['payload']], 'pending' => $command])];
    $this->patchJson('/it/drafts/'.$draft['draft_uuid'], ['actor_user_id' => $this->tech->id, 'expected_revision' => 0, 'fields' => $fields, 'step_index' => 0, 'base_ticket_version' => $command['expected_version']])->assertOk();
    $this->postJson('/it/drafts/'.$draft['draft_uuid'].'/resume', ['actor_user_id' => $this->tech->id])->assertOk()->assertJsonPath('payload.fields.work_form', $fields['work_form']);
    $input = [...$command, 'draft_uuid' => $draft['draft_uuid'], 'draft_revision' => 1, 'draft_actor_user_id' => $this->tech->id];
    $this->postJson("/it/tickets/{$this->ticket->id}/work", $input)->assertOk()->assertJsonPath('draft.state', 'consumed');
    $this->postJson("/it/tickets/{$this->ticket->id}/work", $input)->assertOk()->assertJsonPath('replayed', true);
    expect(ItTicketDraft::where('draft_uuid', $draft['draft_uuid'])->first()->state)->toBe('consumed');
});

test('public note drafts carrying internal work require current work permission on recovery', function () {
    config(['it.drafts.enabled' => true, 'it.drafts.retention_days' => 2, 'it.drafts.terminal_retention_days' => 3]);
    $draft = $this->actingAs($this->requester)->postJson('/it/drafts/context', ['actor_user_id' => $this->requester->id, 'purpose' => 'public_reply', 'ticket_id' => $this->ticket->id])->assertOk()->json('draft');
    $this->patchJson('/it/drafts/'.$draft['draft_uuid'], ['actor_user_id' => $this->requester->id, 'expected_revision' => 0, 'fields' => ['body' => 'Reply', 'work_payload' => json_encode(['periods' => []])], 'step_index' => 0])->assertNotFound();
});

test('work command cancellation prevents a late retry from creating a booking', function () {
    $command = ($this->command)('book', ['starts_at' => now()->addDays(5)->toIso8601String(), 'ends_at' => now()->addDays(5)->addHour()->toIso8601String(), 'technician_user_ids' => [$this->tech->id], 'brief' => 'Check network']);
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/work/commands/{$command['request_uuid']}/cancel", ['operation' => 'book'])->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson("/it/tickets/{$this->ticket->id}/work", $command)->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItTicketBooking::where('ticket_id', $this->ticket->id)->count())->toBe(0);
});

test('public reply recipient selection rejects an empty audience and retains only eligible notifications', function () {
    $input = [...($this->commentInput)(['recipient_user_ids' => []]), 'is_internal' => false];
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/comments", $input)->assertUnprocessable();
    expect($this->ticket->comments()->count())->toBe(0);
    $this->postJson("/it/tickets/{$this->ticket->id}/comments", [...$input, 'work_payload' => json_encode(['recipient_user_ids' => [$this->requester->id]])])->assertCreated();
    expect(ItEmailDelivery::where('it_ticket_id', $this->ticket->id)->count())->toBe(1);
});

test('contact searches and forged context changes enforce approved sites', function () {
    $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $outside = User::factory()->create(['name' => 'Outside private worker', 'approved_at' => now(), 'role' => 'support_worker']);
    ensureCanonicalHrStaffProfile($outside, $site);
    $this->actingAs($this->tech)->getJson("/it/tickets/{$this->ticket->id}/work/people?kind=user&q=Outside")->assertOk()->assertJsonCount(0, 'options');
    $this->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('context', ['affected_user_id' => $outside->id, 'reason' => 'Forged affected user selection']))->assertUnprocessable();
});

test('waiting status follow up and actual work commit together and enforce transition requirements', function () {
    $path = "/it/tickets/{$this->ticket->id}/comments";
    $this->actingAs($this->tech)->postJson($path, ($this->commentInput)(['periods' => [$this->period], 'status' => 'waiting']))->assertUnprocessable();
    expect($this->ticket->comments()->count())->toBe(0)->and(ItTicketTimeEntry::where('ticket_id', $this->ticket->id)->count())->toBe(0);
    $follow = ['owner_user_id' => $this->reviewer->id, 'due_at' => now()->addDay()->toIso8601String(), 'action' => 'Check replacement delivery', 'waiting_party' => 'vendor', 'reason' => 'Vendor is delivering the replacement'];
    $this->postJson($path, ($this->commentInput)(['periods' => [$this->period], 'status' => 'waiting', 'follow_up' => $follow]))->assertCreated();
    expect($this->ticket->refresh()->workflow_state)->toBe('waiting')->and($this->ticket->next_action)->toBe($follow['action'])
        ->and(app(ItTicketWorkService::class)->details($this->ticket)['follow_up']['owner_user_id'])->toBe($this->reviewer->id);
});

test('accepted bookings feed the assigned calendar and completion requires actual work', function () {
    $booking = ItTicketBooking::create(['ticket_id' => $this->ticket->id, 'technician_user_id' => $this->tech->id, 'recorded_by' => $this->reviewer->id,
        'group_uuid' => (string) Str::uuid(), 'starts_at' => $this->period['starts_at'], 'ends_at' => $this->period['ends_at'], 'status' => 'accepted', 'details' => ['brief' => 'Repair the network']]);
    $service = app(ItTicketBookingService::class);
    expect($service->calendar($this->tech, now()->subDay(), now()->addDay()))->toHaveCount(1)
        ->and($service->calendar($this->reviewer, now()->subDay(), now()->addDay()))->toHaveCount(0);
    $path = "/it/tickets/{$this->ticket->id}/comments";
    $this->actingAs($this->tech)->postJson($path, ($this->commentInput)(['booking_id' => $booking->id]))->assertUnprocessable();
    $this->postJson($path, ($this->commentInput)(['booking_id' => $booking->id, 'periods' => [$this->period]]))->assertCreated();
    expect($booking->refresh()->status)->toBe('completed')->and(ItTicketTimeEntry::where('booking_id', $booking->id)->count())->toBe(1);
});

test('revoked technician bookings can be cancelled without granting access again', function () {
    $booking = ItTicketBooking::create(['ticket_id' => $this->ticket->id, 'technician_user_id' => $this->reviewer->id, 'recorded_by' => $this->tech->id,
        'group_uuid' => (string) Str::uuid(), 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'requested']);
    $this->reviewer->update(['approved_at' => null]);
    $this->actingAs($this->tech)->postJson("/it/tickets/{$this->ticket->id}/work", ($this->command)('booking', ['id' => $booking->id, 'action' => 'cancelled', 'reason' => 'Reassign after staff access ended']))->assertOk();
    expect($booking->refresh()->status)->toBe('cancelled');
});

test('editing a pending cost cannot remove its required review', function () {
    $payload = ['kind' => 'part', 'incurred_on' => today()->toDateString(), 'description' => 'Replacement part', 'quantity_hundredths' => 100,
        'unit_cost_cents' => 5000, 'require_approval' => true, 'approver_user_id' => $this->reviewer->id];
    $path = "/it/tickets/{$this->ticket->id}/work";
    $this->actingAs($this->tech)->postJson($path, ($this->command)('cost', $payload))->assertOk();
    $cost = ItTicketCost::where('ticket_id', $this->ticket->id)->firstOrFail();
    $this->postJson($path, ($this->command)('cost', [...$payload, 'id' => $cost->id, 'require_approval' => false, 'reason' => 'Correct part reference']))->assertOk();
    expect($cost->refresh()->approval_status)->toBe('pending');
});
