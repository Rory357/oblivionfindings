<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Notifications\AppEventNotification;
use App\Notifications\MedicationCompetencyExpiringNotification;
use App\Notifications\MedicationRefusalClusterNotification;
use App\Notifications\MedicationStockLowNotification;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
 * Medication alert routing faults found while designing P11
 * (docs/emar-design/P11/v1/README.md, "Facts from today's app").
 * Recipients stay as they are today apart from the break-glass daily report,
 * which Stephan narrowed to its configured groups (29 Sep 2026).
 */

beforeEach(function () {
    Cache::flush();
    Carbon::setTestNow(Carbon::parse('2026-06-08 11:15:00', 'Pacific/Auckland')->utc());
    $this->seed(RbacSeeder::class);
});

afterEach(function () {
    Cache::flush();
    Carbon::setTestNow();
});

function medicationAlertStaff(Site $site, ?string $roleName = null, array $allow = []): User
{
    $user = User::factory()->create(['approved_at' => now()]);
    if ($roleName !== null) {
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $roleName)->firstOrFail()->id]);
    }
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'primary_site_id' => $site->id,
        'is_active' => true,
    ]);
    if ($allow !== []) {
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $allow)->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])
                ->all(),
        );
    }

    return $user;
}

/** Three refusals of one medication in the last seven days. */
function medicationRefusalCluster(Site $site, array $medication = []): ClientMedication
{
    $recorder = User::factory()->create(['approved_at' => now()]);
    $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Mere', 'last_name' => 'Wilson']);
    $order = ClientMedication::factory()->create(array_merge([
        'client_id' => $client->id,
        'name' => 'Risperidone',
        'active' => true,
        'state' => 'active',
        'is_prn' => false,
        'controlled_drug' => false,
        'start_date' => '2026-05-01',
        'end_date' => null,
    ], $medication));

    foreach ([1, 2, 3] as $daysAgo) {
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $order->id,
            'administered_by' => $recorder->id,
            'scheduled_for' => now()->subDays($daysAgo),
            'status' => 'refused',
        ]);
    }

    return $order;
}

function medicationCompetency(User $staff, User $assessor, array $overrides = []): MedicationCompetencyAssessment
{
    return MedicationCompetencyAssessment::query()->create(array_merge([
        'user_id' => $staff->id,
        'assessor_id' => $assessor->id,
        'assessment_type' => 'annual',
        'status' => 'passed',
        'assessment_date' => now()->subYear()->addDays(20)->toDateString(),
        'expiry_date' => now()->addDays(20)->toDateString(),
        'assessor_declared_at' => now()->subYear()->addDays(20),
        'staff_acknowledged_at' => now()->subYear()->addDays(21),
        'restricted' => false,
    ], $overrides));
}

it('sends refusal-cluster alerts to team leads at the house instead of failing on a missing roles column (NF-28)', function () {
    Notification::fake();
    $site = Site::factory()->create();
    $lead = medicationAlertStaff($site, 'team_lead');
    $otherHouseLead = medicationAlertStaff(Site::factory()->create(), 'team_lead');
    $worker = medicationAlertStaff($site, 'support_worker', ['medications.view']);
    $order = medicationRefusalCluster($site);

    $this->artisan('emar:send-alerts')->assertExitCode(0);

    Notification::assertSentTo(
        $lead,
        MedicationRefusalClusterNotification::class,
        fn (MedicationRefusalClusterNotification $notification) => $notification->clientId === $order->client_id
            && $notification->medication === 'Risperidone'
            && $notification->count === 3,
    );
    Notification::assertNotSentTo($otherHouseLead, MedicationRefusalClusterNotification::class);
    Notification::assertNotSentTo($worker, MedicationRefusalClusterNotification::class);
});

it('conceals controlled-drug refusal clusters from team leads without controlled-drug view', function () {
    Notification::fake();
    $site = Site::factory()->create();
    $lead = medicationAlertStaff($site, 'team_lead');
    $controlledLead = medicationAlertStaff($site, 'team_lead', ['medications.controlled.view']);
    medicationRefusalCluster($site, ['name' => 'Morphine', 'controlled_drug' => true]);

    $this->artisan('emar:send-alerts')->assertExitCode(0);

    Notification::assertNotSentTo($lead, MedicationRefusalClusterNotification::class);
    Notification::assertSentTo($controlledLead, MedicationRefusalClusterNotification::class);
});

it('sends a refusal-cluster alert once a day per lead and medication, not every 15-minute run', function () {
    $site = Site::factory()->create();
    $lead = medicationAlertStaff($site, 'team_lead');
    medicationRefusalCluster($site);
    $sent = fn () => $lead->notifications()->where('type', MedicationRefusalClusterNotification::class)->count();

    $this->artisan('emar:send-alerts')->assertExitCode(0);
    Carbon::setTestNow(now()->addMinutes(15));
    $this->artisan('emar:send-alerts')->assertExitCode(0);

    expect($sent())->toBe(1);

    Carbon::setTestNow(now()->addDay());
    $this->artisan('emar:send-alerts')->assertExitCode(0);

    expect($sent())->toBe(2);
});

it('sends each competency renewal reminder once, not every 15-minute run', function () {
    $site = Site::factory()->create();
    $staff = medicationAlertStaff($site);
    medicationCompetency($staff, medicationAlertStaff($site));
    $sent = fn () => $staff->notifications()->where('type', MedicationCompetencyExpiringNotification::class)->count();

    $this->artisan('emar:send-alerts')->assertExitCode(0);
    Carbon::setTestNow(now()->addMinutes(15));
    $this->artisan('emar:send-alerts')->assertExitCode(0);
    Carbon::setTestNow(now()->addDay());
    $this->artisan('emar:send-alerts')->assertExitCode(0);

    expect($sent())->toBe(1);
});

it('does not send a renewal reminder for an assessment that has already been renewed', function () {
    Notification::fake();
    $site = Site::factory()->create();
    $assessor = medicationAlertStaff($site);
    $renewed = medicationAlertStaff($site);
    $notRenewed = medicationAlertStaff($site);
    medicationCompetency($renewed, $assessor);
    medicationCompetency($renewed, $assessor, [
        'assessment_date' => now()->subDay()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
        'assessor_declared_at' => now()->subDay(),
        'staff_acknowledged_at' => now()->subDay()->addHour(),
    ]);
    medicationCompetency($notRenewed, $assessor);

    $this->artisan('emar:send-alerts')->assertExitCode(0);

    Notification::assertNotSentTo($renewed, MedicationCompetencyExpiringNotification::class);
    Notification::assertSentTo($notRenewed, MedicationCompetencyExpiringNotification::class);
});

it('sends the break-glass daily report under its catalogued key so notification settings apply', function () {
    Notification::fake();
    $site = Site::factory()->create();
    $optedOutAdmin = medicationAlertStaff($site, 'admin');
    UserNotificationPreference::query()->create([
        'user_id' => $optedOutAdmin->id,
        'key' => 'breakglass.daily_report',
        'enabled' => false,
    ]);

    $this->artisan('breakglass:daily-report')->assertExitCode(0);

    Notification::assertNotSentTo($optedOutAdmin, AppEventNotification::class);
});

it('sends the break-glass daily report only to provider managers, admins and auditors', function () {
    Notification::fake();
    $site = Site::factory()->create();
    $recipients = collect(['admin', 'provider_manager', 'auditor'])
        ->map(fn (string $role) => medicationAlertStaff($site, $role));
    $others = collect(['coordinator', 'hr', 'finance', 'team_lead', 'support_worker'])
        ->map(fn (string $role) => medicationAlertStaff($site, $role));

    $this->artisan('breakglass:daily-report')->assertExitCode(0);

    $recipients->each(fn (User $user) => Notification::assertSentTo(
        $user,
        AppEventNotification::class,
        fn (AppEventNotification $notification) => $notification->payload['event_key'] === 'breakglass.daily_report',
    ));
    $others->each(fn (User $user) => Notification::assertNotSentTo($user, AppEventNotification::class));
});

it('still sends the low-stock notification after the 06:00 stock check has run', function () {
    Notification::fake();
    $site = Site::factory()->create();
    $recipient = medicationAlertStaff($site, null, ['medications.view']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $order = ClientMedication::factory()->create([
        'client_id' => $client->id,
        'name' => 'Paracetamol',
        'active' => true,
        'state' => 'active',
        'controlled_drug' => false,
        'end_date' => null,
    ]);
    ClientMedicationStock::query()->create([
        'client_medication_id' => $order->id,
        'on_hand' => 2,
        'reorder_level' => 10,
        'unit' => 'tablets',
    ]);

    $this->artisan('emar:check-medication-stock')->assertExitCode(0);
    $this->artisan('emar:send-alerts')->assertExitCode(0);

    Notification::assertSentTo($recipient, MedicationStockLowNotification::class);
});

it('treats a medication order as expiring soon only inside the window', function () {
    $endingOn = fn (?string $endDate) => (new ClientMedication)->forceFill(['end_date' => $endDate]);
    // The window counts days on the New Zealand calendar (11:15 am, 8 June).
    $inDays = fn (int $days) => now(config('app.worker_timezone'))->addDays($days)->toDateString();

    expect($endingOn($inDays(3))->isExpiringSoon())->toBeTrue()
        ->and($endingOn($inDays(7))->isExpiringSoon())->toBeTrue()
        ->and($endingOn($inDays(8))->isExpiringSoon())->toBeFalse()
        ->and($endingOn($inDays(60))->isExpiringSoon())->toBeFalse()
        ->and($endingOn($inDays(60))->isExpiringSoon(90))->toBeTrue()
        ->and($endingOn($inDays(-1))->isExpiringSoon())->toBeFalse()
        ->and($endingOn(null)->isExpiringSoon())->toBeFalse();
});
