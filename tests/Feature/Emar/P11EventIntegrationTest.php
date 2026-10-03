<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\MedicationEvent;
use App\Models\MedicationOnCallRule;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\Settings\EmergencyAccessPolicySettings;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\Medication\Settings\MedicationSettingsStore;

function p11LedgerActor(Site $site): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $user->roles()->detach();
    $overrides = [];
    foreach (['medications.view', 'medications.settings.manage', 'sites.viewAll', EmergencyAccessPolicySettings::PERMISSION] as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications', 'module' => 'Clinical']);
        $overrides[$permission->id] = ['allowed' => true];
    }
    $user->permissionOverrides()->sync($overrides);
    HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear(), 'end_date' => null, 'work_phone' => '09000123']);

    return $user->fresh();
}

it('appends one setting event per approved affected Site and no duplicate for an unchanged save or stale conflict', function () {
    $first = Site::factory()->create(['is_active' => true]);
    $second = Site::factory()->create(['is_active' => true]);
    $archived = Site::factory()->create(['is_active' => true, 'archived' => true, 'archived_at' => now()]);
    $actor = p11LedgerActor($first);
    $payload = ['view' => 'alerts', 'changes' => [['group' => 'delivery', 'key' => 'pin_unattended', 'from' => 'no', 'value' => 'yes']]];
    $this->actingAs($actor)->put('/emar/settings/changes', $payload)->assertSessionHasNoErrors();
    $events = MedicationEvent::query()->orderBy('site_id')->get();
    expect($events)->toHaveCount(2)->and($events->pluck('site_id')->all())->toBe([$first->id, $second->id])
        ->and($events->pluck('sequence')->all())->toBe([1, 1])->and($events->pluck('kind')->unique()->all())->toBe(['settings.changed']);
    $change = MedicationSettingChange::where('setting_group', 'delivery')->sole();
    expect($events[0]->facts['changes'][0])->toBe(['group' => 'delivery', 'key' => 'pin_unattended', 'change_id' => $change->id]);
    $this->put('/emar/settings/changes', ['view' => 'alerts', 'changes' => [['group' => 'delivery', 'key' => 'pin_unattended', 'from' => 'yes', 'value' => 'yes']]])->assertSessionHasNoErrors();
    $this->put('/emar/settings/changes', $payload)->assertSessionHasErrors('conflict');
    expect(MedicationEvent::count())->toBe(2)->and(MedicationEvent::where('site_id', $archived->id)->exists())->toBeFalse();
});

it('records kept policy defaults and scopes a house-only setting to that house', function () {
    $first = Site::factory()->create(['is_active' => true]);
    $second = Site::factory()->create(['is_active' => true]);
    $actor = p11LedgerActor($first);
    $this->actingAs($actor)->post('/emar/settings/keep', ['items' => [['group' => 'ea', 'key' => 'second_person']]])->assertSessionHasNoErrors();
    expect(MedicationEvent::where('kind', 'settings.kept')->count())->toBe(2);
    $this->put('/emar/settings/changes', ['view' => 'alerts', 'changes' => [['group' => 'alertExtra', 'key' => 'stock', 'site_id' => $first->id, 'from' => '[]', 'value' => json_encode([$actor->id])]]])->assertSessionHasNoErrors();
    $events = MedicationEvent::where('kind', 'settings.changed')->get();
    expect($events)->toHaveCount(1)->and($events[0]->site_id)->toBe($first->id)->and($events[0]->sequence)->toBe(2);
});

it('rolls settings, history, review markers and ordinary audit back when event append fails', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $actor = p11LedgerActor($site);
    $before = AuditLog::count();
    $this->mock(MedicationEventRecorder::class, fn ($mock) => $mock->shouldReceive('appendMany')->once()->andThrow(new RuntimeException('Synthetic chain failure')));
    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($actor)->put('/emar/settings/changes', ['view' => 'alerts', 'changes' => [['group' => 'delivery', 'key' => 'pin_unattended', 'from' => 'no', 'value' => 'yes']]]))->toThrow(RuntimeException::class, 'Synthetic chain failure');
    expect(AppSetting::where('key', MedicationSettingsRegistry::DELIVERY_PIN_UNATTENDED)->exists())->toBeFalse()
        ->and(AppSetting::where('key', MedicationSettingsStore::REVISION_KEY)->exists())->toBeFalse()
        ->and(MedicationSettingChange::count())->toBe(0)->and(MedicationEvent::count())->toBe(0)->and(AuditLog::count())->toBe($before);
});

it('records on-call updates and removals at their canonical Site without phone or recipient details and skips a repeated unchanged save', function () {
    $site = Site::factory()->create(['is_active' => true, 'type' => 'house']);
    $actor = p11LedgerActor($site);
    $payload = ['mode' => 'fixed', 'team_lead' => false, 'backup_user_id' => $actor->id];
    $this->actingAs($actor)->put('/emar/settings/oncall/'.$site->id, $payload)->assertSessionHasNoErrors();
    $rule = MedicationOnCallRule::where('site_id', $site->id)->sole();
    $this->put('/emar/settings/oncall/'.$site->id, $payload)->assertSessionHasNoErrors();
    expect(MedicationEvent::count())->toBe(1);
    $this->delete('/emar/settings/oncall/'.$site->id)->assertSessionHasNoErrors();
    $events = MedicationEvent::orderBy('sequence')->get();
    expect($events)->toHaveCount(2)->and($events->pluck('kind')->all())->toBe(['settings.oncall_updated', 'settings.oncall_removed'])
        ->and($events->pluck('site_id')->unique()->all())->toBe([$site->id])->and($events->pluck('subject_id')->unique()->all())->toBe([(string) $rule->id]);
    expect(json_encode($events->toArray()))->not->toContain('09000123', $actor->name, $actor->email);
});

it('rolls the on-call rule and history back when the final chain append fails', function () {
    $site = Site::factory()->create(['is_active' => true, 'type' => 'house']);
    $actor = p11LedgerActor($site);
    $before = AuditLog::count();
    $this->mock(MedicationEventRecorder::class, fn ($mock) => $mock->shouldReceive('append')->once()->andThrow(new RuntimeException('Synthetic on-call chain failure')));
    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($actor)->put('/emar/settings/oncall/'.$site->id, ['mode' => 'fixed', 'team_lead' => false, 'backup_user_id' => $actor->id]))->toThrow(RuntimeException::class, 'Synthetic on-call chain failure');
    expect(MedicationOnCallRule::where('site_id', $site->id)->exists())->toBeFalse()->and(MedicationSettingChange::where('setting_group', 'oncall')->count())->toBe(0)
        ->and(MedicationEvent::count())->toBe(0)->and(AuditLog::count())->toBe($before);
});
