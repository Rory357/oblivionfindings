<?php

use App\Domain\CompanyHub\Services\HubCommunity;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Current ideas team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $this->privateTeam = HrDepartment::create(['name' => 'Private other site team', 'is_active' => true]);
    $this->privateTeam->sites()->attach($this->outside->id);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->other = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->other->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->other, $this->site);
    $this->payload = ['title' => 'A practical shared idea', 'body' => 'A concrete improvement for everyday work.',
        'category' => 'Better ways of working', 'department_id' => $this->team->id, 'actor_id' => $this->reader->id];
    $this->actingAs($this->reader);
});

test('JSON idea creation acknowledges the inserted identity and authenticated actor despite duplicate titles', function () {
    $existing = app(HubCommunity::class)->createIdea($this->other, [...$this->payload, 'actor_id' => $this->other->id]);
    $response = $this->postJson('/company-hub/ideas', $this->payload)->assertOk();
    $created = DB::table('hub_ideas')->where('user_id', $this->reader->id)->sole();
    $id = (int) $created->id;
    $response->assertExactJson(['saved' => true, 'actor_id' => $this->reader->id, 'idea_id' => $id]);
    expect($id)->not->toBe($existing)->toBeGreaterThan(0)
        ->and((int) $created->department_id)->toBe($this->team->id)
        ->and((int) $created->version)->toBe(1)->and($created->status)->toBe('open');
    $this->assertDatabaseCount('hub_ideas', 2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'company_hub.idea_created', 'user_id' => $this->reader->id, 'auditable_id' => $id]);
    $this->get('/company-hub?view=ideas')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('ideaActorId', $this->reader->id)
        ->where('ideas', fn ($ideas) => collect($ideas)->contains(fn ($idea) => $idea['id'] === $id && $idea['body'] === $this->payload['body'])));
    Notification::assertNothingSent();
});

test('idea creation identity provenance site and current staff denials cannot return a saved acknowledgement', function () {
    $this->actingAs($this->other)->postJson('/company-hub/ideas', $this->payload)->assertConflict()->assertJsonMissingPath('saved');
    $this->actingAs($this->reader);
    foreach (['user_id', 'author_id', 'hub_idea_id', 'created_at', 'owner_id', 'status'] as $field) {
        $value = match ($field) {
            'created_at' => '2000-01-01T00:00:00Z',
            'status' => 'delivered',
            default => $this->other->id,
        };
        $this->postJson('/company-hub/ideas', [...$this->payload, $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonMissingPath('saved');
    }
    $this->postJson('/company-hub/ideas', [...$this->payload, 'department_id' => $this->privateTeam->id])
        ->assertUnprocessable()->assertJsonValidationErrors('department_id')->assertJsonMissingPath('saved');
    $this->team->update(['is_active' => false]);
    $this->postJson('/company-hub/ideas', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('department_id')->assertJsonMissingPath('saved');
    $this->team->update(['is_active' => true]);
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->postJson('/company-hub/ideas', $this->payload)->assertForbidden()->assertJsonMissingPath('saved');
    $this->reader->hrEmployeeProfile()->update(['is_active' => true]);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole(), ['allowed' => false]);
    $this->actingAs($this->reader->fresh())->postJson('/company-hub/ideas', $this->payload)->assertForbidden()->assertJsonMissingPath('saved');
    $this->assertDatabaseCount('hub_ideas', 0);
    expect(DB::table('audit_logs')->where('action', 'company_hub.idea_created')->count())->toBe(0);
    Notification::assertNothingSent();
});

test('Inertia JSON and legacy form idea creation retain the existing redirect and success flash', function () {
    $return = '/company-hub?view=ideas';
    $this->from($return)->withHeader('X-Inertia', 'true')->postJson('/company-hub/ideas', $this->payload)
        ->assertRedirect($return)->assertSessionHasNoErrors()->assertSessionHas('success', 'Your idea is on the board.');
    $this->flushHeaders()->from($return)->post('/company-hub/ideas', array_diff_key($this->payload, ['actor_id' => true]))
        ->assertRedirect($return)->assertSessionHasNoErrors()->assertSessionHas('success', 'Your idea is on the board.');
    $this->assertDatabaseCount('hub_ideas', 2);
    expect(DB::table('hub_ideas')->where('user_id', $this->reader->id)->count())->toBe(2);
    Notification::assertNothingSent();
});

test('failed transactional idea creation cannot acknowledge or retain a partially inserted record', function () {
    $listener = 'eloquent.creating: '.AuditLog::class;
    Event::listen($listener, function (AuditLog $audit) {
        if ($audit->action === 'company_hub.idea_created') {
            throw new RuntimeException('Simulated idea audit failure');
        }
    });
    try {
        $this->withoutExceptionHandling();
        expect(fn () => $this->postJson('/company-hub/ideas', $this->payload))
            ->toThrow(RuntimeException::class, 'Simulated idea audit failure');
    } finally {
        Event::forget($listener);
    }
    $this->assertDatabaseCount('hub_ideas', 0);
    expect(DB::table('audit_logs')->where('action', 'company_hub.idea_created')->count())->toBe(0);
    Notification::assertNothingSent();
});
