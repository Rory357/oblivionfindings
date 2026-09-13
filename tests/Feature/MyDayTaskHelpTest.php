<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\MyDay\ShiftTaskHelpService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-09-12 10:30', 'Pacific/Auckland')->utc());
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->owner = User::factory()->frontlineWorker()->create();
    $this->helper = User::factory()->frontlineWorker()->create();
    foreach ([$this->owner, $this->helper] as $worker) {
        HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null]);
        foreach (['shifts.tasks.updateSelf', 'shifts.viewAssigned', 'clients.viewAssigned'] as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'shifts', 'module' => 'Operations']);
            $worker->permissionOverrides()->attach($permission->id, ['allowed' => true]);
        }
    }
    $this->client = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
    $this->client->supportWorkers()->attach([$this->owner->id, $this->helper->id]);
    $this->shift = Shift::factory()->published()->create(['user_id' => $this->owner->id, 'client_id' => $this->client->id,
        'site_id' => $this->site->id, 'status' => 'in_progress', 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(3)]);
    $this->task = $this->shift->tasks()->create(['label' => 'Set up activity', 'client_id' => $this->client->id, 'task_scope' => 'client', 'is_completed' => false]);
    $this->requestHelp = function () {
        return $this->actingAs($this->owner)->putJson("/my-day/tasks/{$this->task->id}/help", ['recipient_id' => $this->helper->id,
            'reason' => 'Need another person to help set up.', 'expected_version' => $this->task->fresh()->version]);
    };
});

it('offers only eligible colleagues and records a retry as one request', function () {
    $this->actingAs($this->owner)->getJson("/my-day/tasks/{$this->task->id}/help-recipients")->assertOk()->assertJsonCount(1, 'recipients')->assertJsonPath('recipients.0.id', $this->helper->id);
    ($this->requestHelp)()->assertOk()->assertJsonPath('task.help.status', 'requested');
    $version = $this->task->fresh()->version;
    ($this->requestHelp)()->assertOk()->assertJsonPath('task.version', $version);
    expect(app(ShiftTaskHelpService::class)->inbox($this->helper))->toHaveCount(1);
    expect($this->task->fresh()->is_completed)->toBeFalse();
});

it('requires recipient acceptance before helper completion and retains an accepted task after shift end', function () {
    ($this->requestHelp)()->assertOk();
    $version = $this->task->fresh()->version;
    $this->actingAs($this->helper)->putJson("/my-day/tasks/{$this->task->id}/completion", ['is_completed' => true, 'expected_version' => $version])->assertForbidden();
    $this->putJson("/my-day/tasks/{$this->task->id}/help-response", ['response' => 'accepted', 'expected_version' => $version])->assertOk()->assertJsonPath('task.is_completed', false)->assertJsonPath('task.follow_through', 'accepted_help');
    $this->shift->update(['status' => 'completed', 'actual_starts_at' => $this->shift->starts_at, 'actual_ends_at' => now()]);
    expect(app(ShiftTaskHelpService::class)->inbox($this->helper))->toHaveCount(1);
    $this->putJson("/my-day/tasks/{$this->task->id}/completion", ['is_completed' => true, 'expected_version' => $this->task->fresh()->version])->assertOk();
    expect($this->task->fresh()->completed_by)->toBe($this->helper->id);
});

it('does not let the requester accept on behalf of their colleague', function () {
    ($this->requestHelp)()->assertOk();
    $this->putJson("/my-day/tasks/{$this->task->id}/help-response", ['response' => 'accepted', 'expected_version' => $this->task->fresh()->version])->assertForbidden();
});

it('rechecks recipient site permission privacy and cancelled shift at response time', function (string $revoke) {
    ($this->requestHelp)()->assertOk();
    if ($revoke === 'site') {
        $this->helper->hrEmployeeProfile->update(['primary_site_id' => Site::factory()->create()->id]);
    }
    if ($revoke === 'permission') {
        $this->helper->permissionOverrides()->updateExistingPivot(Permission::where('key', 'shifts.tasks.updateSelf')->value('id'), ['allowed' => false]);
    }
    if ($revoke === 'privacy') {
        $this->client->supportWorkers()->detach($this->helper->id);
    }
    if ($revoke === 'cancelled') {
        $this->shift->update(['status' => 'cancelled']);
    }
    $this->actingAs($this->helper)->putJson("/my-day/tasks/{$this->task->id}/help-response", ['response' => 'accepted', 'expected_version' => $this->task->fresh()->version])->assertForbidden();
    expect($this->task->fresh()->help_status)->toBe('requested');
})->with(['site', 'permission', 'privacy', 'cancelled']);

it('closes the actual attendance session with an accepted owner while retaining unfinished work', function () {
    $this->shift->update(['actual_starts_at' => $this->shift->starts_at, 'expected_break_minutes' => 0]);
    $session = HrAttendanceSession::create(['user_id' => $this->owner->id, 'shift_id' => $this->shift->id, 'site_id' => $this->site->id,
        'clock_in_at' => $this->shift->starts_at, 'status' => 'open', 'source' => 'web', 'created_by' => $this->owner->id]);
    ($this->requestHelp)()->assertOk();
    $this->actingAs($this->helper)->putJson("/my-day/tasks/{$this->task->id}/help-response", ['response' => 'accepted', 'expected_version' => $this->task->fresh()->version])->assertOk();
    ShiftHandover::create(['outgoing_shift_id' => $this->shift->id, 'client_id' => $this->client->id,
        'outgoing_staff_id' => $this->owner->id, 'status' => 'draft', 'handover_notes' => 'The named colleague has accepted the activity follow-up.']);
    $closed = app(AttendanceService::class)->clockOut($this->owner->fresh(), $session, ['break_minutes' => 0]);
    expect($closed->status)->toBe('closed')->and($this->task->fresh()->is_completed)->toBeFalse();
    $timesheet = Timesheet::where('shift_id', $this->shift->id)->firstOrFail();
    expect((float) $timesheet->total_hours)->toBe(2.0)->and($timesheet->status)->toBe('draft');
    expect(app(ShiftTaskHelpService::class)->inbox($this->helper->fresh()))->toHaveCount(1);
});

it('saves a new handover and multiple person task outcomes in the same clock-out transaction', function () {
    $this->shift->update(['actual_starts_at' => $this->shift->starts_at, 'expected_break_minutes' => 0]);
    $session = HrAttendanceSession::create(['user_id' => $this->owner->id, 'shift_id' => $this->shift->id, 'site_id' => $this->site->id,
        'clock_in_at' => $this->shift->starts_at, 'status' => 'open', 'source' => 'web', 'created_by' => $this->owner->id]);
    $second = Client::factory()->create(['site_id' => $this->site->id]);
    $second->supportWorkers()->attach($this->owner->id);
    $otherTask = $this->shift->tasks()->create(['label' => 'Other person activity', 'task_scope' => 'client', 'client_id' => $second->id, 'is_completed' => false]);
    $this->actingAs($this->owner)->post('/attendance/clock-out', ['session_id' => $session->id, 'break_minutes' => 0,
        'task_updates' => [['id' => $this->task->id, 'is_completed' => true, 'expected_version' => 0], ['id' => $otherTask->id, 'is_completed' => true, 'expected_version' => 0]],
        'handover' => ['meds_completed' => true, 'shift_rating' => 'calm', 'handover_notes' => 'Both activity tasks are finished.', 'follow_up_needed' => false],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($session->fresh()->status)->toBe('closed')->and($otherTask->fresh()->is_completed)->toBeTrue()->and($this->task->fresh()->is_completed)->toBeTrue();
    expect(ShiftHandover::where('outgoing_shift_id', $this->shift->id)->value('status'))->toBe('draft');
});

it('allows a new request when the previously accepted colleague loses eligibility', function () {
    ($this->requestHelp)()->assertOk();
    $this->actingAs($this->helper)->putJson("/my-day/tasks/{$this->task->id}/help-response", ['response' => 'accepted', 'expected_version' => $this->task->fresh()->version])->assertOk();
    $replacement = User::factory()->frontlineWorker()->create();
    HrEmployeeProfile::factory()->create(['user_id' => $replacement->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true]);
    $replacement->permissionOverrides()->attach(Permission::where('key', 'shifts.tasks.updateSelf')->firstOrFail()->id, ['allowed' => true]);
    $replacement->permissionOverrides()->attach(Permission::where('key', 'clients.viewAssigned')->firstOrFail()->id, ['allowed' => true]);
    $this->client->supportWorkers()->attach($replacement->id);
    $this->helper->hrEmployeeProfile->update(['is_active' => false]);
    $this->actingAs($this->owner)->putJson("/my-day/tasks/{$this->task->id}/help", ['recipient_id' => $replacement->id, 'reason' => 'Please follow up this activity.',
        'expected_version' => $this->task->fresh()->version])->assertOk()->assertJsonPath('task.help.status', 'requested')->assertJsonPath('task.help.recipient_id', $replacement->id);
});

it('counts unfinished work as a shift blocker until another eligible person accepts', function () {
    $session = HrAttendanceSession::create(['user_id' => $this->owner->id, 'shift_id' => $this->shift->id, 'site_id' => $this->site->id,
        'clock_in_at' => now()->subHours(2), 'status' => 'open', 'source' => 'web', 'created_by' => $this->owner->id]);
    ($this->requestHelp)()->assertOk();
    expect(array_column(app(AttendanceService::class)->getEndOfShiftBlockers($session->fresh()), 'key'))->toContain('tasks_pending');
    $this->actingAs($this->helper)->putJson("/my-day/tasks/{$this->task->id}/help-response", ['response' => 'accepted', 'expected_version' => $this->task->fresh()->version])->assertOk();
    $keys = array_column(app(AttendanceService::class)->getEndOfShiftBlockers($session->fresh()), 'key');
    expect($keys)->not->toContain('tasks_pending')->toContain('handover_missing');
    expect($this->task->fresh()->is_completed)->toBeFalse();
    $this->helper->hrEmployeeProfile->update(['is_active' => false]);
    expect(array_column(app(AttendanceService::class)->getEndOfShiftBlockers($session->fresh()), 'key'))->toContain('tasks_pending');
});
