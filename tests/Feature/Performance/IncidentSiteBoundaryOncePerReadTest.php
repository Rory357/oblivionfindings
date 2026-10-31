<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Incidents\IncidentAccessService;
use App\Services\Incidents\IncidentHsEventSourceReadService;
use App\Services\Incidents\IncidentOwnerEligibility;
use App\Services\Tasks\Providers\ClientIncidentProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

// The task count checked each incident's Site boundary by reading the viewer's staff profile and
// Sites again: ~900 queries for a manager on the .com (31 Oct 2026). Inside one read session those
// rows are held FOR SHARE, so they are now read once per viewer object per session. A new session
// reads them again. Real seeded roles, real incident provider.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->home = Site::factory()->create(['is_active' => true]);
    $this->away = Site::factory()->create(['is_active' => true]);
});

function boundaryUser(string $role, Site $site): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => $role, 'external_clinical_account' => false]);
    $user->roles()->sync([Role::query()->where('name', $role)->value('id')]);
    HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

    return $user->fresh();
}

function boundaryIncident(Site $site, ?Client $client = null): ClientIncident
{
    return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
        'site_id' => $site->id, 'client_id' => ($client ?? Client::factory()->create(['site_id' => $site->id]))->id,
        'status' => 'submitted', 'submitted_at' => now(), 'occurred_at' => now(), 'type' => 'injury',
        'incident_owner_user_id' => null, 'owner_revision' => 0,
    ]));
}

/** @return list<string> */
function boundaryTaskIds(User $viewer, ?TaskAggregator $aggregate = null): array
{
    $ids = array_map(fn (TaskItem $item) => $item->id, ($aggregate ?? new TaskAggregator([new ClientIncidentProvider]))->itemsFor($viewer));
    sort($ids);

    return $ids;
}

/** The incident register's own boundary, outside any read session (unchanged path). */
function boundaryExpected(User $viewer): array
{
    $ids = app(IncidentAccessService::class)->apply(ClientIncident::query(), $viewer->fresh())
        ->whereNotIn('status', ['closed'])->pluck('id')->map(fn ($id) => 'incident-'.$id)->all();
    sort($ids);

    return $ids;
}

it('gives every seeded role the same incident tasks as the incident boundary itself', function () {
    $assigned = Client::factory()->create(['site_id' => $this->home->id]);
    boundaryIncident($this->home, $assigned);
    boundaryIncident($this->home);
    boundaryIncident($this->home);
    boundaryIncident($this->away);
    boundaryIncident($this->away);

    $readers = 0;
    foreach (Role::query()->orderBy('name')->pluck('name') as $role) {
        $viewer = boundaryUser($role, $this->home);
        $assigned->supportWorkers()->attach($viewer->id);
        $reads = $viewer->canDo('incidents.viewAny') || $viewer->canDo('incidents.viewAssigned');
        $expected = $reads ? boundaryExpected($viewer) : [];

        expect(boundaryTaskIds($viewer))->toBe($expected, "role {$role}");
        $readers += (int) ($expected !== []);
    }
    expect($readers)->toBeGreaterThan(2);
});

it('reads the viewer\'s Sites once per read, however many incidents there are', function () {
    $viewer = boundaryUser('provider_manager', $this->home);
    $counting = false;
    $count = 0;
    DB::listen(function ($query) use (&$counting, &$count) {
        $count += (int) ($counting && str_starts_with($query->sql, 'select `id` from `sites`'));
    });
    $siteReads = function () use ($viewer, &$counting, &$count): array {
        [$counting, $count] = [true, 0];
        $ids = boundaryTaskIds($viewer);
        $counting = false;

        return [$count, $ids];
    };

    boundaryIncident($this->home);
    [$one, $ids] = $siteReads();
    expect($ids)->toHaveCount(1);

    foreach (range(1, 5) as $ignored) {
        boundaryIncident($this->home);
    }
    [$six, $ids] = $siteReads();
    expect($ids)->toHaveCount(6)
        ->and($six)->toBe($one)
        ->and($ids)->toBe(boundaryExpected($viewer));
});

it('reads the Sites again in the next read: a Site removed in between counts, with the same objects reused', function () {
    $viewer = boundaryUser('provider_manager', $this->home);
    $aggregate = new TaskAggregator([new ClientIncidentProvider]);
    $home = boundaryIncident($this->home);
    $away = boundaryIncident($this->away);

    expect(boundaryTaskIds($viewer, $aggregate))->toBe(['incident-'.$home->id]);

    DB::table('hr_employee_profiles')->where('user_id', $viewer->id)->update(['primary_site_id' => $this->away->id]);

    expect(boundaryTaskIds($viewer, $aggregate))->toBe(['incident-'.$away->id]);
});

it('keeps each person\'s Sites to themselves inside one read session', function () {
    $here = boundaryUser('provider_manager', $this->home);
    $there = boundaryUser('provider_manager', $this->away);
    $home = boundaryIncident($this->home);
    $away = boundaryIncident($this->away);
    $access = app(IncidentAccessService::class);

    $seen = DB::transaction(fn () => CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => [
        $access->apply(ClientIncident::query(), $here, $reads)->pluck('id')->all(),
        $access->apply(ClientIncident::query(), $there, $reads)->pluck('id')->all(),
        $access->apply(ClientIncident::query(), $here, $reads)->pluck('id')->all(),
        $access->apply(ClientIncident::query(), $there->fresh(), $reads)->pluck('id')->all(),
    ]));

    expect($seen)->toBe([[$home->id], [$away->id], [$home->id], [$away->id]]);
});

it('checks once per page-wide read that an incident owner is still active, with the same answers', function () {
    $manager = boundaryUser('provider_manager', $this->home);
    $owner = boundaryUser('provider_manager', $this->home);
    $incidents = collect(range(1, 4))->map(fn () => boundaryIncident($this->home));
    $eligibility = app(IncidentOwnerEligibility::class);
    [$counting, $count] = [false, 0];
    DB::listen(function ($query) use (&$counting, &$count) {
        $count += (int) ($counting && str_contains($query->sql, 'from `users`') && str_contains($query->sql, '`approved_at` is not null'));
    });
    $ask = function (bool $pageWide) use ($manager, $owner, $incidents, $eligibility, &$counting, &$count): array {
        [$counting, $count] = [true, 0];
        $answers = DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($pageWide, $manager, $owner, $incidents, $eligibility) {
            if ($pageWide) {
                IncidentHsEventSourceReadService::provePageWide($reads);
            }

            return $incidents->map(fn (ClientIncident $incident) => [
                $eligibility->canManage($manager, $incident, $reads),
                $eligibility->canOwn($owner, $incident, $reads),
            ])->all();
        }));
        $counting = false;

        return [$count, $answers];
    };

    [$everyCall, $fresh] = $ask(false);
    [$pageWide, $remembered] = $ask(true);

    expect($fresh)->toBe(array_fill(0, 4, [true, true]))
        ->and($remembered)->toBe($fresh)
        ->and($everyCall)->toBe(8)   // two people x four incidents: each call decides afresh
        ->and($pageWide)->toBe(2);   // once per person in a page-wide read
});

it('remembers a fact for one exact object and one session only', function () {
    $person = User::factory()->create();
    $samePersonOtherCopy = User::query()->findOrFail($person->id);
    $calls = 0;
    $read = function () use (&$calls): int {
        return ++$calls;
    };

    $session = DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($person, $samePersonOtherCopy, $read) {
        expect($reads->rememberFor($person, 'fact', $read))->toBe(1)
            ->and($reads->rememberFor($person, 'fact', $read))->toBe(1)
            ->and($reads->rememberFor($samePersonOtherCopy, 'fact', $read))->toBe(2)
            ->and($reads->rememberFor($person, 'another fact', $read))->toBe(3);

        return $reads;
    }));

    expect(fn () => $session->rememberFor($person, 'fact', $read))->toThrow(LogicException::class);
    expect($calls)->toBe(3);
});
