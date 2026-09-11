<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItServiceManagementSetupService;
use App\Models\AuditLog;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->actor->roles()->sync(Role::where('name', 'hr')->pluck('id'));
    ensureCanonicalHrStaffProfile($this->actor, $this->site);
    $this->setup = app(ItServiceManagementSetupService::class);
    $this->candidate = [
        'actor_user_id' => $this->actor->id,
        'resource' => 'teams', 'record_id' => null,
        'context_uuid' => (string) Str::uuid(), 'candidate_uuid' => (string) Str::uuid(),
        'configuration_version' => null, 'step_index' => 0,
        'base_fields' => [], 'fields' => ['name' => '', 'description' => 'Private unfinished text'],
        'bound_scopes' => [],
    ];
});

test('setup recovery authorizes incomplete whole-entity candidates without a record write or private echo', function (string $resource) {
    $candidate = [...$this->candidate, 'resource' => $resource];
    if ($resource === 'queues') {
        // Missing accountability is submission validation, not lost access.
        $candidate['fields'] += ['is_active' => true, 'is_default' => true, 'team_id' => null, 'cover_user_id' => null];
    }
    $counts = [ItTeam::count(), ItService::count(), ItQueue::count(), AuditLog::count()];
    $response = $this->actingAs($this->actor)->postJson('/it/setup/validate-candidate', $candidate)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('candidate.candidate_uuid', $candidate['candidate_uuid'])
        ->assertJsonPath('candidate.context_uuid', $candidate['context_uuid'])
        ->assertJsonPath('candidate.actor_user_id', $this->actor->id)
        ->assertJsonPath('candidate.resource', $resource)
        ->assertJsonPath('candidate.authorized', true)
        ->assertJsonPath('candidate.current_configuration_version', null)
        ->assertJsonMissingPath('fields')->assertJsonMissingPath('candidate.fields')
        ->assertJsonMissingPath('records');
    expect($response->getContent())->not->toContain('Private unfinished text')
        ->and([ItTeam::count(), ItService::count(), ItQueue::count(), AuditLog::count()])->toBe($counts);
})->with(['teams', 'services', 'queues']);

test('setup recovery preserves the original stale configuration and requires explicit current review', function (string $resource) {
    $record = match ($resource) {
        'teams' => ItTeam::factory()->create(['manager_user_id' => null]),
        'services' => ItService::factory()->create(['owner_user_id' => null]),
        'queues' => ItQueue::factory()->create(),
    };
    $method = ['teams' => 'teamVersion', 'services' => 'serviceVersion', 'queues' => 'queueVersion'][$resource];
    $original = $this->setup->$method($record);
    $record->update(['description' => 'Other editor change']);
    $current = $this->setup->$method($record->fresh());
    $candidate = [...$this->candidate, 'resource' => $resource, 'record_id' => $record->id, 'configuration_version' => $original];
    $this->actingAs($this->actor)->postJson('/it/setup/validate-candidate', $candidate)
        ->assertOk()->assertJsonPath('candidate.configuration_version', $original)
        ->assertJsonPath('candidate.current_configuration_version', $current)
        ->assertJsonPath('candidate.capabilities.submit', false)
        ->assertJsonPath('candidate.blocker', 'configuration_changed');
    expect($this->setup->$method($record->fresh()))->toBe($current);
})->with(['teams', 'services', 'queues']);

test('setup recovery checks cleared historical bindings and current Site access', function () {
    $otherSite = Site::factory()->create();
    $candidate = [...$this->candidate, 'resource' => 'queues', 'bound_scopes' => [['site_ids' => [$otherSite->id]]]];
    $this->actingAs($this->actor)->postJson('/it/setup/validate-candidate', $candidate)->assertForbidden();
    $candidate['bound_scopes'] = [['site_ids' => [$this->site->id]]];
    $this->postJson('/it/setup/validate-candidate', $candidate)->assertOk();
    $this->site->update(['is_active' => false]);
    $this->postJson('/it/setup/validate-candidate', $candidate)->assertForbidden();
});

test('setup recovery rechecks former member employment even after the proposed selection is cleared', function () {
    $candidate = [...$this->candidate, 'bound_scopes' => [['user_ids' => [$this->actor->id]]]];
    $this->actingAs($this->actor)->postJson('/it/setup/validate-candidate', $candidate)->assertOk();
    HrEmployeeProfile::where('user_id', $this->actor->id)->update(['is_active' => false]);
    $this->postJson('/it/setup/validate-candidate', $candidate)->assertForbidden();
});

test('setup recovery permits repeated bindings across historical scopes but rejects duplicates within one selection', function () {
    $candidate = [...$this->candidate, 'bound_scopes' => [
        ['user_ids' => [$this->actor->id], 'site_ids' => []],
        ['user_ids' => [$this->actor->id], 'site_ids' => [$this->site->id]],
    ]];
    $this->actingAs($this->actor)->postJson('/it/setup/validate-candidate', $candidate)->assertOk();
    $candidate['bound_scopes'][0]['user_ids'][] = $this->actor->id;
    $this->postJson('/it/setup/validate-candidate', $candidate)->assertUnprocessable()
        ->assertJsonValidationErrors('bound_scopes.0.user_ids.0');
});

test('setup candidate and current review refuse a changed account and freshly revoked authorization', function () {
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->sync(Role::where('name', 'hr')->pluck('id'));
    $this->actingAs($other)->postJson('/it/setup/validate-candidate', $this->candidate)->assertForbidden();
    $this->getJson('/it/setup?review_resource=teams&actor_user_id='.$this->actor->id)->assertForbidden();
    $this->actingAs($this->actor)->getJson('/it/setup?review_resource=teams&actor_user_id='.$this->actor->id)
        ->assertOk()->assertJsonPath('viewer_user_id', $this->actor->id);
    $this->actor->load(['roles.permissions', 'permissionOverrides']);
    $permission = Permission::where('key', 'it.manage')->firstOrFail();
    $this->actor->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    expect(fn () => $this->setup->authorizeCandidate($this->actor, $this->candidate))->toThrow(AuthorizationException::class);
    $this->actingAs($this->actor->fresh())->postJson('/it/setup/validate-candidate', $this->candidate)->assertForbidden();
});

test('setup candidate rejects unknown fields malformed identities and missing canonical records', function () {
    $this->actingAs($this->actor)->postJson('/it/setup/validate-candidate', [
        ...$this->candidate, 'fields' => ['name' => 'Work', 'secret_payload' => 'unsupported'],
    ])->assertUnprocessable()->assertJsonValidationErrors('fields');
    $this->postJson('/it/setup/validate-candidate', [...$this->candidate, 'candidate_uuid' => 'not-an-identity'])
        ->assertUnprocessable()->assertJsonValidationErrors('candidate_uuid');
    $this->postJson('/it/setup/validate-candidate', [
        ...$this->candidate, 'record_id' => 99999999, 'configuration_version' => str_repeat('a', 64),
    ])->assertNotFound();
    $this->postJson('/it/setup/validate-candidate', [...$this->candidate, 'bound_scopes' => [['team_ids' => [99999999]]]])
        ->assertForbidden();
});

test('setup save endpoints bind the original actor for both create and edits', function () {
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->sync(Role::where('name', 'hr')->pluck('id'));
    $this->actingAs($other);
    foreach (['teams' => ItTeam::factory()->create(), 'queues' => ItQueue::factory()->create(), 'services' => ItService::factory()->create()] as $resource => $record) {
        $this->postJson('/it/setup/'.$resource, ['actor_user_id' => $this->actor->id])->assertForbidden();
        $this->patchJson('/it/setup/'.$resource.'/'.$record->id, ['actor_user_id' => $this->actor->id])->assertForbidden();
    }
});
