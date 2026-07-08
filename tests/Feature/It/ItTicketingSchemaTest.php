<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;

function itSchemaAgent(): User
{
    $user = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

test('tickets carry the full ticketing schema and tolerate null references', function () {
    $ticket = ItTicket::factory()->create([
        'reference' => 'IT-000101',
        'subcategory' => 'laptop',
        'source' => 'agent',
        'sla_state' => 'ok',
    ]);

    $ticket->refresh(); // pull DB defaults for columns the insert omitted

    expect($ticket->reference)->toBe('IT-000101');
    expect($ticket->subcategory)->toBe('laptop');
    expect($ticket->source)->toBe('agent');
    expect($ticket->sla_paused_minutes)->toBe(0);
    expect($ticket->reopened_count)->toBe(0);
    expect($ticket->csat_score)->toBeNull();

    // The tenant-unique reference index must tolerate many NULLs (rows
    // written outside Eloquent bypass the generating hook). Blank them via
    // raw SQL to prove the index property.
    $ids = ItTicket::factory()->count(2)->create()->pluck('id');
    \Illuminate\Support\Facades\DB::table('it_tickets')->whereIn('id', $ids)->update(['reference' => null]);
    expect(ItTicket::query()->whereNull('reference')->count())->toBe(2);

    // waiting is now a legal status (per §P.10 — display "Waiting on requester").
    expect(ItTicket::STATUSES)->toBe(['open', 'in_progress', 'waiting', 'resolved', 'closed']);
    $ticket->update(['status' => 'waiting', 'waiting_since' => now()]);
    expect($ticket->fresh()->status)->toBe('waiting');
});

test('agents can move a ticket to waiting through the update route', function () {
    $agent = itSchemaAgent();
    $ticket = ItTicket::factory()->create();

    $this->actingAs($agent)
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'waiting'])
        ->assertRedirect();

    expect($ticket->fresh()->status)->toBe('waiting');
});

test('the conversation, watcher and link relationships round-trip', function () {
    $agent = itSchemaAgent();
    $requester = User::factory()->create();
    $asset = Asset::factory()->create();

    $ticket = ItTicket::factory()->create([
        'requester_user_id' => $requester->id,
        'asset_id' => $asset->id,
    ]);

    ItTicketComment::factory()->create([
        'ticket_id' => $ticket->id,
        'author_user_id' => $requester->id,
        'body' => 'It is still broken.',
    ]);
    ItTicketComment::factory()->internal()->create([
        'ticket_id' => $ticket->id,
        'author_user_id' => $agent->id,
        'body' => 'Suspect the charging port — ordering a swap unit.',
    ]);

    expect($ticket->comments()->count())->toBe(2);
    expect($ticket->comments()->publicOnly()->count())->toBe(1);
    expect($ticket->asset->id)->toBe($asset->id);

    $ticket->watchers()->attach($agent->id);
    expect($ticket->watchers()->count())->toBe(1);
    // Unique pivot: re-attaching the same watcher must fail loudly.
    expect(fn () => $ticket->watchers()->attach($agent->id))
        ->toThrow(QueryException::class);
});

test('the polymorphic event trail serves tickets and provisioning requests', function () {
    $agent = itSchemaAgent();
    $ticket = ItTicket::factory()->create();

    $event = ItTicketEvent::record($ticket, 'created', $agent->id, ['via' => 'test']);
    expect($event->subject_type)->toBe('it_ticket');
    expect($event->created_at)->not->toBeNull();
    expect($ticket->events()->count())->toBe(1);
    expect($ticket->events()->first()->payload)->toBe(['via' => 'test']);

    $profileUser = User::factory()->create();
    $profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $profileUser->id,
        'employee_number' => 'EMP-SCHEMA-'.$profileUser->id,
        'work_email' => $profileUser->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->addDays(5)->toDateString(),
        'is_active' => true,
    ]);
    $request = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'type' => 'account',
        'item' => 'M365 account',
        'status' => 'pending',
    ]);

    ItTicketEvent::record($request, 'fulfilled', $agent->id);
    expect($request->events()->count())->toBe(1);
    expect($request->events()->first()->subject_type)->toBe('it_provisioning_request');
    expect($request->events()->first()->subject->id)->toBe($request->id);
});

test('provisioning requests gain priority and due date with safe defaults', function () {
    $profileUser = User::factory()->create();
    $profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $profileUser->id,
        'employee_number' => 'EMP-PRIO-'.$profileUser->id,
        'work_email' => $profileUser->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->addDays(5)->toDateString(),
        'is_active' => true,
    ]);

    // Created without priority (exactly how the onboarding bridge writes) —
    // the DB default must apply.
    $request = ItProvisioningRequest::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $profile->id,
        'type' => 'account',
        'item' => 'Payroll portal login',
        'status' => 'pending',
    ]);
    expect($request->fresh()->priority)->toBe('normal');
    expect($request->fresh()->due_date)->toBeNull();

    $request->update(['priority' => 'high', 'due_date' => now()->addDays(3)->toDateString()]);
    expect($request->fresh()->priority)->toBe('high');
    expect($request->fresh()->due_date->toDateString())->toBe(now()->addDays(3)->toDateString());

    // A ticket raised from the request links both ways.
    $ticket = ItTicket::factory()->create(['provisioning_request_id' => $request->id]);
    expect($ticket->provisioningRequest->id)->toBe($request->id);
    expect($request->linkedTickets()->count())->toBe(1);
});
