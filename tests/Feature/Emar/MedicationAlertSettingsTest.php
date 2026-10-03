<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\SignalRule;
use App\Models\MedicationSettingChange;
use App\Models\MedicationOnCallRule;
use App\Models\MedicationSiteSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Alerts\MedicationAlertSettings;
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
        // The on-call person since on-call contacts (B2 chunk 4).
        ->and($definitions['errors']['alert']['groups'])->toBe(['houseLead', 'onCall', 'clinicalLead', 'providerManager'])
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
        ->and($row->before_text)->toBe('In-app on · email off · push off · follow up off · House lead, People who update stock here')
        ->and($row->after_text)->toBe('In-app on · email off · push off · follow up off · House lead')
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

it('lets in-app be switched off once email or push is on (B2 C2: at least one channel)', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());
    $emailOnly = json_encode(['inapp' => false, 'email' => true, 'push' => false, 'follow_up' => false, 'groups' => ['houseLead', 'stockStaff'], 'people' => []]);

    b2Save($this, $manager, [
        'group' => 'alerts', 'key' => 'stock', 'site_id' => null,
        'value' => $emailOnly,
        'from' => b2AlertValue(['houseLead', 'stockStaff']),
    ], confirm: true)->assertSessionHasNoErrors();

    expect(json_decode(DB::table('app_settings')->where('key', 'medications.alerts.stock')->value('value'), true))->toBe($emailOnly);
    expect(MedicationSettingChange::query()->where('setting_key', 'stock')->value('after_text'))
        ->toBe('In-app off · email on · push off · follow up off · House lead, People who update stock here');
});

it('saves the privacy switch for email and push, asking before it is switched off', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());
    $change = ['group' => 'delivery', 'key' => 'private', 'site_id' => null, 'value' => 'no', 'from' => 'yes'];
    expect(app(MedicationAlertSettings::class)->privateDelivery())->toBeTrue();

    b2Save($this, $manager, $change)->assertSessionHasErrors('confirm_loosening');
    b2Save($this, $manager, $change, confirm: true)->assertSessionHasNoErrors();

    $row = MedicationSettingChange::query()->where('setting_group', 'delivery')->where('setting_key', 'private')->firstOrFail();
    expect($row->loosens)->toBeTrue()
        ->and($row->before_text)->toBe('On')
        ->and($row->after_text)->toBe('Off — email and push include client names and medicines')
        ->and($row->audit_event)->toBe('medications.alert_delivery.updated')
        ->and(app(MedicationAlertSettings::class)->privateDelivery())->toBeFalse();
});

it('previews each alert from the real notification, with the privacy switch on and off', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());

    $response = $this->actingAs($manager)->get(route('emar.settings'))->assertOk();

    $stock = $response->inertiaProps('alertPreviews.stock');
    expect($stock['inapp']['message'])->toBe('Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.')
        ->and($stock['private']['email'])->toBe([
            'subject' => 'Stock running low',
            'lines' => ['A medicine at Kōwhai House is running low.', 'Open Oblivion Care to see the details and respond.'],
            'action' => 'Open in Oblivion Care',
        ])
        ->and($stock['private']['push'])->toBe(['title' => 'Stock running low', 'body' => 'A medicine at Kōwhai House is running low.'])
        ->and($stock['open']['email']['subject'])->toBe('Stock running low — Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.')
        ->and($stock['open']['push']['body'])->toBe('Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.')
        // A message that starts with its title doesn't repeat it.
        ->and($response->inertiaProps('alertPreviews.cdDiscrepancy.open.email.subject'))
        ->toBe('Controlled-drug count doesn’t match — Methylphenidate at Kōwhai House: 1 short.')
        ->and($response->inertiaProps('alertPreviews.cdDiscrepancy.controlled'))->toBeTrue()
        ->and(array_keys($response->inertiaProps('alertPreviews')))->toBe(array_keys($response->inertiaProps('settings.definitions.alerts')))
        ->and($response->inertiaProps('alertDelivery'))->toMatchArray(['push_ready' => 0]);
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

/*
 * B2 chunk 3: the follow-up settings.
 */
it('saves follow-up settings that work together, and refuses ones that don’t', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());
    $change = fn (string $key, string $value, string $from) => ['group' => 'delivery', 'key' => $key, 'site_id' => null, 'value' => $value, 'from' => $from];
    $save = fn (array $changes, bool $confirm = false) => $this->actingAs($manager)
        ->from(route('emar.settings'))
        ->put(route('emar.settings.changes.save'), ['view' => 'alerts', 'changes' => $changes, 'confirm_loosening' => $confirm]);

    // A re-alert needs both how often and how many times.
    $save([$change('realert_every', '30', 'off')])->assertSessionHasErrors([
        'changes.0.value' => 'Re-alerting needs both how often and how many times — or switch it off.',
    ]);
    $save([$change('realert_every', '30', 'off'), $change('realert_max', '3', 'off')])->assertSessionHasNoErrors();
    expect(app(MedicationAlertSettings::class)->followUp())->toMatchArray(['realert_every' => 30, 'realert_max' => 3]);

    // An escalation needs someone to escalate to.
    $save([$change('escalate_after', '60', 'off')])->assertSessionHasErrors([
        'changes.0.value' => 'Choose who it escalates to.',
    ]);
    $save([$change('escalate_after', '60', 'off'), $change('escalate_to', '["clinicalLead","houseLead"]', '[]')])->assertSessionHasNoErrors();
    expect(app(MedicationAlertSettings::class)->followUp())->toMatchArray(['escalate_after' => 60, 'escalate_to' => ['houseLead', 'clinicalLead']])
        ->and(MedicationSettingChange::query()->where('setting_key', 'escalate_to')->value('after_text'))->toBe('House lead, Clinical lead');
});

it('treats moving what counts as attended towards “opened” as a loosening', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create());
    $attended = fn (string $value, string $from) => ['group' => 'delivery', 'key' => 'attended', 'site_id' => null, 'value' => $value, 'from' => $from];

    // Stronger (dealt with) is never a loosening.
    b2Save($this, $manager, $attended('done', 'ack'))->assertSessionHasNoErrors();
    // Back towards "opened" asks first.
    b2Save($this, $manager, $attended('open', 'done'))->assertSessionHasErrors('confirm_loosening');
    b2Save($this, $manager, $attended('open', 'done'), confirm: true)->assertSessionHasNoErrors();

    expect(MedicationSettingChange::query()->where('setting_key', 'attended')->latest('id')->first())
        ->loosens->toBeTrue()
        ->after_text->toBe('Someone opens it');
});

/*
 * B2 chunk 4: on-call contacts save straight away, per house.
 */
function b2OnCallStaff(Site $site, ?string $phone, string $role = 'support_worker'): User
{
    $user = b2SettingsActor($role, $site);
    HrEmployeeProfile::query()->where('user_id', $user->id)->update(['work_phone' => $phone]);

    return $user;
}

it('saves a house’s on-call contact straight away, in the change history, and removes it after a confirm', function () {
    $site = Site::factory()->create(['is_active' => true, 'type' => 'house', 'name' => 'Kōwhai House']);
    $manager = b2SettingsActor('provider_manager', $site);
    $backup = b2OnCallStaff($site, '021 555 0142');

    $this->actingAs($manager)->from(route('emar.settings'))
        ->put(route('emar.settings.oncall.save', $site), ['mode' => 'roster', 'team_lead' => true, 'backup_user_id' => $backup->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('medication_settings_saved', 'On-call contact saved for Kōwhai House.');

    $rule = MedicationOnCallRule::query()->where('site_id', $site->id)->sole();
    expect($rule->only(['mode', 'team_lead', 'backup_user_id', 'updated_by']))
        ->toBe(['mode' => 'roster', 'team_lead' => true, 'backup_user_id' => $backup->id, 'updated_by' => $manager->id]);
    $row = MedicationSettingChange::query()->where('setting_group', 'oncall')->sole();
    expect($row->only(['site_id', 'label', 'before_text', 'after_text', 'loosens', 'audit_event']))->toBe([
        'site_id' => $site->id,
        'label' => 'On-call contact',
        'before_text' => 'Not configured',
        'after_text' => 'Follows the roster, then the team lead on shift · backup '.$backup->name,
        'loosens' => false,
        'audit_event' => 'medications.oncall_contact.updated',
    ]);

    $response = $this->actingAs($manager)->get(route('emar.settings'))->assertOk();
    $house = collect($response->inertiaProps('onCall.houses'))->firstWhere('site_id', $site->id);
    expect($house['rule'])->toMatchArray(['mode' => 'roster', 'backup' => ['id' => $backup->id, 'name' => $backup->name, 'phone' => '021 555 0142']])
        ->and($house['can_manage'])->toBeTrue()
        ->and($house['roster'])->toHaveCount(3);

    $this->actingAs($manager)->from(route('emar.settings'))
        ->delete(route('emar.settings.oncall.remove', $site))
        ->assertSessionHas('medication_settings_saved', 'On-call contact removed for Kōwhai House. Screens show “Not configured” again.');
    expect(MedicationOnCallRule::query()->count())->toBe(0)
        ->and(MedicationSettingChange::query()->where('label', 'On-call contact removed')->value('loosens'))->toBeTrue();
});

it('only takes a backup with access to the house and a work phone', function () {
    $site = Site::factory()->create(['is_active' => true, 'type' => 'house']);
    $manager = b2SettingsActor('provider_manager', $site);
    $noPhone = b2OnCallStaff($site, null);
    $elsewhere = b2OnCallStaff(Site::factory()->create(['is_active' => true]), '021 555 0000');
    $save = fn (User $backup, string $mode = 'roster') => $this->actingAs($manager)->from(route('emar.settings'))
        ->put(route('emar.settings.oncall.save', $site), ['mode' => $mode, 'team_lead' => true, 'backup_user_id' => $backup->id]);

    $save($noPhone)->assertSessionHasErrors(['backup_user_id' => 'Choose who staff call when nobody is rostered on call.']);
    $save($elsewhere, 'fixed')->assertSessionHasErrors(['backup_user_id' => 'Choose the on-call person.']);
    expect(MedicationOnCallRule::query()->count())->toBe(0);
});

it('lets a house lead set only their own house’s on-call contact', function () {
    $home = Site::factory()->create(['is_active' => true, 'type' => 'house']);
    $other = Site::factory()->create(['is_active' => true, 'type' => 'house']);
    $lead = b2SettingsActor('team_lead', $home);
    $homeBackup = b2OnCallStaff($home, '021 555 0001');
    $otherBackup = b2OnCallStaff($other, '021 555 0002');

    $this->actingAs($lead)->from(route('emar.settings'))
        ->put(route('emar.settings.oncall.save', $home), ['mode' => 'fixed', 'team_lead' => false, 'backup_user_id' => $homeBackup->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($lead)->put(route('emar.settings.oncall.save', $other), ['mode' => 'fixed', 'team_lead' => false, 'backup_user_id' => $otherBackup->id])
        ->assertForbidden();
    $this->actingAs($lead)->delete(route('emar.settings.oncall.remove', $other))->assertForbidden();
    expect(MedicationOnCallRule::query()->pluck('site_id')->all())->toBe([$home->id]);
});

it('counts a Site as a house by its type, a facility that holds people, or any Site with active orders (Q12)', function () {
    $manager = b2SettingsActor('provider_manager', Site::factory()->create(['is_active' => true, 'type' => 'house', 'name' => 'A house']));
    Site::factory()->create(['is_active' => true, 'type' => 'residential', 'name' => 'B residential']);
    Site::factory()->create(['is_active' => true, 'type' => 'head_office', 'name' => 'C head office']);
    Site::factory()->create(['is_active' => true, 'type' => 'facility', 'name' => 'D empty facility']);
    $facility = Site::factory()->create(['is_active' => true, 'type' => 'facility', 'name' => 'E facility with people']);
    App\Models\Client::factory()->create(['site_id' => $facility->id]);
    $office = Site::factory()->create(['is_active' => true, 'type' => 'head_office', 'name' => 'F head office with orders']);
    App\Models\ClientMedication::factory()->create([
        'client_id' => App\Models\Client::factory()->create(['site_id' => $office->id])->id,
        'active' => true,
        'state' => 'active',
    ]);

    $names = collect($this->actingAs($manager)->get(route('emar.settings'))->assertOk()->inertiaProps('onCall.houses'))->pluck('name')->all();

    expect($names)->toContain('A house', 'B residential', 'E facility with people', 'F head office with orders')
        ->not->toContain('C head office')
        ->not->toContain('D empty facility');
});
