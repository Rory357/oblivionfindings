<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\BreakGlassPolicy;
use App\Models\Client;
use App\Models\ClientControlledDrugEntry;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Models\MedicationAlertRecipient;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Alerts\MedicationAlertLog;
use App\Services\Medication\Alerts\MedicationBellOrder;
use App\Services\Medication\Alerts\OnCallResolver;
use App\Services\Medication\Settings\EmergencyAccessPolicySettings;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function p11FinishActor(Site $site, array $keys): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    $ids = [];
    foreach ($keys as $key) {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'medications', 'module' => 'Clinical']);
        $ids[$permission->id] = ['allowed' => true];
    }
    $user->roles()->detach();
    $user->permissionOverrides()->sync($ids);
    HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [], 'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);

    return $user->fresh();
}

function p11FinishAlert(Site $site, array $extra = []): MedicationAlert
{
    $key = uniqid('p11-', true);

    return MedicationAlert::create(array_merge(['type' => 'stock', 'dedupe_key' => $key, 'open_key' => $key, 'site_id' => $site->id, 'controlled' => false, 'title' => 'Stock running low', 'message' => 'Synthetic medicine stock', 'short_message' => 'Stock needs attention', 'follow_up' => false, 'status' => 'open', 'raised_at' => now()], $extra));
}

it('pages all retained alert history and restricts forged house filters', function () {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $reader = p11FinishActor($site, ['medications.view', 'medications.audit.view']);
    for ($i = 0; $i < 28; $i++) {
        p11FinishAlert($site, ['raised_at' => now()->subDays(90), 'open_key' => null, 'status' => 'dealt_with', 'dealt_with_at' => now()->subDays(89)]);
    }
    p11FinishAlert($other);
    $log = app(MedicationAlertLog::class);
    $page = $log->page($reader, [$site->id], false, now(), ['range' => 'all']);
    expect($page['total'])->toBe(28)->and($page['data'])->toHaveCount(25)->and($page['last_page'])->toBe(2)
        ->and($page['links'][2]['url'])->toContain('alert_page=2', '#alerts/log');
    expect($log->page($reader, [$site->id], false, now(), ['house' => $other->id])['total'])->toBe(0)
        ->and($log->page($reader, [$site->id], false, now())['total'])->toBe(0);
});

it('conceals controlled details from payload and search while retaining the approved shell', function () {
    $site = Site::factory()->create();
    $reader = p11FinishActor($site, ['medications.view', 'medications.audit.view']);
    $alert = p11FinishAlert($site, ['controlled' => true, 'type' => 'cdCheck', 'message' => 'SECRET MEDICINE', 'action_url' => '/emar/controlled-drugs']);
    MedicationAlertRecipient::create(['medication_alert_id' => $alert->id, 'user_id' => $reader->id, 'reason' => 'named', 'step' => 0, 'channels' => ['inapp'], 'told_at' => now()]);
    MedicationAlertEvent::create(['medication_alert_id' => $alert->id, 'event' => MedicationAlertEvent::SENT, 'occurred_at' => now(), 'detail' => ['told' => [['user_id' => $reader->id, 'channels' => ['inapp']]]]]);
    $log = app(MedicationAlertLog::class);
    $row = $log->page($reader, [$site->id], false, now())['data'][0];
    expect($row['concealed'])->toBeTrue()->and($row['about'])->toBeNull()->and($row['told'])->toBe([])->and($row['events'])->toBe([])->and($row['action_url'])->toBeNull();
    expect(json_encode($row))->not->toContain('SECRET MEDICINE', $reader->name);
    expect($log->page($reader, [$site->id], false, now(), ['q' => 'SECRET MEDICINE'])['total'])->toBe(0)
        ->and($log->page($reader, [$site->id], false, now(), ['q' => 'Controlled-medicine'])['total'])->toBe(1);
});

it('removes historical client alerts after a move and denies nonassigned people before counting', function () {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $reader = p11FinishActor($site, ['medications.view', 'medications.audit.view']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    p11FinishAlert($site, ['client_id' => $client->id]);
    $log = app(MedicationAlertLog::class);
    expect($log->summary($reader, [$site->id], false, now())['recent'])->toBe(1);
    $client->update(['site_id' => $other->id]);
    expect($log->summary($reader, [$site->id], false, now())['recent'])->toBe(0)->and($log->page($reader, [$site->id], false, now())['total'])->toBe(0);
    $worker = p11FinishActor($site, ['medications.view']);
    $unassigned = Client::factory()->create(['site_id' => $site->id]);
    p11FinishAlert($site, ['client_id' => $unassigned->id]);
    expect($log->page($worker, [$site->id], false, now())['total'])->toBe(0);
});

it('shows canonical event IDs and delivered recipients without calling held recipients told', function () {
    $site = Site::factory()->create();
    $reader = p11FinishActor($site, ['medications.view', 'medications.audit.view', 'medications.controlled.view']);
    $alert = p11FinishAlert($site, ['action_url' => 'https://untrusted.invalid/source']);
    MedicationAlertRecipient::create(['medication_alert_id' => $alert->id, 'user_id' => $reader->id, 'reason' => 'named', 'step' => 0, 'channels' => ['email'], 'held_until' => now()->addHour()]);
    $event = MedicationAlertEvent::create(['medication_alert_id' => $alert->id, 'event' => MedicationAlertEvent::HELD, 'occurred_at' => now(), 'detail' => ['until' => now()->addHour()->toIso8601String(), 'user_ids' => [$reader->id]]]);
    $row = app(MedicationAlertLog::class)->page($reader, [$site->id], false, now())['data'][0];
    expect($row['told'])->toBe([])->and($row['via'])->toBe([])->and($row['events'][0]['id'])->toBe($event->id)->and($row['action_url'])->toBeNull();
});

it('retains recently closed and unattended alerts beyond the audit cutoff and dry-run changes nothing', function () {
    $site = Site::factory()->create();
    $old = now()->subYears(4);
    $expired = p11FinishAlert($site, ['raised_at' => $old, 'open_key' => null, 'status' => 'dealt_with', 'dealt_with_at' => $old]);
    $open = p11FinishAlert($site, ['raised_at' => $old]);
    $recentlyClosed = p11FinishAlert($site, ['raised_at' => $old, 'open_key' => null, 'status' => 'dealt_with', 'dealt_with_at' => now()]);
    $event = MedicationAlertEvent::create(['medication_alert_id' => $expired->id, 'event' => MedicationAlertEvent::DEALT_WITH, 'occurred_at' => $old, 'detail' => []]);
    $this->artisan('oblivion:prune-retention', ['--audit-years' => 2, '--dry-run' => true])->assertSuccessful();
    expect(MedicationAlert::count())->toBe(3)->and(MedicationAlertEvent::whereKey($event->id)->exists())->toBeTrue();
    $this->artisan('oblivion:prune-retention', ['--audit-years' => 2])->assertSuccessful();
    expect(MedicationAlert::whereKey($expired->id)->exists())->toBeFalse()->and(MedicationAlertEvent::whereKey($event->id)->exists())->toBeFalse()
        ->and(MedicationAlert::whereKey($open->id)->exists())->toBeTrue()->and(MedicationAlert::whereKey($recentlyClosed->id)->exists())->toBeTrue();
});

it('preserves every canonical medication audit family and type while counting and pruning ordinary expired rows', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06T00:00:00Z'));

    try {
        $old = now()->subYears(4);
        $protected = collect();
        foreach (['medications.orders.updated', 'emar.dose.recorded', 'meds.administer', 'medication_order.checked',
            'medication_reconciliation.completed', 'clientmedication.updated', 'clientmedicationadministration.created',
            'clientcontrolleddrugentry.created', 'controlled_drug.check_recorded', 'cd.check_recorded', 'cd_check_recorded'] as $action) {
            foreach ([null, Client::class] as $type) {
                $protected->push(AuditLog::query()->forceCreate([
                    'action' => $action, 'auditable_type' => $type, 'meta' => ['fixture' => 'Synthetic medication audit retention'],
                    'created_at' => $old, 'updated_at' => $old,
                ]));
            }
        }
        foreach ([ClientMedication::class, ClientMedicationAdministration::class, ClientControlledDrugEntry::class,
            'Medication', 'ControlledDrugRecord'] as $type) {
            $protected->push(AuditLog::query()->forceCreate([
                'action' => 'updated', 'auditable_type' => $type, 'meta' => ['fixture' => 'Synthetic typed medication audit'],
                'created_at' => $old, 'updated_at' => $old,
            ]));
        }
        $ordinary = collect();
        foreach ([['profile.updated', User::class], ['settings.updated', null], ['', null], ['cdx.updated', null]] as [$action, $type]) {
            $ordinary->push(AuditLog::query()->forceCreate([
                'action' => $action, 'auditable_type' => $type, 'created_at' => $old, 'updated_at' => $old,
            ]));
        }
        $boundary = AuditLog::query()->forceCreate([
            'action' => 'profile.updated', 'auditable_type' => User::class,
            'created_at' => now()->subYears(2), 'updated_at' => now()->subYears(2),
        ]);
        $recent = AuditLog::query()->forceCreate([
            'action' => 'settings.updated', 'auditable_type' => null, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        $snapshot = fn () => AuditLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $before = $snapshot();
        $retainedIds = $protected->pluck('id')->push($boundary->id, $recent->id)->sort()->values()->all();
        $retainedBefore = AuditLog::query()->whereIn('id', $retainedIds)->orderBy('id')->get()->map->getRawOriginal()->all();

        $this->artisan('oblivion:prune-retention', ['--audit-years' => 2, '--timeline-years' => 5, '--dry-run' => true])
            ->expectsOutput('Audit logs older than 2 years: 4')
            ->expectsOutput('Dry run — no rows deleted.')
            ->assertSuccessful();
        expect($snapshot())->toBe($before);

        $this->artisan('oblivion:prune-retention', ['--audit-years' => 2, '--timeline-years' => 5])
            ->expectsOutput('Audit logs older than 2 years: 4')
            ->expectsOutput('Pruned 4 audit log row(s) and 0 timeline event row(s).')
            ->assertSuccessful();
        expect(AuditLog::query()->whereIn('id', $ordinary->pluck('id'))->count())->toBe(0)
            ->and(AuditLog::query()->orderBy('id')->pluck('id')->all())->toBe($retainedIds)
            ->and($snapshot())->toBe($retainedBefore);

        $this->artisan('oblivion:prune-retention', ['--audit-years' => 2, '--timeline-years' => 5])
            ->expectsOutput('Audit logs older than 2 years: 0')
            ->expectsOutput('Pruned 0 audit log row(s) and 0 timeline event row(s).')
            ->assertSuccessful();
        expect($snapshot())->toBe($retainedBefore);
    } finally {
        $this->travelBack();
    }
});

it('keeps the medication audit boundary null-safe for action and auditable type independently', function () {
    // The persisted schema requires an action; derived rows exercise nullable legacy inputs without changing it.
    $rows = [
        [1, null, null], [2, null, User::class], [3, 'profile.updated', null],
        [4, null, ClientMedication::class], [5, null, ClientControlledDrugEntry::class], [6, 'meds.administer', null],
        [7, 'profile.updated', ClientMedication::class], [8, 'clientmedication.updated', null],
        [9, null, 'ControlledDrugRecord'], [10, null, 'Medication'], [11, 'cd_check_recorded', null], [12, 'cdx.updated', null],
    ];
    $source = DB::query()->selectRaw('? AS id, ? AS action, ? AS auditable_type', $rows[0]);
    foreach (array_slice($rows, 1) as $row) {
        $source->unionAll(DB::query()->selectRaw('? AS id, ? AS action, ? AS auditable_type', $row));
    }

    $eligible = AuditLog::query()->fromSub($source, 'audit_logs')->withoutMedicationEvidence()
        ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($eligible)->toBe([1, 2, 3, 12])->and(AuditLog::query()->count())->toBe(0);
});

it('saves emergency policy into the existing runtime row with structured history and a loosening confirmation', function () {
    $site = Site::factory()->create();
    $manager = p11FinishActor($site, ['medications.view', 'medications.settings.manage', 'sites.viewAll', EmergencyAccessPolicySettings::PERMISSION]);
    $from = (string) BreakGlassPolicy::current()->max_minutes;
    $payload = ['view' => 'alerts', 'changes' => [['group' => 'ea', 'key' => 'max_minutes', 'from' => $from, 'value' => '600']]];
    $this->actingAs($manager)->put('/emar/settings/changes', $payload)->assertSessionHasErrors('confirm_loosening');
    $this->put('/emar/settings/changes', $payload + ['confirm_loosening' => true])->assertSessionHasNoErrors();
    expect(BreakGlassPolicy::current()->max_minutes)->toBe(600)
        ->and(MedicationSettingChange::where('setting_group', 'ea')->sole()->before_value)->toBe($from)
        ->and(MedicationSettingChange::where('setting_group', 'ea')->sole()->loosens)->toBeTrue();
    $this->put('/emar/settings/changes', $payload + ['confirm_loosening' => true])->assertSessionHasErrors('conflict');
});

it('requires the dedicated emergency-policy grant and validates duration relationships under the write lock', function () {
    $site = Site::factory()->create();
    $manager = p11FinishActor($site, ['medications.view', 'medications.settings.manage', 'sites.viewAll']);
    $payload = ['view' => 'alerts', 'changes' => [['group' => 'ea', 'key' => 'default_minutes', 'from' => '60', 'value' => '1000']], 'confirm_loosening' => true];
    $this->actingAs($manager)->put('/emar/settings/changes', $payload)->assertForbidden();
    $granted = p11FinishActor($site, ['medications.view', 'medications.settings.manage', 'sites.viewAll', EmergencyAccessPolicySettings::PERMISSION]);
    $this->actingAs($granted)->put('/emar/settings/changes', $payload)->assertSessionHasErrors('ea.default_minutes');
    expect(BreakGlassPolicy::count())->toBe(0)->and(MedicationSettingChange::where('setting_group', 'ea')->count())->toBe(0);
});

it('lets the account owner consent and withdraw without changing HR contacts or exposing the consent in user payloads', function () {
    $site = Site::factory()->create();
    $user = p11FinishActor($site, []);
    $profile = $user->hrEmployeeProfile;
    $profile->update(['work_phone' => null, 'work_email' => 'synthetic.work@example.test']);
    $user->update(['cellphone' => '021000111']);
    expect(app(OnCallResolver::class)->phoneOf($user->fresh()))->toBeNull();
    $this->actingAs($user)->patch(route('profile.update'), ['on_call_cellphone_consent' => true])->assertSessionHasNoErrors();
    $fresh = $user->fresh();
    expect($fresh->on_call_cellphone_consented_at)->not->toBeNull()->and(app(OnCallResolver::class)->phoneOf($fresh))->toBe('021000111')->and($fresh->toArray())->not->toHaveKey('on_call_cellphone_consented_at');
    $this->patch(route('profile.update'), ['timezone' => 'Pacific/Auckland'])->assertSessionHasNoErrors();
    expect($user->fresh()->on_call_cellphone_consented_at)->not->toBeNull();
    $profile->update(['work_phone' => '09000111']);
    expect(app(OnCallResolver::class)->phoneOf($user->fresh()))->toBe('09000111');
    $this->patch(route('profile.update'), ['on_call_cellphone_consent' => false])->assertSessionHasNoErrors();
    expect($user->fresh()->on_call_cellphone_consented_at)->toBeNull()->and($profile->fresh()->work_email)->toBe('synthetic.work@example.test')->and($profile->fresh()->work_phone)->toBe('09000111')
        ->and(AuditLog::where('action', 'medications.on_call_cellphone_consent.updated')->count())->toBe(2);
});

it('loads alert history only when asked and applies site filters to the deferred response', function () {
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $reader = p11FinishActor($site, ['medications.view', 'medications.audit.view']);
    p11FinishAlert($site);
    p11FinishAlert($other);
    $this->actingAs($reader)->get('/emar/settings')->assertOk()->assertInertia(fn (Assert $p) => $p->component('emar/Settings')->where('alertLog', null)->where('alertLogSummary.open', 1));
    $this->get('/emar/settings?log=1')->assertOk()->assertInertia(fn (Assert $p) => $p->where('alertLog.total', 1)->where('alertLog.data.0.site_name', $site->name));
    $this->get('/emar/settings?log=1&log_house='.$other->id)->assertOk()->assertInertia(fn (Assert $p) => $p->where('alertLog.total', 0));
});

it('keeps the bell chronological by default and pins only shared unattended medication followups when enabled', function () {
    $site = Site::factory()->create();
    $reader = p11FinishActor($site, []);
    $old = p11FinishAlert($site, ['follow_up' => true]);
    $stock = p11FinishAlert($site, ['follow_up' => false]);
    $attended = p11FinishAlert($site, ['follow_up' => true, 'attended_at' => now()]);
    $make = fn (array $data, $at) => $reader->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'SyntheticP11Bell', 'data' => $data, 'created_at' => $at, 'updated_at' => $at]);
    $pinned = $make(['medication_alert_id' => $old->id], now()->subHours(3));
    $stockN = $make(['medication_alert_id' => $stock->id], now()->subHours(2));
    $attendedN = $make(['medication_alert_id' => $attended->id], now()->subHour());
    $ordinary = $make(['type' => 'ordinary'], now());
    $ids = fn () => app(MedicationBellOrder::class)->apply($reader->notifications())->latest()->limit(2)->pluck('id')->all();
    expect($ids())->toBe([$ordinary->id, $attendedN->id]);
    AppSetting::updateOrCreate(['key' => MedicationSettingsRegistry::DELIVERY_PIN_UNATTENDED], ['value' => 'yes']);
    expect($ids())->toBe([$pinned->id, $ordinary->id]);
    $old->update(['attended_at' => now()]);
    expect($ids())->toBe([$ordinary->id, $attendedN->id]);
});

it('saves and audits the canonical P10 second-person and review rules without changing an earlier snapshot', function () {
    $site = Site::factory()->create();
    $manager = p11FinishActor($site, ['medications.view', 'medications.settings.manage', 'sites.viewAll', EmergencyAccessPolicySettings::PERMISSION]);
    $snapshot = BreakGlassPolicy::current()->snapshot();
    expect($snapshot['second_person'])->toBe('optional')->and($snapshot['review_days'])->toBe(2);
    $stricter = ['view' => 'alerts', 'changes' => [
        ['group' => 'ea', 'key' => 'second_person', 'from' => 'optional', 'value' => 'required'],
        ['group' => 'ea', 'key' => 'review_days', 'from' => '2', 'value' => '1'],
    ]];
    $this->actingAs($manager)->put('/emar/settings/changes', $stricter)->assertSessionHasNoErrors();
    expect(BreakGlassPolicy::current()->second_person)->toBe('required')->and(BreakGlassPolicy::current()->review_days)->toBe(1)
        ->and(MedicationSettingChange::where('setting_group', 'ea')->count())->toBe(2)
        ->and(MedicationSettingChange::where('setting_group', 'ea')->where('loosens', true)->count())->toBe(0)
        ->and($snapshot['second_person'])->toBe('optional')->and($snapshot['review_days'])->toBe(2);
    $looser = ['view' => 'alerts', 'changes' => [
        ['group' => 'ea', 'key' => 'second_person', 'from' => 'required', 'value' => 'off'],
        ['group' => 'ea', 'key' => 'review_days', 'from' => '1', 'value' => '3'],
    ]];
    $this->put('/emar/settings/changes', $looser)->assertSessionHasErrors('confirm_loosening');
    expect(BreakGlassPolicy::current()->second_person)->toBe('required')->and(MedicationSettingChange::where('setting_group', 'ea')->count())->toBe(2);
    $this->put('/emar/settings/changes', $looser + ['confirm_loosening' => true])->assertSessionHasNoErrors();
    expect(BreakGlassPolicy::current()->second_person)->toBe('off')->and(BreakGlassPolicy::current()->review_days)->toBe(3)
        ->and(MedicationSettingChange::where('setting_group', 'ea')->where('loosens', true)->count())->toBe(2);
});
