<?php

use App\Domain\It\Services\ItServiceManagementSetupService;
use App\Domain\It\Services\ItSetupCommandService;
use App\Models\AuditLog;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItSetupCommandReceipt;
use App\Models\ItTeam;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->actor->roles()->sync(Role::where('name', 'admin')->pluck('id'));
    $this->actingAs($this->actor);
    $this->uuid = (string) Str::uuid();
});

function setupCommandPayload(User $actor, string $uuid, string $resource): array
{
    return [
        'actor_user_id' => $actor->id, 'request_uuid' => $uuid,
        'name' => 'Isolated canonical setup command', 'description' => 'Private setup proposal', 'is_active' => false,
        ...($resource !== 'teams' ? ['key' => 'isolated-command-'.substr($uuid, 0, 8)] : []),
        ...($resource === 'services' ? ['status' => 'operational', 'criticality' => 'medium'] : []),
    ];
}

test('setup create commands replay exactly once without repeating configuration or audits', function (string $resource) {
    $payload = setupCommandPayload($this->actor, $this->uuid, $resource);
    $model = ['teams' => ItTeam::class, 'queues' => ItQueue::class, 'services' => ItService::class][$resource];
    $action = 'it.setup.'.['teams' => 'team', 'queues' => 'queue', 'services' => 'service'][$resource].'.created';
    $before = $model::count();
    $audits = AuditLog::where('action', $action)->count();
    $created = $this->postJson('/it/setup/'.$resource, $payload)->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('status', 'committed')
        ->assertJsonPath('data.viewer_user_id', $this->actor->id)->assertJsonPath('data.request_uuid', $this->uuid)
        ->assertJsonPath('data.resource', $resource)->assertJsonPath('data.replayed', false);
    $id = $created->json('data.id');
    $this->postJson('/it/setup/'.$resource, $payload)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.replayed', true);
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', ['actor_user_id' => $this->actor->id, 'resource' => $resource])
        ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('status', 'committed');
    expect($model::count())->toBe($before + 1)->and(AuditLog::where('action', $action)->count())->toBe($audits + 1)
        ->and(ItSetupCommandReceipt::where('actor_user_id', $this->actor->id)->count())->toBe(1)
        ->and($created->getContent())->not->toContain('Private setup proposal');
    $stored = ItSetupCommandReceipt::where('actor_user_id', $this->actor->id)->firstOrFail();
    expect(json_encode($stored->getAttributes()))->not->toContain('Private setup proposal');
})->with(['teams', 'queues', 'services']);

test('setup command identity cannot be reused with changed fields or another resource', function () {
    $payload = setupCommandPayload($this->actor, $this->uuid, 'teams');
    $this->postJson('/it/setup/teams', $payload)->assertOk();
    $this->postJson('/it/setup/teams', [...$payload, 'description' => 'Different private work'])->assertConflict();
    $this->postJson('/it/setup/services', setupCommandPayload($this->actor, $this->uuid, 'services'))->assertConflict();
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', ['actor_user_id' => $this->actor->id, 'resource' => 'queues'])->assertConflict();
    expect(ItSetupCommandReceipt::where('actor_user_id', $this->actor->id)->count())->toBe(1);
});

test('setup receipts remain exact after the saved configuration is independently edited', function () {
    $payload = setupCommandPayload($this->actor, $this->uuid, 'services');
    $created = $this->postJson('/it/setup/services', $payload)->assertOk();
    $record = ItService::findOrFail($created->json('data.id'));
    app(ItServiceManagementSetupService::class)->updateService($record, $this->actor, [
        'configuration_version' => $created->json('data.configuration_version'), 'description' => 'Another approved editor update',
    ]);
    $this->postJson('/it/setup/services', $payload)->assertOk()
        ->assertJsonPath('data.id', $record->id)
        ->assertJsonPath('data.committed_configuration_version', $created->json('data.configuration_version'));
    expect($record->fresh()->description)->toBe('Another approved editor update');
});

test('setup command recovery refuses changed actors and fresh permission loss', function () {
    $payload = setupCommandPayload($this->actor, $this->uuid, 'teams');
    $this->postJson('/it/setup/teams', $payload)->assertOk();
    $other = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $other->roles()->sync(Role::where('name', 'admin')->pluck('id'));
    $this->actingAs($other)->postJson('/it/setup/commands/'.$this->uuid.'/recover', ['actor_user_id' => $this->actor->id, 'resource' => 'teams'])->assertForbidden();
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', ['actor_user_id' => $other->id, 'resource' => 'teams'])
        ->assertOk()->assertJsonPath('status', 'not_found')->assertJsonMissingPath('data.id');
    $this->actor->permissionOverrides()->attach(Permission::where('key', 'it.manage')->firstOrFail()->id, ['allowed' => false]);
    $this->actingAs($this->actor->fresh())->postJson('/it/setup/commands/'.$this->uuid.'/recover', ['actor_user_id' => $this->actor->id, 'resource' => 'teams'])->assertForbidden();
});

test('failed audit rolls back the setup record and receipt so the exact command can be retried', function () {
    $payload = setupCommandPayload($this->actor, $this->uuid, 'teams');
    $count = ItTeam::count();
    $fail = true;
    AuditLog::creating(function (AuditLog $entry) use (&$fail): void {
        if ($fail && $entry->action === 'it.setup.team.created') {
            throw new RuntimeException('Isolated required audit failure');
        }
    });
    try {
        expect(fn () => app(ItSetupCommandService::class)->create($this->actor, 'teams', $payload))->toThrow(RuntimeException::class, 'Isolated required audit failure');
    } finally {
        $fail = false;
    }
    expect(ItTeam::count())->toBe($count)->and(ItSetupCommandReceipt::where('actor_user_id', $this->actor->id)->exists())->toBeFalse();
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', ['actor_user_id' => $this->actor->id, 'resource' => 'teams'])
        ->assertOk()->assertJsonPath('status', 'not_found')->assertJsonPath('data.retry_same_command', true);
    $this->postJson('/it/setup/teams', $payload)->assertOk()->assertJsonPath('status', 'committed');
});

test('a new command retains normal uniqueness validation without mistaking another saved record for success', function () {
    $payload = setupCommandPayload($this->actor, $this->uuid, 'teams');
    $this->postJson('/it/setup/teams', $payload)->assertOk();
    $newUuid = (string) Str::uuid();
    $this->postJson('/it/setup/teams', [...$payload, 'request_uuid' => $newUuid])->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(ItSetupCommandReceipt::where('request_uuid', $newUuid)->exists())->toBeFalse();
});

test('explicit cancellation prevents a late original create and is itself replayable', function () {
    $body = ['actor_user_id' => $this->actor->id, 'resource' => 'teams'];
    $before = ItTeam::count();
    $audits = AuditLog::where('action', 'it.setup.create.cancelled')->count();
    $this->postJson('/it/setup/commands/'.$this->uuid.'/cancel', $body)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('data.cancelled', true);
    $this->postJson('/it/setup/commands/'.$this->uuid.'/cancel', $body)->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson('/it/setup/teams', setupCommandPayload($this->actor, $this->uuid, 'teams'))->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', $body)->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItTeam::count())->toBe($before)->and(AuditLog::where('action', 'it.setup.create.cancelled')->count())->toBe($audits + 1)
        ->and(ItSetupCommandReceipt::where('request_uuid', $this->uuid)->sole()->request_hash)->toBeNull();
});

test('cancelling an already committed create reports that exact result without undoing it', function () {
    $created = $this->postJson('/it/setup/services', setupCommandPayload($this->actor, $this->uuid, 'services'))->assertOk();
    $this->postJson('/it/setup/commands/'.$this->uuid.'/cancel', ['actor_user_id' => $this->actor->id, 'resource' => 'services'])
        ->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.id', $created->json('data.id'));
    expect(ItService::find($created->json('data.id')))->not->toBeNull();
});
