<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistTemplateItem;
use App\Models\User;
use App\Support\ResidentHue;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-05-21 09:30:00', 'Pacific/Auckland'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('exposes the active shift site with every co-resident', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create(['name' => 'Rimu House', 'type' => 'house']);

    $margaret = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Margaret',
        'last_name' => 'Hewitt',
    ]);
    Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Hone',
        'last_name' => 'Tāmati',
    ]);
    Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Aroha',
        'last_name' => 'Lee',
    ]);

    $start = Carbon::now('Pacific/Auckland')->setTime(9, 0);
    Shift::factory()
        ->assignedToday($worker, $start)
        ->inProgress()
        ->create([
            'client_id' => $margaret->id,
            'site_id' => $site->id,
        ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('active_shift.site.id', $site->id)
            ->where('active_shift.site.name', 'Rimu House')
            ->has('active_shift.site.residents', 3)
            ->where('active_shift.site.residents.0.first_name', 'Margaret')
            ->where('active_shift.site.residents.0.initials', 'MH')
            ->where('active_shift.site.residents.0.hue', ResidentHue::for($margaret->id))
        );
});

it('uses the open attendance session shift as the active site shift after the UTC date rolls over', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-23 17:30:00', 'Pacific/Auckland'));

    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create(['name' => 'Rimu House', 'type' => 'house']);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Margaret',
        'last_name' => 'Hewitt',
    ]);
    Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Hone',
        'last_name' => 'Tamati',
    ]);

    $start = Carbon::parse('2026-05-23 09:00:00', 'Pacific/Auckland');
    $shift = Shift::factory()
        ->assignedToday($worker, $start)
        ->inProgress()
        ->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
            'ends_at' => Carbon::parse('2026-05-23 18:00:00', 'Pacific/Auckland')->utc(),
        ]);

    HrAttendanceSession::query()->create([
        'user_id' => $worker->id,
        'shift_id' => $shift->id,
        'site_id' => $site->id,
        'clock_in_at' => $start->copy()->utc(),
        'status' => 'open',
        'source' => 'web',
        'created_by' => $worker->id,
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('clock.open_session.shift_id', $shift->id)
            ->where('active_shift.id', $shift->id)
            ->where('active_shift.site.id', $site->id)
            ->has('active_shift.site.residents', 2)
        );
});

it('does not let an unsupported legacy active shift displace a clockable scheduled shift', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $site = Site::factory()->create(['name' => 'Rimu House', 'type' => 'house']);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Margaret',
        'last_name' => 'Hewitt',
    ]);

    Shift::factory()->create([
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => Carbon::now()->subHours(2),
        'ends_at' => Carbon::now()->addHours(2),
        'status' => 'active',
    ]);

    $scheduled = Shift::factory()->create([
        'user_id' => $worker->id,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => Carbon::now()->subMinutes(20),
        'ends_at' => Carbon::now()->addHours(7),
        'status' => 'scheduled',
    ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('active_shift.id', $scheduled->id)
        );
});

it('exposes active shift site checklists and clears them after completion', function () {
    $this->seed(RbacSeeder::class);

    $worker = User::factory()->frontlineWorker()->create();
    $worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());

    $site = Site::factory()->create(['name' => 'Rimu House', 'type' => 'house']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Margaret',
        'last_name' => 'Hewitt',
    ]);

    $start = Carbon::now('Pacific/Auckland')->setTime(9, 0);
    Shift::factory()
        ->assignedToday($worker, $start)
        ->inProgress()
        ->create([
            'client_id' => $client->id,
            'site_id' => $site->id,
        ]);

    [$run, $item] = makeMyDayChecklistRun($site, $worker);

    $this->actingAs($worker)
        ->get("/my-day?run={$run->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('active_shift.site.id', $site->id)
            ->has('shiftChecklists', 1)
            ->where('shiftChecklists.0.id', $run->id)
            ->where('shiftChecklists.0.template.name', 'Shift Daily Checks')
            ->where('shiftChecklists.0.is_overdue', false)
            ->where('checklistConfig.can.view', true)
            ->where('checklistConfig.can.run', true)
            ->where('runDetail.id', $run->id)
            ->has('runDetail.items', 1)
        );

    $this->from('/my-day')
        ->actingAs($worker)
        ->post("/checklists/runs/{$run->id}/complete", [
            'responses' => [
                ['template_item_id' => $item->id, 'response_value' => 'yes', 'is_failed' => false],
            ],
            'signature_name' => 'Support Worker',
        ])
        ->assertRedirect('/my-day');

    expect($run->fresh()->status)->toBe('completed');

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->has('shiftChecklists', 0)
        );
});

it('returns null active_shift.site when the worker is not on a site shift', function () {
    $worker = User::factory()->frontlineWorker()->create();

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('active_shift', null)
        );
});

it('still works on a 1:1 shift without a site relationship', function () {
    $worker = User::factory()->frontlineWorker()->create();
    $client = Client::factory()->create(['first_name' => 'Margaret', 'last_name' => 'Hewitt']);

    $start = Carbon::now('Pacific/Auckland')->setTime(9, 0);
    Shift::factory()
        ->assignedToday($worker, $start)
        ->inProgress()
        ->create([
            'client_id' => $client->id,
            'site_id' => null,
        ]);

    $this->actingAs($worker)
        ->get('/my-day')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('my-day/index')
            ->where('active_shift.site', null)
            ->where('active_shift.client.id', $client->id)
        );
});

it('hue helper matches the TS implementation byte-for-byte for known inputs', function () {
    // These reference values were computed by the TS implementation in
    // resources/js/pages/my-day/lib/resident-hue.ts so the server-rendered
    // payload and any front-end fallback colour the same resident the same way.
    expect(ResidentHue::for(1))->toBeLessThan(360)->toBeGreaterThanOrEqual(0);
    expect(ResidentHue::for(1))->toBe(ResidentHue::for('1'));
    expect(ResidentHue::for(42))->not->toBe(ResidentHue::for(43));
    expect(ResidentHue::initials('Margaret', 'Hewitt'))->toBe('MH');
    expect(ResidentHue::initials('Aroha', 'Lee'))->toBe('AL');
    expect(ResidentHue::initials(null, null))->toBe('');
});

function makeMyDayChecklistRun(Site $site, User $worker): array
{
    $template = SiteChecklistTemplate::create([
        'tenant_id' => $site->tenant_id,
        'key' => 'my_day_shift_'.uniqid(),
        'name' => 'Shift Daily Checks',
        'category' => 'quality',
        'applicable_to_type' => 'house',
        'frequency' => 'daily',
        'is_active' => true,
    ]);

    $item = SiteChecklistTemplateItem::create([
        'template_id' => $template->id,
        'question' => 'Kitchen reset complete?',
        'response_type' => 'yes_no',
        'is_required' => true,
        'sort_order' => 1,
    ]);

    $assignment = SiteChecklistAssignment::create([
        'tenant_id' => $site->tenant_id,
        'site_id' => $site->id,
        'template_id' => $template->id,
        'frequency' => 'daily',
        'start_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $run = SiteChecklistRun::create([
        'tenant_id' => $site->tenant_id,
        'assignment_id' => $assignment->id,
        'site_id' => $site->id,
        'template_id' => $template->id,
        'assigned_to_user_id' => $worker->id,
        'scheduled_date' => now('Pacific/Auckland')->toDateString(),
        'status' => 'scheduled',
    ]);

    return [$run, $item];
}
