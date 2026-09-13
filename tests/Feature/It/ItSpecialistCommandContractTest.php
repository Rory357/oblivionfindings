<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ItChange;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->commandSite = Site::factory()->create();
    $this->commandActor = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
    $this->commandActor->roles()->sync([Role::query()->where('name', 'provider_manager')->firstOrFail()->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->commandActor->id, 'primary_site_id' => $this->commandSite->id,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null,
    ]);
});

test('specialist edits require a reviewed version and stale commands cannot replace newer work', function (string $workspace, string $model, string $field, string $state) {
    $this->freezeTime();
    $record = $model::factory()->create();
    $record->ticket()->update(['site_id' => $this->commandSite->id]);
    $version = (int) $record->ticket()->value('lock_version');
    $path = "/it/{$workspace}/{$record->id}";
    $this->actingAs($this->commandActor)->patchJson($path, [$field => 'Missing version'])
        ->assertUnprocessable()->assertJsonValidationErrors('expected_version');
    $this->patch($path, ['expected_version' => $version, 'actor_user_id' => $this->commandActor->id, $field => 'Current saved investigation'])
        ->assertRedirect()->assertSessionDoesntHaveErrors();
    $current = (int) $record->ticket()->value('lock_version');
    expect($current)->toBeGreaterThan($version)->and($record->fresh()->getAttribute($field))->toBe('Current saved investigation');
    $differentActor = User::factory()->create(['approved_at' => now()]);
    $auditCount = AuditLog::query()->count();
    $eventCount = $record->ticket->events()->count();
    $this->patchJson($path, ['expected_version' => $version, $field => 'Stale replacement'])
        ->assertConflict()->assertJsonPath('code', 'stale_ticket')->assertJsonValidationErrors('expected_version');
    $this->postJson($path.'/transitions', ['expected_version' => $version, 'workflow_state' => $state, 'reason' => 'Stale command'])
        ->assertConflict()->assertJsonPath('code', 'stale_ticket');
    if ($workspace === 'major-incidents') {
        $this->postJson($path.'/updates', [
            'expected_version' => $version, 'update_kind' => 'command_note', 'audience' => 'internal',
            'summary' => 'Stale communication', 'service_status' => 'investigating',
        ])->assertConflict()->assertJsonPath('code', 'stale_ticket');
        expect($record->updates()->count())->toBe(0);
    }
    $this->patchJson($path, ['expected_version' => $current, 'actor_user_id' => $differentActor->id, $field => 'Wrong account'])
        ->assertForbidden();
    expect($record->fresh()->getAttribute($field))->toBe('Current saved investigation')
        ->and((int) $record->ticket()->value('lock_version'))->toBe($current)
        ->and($record->ticket->events()->count())->toBe($eventCount)
        ->and(AuditLog::query()->count())->toBe($auditCount);
})->with([
    'problem' => ['problems', ItProblem::class, 'root_cause', 'investigating'],
    'change' => ['changes', ItChange::class, 'impact_summary', 'assessment'],
    'major incident' => ['major-incidents', ItMajorIncident::class, 'impact_summary', 'responding'],
]);

test('a specialist wizard returns the actual saved canonical record and originating account', function (string $workspace, string $model, array $details) {
    $this->actingAs($this->commandActor)->from('/it/'.$workspace)->post('/it/'.$workspace, [
        'wizard' => true, 'actor_user_id' => $this->commandActor->id,
        'title' => 'Synthetic reviewed creation receipt', 'description' => 'Focused command verification.',
        'category' => 'network', 'priority' => 'high', ...$details,
    ])->assertRedirect('/it/'.$workspace)->assertSessionDoesntHaveErrors()
        ->assertSessionHas('it_ticket', function ($receipt) use ($workspace, $model) {
            $record = $model::query()->with('ticket')->firstOrFail();

            return $receipt['id'] === $record->ticket_id
                && $receipt['specialist_id'] === $record->id
                && $receipt['reference'] === $record->ticket->reference
                && $receipt['workspace'] === $workspace
                && $receipt['actor_user_id'] === $this->commandActor->id;
        });
})->with([
    'problem' => ['problems', ItProblem::class, []],
    'change' => ['changes', ItChange::class, ['change_type' => 'standard', 'risk_level' => 'low']],
    'major incident' => ['major-incidents', ItMajorIncident::class, ['severity' => 'sev2', 'target_update_minutes' => 30, 'impact_summary' => 'Synthetic service interruption.']],
]);
