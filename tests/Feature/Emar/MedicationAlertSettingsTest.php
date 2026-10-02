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
        'overdue', 'followups', 'stock', 'expiry', 'refusals', 'renewals', 'errors',
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

it('refuses a change that switches off every way of telling people', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());

    b2Save($this, $manager, [
        'group' => 'alerts', 'key' => 'stock', 'site_id' => null,
        'value' => json_encode(['inapp' => false, 'email' => false, 'push' => false, 'follow_up' => false, 'groups' => ['houseLead', 'stockStaff'], 'people' => []]),
        'from' => b2AlertValue(['houseLead', 'stockStaff']),
    ], confirm: true)->assertSessionHasErrors([
        'changes.0.value' => 'Choose who gets “Stock running low” from the listed groups and people, with at least one way to tell them switched on.',
    ]);

    expect(DB::table('app_settings')->where('key', 'medications.alerts.stock')->exists())->toBeFalse();
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

it('grants the settings key only where it is new, so a revoked grant stays revoked', function () {
    $migration = require database_path('migrations/2026_10_02_100000_grant_medication_alert_and_settings_keys.php');
    $settings = Permission::query()->where('key', 'medications.settings.manage')->firstOrFail();
    $coordinator = Role::query()->where('name', 'coordinator')->firstOrFail();
    DB::table('role_permission')->where('permission_id', $settings->id)->where('role_id', $coordinator->id)->delete();

    // Seeded already (RbacSeeder since 2 June): its grants are left as they are.
    $migration->up();
    expect(DB::table('role_permission')->where('permission_id', $settings->id)->where('role_id', $coordinator->id)->exists())->toBeFalse();

    // Never seeded: this is its first grant.
    DB::table('role_permission')->where('permission_id', $settings->id)->delete();
    DB::table('permissions')->where('id', $settings->id)->delete();
    $migration->up();
    $created = Permission::query()->where('key', 'medications.settings.manage')->firstOrFail();
    expect(Role::query()->whereHas('permissions', fn ($p) => $p->whereKey($created->id))->pluck('name')->sort()->values()->all())
        ->toBe(['admin', 'clinical_lead', 'coordinator', 'provider_manager']);
});

it('stops Control Room rules notifying only where they still hold their seeded recipients, and restores them exactly', function () {
    (require database_path('migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php'))->up();
    (require database_path('migrations/2026_04_12_150000_expand_medication_signal_types_for_exceptions.php'))->up();
    $migration = require database_path('migrations/2026_10_02_100200_medication_settings_own_who_is_told_c1.php');
    DB::table('medication_alert_cr_rule_snapshots')->delete();
    $roles = fn (string $code) => SignalRule::query()->where('signal_type_code', $code)->value('notify_roles');
    $stored = fn (string $code) => DB::table('control_room_signal_rules')->where('signal_type_code', $code)->value('notify_roles');
    $set = fn (string $code, array $to) => DB::table('control_room_signal_rules')->where('signal_type_code', $code)->update(['notify_roles' => json_encode($to)]);
    // Someone already chose the as-needed rule's recipients: theirs stay.
    $set('medication_prn_over_limit', ['managers_core', 'site_managers']);
    $stockOutBefore = $stored('medication_stock_out');

    $migration->up();

    $cleared = ['medication_controlled_discrepancy', 'medication_controlled_loss', 'medication_error', 'medication_overdue', 'medication_refusal_escalation', 'medication_stock_out'];
    foreach ($cleared as $code) {
        expect($roles($code))->toBe([]);
    }
    expect($roles('medication_prn_over_limit'))->toBe(['managers_core', 'site_managers'])
        // Expired stock still notifies (order end dates); the alert row says so.
        ->and($roles('medication_expired'))->toBe(['managers_core'])
        ->and(DB::table('medication_alert_cr_rule_snapshots')->orderBy('signal_type_code')->pluck('signal_type_code')->all())->toBe($cleared);

    // After the clear, someone chose new recipients for errors: a rollback keeps them.
    $set('medication_error', ['coordinators']);
    // down() also drops the snapshot table — DDL, never run inside a test transaction.
    (new ReflectionMethod($migration, 'restoreSnapshots'))->invoke($migration);

    expect($stored('medication_stock_out'))->toBe($stockOutBefore)
        ->and($roles('medication_controlled_discrepancy'))->toBe(['managers_core', 'coordinators'])
        ->and($roles('medication_controlled_loss'))->toBe(['managers_core', 'coordinators'])
        ->and($roles('medication_overdue'))->toBe(['managers_core'])
        ->and($roles('medication_refusal_escalation'))->toBe(['managers_core', 'coordinators'])
        ->and($roles('medication_error'))->toBe(['coordinators'])
        ->and($roles('medication_prn_over_limit'))->toBe(['managers_core', 'site_managers']);
});

/*
 * Settings page cost (B2 C1 review). Permission reads are cached, not changed:
 * people are loaded with their roles' permissions, their own overrides and
 * their HR profile, so canDo() answers from memory, and the witness PIN list
 * reads its renewal setting once. On the 258-person demo the page went from
 * 4,202 queries (C1 as handed over) to 486.
 *
 * What still costs a query per person is the shared site-access service
 * reading their current houses, once each — so each person added may add at
 * most one query, and a small organisation's page stays under a fixed bound.
 */
function b2MedicationPeople(array $sites, int $perHouse): void
{
    foreach ($sites as $site) {
        b2SettingsActor('team_lead', $site);
        for ($i = 0; $i < $perHouse; $i++) {
            $worker = b2SettingsActor('support_worker', $site);
            $worker->permissionOverrides()->syncWithoutDetaching(
                Permission::query()->whereIn('key', ['medications.view', 'medications.stock.update'])->pluck('id')
                    ->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
            );
        }
    }
}

function b2SettingsQueries($test, User $viewer): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $test->actingAs($viewer)->get(route('emar.settings'))->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('keeps the Settings page’s queries bounded as the organisation grows', function () {
    $sites = Site::factory()->count(3)->create(['is_active' => true]);
    $manager = b2SettingsActor('provider_manager', $sites[0]);
    b2MedicationPeople($sites->all(), 3);
    // The first request also fills once-per-process caches (schema checks and
    // the like); measure from the second.
    b2SettingsQueries($this, $manager);
    $small = b2SettingsQueries($this, $manager);

    // Ten more medication staff and another house lead at each house: 33 people.
    b2MedicationPeople($sites->all(), 10);
    $large = b2SettingsQueries($this, $manager);

    expect($small)->toBeLessThanOrEqual(110)
        ->and($large - $small)->toBeLessThanOrEqual(33);
});
