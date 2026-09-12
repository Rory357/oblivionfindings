<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItProvisioningTemplateService;
use App\Domain\It\Services\ItSetupCommandService;
use App\Models\AuditLog;
use App\Models\ItProvisioningTemplate;
use App\Models\ItProvisioningTemplateVersion;
use App\Models\ItSetupCommandReceipt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->actor->roles()->sync(Role::where('name', 'hr')->pluck('id'));
    $this->site = Site::factory()->create();
    $this->profile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
        'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null,
    ]);
    $this->uuid = (string) Str::uuid();
    $this->body = ['actor_user_id' => $this->actor->id, 'resource' => 'provisioning-templates'];
    $task = [
        'task_key' => 'account', 'title' => 'Verify synthetic account',
        'description' => 'Private manual instructions', 'category' => 'account',
        'action' => 'verify', 'request_type' => 'account', 'responsible_team_id' => null,
        'stage' => 1, 'sort_order' => 0, 'dependency_task_keys' => [], 'trigger_fields' => [],
        'approval_required' => true, 'evidence_required' => true, 'due_offset_days' => 0,
        'fulfiller_fields' => ['work_email'],
    ];
    $this->payload = [
        'actor_user_id' => $this->actor->id, 'request_uuid' => $this->uuid,
        'name' => 'Isolated recoverable joiner template', 'description' => 'Private proposal',
        'lifecycle_type' => 'joiner', 'site_id' => $this->site->id, 'position_role' => null,
        'employment_type' => null, 'selection_priority' => 0, 'is_active' => true,
        'tasks' => [$task, [...$task, 'task_key' => 'equipment', 'title' => 'Verify synthetic equipment', 'sort_order' => 1]],
    ];
    $this->actingAs($this->actor);
});

test('template command replay and recovery preserve one template version and creation audit', function () {
    $before = ItProvisioningTemplate::count();
    $versions = ItProvisioningTemplateVersion::count();
    $audits = AuditLog::where('action', 'it.provisioning.template.created')->count();
    $created = $this->postJson('/it/setup/provisioning-templates', $this->payload)
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('status', 'committed')->assertJsonPath('data.replayed', false);
    $id = $created->json('data.id');
    $this->postJson('/it/setup/provisioning-templates', $this->payload)
        ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.replayed', true);
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', $this->body)
        ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('status', 'committed');
    expect(ItProvisioningTemplate::count())->toBe($before + 1)
        ->and(ItProvisioningTemplateVersion::count())->toBe($versions + 1)
        ->and(AuditLog::where('action', 'it.provisioning.template.created')->count())->toBe($audits + 1)
        ->and(ItSetupCommandReceipt::where('request_uuid', $this->uuid)->sole()->it_provisioning_template_id)->toBe($id)
        ->and($created->getContent())->not->toContain('Private proposal', 'Private manual instructions');
    expect(json_encode(ItSetupCommandReceipt::where('request_uuid', $this->uuid)->sole()->getAttributes()))
        ->not->toContain('Private proposal', 'Private manual instructions');

    $template = ItProvisioningTemplate::findOrFail($id);
    app(ItProvisioningTemplateService::class)->update($template, $this->actor, [
        ...$this->payload, 'expected_version' => 1, 'name' => 'Reviewed later version',
    ]);
    $replay = $this->postJson('/it/setup/provisioning-templates', $this->payload)->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.committed_configuration_version', $created->json('data.configuration_version'));
    expect($replay->json('data.configuration_version'))->not->toBe($created->json('data.configuration_version'))
        ->and($template->fresh()->name)->toBe('Reviewed later version');
});

test('template command rejects changed or reordered task contracts without another version', function () {
    $this->postJson('/it/setup/provisioning-templates', $this->payload)->assertOk();
    $versions = ItProvisioningTemplateVersion::count();
    $this->postJson('/it/setup/provisioning-templates', [...$this->payload, 'tasks' => array_reverse($this->payload['tasks'])])
        ->assertConflict();
    $this->postJson('/it/setup/provisioning-templates', [...$this->payload, 'description' => 'Different proposal'])
        ->assertConflict();
    expect(ItProvisioningTemplateVersion::count())->toBe($versions);
});

test('template cancellation is replayable and prevents a late create without undoing committed work', function () {
    $before = ItProvisioningTemplate::count();
    $this->postJson('/it/setup/commands/'.$this->uuid.'/cancel', $this->body)
        ->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson('/it/setup/provisioning-templates', $this->payload)
        ->assertOk()->assertJsonPath('status', 'cancelled');
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', $this->body)
        ->assertOk()->assertJsonPath('status', 'cancelled');
    expect(ItProvisioningTemplate::count())->toBe($before);
    $newUuid = (string) Str::uuid();
    $created = $this->postJson('/it/setup/provisioning-templates', [...$this->payload, 'request_uuid' => $newUuid])->assertOk();
    $this->postJson('/it/setup/commands/'.$newUuid.'/cancel', $this->body)
        ->assertOk()->assertJsonPath('status', 'committed')->assertJsonPath('data.id', $created->json('data.id'));
    expect(ItProvisioningTemplate::find($created->json('data.id')))->not->toBeNull();
    $migration = require database_path('migrations/2026_09_12_000039_bind_provisioning_template_setup_commands.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'command history must be retained');
});

test('template commands bind the browser actor and recheck access to the current saved Site', function () {
    expect($this->actor->canDo('it.organisationWide'))->toBeFalse();
    $this->postJson('/it/setup/provisioning-templates', $this->payload)->assertOk();
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->sync(Role::where('name', 'hr')->pluck('id'));
    $this->actingAs($other)->postJson('/it/setup/provisioning-templates', $this->payload)->assertForbidden();
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', $this->body)->assertForbidden();
    $this->actingAs($this->actor);
    $this->profile->update(['primary_site_id' => Site::factory()->create()->id, 'secondary_site_ids' => []]);
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', $this->body)->assertNotFound()->assertJsonMissingPath('data.id');
    $this->postJson('/it/setup/commands/'.$this->uuid.'/cancel', $this->body)->assertNotFound()->assertJsonMissingPath('data.id');
    expect(fn () => app(ItSetupCommandService::class)->create($this->actor->fresh(), 'provisioning-templates', $this->payload))
        ->toThrow(HttpException::class);
    $this->actor->permissionOverrides()->attach(Permission::where('key', 'it.manage')->firstOrFail()->id, ['allowed' => false]);
    $this->actingAs($this->actor->fresh())->postJson('/it/setup/commands/'.$this->uuid.'/recover', $this->body)->assertForbidden();
});

test('failed template audit rolls back template versions and command receipt before exact retry', function () {
    $templates = ItProvisioningTemplate::count();
    $versions = ItProvisioningTemplateVersion::count();
    $fail = true;
    AuditLog::creating(function (AuditLog $entry) use (&$fail): void {
        if ($fail && $entry->action === 'it.provisioning.template.created') {
            throw new RuntimeException('Isolated template audit failure');
        }
    });
    try {
        expect(fn () => app(ItSetupCommandService::class)->create($this->actor, 'provisioning-templates', $this->payload))
            ->toThrow(RuntimeException::class, 'Isolated template audit failure');
    } finally {
        $fail = false;
    }
    expect(ItProvisioningTemplate::count())->toBe($templates)
        ->and(ItProvisioningTemplateVersion::count())->toBe($versions)
        ->and(ItSetupCommandReceipt::where('request_uuid', $this->uuid)->exists())->toBeFalse();
    $this->postJson('/it/setup/commands/'.$this->uuid.'/recover', $this->body)
        ->assertOk()->assertJsonPath('status', 'not_found')->assertJsonPath('data.retry_same_command', true);
    $this->postJson('/it/setup/provisioning-templates', $this->payload)->assertOk()->assertJsonPath('status', 'committed');
});
