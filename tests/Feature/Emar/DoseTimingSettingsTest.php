<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRefusalFollowup;
use App\Models\MedicationSettingChange;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\MedicationAlertNotification;
use App\Services\MarScheduleService;
use App\Services\Medication\DoseSlots\DoseWindowResolver;
use App\Services\Medication\DoseTimingSettings;
use App\Services\Medication\RefusalEscalationPolicy;
use App\Services\MedicationIncidentIntegrationService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 Rounds & timing › Dose timing: the dose window, the late-dose
 * incident and repeated-refusal escalation are organisation settings saved
 * through "Review changes", and every place that uses them reads the same
 * value (DoseTimingSettings, RefusalEscalationPolicy).
 */
class DoseTimingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    public function test_dose_timing_is_saved_through_review_changes_and_every_reader_uses_it(): void
    {
        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll']);
        $schedule = app(MarScheduleService::class);
        $this->assertSame([30, 60], [$schedule->windowBeforeMinutes(), $schedule->windowAfterMinutes()]);

        // Giving doses earlier loosens the window: it needs the confirmation.
        $this->actingAs($manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save([['early', '45', '30'], ['refusal_days', '10', '7']]))
            ->assertSessionHasErrors('confirm_loosening');
        $this->assertNull(AppSetting::query()->where('key', DoseTimingSettings::EARLY_MINUTES)->first());

        $this->actingAs($manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save([['early', '45', '30'], ['refusal_days', '10', '7']], true))
            ->assertRedirect('/emar/settings')
            ->assertSessionHas(
                'medication_settings_saved',
                '2 changes saved. From the next dose shown on Meds today, at every house — recording is never blocked.',
            );

        $this->assertSame(45, $schedule->windowBeforeMinutes());
        $this->assertSame(45, app(DoseWindowResolver::class)->forOrder(1)->beforeMinutes);
        $this->assertSame(60, app(DoseWindowResolver::class)->forOrder(1)->afterMinutes);
        $this->assertSame(10, app(RefusalEscalationPolicy::class)->days());

        $early = MedicationSettingChange::query()->where('setting_key', 'early')->sole();
        $this->assertSame('rounds', $early->view);
        $this->assertSame('timing', $early->section);
        $this->assertSame('Doses can be given from', $early->label);
        $this->assertSame('30 minutes before the dose time', $early->before_text);
        $this->assertSame('45 minutes before the dose time', $early->after_text);
        $this->assertTrue($early->loosens);
        // More days catches more refusals: stricter, not looser.
        $this->assertFalse(MedicationSettingChange::query()->where('setting_key', 'refusal_days')->sole()->loosens);

        $audit = AuditLog::query()->where('action', 'medications.mar_timing.updated')->sole();
        $this->assertSame(['early'], $audit->meta['loosened']);

        $this->actingAs($manager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.values.timing.early', '45')
                ->where('settings.definitions.timing.early.range', [1, 1440])
                ->where('settings.definitions.timing.early.unit', 'minutes before the dose time')
                ->where('settings.definitions.timing.refusal_count.paired_with', 'refusal_days')
                ->missing('settings.definitions.timing.soon')
                ->where('settings.reviewed.timing.late', null));
    }

    public function test_shows_as_due_soon_is_a_setting_again_and_never_loosens_a_check(): void
    {
        // Meds today and the MAR read it since P01 C6(b): v5's row is back.
        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll']);
        $this->actingAs($manager)->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.values.timing.due_soon', '60')
                ->where('settings.definitions.timing.due_soon.label', 'Doses show as due soon')
                ->where('settings.definitions.timing.due_soon.numeric', null));

        // Either way, no confirmation: it only changes what's highlighted.
        $this->actingAs($manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save([['due_soon', '90', '60']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(90, app(DoseTimingSettings::class)->dueSoonMinutes());
        $this->actingAs($manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save([['due_soon', '15', '90']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(15, app(DoseTimingSettings::class)->dueSoonMinutes());
    }

    public function test_a_number_outside_its_range_is_refused_with_the_range(): void
    {
        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll']);

        foreach (['0', '1441', '2.5', 'soon', ''] as $value) {
            $this->actingAs($manager)
                ->from('/emar/settings')
                ->put('/emar/settings/changes', $this->save([['late', $value, '60']], true))
                ->assertSessionHasErrors([
                    'changes.0.value' => 'Enter a whole number from 1 to 1,440 for “Doses count as late”.',
                ]);
        }
        $this->actingAs($manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', $this->save([['refusal_count', '51', '3']], true))
            ->assertSessionHasErrors([
                'changes.0.value' => 'Enter a whole number from 1 to 50 for “Repeated refusals escalate”.',
            ]);

        $this->assertSame(0, MedicationSettingChange::query()->count());
    }

    public function test_a_house_manager_reads_dose_timing_but_cannot_change_it(): void
    {
        $houseManager = $this->staff(['medications.settings.manage']);

        $this->actingAs($houseManager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.can_manage_organisation', false)
                ->where('settings.values.timing.late', '60'));

        $this->actingAs($houseManager)
            ->put('/emar/settings/changes', $this->save([['late', '30', '60']]))
            ->assertForbidden();
        $this->assertNull(AppSetting::query()->where('key', DoseTimingSettings::LATE_MINUTES)->first());
    }

    public function test_keeping_the_escalation_default_keeps_both_numbers(): void
    {
        $manager = $this->staff(['medications.settings.manage', 'sites.viewAll']);

        $this->actingAs($manager)
            ->post('/emar/settings/keep', ['items' => [
                ['group' => 'timing', 'key' => 'refusal_count', 'site_id' => null],
                ['group' => 'timing', 'key' => 'refusal_days', 'site_id' => null],
            ]])
            ->assertRedirect();

        $this->assertSame('3', AppSetting::query()->where('key', DoseTimingSettings::REFUSAL_COUNT)->value('value'));
        $this->assertSame('7', AppSetting::query()->where('key', DoseTimingSettings::REFUSAL_DAYS)->value('value'));
        $this->assertSame(
            ['Kept: 3 refusals or withholds', 'Kept: 7 days'],
            MedicationSettingChange::query()->orderBy('id')->pluck('after_text')->all(),
        );
    }

    public function test_a_stored_value_out_of_range_reads_as_the_default(): void
    {
        AppSetting::query()->create(['key' => DoseTimingSettings::LATE_MINUTES, 'value' => 15]);
        AppSetting::query()->create(['key' => DoseTimingSettings::EARLY_MINUTES, 'value' => '0']);

        $timing = app(DoseTimingSettings::class);
        $this->assertSame(15, $timing->lateMinutes());
        $this->assertSame(30, $timing->earlyMinutes());
    }

    public function test_a_late_dose_raises_an_incident_only_after_the_setting(): void
    {
        [$actor, $client, $medication] = $this->medicationFixture();
        $late = ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => 'given',
            'scheduled_for' => now()->subMinutes(100),
            'administered_at' => now(),
            'reason' => 'Delayed return from an appointment.',
        ]);
        $incidents = app(MedicationIncidentIntegrationService::class);

        // Today's rule: more than 120 minutes.
        $this->assertNull($incidents->handleLateDose($late, 100));
        $this->assertNull($incidents->handleLateDose($late, 120));

        AppSetting::query()->create(['key' => DoseTimingSettings::LATE_INCIDENT_MINUTES, 'value' => '90']);
        $this->assertNull($incidents->handleLateDose($late, 90));
        $incident = $incidents->handleLateDose($late, 100);

        $this->assertInstanceOf(ClientIncident::class, $incident);
        $this->assertSame($client->id, $incident->client_id);
        $this->assertSame(1, ClientIncident::query()->count());
    }

    public function test_refusals_and_withholds_within_the_period_escalate_at_the_setting(): void
    {
        [$actor, $client, $medication] = $this->medicationFixture();
        $record = fn (string $status, int $daysAgo) => ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'administered_by' => $actor->id,
            'status' => $status,
            'scheduled_for' => now()->subDays($daysAgo),
            'administered_at' => now()->subDays($daysAgo),
        ]);
        $record('refused', 1);
        $record('withheld', 2);
        $record('given', 1);
        $record('refused', 10);
        $policy = app(RefusalEscalationPolicy::class);

        $this->assertSame(2, $policy->countFor($client->id, $medication->id));
        $this->assertFalse($policy->escalates(2));

        AppSetting::query()->create(['key' => DoseTimingSettings::REFUSAL_COUNT, 'value' => '2']);
        $this->assertTrue($policy->escalates(2));

        AppSetting::query()->create(['key' => DoseTimingSettings::REFUSAL_DAYS, 'value' => '14']);
        $this->assertSame(3, $policy->countFor($client->id, $medication->id));
    }

    public function test_the_daily_alert_to_team_leads_uses_the_same_definition(): void
    {
        Notification::fake();
        Cache::flush();
        [$actor, $client, $medication] = $this->medicationFixture(['high_risk' => false, 'controlled_drug' => false]);
        $lead = $this->staff([], $this->site, 'team_lead');
        foreach (['refused' => 1, 'withheld' => 3] as $status => $daysAgo) {
            ClientMedicationAdministration::query()->create([
                'client_id' => $client->id,
                'client_medication_id' => $medication->id,
                'administered_by' => $actor->id,
                'status' => $status,
                'scheduled_for' => now()->subDays($daysAgo),
                'administered_at' => now()->subDays($daysAgo),
            ]);
        }

        // Two of three: no alert yet.
        $this->artisan('emar:send-alerts')->assertExitCode(0);
        Notification::assertNotSentTo($lead, MedicationAlertNotification::class);

        AppSetting::query()->create(['key' => DoseTimingSettings::REFUSAL_COUNT, 'value' => '2']);
        AppSetting::query()->create(['key' => DoseTimingSettings::REFUSAL_DAYS, 'value' => '5']);
        $this->artisan('emar:send-alerts')->assertExitCode(0);

        Notification::assertSentTo(
            $lead,
            MedicationAlertNotification::class,
            fn (MedicationAlertNotification $notification): bool => $notification->alert->type === 'refusals'
                && str_contains($notification->toArray($lead)['message'], 'refused or withheld 2 times in 5 days'),
        );
    }

    public function test_recording_a_refusal_follow_up_escalates_at_the_setting(): void
    {
        [, $client, $medication] = $this->medicationFixture();
        $recorder = $this->staff(['medications.administer.record']);
        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $client->service_context_id,
            'user_id' => $recorder->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(7),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $recorder->id,
            'status' => 'in_progress',
        ]);
        $refusal = fn (string $status, int $daysAgo) => ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $recorder->id,
            'scheduled_for' => now()->subDays($daysAgo),
            'administered_at' => now()->subDays($daysAgo),
            'status' => $status,
        ]);
        $refusal('withheld', 2);
        $latest = $refusal('refused', 0);
        $followUp = fn () => $this->actingAs($recorder)
            ->post(route('emar.refusal_followups.store'), [
                'client_id' => $client->id,
                'client_medication_administration_id' => $latest->id,
                'reason_category' => 'personal_choice',
                'client_capacity_at_time' => 'has_capacity',
            ])
            ->assertRedirect();

        // Two of three (today's rule): recorded, not escalated.
        $followUp();
        $this->assertFalse((bool) MedicationRefusalFollowup::query()->latest('id')->first()->escalated_to_manager);

        // Two within seven days now escalates, and the incident hears the count.
        AppSetting::query()->create(['key' => DoseTimingSettings::REFUSAL_COUNT, 'value' => '2']);
        $this->mock(MedicationIncidentIntegrationService::class)
            ->shouldReceive('handleRefusalEscalation')
            ->once()
            ->withArgs(fn (MedicationRefusalFollowup $followup, int $count): bool => $count === 2);
        $followUp();
        $escalated = MedicationRefusalFollowup::query()->latest('id')->first();
        $this->assertTrue((bool) $escalated->escalated_to_manager);
        $this->assertTrue((bool) $escalated->gp_notification_required);
    }

    /** @param  list<array{0: string, 1: string, 2: string}>  $changes  key, value, from */
    private function save(array $changes, bool $confirm = false): array
    {
        return [
            'view' => 'rounds',
            'changes' => array_map(fn (array $c): array => [
                'group' => 'timing',
                'key' => $c[0],
                'site_id' => null,
                'value' => $c[1],
                'from' => $c[2],
            ], $changes),
            ...($confirm ? ['confirm_loosening' => true] : []),
        ];
    }

    /** @param  list<string>  $permissions */
    private function staff(array $permissions, ?Site $site = null, string $role = 'support_worker'): User
    {
        $site ??= $this->site;
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        if ($permissions !== []) {
            $user->permissionOverrides()->syncWithoutDetaching(
                Permission::query()->whereIn('key', $permissions)->pluck('id')
                    ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                    ->all(),
            );
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);

        return $user->fresh();
    }

    /** @return array{0: User, 1: Client, 2: ClientMedication} */
    private function medicationFixture(array $medication = []): array
    {
        $actor = $this->staff(['medications.administer.record']);
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $order = ClientMedication::factory()->create(array_merge([
            'client_id' => $client->id,
            'name' => 'Risperidone',
            'active' => true,
            'state' => 'active',
            'is_prn' => false,
            'controlled_drug' => false,
            'high_risk' => false,
        ], $medication));

        return [$actor, $client, $order];
    }
}
