<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientControlledDrugDiscrepancy;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAlert;
use App\Models\MedicationAlertEvent;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationError;
use App\Models\MedicationRefusalFollowup;
use App\Models\MedicationSiteSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\MedicationAlertNotification;
use App\Services\Medication\Alerts\MedicationAlertRecipients;
use App\Services\Medication\Alerts\MedicationAlerts;
use App\Services\Medication\Alerts\MedicationAlertSettings;
use App\Services\Medication\Alerts\MedicationAlertSources;
use App\Services\Medication\Alerts\MedicationAlertSubject;
use App\Services\Medication\Settings\MedicationSettingsRegistry;
use App\Services\MedicationAlertService;
use App\Services\MedicationIncidentIntegrationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * P11 B2 chunk 1: medication alerts are raised once per open subject, to the
 * people Medication Settings › Alerts & access chooses (v5's defaults until
 * someone reviews them), never past today's gate (medication access at the
 * house; controlled alerts only to controlled-medicine access).
 */

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-10-02 11:15:00', 'Pacific/Auckland')->utc());
    $this->seed(RbacSeeder::class);
    Notification::fake();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** @param list<string> $allow @param list<string> $deny */
function b2Staff(?Site $site, ?string $role = null, array $allow = [], array $deny = []): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    if ($role !== null) {
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
    }
    if ($site !== null) {
        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => 'EMP-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Staff',
            'position_role' => $role ?? 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subYear()->toDateString(),
            'primary_site_id' => $site->id,
            'is_active' => true,
        ]);
    }
    $overrides = [];
    foreach (Permission::query()->whereIn('key', $allow)->pluck('id') as $id) {
        $overrides[$id] = ['allowed' => true];
    }
    foreach (Permission::query()->whereIn('key', $deny)->pluck('id') as $id) {
        $overrides[$id] = ['allowed' => false];
    }
    if ($overrides !== []) {
        $user->permissionOverrides()->syncWithoutDetaching($overrides);
    }

    return $user;
}

function b2Order(Site $site, array $attributes = []): ClientMedication
{
    $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Aroha', 'last_name' => 'Ngata']);

    return ClientMedication::factory()->create(array_merge([
        'client_id' => $client->id,
        'name' => 'Salbutamol inhaler',
        'active' => true,
        'state' => 'active',
        'is_prn' => false,
        'controlled_drug' => false,
        'start_date' => '2026-09-01',
        'end_date' => null,
    ], $attributes));
}

function b2Subject(Site $site, string $key, bool $controlled = false): MedicationAlertSubject
{
    return new MedicationAlertSubject(
        key: $key,
        siteId: (int) $site->id,
        title: 'Test alert',
        message: 'Something at '.$site->name.'.',
        shortMessage: 'Something at '.$site->name.'.',
        controlled: $controlled,
    );
}

function b2Told(User $user, string $type): bool
{
    return Notification::sent($user, MedicationAlertNotification::class)
        ->contains(fn (MedicationAlertNotification $n) => $n->alert->type === $type);
}

function b2Setting(string $alert, array $value): void
{
    AppSetting::query()->updateOrCreate(['key' => 'medications.alerts.'.$alert], ['value' => json_encode($value)]);
}

it('raises an alert once while it is open, even after a deploy clears the cache', function () {
    $site = Site::factory()->create();
    $lead = b2Staff($site, 'team_lead');
    $alerts = app(MedicationAlerts::class);

    expect($alerts->raise('stock', b2Subject($site, 'stock:1')))->not->toBeNull();
    Cache::flush();
    expect($alerts->raise('stock', b2Subject($site, 'stock:1')))->toBeNull();

    expect(MedicationAlert::query()->count())->toBe(1);
    Notification::assertSentToTimes($lead, MedicationAlertNotification::class, 1);
});

it('tells the groups switched on for the alert, only at the alert’s house', function () {
    $site = Site::factory()->create();
    $lead = b2Staff($site, 'team_lead');
    $otherLead = b2Staff(Site::factory()->create(), 'team_lead');
    $clinical = b2Staff(null, 'clinical_lead');
    $manager = b2Staff(null, 'provider_manager');
    $worker = b2Staff($site, 'support_worker');

    // v5 default for "Medication errors reported": house lead and clinical lead.
    app(MedicationAlerts::class)->raise('errors', b2Subject($site, 'error:1'));

    expect(b2Told($lead, 'errors'))->toBeTrue()
        ->and(b2Told($clinical, 'errors'))->toBeTrue()
        ->and(b2Told($otherLead, 'errors'))->toBeFalse()
        ->and(b2Told($manager, 'errors'))->toBeFalse()
        ->and(b2Told($worker, 'errors'))->toBeFalse();
    $sent = MedicationAlert::query()->firstOrFail()->events()->where('event', MedicationAlertEvent::SENT)->firstOrFail();
    expect(collect($sent->detail['told'])->pluck('reason', 'user_id')->all())
        ->toEqual([$lead->id => 'houseLead', $clinical->id => 'clinicalLead']);
});

it('keeps a controlled alert from people without controlled-medicine access, and records who', function () {
    $site = Site::factory()->create();
    $lead = b2Staff($site, 'team_lead', deny: ['medications.controlled.view']);
    $controlledLead = b2Staff($site, 'team_lead');

    // "Controlled-drug balance check overdue" is always controlled; its default is the house lead.
    $alert = app(MedicationAlerts::class)->raise('cdCheck', b2Subject($site, 'cd-check:'.$site->id));

    expect(b2Told($lead, 'cdCheck'))->toBeFalse()
        ->and(b2Told($controlledLead, 'cdCheck'))->toBeTrue()
        ->and($alert->controlled)->toBeTrue()
        ->and($alert->events()->where('event', MedicationAlertEvent::NOT_TOLD_CONTROLLED)->value('detail'))
        ->toEqual(['user_ids' => [$lead->id]]);
});

it('adds named people everywhere they work and house extras only at their house', function () {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $named = b2Staff(null, 'provider_manager');
    $extra = b2Staff($siteA, 'support_worker');
    b2Setting('stock', ['inapp' => true, 'email' => false, 'push' => false, 'follow_up' => false, 'groups' => [], 'people' => [$named->id]]);
    MedicationSiteSetting::query()->create(['site_id' => $siteA->id, 'key' => 'medications.alert_extras.stock', 'value' => json_encode([$extra->id])]);
    $alerts = app(MedicationAlerts::class);

    $alerts->raise('stock', b2Subject($siteA, 'stock:a'));
    expect(b2Told($named, 'stock'))->toBeTrue()->and(b2Told($extra, 'stock'))->toBeTrue();

    Notification::fake();
    $alerts->raise('stock', b2Subject($siteB, 'stock:b'));
    expect(b2Told($named, 'stock'))->toBeTrue()->and(b2Told($extra, 'stock'))->toBeFalse();
});

it('tells everyone rostered on a shift covering the alert time', function () {
    $site = Site::factory()->create();
    $onShift = b2Staff($site, 'support_worker');
    $offShift = b2Staff($site, 'support_worker');
    $client = Client::factory()->create(['site_id' => $site->id]);
    Shift::factory()->create(['client_id' => $client->id, 'site_id' => $site->id, 'user_id' => $onShift->id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->addHours(4), 'status' => 'in_progress']);
    Shift::factory()->create(['client_id' => $client->id, 'site_id' => $site->id, 'user_id' => $offShift->id, 'starts_at' => now()->subHours(9), 'ends_at' => now()->subHour(), 'status' => 'scheduled']);

    // "Follow-ups overdue" is decided: everyone rostered and the house lead, always.
    app(MedicationAlerts::class)->raise('followups', b2Subject($site, 'refusal-followup:1'));

    expect(b2Told($onShift, 'followups'))->toBeTrue()->and(b2Told($offShift, 'followups'))->toBeFalse();
});

it('sends an alert whose groups are empty at the house to medication settings managers there', function () {
    $site = Site::factory()->create();
    $coordinator = b2Staff($site, 'coordinator');
    $otherCoordinator = b2Staff(Site::factory()->create(), 'coordinator');

    // "Medication review due" goes to clinical leads by default; there are none.
    $alert = app(MedicationAlerts::class)->raise('reviewDue', b2Subject($site, 'chart-review:1'));

    expect(b2Told($coordinator, 'reviewDue'))->toBeTrue()
        ->and(b2Told($otherCoordinator, 'reviewDue'))->toBeFalse()
        ->and($alert->reached_nobody)->toBeFalse()
        ->and($alert->events()->where('event', MedicationAlertEvent::FALLBACK)->exists())->toBeTrue()
        ->and($alert->recipients()->value('reason'))->toBe('fallback');
});

it('records “Nobody could be told”, with the reason, when even the safety net is empty', function () {
    $site = Site::factory()->create();

    $alert = app(MedicationAlerts::class)->raise('reviewDue', b2Subject($site, 'chart-review:1'));

    expect($alert->reached_nobody)->toBeTrue()
        ->and($alert->events()->where('event', MedicationAlertEvent::NOBODY_TOLD)->value('detail'))
        ->toMatchArray(['reason' => 'Nobody in its groups, and no medication settings manager with access to this house.']);
});

it('marks alerts no longer true as dealt with, so the same subject can alert again', function () {
    $site = Site::factory()->create();
    b2Staff($site, 'team_lead');
    $alerts = app(MedicationAlerts::class);
    $alerts->raise('stock', b2Subject($site, 'stock:1'));
    $alerts->raise('stock', b2Subject($site, 'stock:2'));

    expect($alerts->reconcile('stock', ['stock:2'], 'Restocked'))->toBe(1);

    $first = MedicationAlert::query()->where('dedupe_key', 'stock:stock:1')->firstOrFail();
    expect($first->status)->toBe('dealt_with')->and($first->outcome)->toBe('Restocked')->and($first->open_key)->toBeNull();
    expect($alerts->raise('stock', b2Subject($site, 'stock:1')))->not->toBeNull();
    expect($alerts->raise('stock', b2Subject($site, 'stock:2')))->toBeNull();
});

it('raises low stock, expiring, expired and out-of-stock alerts from the stock checks', function () {
    $site = Site::factory()->create(['name' => 'Kōwhai House']);
    $lead = b2Staff($site, 'team_lead');
    $stockStaff = b2Staff($site, 'support_worker', ['medications.stock.update']);
    $low = b2Order($site);
    ClientMedicationStock::query()->create(['client_medication_id' => $low->id, 'on_hand' => 2, 'reorder_level' => 10, 'unit' => 'doses']);
    $out = b2Order($site, ['name' => 'Levetiracetam']);
    ClientMedicationStock::query()->create(['client_medication_id' => $out->id, 'on_hand' => 0, 'reorder_level' => 5, 'unit' => 'tablets']);
    $expiring = b2Order($site, ['name' => 'Enoxaparin 40 mg']);
    ClientMedicationStock::query()->create(['client_medication_id' => $expiring->id, 'on_hand' => 20, 'reorder_level' => 2, 'unit' => 'syringes', 'expiry_date' => '2026-10-06']);

    $this->artisan('emar:send-alerts')->assertExitCode(0);
    $this->artisan('emar:check-medication-stock')->assertExitCode(0);

    $messages = MedicationAlert::query()->pluck('message', 'type');
    expect(MedicationAlert::query()->where('type', 'stock')->pluck('message')->all())
        ->toContain('Salbutamol inhaler for Aroha N. is below its reorder level (2 left). Kōwhai House.')
        ->and($messages['expiry'])->toBe('Enoxaparin 40 mg for Aroha N. expires on 6 Oct 2026. Kōwhai House.')
        ->and($messages['outOfStock'])->toBe('Levetiracetam for Aroha N. is out of stock. Kōwhai House.');
    // v5 defaults: low stock to the house lead and stock staff; expiring to stock staff.
    expect(b2Told($lead, 'stock'))->toBeTrue()
        ->and(b2Told($stockStaff, 'stock'))->toBeTrue()
        ->and(b2Told($stockStaff, 'expiry'))->toBeTrue()
        ->and(b2Told($lead, 'expiry'))->toBeFalse()
        ->and(b2Told($lead, 'outOfStock'))->toBeTrue();
    expect(MedicationAlert::query()->where('type', 'expiry')->value('severity'))->toBe('critical');
});

it('raises one balance-check alert per house and deals with it when every check is done', function () {
    $site = Site::factory()->create(['name' => 'Rimu House']);
    $lead = b2Staff($site, 'team_lead');
    b2Order($site, ['name' => 'Methylphenidate', 'controlled_drug' => true]);

    $this->artisan('emar:escalate-overdue-cd-checks')->assertExitCode(0);

    $alert = MedicationAlert::query()->where('type', 'cdCheck')->firstOrFail();
    expect($alert->message)->toBe('No controlled-drug balance check at Rimu House for 7 days (1 medicine).')
        ->and(b2Told($lead, 'cdCheck'))->toBeTrue();

    ClientMedication::query()->update(['active' => false]);
    $this->artisan('emar:escalate-overdue-cd-checks')->assertExitCode(0);
    expect($alert->fresh()->status)->toBe('dealt_with');
});

it('raises as-needed limit and review alerts when a person’s alerts are refreshed', function () {
    $site = Site::factory()->create(['name' => 'Kōwhai House']);
    $lead = b2Staff($site, 'team_lead');
    $clinical = b2Staff(null, 'clinical_lead');
    $prn = b2Order($site, ['name' => 'Paracetamol', 'is_prn' => true, 'max_per_day' => '2']);
    foreach ([1, 3] as $hoursAgo) {
        ClientMedicationAdministration::query()->create([
            'client_id' => $prn->client_id,
            'client_medication_id' => $prn->id,
            'administered_by' => $lead->id,
            'administered_at' => now()->subHours($hoursAgo),
            'status' => 'given',
        ]);
    }
    $client = $prn->client;
    $client->forceFill(['next_chart_review_date' => '2026-10-05'])->save();

    app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

    expect(MedicationAlert::query()->where('type', 'prnLimit')->value('message'))
        ->toBe('Aroha N. — as-needed Paracetamol has reached the order’s daily limit. Kōwhai House.')
        ->and(b2Told($lead, 'prnLimit'))->toBeTrue()
        ->and(b2Told($clinical, 'prnLimit'))->toBeTrue()
        ->and(MedicationAlert::query()->where('type', 'reviewDue')->value('message'))
        ->toBe('Aroha N.’s medication chart review is due by 5 Oct 2026. Kōwhai House.')
        ->and(b2Told($clinical, 'reviewDue'))->toBeTrue();
});

it('tells people about a reported medication error and a controlled-drug discrepancy', function () {
    $site = Site::factory()->create(['name' => 'Rimu House']);
    $lead = b2Staff($site, 'team_lead');
    $order = b2Order($site, ['name' => 'Methylphenidate', 'controlled_drug' => true]);
    $error = MedicationError::query()->create([
        'client_id' => $order->client_id,
        'client_medication_id' => $order->id,
        'error_type' => 'wrong_time',
        'severity' => 'minor',
        'description' => 'Given at the wrong time.',
        'reported_by' => $lead->id,
        'reported_at' => now(),
        'status' => 'reported',
    ]);
    $discrepancy = ClientControlledDrugDiscrepancy::query()->create([
        'client_id' => $order->client_id,
        'client_medication_id' => $order->id,
        'on_hand_before' => 10,
        'on_hand_after' => 9,
        'difference' => -1,
        'status' => 'open',
        'reported_at' => now(),
        'reported_by' => $lead->id,
    ]);

    app(MedicationAlertSources::class)->error($error);
    app(MedicationAlertSources::class)->discrepancy($discrepancy);

    expect(MedicationAlert::query()->where('type', 'errors')->value('message'))
        ->toBe('A medication error was reported at Rimu House: Aroha N., Methylphenidate — wrong time.')
        ->and(MedicationAlert::query()->where('type', 'cdDiscrepancy')->value('message'))
        ->toBe('Controlled-drug count doesn’t match — Methylphenidate at Rimu House: 1 short.')
        ->and(b2Told($lead, 'errors'))->toBeTrue()
        ->and(b2Told($lead, 'cdDiscrepancy'))->toBeTrue();

    app(MedicationAlertSources::class)->discrepancyResolved($discrepancy);
    expect(MedicationAlert::query()->where('type', 'cdDiscrepancy')->value('status'))->toBe('dealt_with');
});

it('raises a follow-up alert for a refusal follow-up past its due time', function () {
    $site = Site::factory()->create(['name' => 'Kōwhai House']);
    $lead = b2Staff($site, 'team_lead');
    $order = b2Order($site, ['name' => 'Digoxin']);
    $refusal = ClientMedicationAdministration::query()->create([
        'client_id' => $order->client_id,
        'client_medication_id' => $order->id,
        'administered_by' => $lead->id,
        'scheduled_for' => now()->subDay(),
        'status' => 'refused',
    ]);
    MedicationRefusalFollowup::query()->create([
        'client_id' => $order->client_id,
        'client_medication_administration_id' => $refusal->id,
        'reason_category' => 'personal_choice',
        'follow_up_due_at' => now()->subHour(),
        'created_by' => $lead->id,
    ]);

    $this->artisan('emar:send-alerts')->assertExitCode(0);

    expect(MedicationAlert::query()->where('type', 'followups')->value('message'))
        ->toContain('Aroha N. — refusal follow-up for Digoxin was due 2 Oct 2026, 10:15 am. Kōwhai House.')
        ->and(b2Told($lead, 'followups'))->toBeTrue();
});

it('never raises an alert that isn’t offered yet', function () {
    $site = Site::factory()->create();
    b2Staff($site, 'team_lead');

    // Witness overrides arrive with PIN-2; the emergency-access report keeps its routing until B3.
    expect(app(MedicationAlerts::class)->raise('override', b2Subject($site, 'override:1')))->toBeNull()
        ->and(app(MedicationAlerts::class)->raise('breakglass', b2Subject($site, 'report:1')))->toBeNull();
    expect(MedicationAlert::query()->count())->toBe(0);
});

/** How many of this alert type the person was sent. */
function b2SentCount(User $user, string $type): int
{
    return Notification::sent($user, MedicationAlertNotification::class)
        ->filter(fn (MedicationAlertNotification $n) => $n->alert->type === $type)
        ->count();
}

/*
 * B2 C1 review fixes (Main, under Stephan's delegation).
 */

it('raises out of stock at 0 from the 6:00 am check when the stock has no reorder level', function () {
    $site = Site::factory()->create(['name' => 'Kōwhai House']);
    $lead = b2Staff($site, 'team_lead');
    $order = b2Order($site, ['name' => 'Levetiracetam']);
    ClientMedicationStock::query()->create(['client_medication_id' => $order->id, 'on_hand' => 0, 'reorder_level' => null, 'unit' => 'tablets']);
    // Run out on an order that has ended: nothing to give, so no alert.
    $ended = b2Order($site, ['name' => 'Amoxicillin', 'active' => false, 'state' => 'discontinued']);
    ClientMedicationStock::query()->create(['client_medication_id' => $ended->id, 'on_hand' => 0, 'reorder_level' => null, 'unit' => 'capsules']);

    $this->artisan('emar:check-medication-stock')->assertExitCode(0);

    expect(MedicationAlert::query()->where('type', 'outOfStock')->pluck('message')->all())
        ->toBe(['Levetiracetam for Aroha N. is out of stock. Kōwhai House.'])
        ->and(b2SentCount($lead, 'outOfStock'))->toBe(1);
});

it('raises out of stock when a person’s alerts refresh after a change, and deals with it once restocked', function () {
    $site = Site::factory()->create(['name' => 'Kōwhai House']);
    $lead = b2Staff($site, 'team_lead');
    $order = b2Order($site, ['name' => 'Levetiracetam']);
    $stock = ClientMedicationStock::query()->create(['client_medication_id' => $order->id, 'on_hand' => 0, 'reorder_level' => null, 'unit' => 'tablets']);
    $insulin = ClientMedication::factory()->create([
        'client_id' => $order->client_id,
        'name' => 'Insulin glargine',
        'active' => true,
        'state' => 'active',
        'is_prn' => false,
        'controlled_drug' => false,
        'start_date' => '2026-09-01',
        'end_date' => null,
    ]);
    $expired = ClientMedicationStock::query()->create(['client_medication_id' => $insulin->id, 'on_hand' => 5, 'reorder_level' => null, 'unit' => 'pens', 'expiry_date' => '2026-09-30']);

    // Every dose recorded and every order setting change runs this refresh.
    app(MedicationAlertService::class)->generateClientAlerts($order->client->fresh());
    expect(MedicationAlert::query()->where('type', 'outOfStock')->whereNotNull('open_key')->pluck('open_key')->all())
        ->toBe(['outOfStock:out:'.$stock->id])
        ->and(b2SentCount($lead, 'outOfStock'))->toBe(1);

    // The 6:00 am check finds the expired pen; the run-out alert is already open.
    $this->artisan('emar:check-medication-stock')->assertExitCode(0);
    expect(MedicationAlert::query()->where('type', 'outOfStock')->whereNotNull('open_key')->pluck('open_key')->sort()->values()->all())
        ->toBe(['outOfStock:expired:'.$expired->id.':2026-09-30', 'outOfStock:out:'.$stock->id])
        ->and(b2SentCount($lead, 'outOfStock'))->toBe(2);

    // Restocked: the next refresh deals with the run-out alert, and leaves
    // expired stock to the stock check.
    $stock->update(['on_hand' => 30]);
    app(MedicationAlertService::class)->generateClientAlerts($order->client->fresh());

    $out = MedicationAlert::query()->where('dedupe_key', 'outOfStock:out:'.$stock->id)->firstOrFail();
    expect($out->status)->toBe('dealt_with')
        ->and($out->outcome)->toBe('Restocked or removed')
        ->and(app(MedicationAlerts::class)->isOpen('outOfStock', 'expired:'.$expired->id.':2026-09-30'))->toBeTrue();
});

it('keeps in-app on for a stored choice with every channel off, so the alert still tells people', function () {
    $site = Site::factory()->create();
    $lead = b2Staff($site, 'team_lead');
    b2Setting('stock', ['inapp' => false, 'email' => false, 'push' => false, 'follow_up' => false, 'groups' => ['houseLead'], 'people' => []]);

    $alert = app(MedicationAlerts::class)->raise('stock', b2Subject($site, 'stock:1'));

    expect($alert->reached_nobody)->toBeFalse()
        ->and(b2Told($lead, 'stock'))->toBeTrue()
        ->and($alert->recipients()->value('channels'))->toBe(['inapp']);
});

it('records an alert with no channel to send on as reaching nobody, not as told', function () {
    $site = Site::factory()->create();
    $lead = b2Staff($site, 'team_lead');
    $settings = Mockery::mock(MedicationAlertSettings::class, [app(MedicationSettingsRegistry::class)])->makePartial();
    $settings->shouldReceive('channels')->andReturn([]);
    $this->instance(MedicationAlertSettings::class, $settings);

    $alert = app(MedicationAlerts::class)->raise('stock', b2Subject($site, 'stock:1'));

    expect($alert->reached_nobody)->toBeTrue()
        ->and($alert->recipients()->count())->toBe(0)
        ->and(b2Told($lead, 'stock'))->toBeFalse()
        ->and($alert->events()->where('event', MedicationAlertEvent::NOBODY_TOLD)->value('detail'))
        ->toMatchArray(['reason' => 'No way to tell people is switched on for this alert.', 'would_have_told' => [$lead->id]]);
});

it('keeps nothing when raising fails part-way, so the next check raises it again', function () {
    $site = Site::factory()->create();
    $lead = b2Staff($site, 'team_lead');
    $this->partialMock(MedicationAlertRecipients::class, fn ($mock) => $mock->shouldReceive('resolve')->once()->andThrow(new RuntimeException('Recipient lookup failed')));

    expect(fn () => app(MedicationAlerts::class)->raise('stock', b2Subject($site, 'stock:1')))
        ->toThrow(RuntimeException::class, 'Recipient lookup failed');
    expect(MedicationAlert::query()->count())->toBe(0)
        ->and(MedicationAlertEvent::query()->count())->toBe(0);

    $this->app->forgetInstance(MedicationAlertRecipients::class);
    $alert = app(MedicationAlerts::class)->raise('stock', b2Subject($site, 'stock:1'));

    expect($alert)->not->toBeNull()
        ->and(b2Told($lead, 'stock'))->toBeTrue();
});

it('deals with a medication error’s alert when the error is resolved', function () {
    $site = Site::factory()->create(['name' => 'Rimu House']);
    $lead = b2Staff($site, 'team_lead');
    $order = b2Order($site);
    $error = MedicationError::query()->create([
        'client_id' => $order->client_id,
        'client_medication_id' => $order->id,
        'error_type' => 'omission',
        'severity' => 'minor',
        'description' => 'Missed.',
        'reported_by' => $lead->id,
        'reported_at' => now(),
        'status' => 'reported',
    ]);
    app(MedicationAlertSources::class)->error($error);
    expect(app(MedicationAlerts::class)->isOpen('errors', 'error:'.$error->id))->toBeTrue();

    app(MedicationIncidentIntegrationService::class)->resolveMedicationError($error, 'Medication error resolved.', $lead->id);

    $alert = MedicationAlert::query()->where('type', 'errors')->firstOrFail();
    expect($alert->status)->toBe('dealt_with')
        ->and($alert->outcome)->toBe('The error was resolved');
});

it('closes a renewal reminder as renewed only when there is a renewal', function () {
    $site = Site::factory()->create();
    $assessor = b2Staff($site, 'clinical_lead');
    $renewing = b2Staff($site, 'support_worker', ['medications.view']);
    $lapsing = b2Staff($site, 'support_worker', ['medications.view']);
    $competency = fn (User $staff, string $expiry, array $extra = []) => MedicationCompetencyAssessment::query()->create(array_merge([
        'user_id' => $staff->id,
        'assessor_id' => $assessor->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => '2025-10-20',
        'expiry_date' => $expiry,
        'assessor_declared_at' => now()->subYear(),
        'staff_acknowledged_at' => now()->subYear(),
        'restricted' => false,
    ], $extra));
    $old = $competency($renewing, '2026-10-20');
    $lapsed = $competency($lapsing, '2026-10-20');
    app(MedicationAlertSources::class)->renewals();
    expect(MedicationAlert::query()->where('type', 'renewals')->whereNotNull('open_key')->count())->toBe(2);

    // One renews; the other lapses without a renewal.
    $competency($renewing, '2027-10-20', ['assessment_date' => '2026-10-02']);
    $lapsed->update(['status' => 'expired']);
    app(MedicationAlertSources::class)->renewals();

    expect(MedicationAlert::query()->where('dedupe_key', 'renewals:renewal:'.$old->id.':2026-10-20')->value('outcome'))->toBe('Renewed')
        ->and(MedicationAlert::query()->where('dedupe_key', 'renewals:renewal:'.$lapsed->id.':2026-10-20')->value('outcome'))->toBe('No longer due');
});

it('carries over at deploy what people were already told, so the first run tells nobody again', function () {
    $site = Site::factory()->create(['name' => 'Kōwhai House']);
    $assessor = b2Staff($site, 'clinical_lead');
    $lead = b2Staff($site, 'team_lead');
    $staff = b2Staff($site, 'support_worker', ['medications.view']);
    $low = b2Order($site);
    $stock = ClientMedicationStock::query()->create(['client_medication_id' => $low->id, 'on_hand' => 2, 'reorder_level' => 10, 'unit' => 'doses', 'last_reorder_alert_at' => now()->subHours(5)]);
    $refused = b2Order($site, ['name' => 'Risperidone']);
    $longAgo = b2Order($site, ['name' => 'Quetiapine']);
    $overdue = b2Order($site, ['name' => 'Levetiracetam']);
    $doseKey = $overdue->id.'@2026-10-01T19:00Z~1';
    $competency = MedicationCompetencyAssessment::query()->create([
        'user_id' => $staff->id,
        'assessor_id' => $assessor->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => '2025-10-20',
        'expiry_date' => '2026-10-20',
        'assessor_declared_at' => now()->subYear(),
        'staff_acknowledged_at' => now()->subYear(),
        'restricted' => false,
    ]);
    $told = fn (User $user, string $class, array $data, int $hoursAgo = 3) => DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\'.$class,
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => json_encode($data),
        'created_at' => now()->subHours($hoursAgo),
        'updated_at' => now()->subHours($hoursAgo),
    ]);
    $told($lead, 'MedicationOverdueNotification', ['client_id' => $overdue->client_id, 'dose_key' => $doseKey]);
    $told($lead, 'MedicationRefusalClusterNotification', ['client_id' => $refused->client_id, 'client_medication_id' => $refused->id]);
    $told($staff, 'MedicationCompetencyExpiringNotification', ['assessment_id' => $competency->id, 'expiry_date' => '20/10/2026']);
    // Told days ago: an open cluster was re-told daily, so this one isn't open.
    $told($lead, 'MedicationRefusalClusterNotification', ['client_id' => $longAgo->client_id, 'client_medication_id' => $longAgo->id], 72);

    (require database_path('migrations/2026_10_02_100300_carry_over_notified_medication_alerts.php'))->up();

    expect(MedicationAlert::query()->whereNotNull('open_key')->pluck('open_key')->sort()->values()->all())->toBe([
        'overdue:'.$doseKey,
        'refusals:refusals:'.$refused->id,
        'renewals:renewal:'.$competency->id.':2026-10-20',
        'stock:stock:'.$stock->id,
    ])
        ->and(MedicationAlertEvent::query()->where('event', MedicationAlertEvent::CARRIED_OVER)->count())->toBe(4)
        ->and(MedicationAlert::query()->where('type', 'stock')->first()->only(['site_id', 'client_id', 'reached_nobody']))
        ->toBe(['site_id' => $site->id, 'client_id' => $low->client_id, 'reached_nobody' => false])
        ->and(MedicationAlert::query()->where('type', 'renewals')->value('staff_user_id'))->toBe($staff->id);

    // The first runs find them open: nobody is told again.
    app(MedicationAlertSources::class)->lowStock();
    app(MedicationAlertSources::class)->renewals();
    expect(app(MedicationAlerts::class)->raise('overdue', b2Subject($site, $doseKey)))->toBeNull()
        ->and(app(MedicationAlerts::class)->raise('refusals', b2Subject($site, 'refusals:'.$refused->id)))->toBeNull();
    Notification::assertNothingSent();
});
