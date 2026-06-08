<?php

use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftHandoverService;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-08 10:00:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('populates the My Day digest handover prop for the incoming worker', function () {
    $worker = makeClockCapableWorker();
    $outgoing = User::factory()->frontlineWorker()->create(['name' => 'Alex Taylor']);
    $client = Client::factory()->create(['first_name' => 'Mere', 'last_name' => 'Wilson']);
    $incomingShift = Shift::factory()
        ->assignedToday($worker, Carbon::parse('2026-06-08 10:00:00', 'Pacific/Auckland'))
        ->published()
        ->create([
            'client_id' => $client->id,
            'user_id' => $worker->id,
        ]);
    $outgoingShift = Shift::factory()->completed()->create([
        'client_id' => $client->id,
        'user_id' => $outgoing->id,
        'starts_at' => Carbon::parse('2026-06-08 02:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-08 10:00:00', 'Pacific/Auckland')->utc(),
        'actual_ends_at' => Carbon::parse('2026-06-08 09:45:00', 'Pacific/Auckland')->utc(),
    ]);

    $handover = ShiftHandover::factory()->create([
        'outgoing_shift_id' => $outgoingShift->id,
        'incoming_shift_id' => $incomingShift->id,
        'client_id' => $client->id,
        'outgoing_staff_id' => $outgoing->id,
        'incoming_staff_id' => $worker->id,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'handover_notes' => 'Mere slept poorly and needs a calm start.',
        'medications_due' => [['label' => 'Morning meds still due', 'severity' => 'high']],
        'incidents_to_note' => [['label' => 'Near fall overnight', 'severity' => 'high']],
        'follow_up_items' => [['label' => 'Call GP after breakfast', 'priority' => 'medium']],
        'submitted_at' => Carbon::parse('2026-06-08 09:45:00', 'Pacific/Auckland')->utc(),
        'submitted_by' => $outgoing->id,
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('handover.id', $handover->id)
            ->where('handover.summary', 'Mere slept poorly and needs a calm start.')
            ->where('handover.unread', true)
            ->where('handover.from.name', 'Alex Taylor')
            ->where('handover.flags.0.label', 'Morning meds still due')
            ->where('handover.flags.1.label', 'Near fall overnight')
            ->where('handover.flags.2.label', 'Call GP after breakfast')
        );
});

it('does not surface an unassigned handover for a resident outside the worker site shift', function () {
    $worker = makeClockCapableWorker();
    $workerSite = Site::factory()->create(['type' => 'house']);
    $foreignSite = Site::factory()->create(['type' => 'house']);
    $workerResident = Client::factory()->create(['site_id' => $workerSite->id]);
    $foreignResident = Client::factory()->create(['site_id' => $foreignSite->id]);
    $workerShift = Shift::factory()
        ->assignedToday($worker, Carbon::parse('2026-06-08 10:00:00', 'Pacific/Auckland'))
        ->published()
        ->create([
            'client_id' => $workerResident->id,
            'site_id' => $workerSite->id,
            'user_id' => $worker->id,
        ]);

    $foreignOutgoingShift = Shift::factory()->completed()->create([
        'client_id' => $foreignResident->id,
        'site_id' => $foreignSite->id,
        'starts_at' => Carbon::parse('2026-06-08 02:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-08 09:30:00', 'Pacific/Auckland')->utc(),
    ]);
    ShiftHandover::factory()->create([
        'outgoing_shift_id' => $foreignOutgoingShift->id,
        'incoming_shift_id' => null,
        'client_id' => $foreignResident->id,
        'incoming_staff_id' => null,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'handover_notes' => 'This belongs to a different house.',
        'submitted_at' => Carbon::parse('2026-06-08 09:30:00', 'Pacific/Auckland')->utc(),
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('handover', null)
            ->where('next_shift_briefing.id', $workerShift->id)
            ->where('next_shift_briefing.incoming_handover', null)
        );
});

it('does not let a worker claim an unassigned handover for a foreign resident', function () {
    $worker = makeClockCapableWorker();
    $workerSite = Site::factory()->create(['type' => 'house']);
    $foreignSite = Site::factory()->create(['type' => 'house']);
    $workerResident = Client::factory()->create(['site_id' => $workerSite->id]);
    $foreignResident = Client::factory()->create(['site_id' => $foreignSite->id]);
    Shift::factory()
        ->assignedToday($worker, Carbon::parse('2026-06-08 10:00:00', 'Pacific/Auckland'))
        ->published()
        ->create([
            'client_id' => $workerResident->id,
            'site_id' => $workerSite->id,
            'user_id' => $worker->id,
        ]);
    $foreignOutgoingShift = Shift::factory()->completed()->create([
        'client_id' => $foreignResident->id,
        'site_id' => $foreignSite->id,
        'starts_at' => Carbon::parse('2026-06-08 02:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-06-08 09:30:00', 'Pacific/Auckland')->utc(),
    ]);
    $handover = ShiftHandover::factory()->create([
        'outgoing_shift_id' => $foreignOutgoingShift->id,
        'incoming_shift_id' => null,
        'client_id' => $foreignResident->id,
        'incoming_staff_id' => null,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'submitted_at' => Carbon::parse('2026-06-08 09:30:00', 'Pacific/Auckland')->utc(),
    ]);

    $this->actingAs($worker)
        ->patch("/attendance/handover/{$handover->id}/acknowledge")
        ->assertForbidden();

    $this->assertDatabaseHas('shift_handovers', [
        'id' => $handover->id,
        'incoming_staff_id' => null,
        'status' => ShiftHandoverService::STATUS_SUBMITTED,
        'acknowledged_by' => null,
    ]);
});

function makeClockCapableWorker(): User
{
    $worker = User::factory()->frontlineWorker()->create();
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'shifts.viewAssigned'],
        ['description' => 'shifts.viewAssigned'],
    );
    $worker->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);

    return $worker;
}
