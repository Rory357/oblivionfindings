<?php

use App\Models\PersonalCalendarEntry;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->actingAs($this->worker);
    $this->entryData = [
        'request_id' => (string) Str::uuid(), 'kind' => 'task', 'title' => 'Prepare my notes',
        'start_at' => '2026-09-15T09:00:00+12:00', 'end_at' => '2026-09-15T10:00:00+12:00',
        'all_day' => false, 'status' => 'scheduled',
    ];
});

test('staff can create each planning kind and see only their entries in their feed', function (string $kind) {
    $entry = $this->postJson('/my-calendar/entries', [...$this->entryData, 'kind' => $kind, 'user_id' => 999])
        ->assertCreated()->assertJsonPath('entry.version', 1)->assertJsonPath('entry.start_at', '2026-09-14T21:00:00+00:00')->json('entry');
    expect(PersonalCalendarEntry::findOrFail($entry['id'])->user_id)->toBe($this->worker->id);
    $url = '/my-calendar/events?start=2026-09-14T00:00:00Z&end=2026-09-16T00:00:00Z';
    $this->getJson($url)->assertOk()->assertJsonCount(1)->assertJsonFragment(['id' => 'personal-'.$entry['id']]);
    $this->actingAs(User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]));
    $this->getJson($url)->assertOk()->assertJsonMissing(['id' => 'personal-'.$entry['id']]);
})->with(['task', 'meeting', 'appointment', 'reminder']);

test('another worker and an admin cannot read change delete or restore an owned entry', function (string $role) {
    $id = $this->postJson('/my-calendar/entries', $this->entryData)->assertCreated()->json('entry.id');
    $this->actingAs(User::factory()->create(['role' => $role, 'approved_at' => now()]));
    $this->getJson('/my-calendar/entries/'.$id)->assertNotFound();
    $this->putJson('/my-calendar/entries/'.$id, ['version' => 1, 'title' => 'Changed'])->assertNotFound();
    $this->deleteJson('/my-calendar/entries/'.$id, ['version' => 1])->assertNotFound();
    $this->postJson('/my-calendar/entries/'.$id.'/restore', ['version' => 1])->assertNotFound();
})->with(['support_worker', 'admin']);

test('unapproved staff and portal users cannot create entries', function (string $role, bool $approved) {
    $this->actingAs(User::factory()->create(['role' => $role, 'approved_at' => $approved ? now() : null]));
    $response = $this->postJson('/my-calendar/entries', $this->entryData);
    $approved ? $response->assertForbidden() : $response->assertRedirect();
    expect(PersonalCalendarEntry::count())->toBe(0);
})->with([['support_worker', false], ['client', true], ['next_of_kin', true]]);

test('create retries do not duplicate entries or reuse a receipt with changed data', function () {
    $id = $this->postJson('/my-calendar/entries', $this->entryData)->assertCreated()->json('entry.id');
    $this->postJson('/my-calendar/entries', $this->entryData)->assertOk()->assertJsonPath('entry.id', $id);
    $this->postJson('/my-calendar/entries', [...$this->entryData, 'title' => 'Different'])->assertConflict();
    expect(PersonalCalendarEntry::where('user_id', $this->worker->id)->count())->toBe(1);
});

test('date validation prevents ambiguous timestamps and backwards ranges including partial updates', function () {
    $this->postJson('/my-calendar/entries', [...$this->entryData, 'start_at' => '2026-09-15T09:00'])->assertUnprocessable();
    $this->postJson('/my-calendar/entries', [...$this->entryData, 'end_at' => $this->entryData['start_at']])->assertUnprocessable();
    $id = $this->postJson('/my-calendar/entries', $this->entryData)->assertCreated()->json('entry.id');
    $this->putJson('/my-calendar/entries/'.$id, ['version' => 1, 'start_at' => '2026-09-16T09:00:00+12:00'])->assertUnprocessable();
});

test('updates use versions and deletion has a version protected undo', function () {
    $id = $this->postJson('/my-calendar/entries', $this->entryData)->assertCreated()->json('entry.id');
    $url = '/my-calendar/entries/'.$id;
    $this->putJson($url, ['version' => 1, 'status' => 'completed'])->assertOk()->assertJsonPath('entry.version', 2);
    $this->putJson($url, ['version' => 1, 'status' => 'cancelled'])->assertConflict();
    $this->deleteJson($url, ['version' => 1])->assertConflict();
    $this->deleteJson($url, ['version' => 2])->assertOk()->assertJsonPath('entry.version', 3);
    $this->getJson($url)->assertNotFound();
    $this->postJson($url.'/restore', ['version' => 2])->assertConflict();
    $this->postJson($url.'/restore', ['version' => 3])->assertOk()->assertJsonPath('entry.version', 4);
    $this->getJson($url)->assertOk()->assertJsonPath('entry.status', 'completed');
    $this->postJson('/my-calendar/entries', $this->entryData)->assertOk()->assertJsonPath('entry.id', $id);
});
