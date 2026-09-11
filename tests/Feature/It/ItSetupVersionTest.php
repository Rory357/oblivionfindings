<?php

use App\Domain\It\Services\ItServiceManagementSetupService;
use App\Models\AuditLog;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->actor->roles()->sync([Role::where('name', 'admin')->firstOrFail()->id]);
    $this->setup = app(ItServiceManagementSetupService::class);
});

test('setup review returns only the requested current canonical register without an Inertia version', function (string $resource) {
    $record = match ($resource) {
        'teams' => ItTeam::factory()->create(['manager_user_id' => null]),
        'queues' => ItQueue::factory()->create(),
        'services' => ItService::factory()->create(['owner_user_id' => null]),
    };
    $response = $this->actingAs($this->actor)->getJson('/it/setup?review_resource='.$resource)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('resource', $resource)
        ->assertJsonMissingPath('props')->assertJsonMissingPath('apiIdentities')
        ->assertJsonMissingPath('automation')->assertJsonMissingPath('sso');
    expect(array_keys($response->json()))->toBe(['resource', 'viewer_user_id', 'records']);
    $row = collect($response->json('records'))->firstWhere('id', $record->id);
    expect($row)->not->toBeNull()
        ->and($row['configuration_version'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($row['name'])->toBe($record->name);
    $record->update(['name' => 'Newer current review name']);
    $current = $this->getJson('/it/setup?review_resource='.$resource)->assertOk();
    $currentRow = collect($current->json('records'))->firstWhere('id', $record->id);
    expect($currentRow['name'])->toBe('Newer current review name')
        ->and($currentRow['configuration_version'])->not->toBe($row['configuration_version']);
})->with(['teams', 'queues', 'services']);

test('setup review rejects unsupported resources and current restricted access', function () {
    $this->actingAs($this->actor)->getJson('/it/setup?review_resource=apiIdentities')
        ->assertUnprocessable()->assertJsonValidationErrors('review_resource');
    $permission = Permission::where('key', 'it.manage')->firstOrFail();
    $this->actor->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    $this->actingAs($this->actor->fresh())->getJson('/it/setup?review_resource=teams')->assertForbidden();
});

test('team editors cannot overwrite a newer member role and must explicitly review a new version', function () {
    $team = ItTeam::factory()->create(['manager_user_id' => null]);
    $team->members()->attach($this->actor->id, ['role' => 'member']);
    $original = $this->setup->teamVersion($team->fresh());
    // Membership is canonical configuration even when the team's own timestamps do not change.
    $team->members()->updateExistingPivot($this->actor->id, ['role' => 'lead']);
    $current = $this->setup->teamVersion($team->fresh());
    expect($current)->not->toBe($original);
    $auditBefore = AuditLog::where('action', 'it.setup.team.updated')->count();
    $this->actingAs($this->actor)->patchJson("/it/setup/teams/{$team->id}", [
        'name' => 'Outdated editor name', 'configuration_version' => $original,
    ])->assertUnprocessable()->assertJsonValidationErrors('configuration_version');
    expect($team->fresh()->name)->not->toBe('Outdated editor name')
        ->and(AuditLog::where('action', 'it.setup.team.updated')->count())->toBe($auditBefore);

    $this->actingAs($this->actor)->patch("/it/setup/teams/{$team->id}", [
        'name' => 'Reviewed team name', 'configuration_version' => $current,
    ])->assertRedirect()->assertSessionDoesntHaveErrors();
    expect($team->fresh()->name)->toBe('Reviewed team name')
        ->and($team->members()->first()->pivot->role)->toBe('lead')
        ->and(AuditLog::where('action', 'it.setup.team.updated')->count())->toBe($auditBefore + 1);
});

test('service editor rejects an obsolete version and preserves the newer status on an explicit retry', function () {
    $service = ItService::factory()->create(['owner_user_id' => null, 'status' => 'operational']);
    $version = $this->setup->serviceVersion($service);
    $this->actingAs($this->actor)->patch("/it/setup/services/{$service->id}", [
        'status' => 'degraded', 'configuration_version' => $version,
    ])->assertRedirect()->assertSessionDoesntHaveErrors();
    $this->actingAs($this->actor)->patchJson("/it/setup/services/{$service->id}", [
        'name' => 'Stale name', 'configuration_version' => $version,
    ])->assertUnprocessable()->assertJsonValidationErrors('configuration_version');
    expect($service->fresh()->status)->toBe('degraded')->and($service->fresh()->name)->not->toBe('Stale name');
    $this->actingAs($this->actor)->patch("/it/setup/services/{$service->id}", [
        'name' => 'Reviewed service name', 'configuration_version' => $this->setup->serviceVersion($service->fresh()),
    ])->assertRedirect()->assertSessionDoesntHaveErrors();
    expect($service->fresh()->status)->toBe('degraded')->and($service->fresh()->name)->toBe('Reviewed service name');
});

test('team and service update endpoints require the displayed version', function () {
    $team = ItTeam::factory()->create();
    $service = ItService::factory()->create();
    foreach (["/it/setup/teams/{$team->id}", "/it/setup/services/{$service->id}"] as $url) {
        $this->actingAs($this->actor)->patchJson($url, ['name' => 'Unversioned change'])
            ->assertUnprocessable()->assertJsonValidationErrors('configuration_version');
    }
});

test('canonical setup mutation rechecks a cached actor permission and approval before writing', function () {
    $team = ItTeam::factory()->create();
    $service = ItService::factory()->create();
    $this->actor->load(['roles.permissions', 'permissionOverrides']);
    expect($this->actor->canDo('it.manage'))->toBeTrue();
    $permission = Permission::where('key', 'it.manage')->firstOrFail();
    $this->actor->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    expect(fn () => $this->setup->updateTeam($team, $this->actor, [
        'name' => 'Denied edit', 'configuration_version' => $this->setup->teamVersion($team),
    ]))->toThrow(DomainException::class);
    $this->actor->permissionOverrides()->detach($permission->id);
    User::whereKey($this->actor->id)->update(['approved_at' => null]);
    expect(fn () => $this->setup->updateService($service, $this->actor, [
        'name' => 'Denied edit', 'configuration_version' => $this->setup->serviceVersion($service),
    ]))->toThrow(DomainException::class);
    expect($team->fresh()->name)->not->toBe('Denied edit')->and($service->fresh()->name)->not->toBe('Denied edit');
});

test('canonical setup service cannot bypass the version check by omitting transport validation', function () {
    $team = ItTeam::factory()->create();
    $service = ItService::factory()->create();
    expect(fn () => $this->setup->updateTeam($team, $this->actor, ['name' => 'Bypass']))->toThrow(ValidationException::class)
        ->and(fn () => $this->setup->updateService($service, $this->actor, ['name' => 'Bypass']))->toThrow(ValidationException::class);
});

test('a failed required audit leaves the service configuration and version unchanged', function () {
    $service = ItService::factory()->create(['owner_user_id' => null]);
    $originalName = $service->name;
    $version = $this->setup->serviceVersion($service);
    $fail = true;
    AuditLog::creating(function (AuditLog $audit) use (&$fail): void {
        if ($fail && $audit->action === 'it.setup.service.updated') {
            throw new RuntimeException('Isolated setup audit failure');
        }
    });
    try {
        expect(fn () => $this->setup->updateService($service, $this->actor, [
            'name' => 'Must roll back', 'configuration_version' => $version,
        ]))->toThrow(RuntimeException::class, 'Isolated setup audit failure');
    } finally {
        $fail = false;
    }
    expect($service->fresh()->name)->toBe($originalName)
        ->and($this->setup->serviceVersion($service->fresh()))->toBe($version);
});
