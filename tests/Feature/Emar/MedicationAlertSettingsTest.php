<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\SignalRule;
use App\Models\MedicationSettingChange;
use App\Models\MedicationSiteSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

/*
 * P11 B2 chunk 1: who gets each medication alert is a Medication Setting —
 * organisation-wide by all-sites settings managers, a house's extra people
 * by that house's managers (B2 Q3) — saved through the change history.
 */

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function b2SettingsActor(string $role, ?Site $site): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $user->roles()->sync([Role::query()->where('name', $role)->firstOrFail()->id]);
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Staff',
        'position_role' => $role,
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'primary_site_id' => $site?->id,
        'is_active' => true,
    ]);

    return $user;
}

function b2AlertValue(array $groups, array $people = [], bool $followUp = false): string
{
    return json_encode(['inapp' => true, 'email' => false, 'push' => false, 'follow_up' => $followUp, 'groups' => $groups, 'people' => $people]);
}

function b2Save($test, User $actor, array $change, bool $confirm = false)
{
    return $test->actingAs($actor)
        ->from(route('emar.settings'))
        ->put(route('emar.settings.changes.save'), [
            'view' => 'alerts',
            'changes' => [$change],
            'confirm_loosening' => $confirm,
        ]);
}

it('offers every alert that has a source, with v5’s defaults, not yet reviewed', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());

    $response = $this->actingAs($manager)->get(route('emar.settings'))->assertOk();

    $definitions = $response->inertiaProps('settings.definitions.alerts');
    expect(array_keys($definitions))->toBe([
        'followups', 'stock', 'expiry', 'refusals', 'renewals', 'errors',
        'cdDiscrepancy', 'prnLimit', 'outOfStock', 'cdCheck', 'reviewDue',
    ]);
    expect($response->inertiaProps('settings.values.alerts.stock'))->toBe(b2AlertValue(['houseLead', 'stockStaff']))
        ->and($response->inertiaProps('settings.reviewed.alerts.stock'))->toBeNull()
        ->and($definitions['followups']['alert']['locked'])->toBe(['rostered', 'houseLead'])
        // The on-call person arrives with on-call contacts (B2 chunk 4).
        ->and($definitions['errors']['alert']['groups'])->toBe(['houseLead', 'clinicalLead', 'providerManager'])
        ->and($response->inertiaProps('alertAccess'))->toMatchArray(['view' => true, 'manage_org' => true]);
});

it('saves who gets an alert in the change history, asking before it loosens', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());
    $from = b2AlertValue(['houseLead', 'stockStaff']);
    $change = ['group' => 'alerts', 'key' => 'stock', 'site_id' => null, 'value' => b2AlertValue(['houseLead']), 'from' => $from];

    b2Save($this, $manager, $change)->assertSessionHasErrors('confirm_loosening');
    b2Save($this, $manager, $change, confirm: true)->assertSessionHasNoErrors();

    $row = MedicationSettingChange::query()->where('setting_group', 'alerts')->where('setting_key', 'stock')->firstOrFail();
    expect($row->loosens)->toBeTrue()
        ->and($row->before_text)->toBe('In-app on · House lead, People who update stock here')
        ->and($row->after_text)->toBe('In-app on · House lead')
        ->and($row->actor_id)->toBe($manager->id)
        ->and($row->audit_event)->toBe('medications.alert_recipients.updated');
});

it('keeps decided groups and in-app on for a decided alert', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());

    b2Save($this, $manager, [
        'group' => 'alerts', 'key' => 'followups', 'site_id' => null,
        'value' => json_encode(['inapp' => false, 'email' => false, 'push' => false, 'follow_up' => true, 'groups' => ['clinicalLead'], 'people' => []]),
        'from' => b2AlertValue(['rostered', 'houseLead'], [], true),
    ])->assertSessionHasNoErrors();

    expect(json_decode(DB::table('app_settings')->where('key', 'medications.alerts.followups')->value('value'), true))
        ->toBe(b2AlertValue(['rostered', 'houseLead', 'clinicalLead'], [], true));
});

it('lets a house lead change only their own houses’ extra people', function () {
    $home = Site::factory()->create(['is_active' => true]);
    $other = Site::factory()->create(['is_active' => true]);
    $lead = b2SettingsActor('team_lead', $home);
    $colleague = b2SettingsActor('support_worker', $home);
    $extra = fn (Site $site) => ['group' => 'alertExtra', 'key' => 'stock', 'site_id' => $site->id, 'value' => json_encode([$colleague->id]), 'from' => '[]'];

    b2Save($this, $lead, $extra($home))->assertSessionHasNoErrors();
    expect(MedicationSiteSetting::query()->where('site_id', $home->id)->value('value'))->toBe(json_encode([$colleague->id]));
    $row = MedicationSettingChange::query()->where('setting_group', 'alertExtra')->firstOrFail();
    expect($row->site_id)->toBe($home->id)->and($row->actor_id)->toBe($lead->id);

    // Another house, an organisation setting, or anything not a house setting: refused.
    b2Save($this, $lead, $extra($other))->assertForbidden();
    b2Save($this, $lead, ['group' => 'alerts', 'key' => 'stock', 'site_id' => null, 'value' => b2AlertValue(['houseLead']), 'from' => b2AlertValue(['houseLead', 'stockStaff'])], true)->assertForbidden();
    $this->actingAs($lead)->put(route('emar.settings.changes.save'), [
        'view' => 'rules',
        'changes' => [['group' => 'safety', 'key' => 'profile_allergy_match', 'site_id' => null, 'value' => 'block', 'from' => 'warn']],
    ])->assertForbidden();
    expect(MedicationSiteSetting::query()->where('site_id', $other->id)->exists())->toBeFalse();
});

it('only lets people who can get the alert be named on it', function () {
    $site = Site::factory()->create(['is_active' => true]);
    $manager = b2SettingsActor('provider_manager', $site);
    $noAccess = User::factory()->create(['approved_at' => now()]);

    b2Save($this, $manager, [
        'group' => 'alerts', 'key' => 'stock', 'site_id' => null,
        'value' => b2AlertValue(['houseLead', 'stockStaff'], [$noAccess->id]),
        'from' => b2AlertValue(['houseLead', 'stockStaff']),
    ])->assertSessionHasErrors('changes.0.value');
});

it('grants the house key to team leads and coordinators, and rolls back only it', function () {
    $migration = require database_path('migrations/2026_10_02_100000_grant_medication_alert_and_settings_keys.php');
    $key = Permission::query()->where('key', 'medications.alerts.manage_house')->firstOrFail();
    DB::table('role_permission')->where('permission_id', $key->id)->delete();

    $migration->up();
    $holders = fn () => Role::query()->whereHas('permissions', fn ($p) => $p->whereKey($key->id))->pluck('name')->sort()->values()->all();
    expect($holders())->toBe(['admin', 'coordinator', 'team_lead']);
    $migration->up();
    expect($holders())->toBe(['admin', 'coordinator', 'team_lead']);

    $migration->down();
    expect(Permission::query()->where('key', 'medications.alerts.manage_house')->exists())->toBeFalse()
        ->and(Permission::query()->where('key', 'medications.settings.manage')->exists())->toBeTrue();
});

it('stops Control Room rules notifying for alerts the catalogue now owns, and restores them on rollback', function () {
    (require database_path('migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php'))->up();
    (require database_path('migrations/2026_04_12_150000_expand_medication_signal_types_for_exceptions.php'))->up();
    $migration = require database_path('migrations/2026_10_02_100200_medication_settings_own_who_is_told_c1.php');
    $roles = fn (string $code) => SignalRule::query()->where('signal_type_code', $code)->value('notify_roles');

    $migration->up();
    foreach (['medication_controlled_discrepancy', 'medication_controlled_loss', 'medication_prn_over_limit', 'medication_stock_out', 'medication_error', 'medication_overdue'] as $code) {
        expect($roles($code))->toBe([]);
    }
    // Not wired yet: unchanged.
    expect($roles('medication_expired'))->toBe(['managers_core'])
        ->and($roles('medication_refusal_escalation'))->toBe(['managers_core', 'coordinators']);

    $migration->down();
    expect($roles('medication_controlled_discrepancy'))->toBe(['managers_core', 'coordinators'])
        ->and($roles('medication_controlled_loss'))->toBe(['managers_core', 'coordinators'])
        ->and($roles('medication_prn_over_limit'))->toBe(['managers_core'])
        ->and($roles('medication_stock_out'))->toBe(['managers_core'])
        ->and($roles('medication_error'))->toBe(['managers_core'])
        ->and($roles('medication_overdue'))->toBe(['managers_core']);
});
