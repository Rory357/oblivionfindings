<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ControlRoom\Signal;
use App\Models\MedicationAlert;
use App\Models\MedicationDashboardAlert;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AppEventNotification;
use App\Services\Medication\Alerts\MedicationAlertCatalogue;
use App\Services\Medication\MedicationSignalService;
use App\Services\MedicationIncidentIntegrationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * C6 (EA-035, EA-036, EA-088): incidents and Control Room alerts raised from a
 * dose record read in NZ time; a dose's draft incident carries its shift and
 * is put in front of someone who can open the person — the shift's owner, or
 * the house lead when the dose has no shift — and raises the Medication
 * Settings alert "Missed, late or refused dose — incident to submit". A
 * controlled medicine's incident text shows no dose or strength.
 *
 * Friday 9 October 2026, NZDT (UTC+13).
 */
final class MedicationAutoIncidentRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Site $house;

    private Client $person;

    private ClientMedication $paracetamol;

    private ClientMedication $morphine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        (require database_path('migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php'))->up();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-09 10:30', 'Pacific/Auckland')->utc());
        $this->house = Site::factory()->create(['is_active' => true, 'name' => 'Kowhai House']);
        $this->person = Client::factory()->create(['site_id' => $this->house->id, 'first_name' => 'Aroha', 'last_name' => 'Ngata', 'status' => 'active']);
        $this->paracetamol = ClientMedication::factory()->create([
            'client_id' => $this->person->id, 'name' => 'Paracetamol', 'dosage' => '1 g', 'controlled_drug' => false, 'high_risk' => true,
        ]);
        $this->morphine = ClientMedication::factory()->create([
            'client_id' => $this->person->id, 'name' => 'Morphine', 'dosage' => '10 mg', 'controlled_drug' => true, 'high_risk' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ea035_dose_incidents_and_their_alerts_read_in_nz_time(): void
    {
        $worker = $this->staff('support_worker');
        $missed = $this->dose($this->paracetamol, $worker, ['status' => 'missed', 'administered_at' => null, 'reason' => 'Asleep']);
        $late = $this->dose($this->paracetamol, $worker, ['status' => 'given', 'administered_at' => $this->nz('2026-10-09 10:30')]);
        $service = app(MedicationIncidentIntegrationService::class);

        $missedIncident = $service->handleMissedDose($missed, $worker->id);
        $lateIncident = $service->handleLateDose($late, 150);

        // 08:00 NZDT is 19:00 UTC the day before.
        $this->assertStringContainsString('Scheduled time: 09/10/2026 08:00 NZDT', $missedIncident->description);
        $this->assertStringContainsString('Scheduled: 09/10/2026 08:00 NZDT', $lateIncident->description);
        $this->assertStringContainsString('Given: 09/10/2026 10:30 NZDT', $lateIncident->description);
        $this->assertSame(
            'Missed dose: Paracetamol scheduled for 08:00',
            data_get(Signal::query()->where('signal_type_code', MedicationSignalService::TYPE_MISSED_DOSE)->sole()->normalized_data, 'title'),
        );
        $this->assertSame('Missed dose: Paracetamol scheduled for 08:00', MedicationDashboardAlert::query()->where('alert_type', 'missed_dose')->sole()->message);
    }

    public function test_ea035_a_correction_incident_reads_its_times_in_nz_time(): void
    {
        $worker = $this->staff('support_worker');
        $original = $this->dose($this->paracetamol, $worker, ['status' => 'given', 'administered_at' => $this->nz('2026-10-09 08:05')]);
        // Charted early in the morning; corrected more than four hours later.
        $original->forceFill(['created_at' => $this->nz('2026-10-09 06:00')])->saveQuietly();

        $incident = app(MedicationIncidentIntegrationService::class)->handleUnsafeCorrection($original, [
            'status' => 'refused', 'administered_at' => '2026-10-09T08:10', 'correction_reason' => 'Charted on the wrong person',
        ], $worker->id);

        $this->assertStringContainsString('- Time: 09/10/2026 08:05 NZDT', $incident->description);
        // A time entered without a zone is the worker's own clock time.
        $this->assertStringContainsString('- Time: 09/10/2026 08:10 NZDT', $incident->description);
    }

    public function test_ea036_a_dose_draft_carries_its_shift_and_is_put_to_the_shift_owner(): void
    {
        $worker = $this->staff('support_worker');
        $shift = $this->clockedIn($worker);
        $lead = $this->staff('team_lead');
        $missed = $this->dose($this->paracetamol, $worker, ['status' => 'missed', 'administered_at' => null, 'shift_id' => $shift->id]);

        $incident = app(MedicationIncidentIntegrationService::class)->handleMissedDose($missed, $worker->id);

        $this->assertSame($shift->id, (int) $incident->shift_id);
        $this->assertSame([$incident->id], $this->draftNoticesFor($worker));
        // The shift's owner has it; the house lead hears through Medication Settings.
        $this->assertSame([], $this->draftNoticesFor($lead));
    }

    public function test_ea036_without_a_shift_the_house_lead_who_can_open_the_person_is_told(): void
    {
        $recorder = $this->staff('support_worker');
        $lead = $this->staff('team_lead');
        $elsewhere = $this->staff('team_lead', Site::factory()->create(['is_active' => true]));
        $late = $this->dose($this->paracetamol, $recorder, ['status' => 'given', 'administered_at' => now()]);

        $incident = app(MedicationIncidentIntegrationService::class)->handleLateDose($late, 150);

        $this->assertNull($incident->shift_id);
        $this->assertSame([$incident->id], $this->draftNoticesFor($lead));
        $this->assertSame([], $this->draftNoticesFor($elsewhere));
    }

    public function test_ea036_each_new_dose_draft_raises_the_medication_settings_alert_once(): void
    {
        $worker = $this->staff('support_worker');
        $lead = $this->staff('team_lead');
        $service = app(MedicationIncidentIntegrationService::class);
        $missed = $this->dose($this->paracetamol, $worker, ['status' => 'missed', 'administered_at' => null]);
        $refused = $this->dose($this->paracetamol, $worker, ['status' => 'refused', 'administered_at' => now()]);
        $onTime = $this->dose($this->paracetamol, $worker, ['status' => 'given', 'administered_at' => now()]);

        $first = $service->handleMissedDose($missed, $worker->id);
        $service->handleMissedDose($missed->fresh(), $worker->id);
        $second = $service->handleRefusedDose($refused);
        // Not late enough for an incident: no draft, no alert.
        $this->assertNull($service->handleLateDose($onTime, 30));

        $alerts = MedicationAlert::query()->where('type', MedicationAlertCatalogue::DOSE_INCIDENT)->orderBy('id')->get();
        $this->assertSame(['doseIncident:dose-incident:'.$first->id, 'doseIncident:dose-incident:'.$second->id], $alerts->pluck('dedupe_key')->all());
        $this->assertSame([$this->person->id, $this->person->id], $alerts->pluck('client_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(2, $lead->notifications()->where('data->alert_key', MedicationAlertCatalogue::DOSE_INCIDENT)->count());
        $this->assertSame([MedicationAlertCatalogue::HOUSE_LEAD], MedicationAlertCatalogue::ALERTS[MedicationAlertCatalogue::DOSE_INCIDENT]['default']);
    }

    public function test_a_controlled_medicines_dose_incident_shows_no_dose_or_strength(): void
    {
        $worker = $this->staff('support_worker');
        $service = app(MedicationIncidentIntegrationService::class);
        $missed = $this->dose($this->morphine, $worker, ['status' => 'missed', 'administered_at' => null]);
        $original = $this->dose($this->morphine, $worker, ['status' => 'given', 'dose_given' => '10 mg', 'administered_at' => $this->nz('2026-10-09 08:05')]);
        $original->forceFill(['created_at' => $this->nz('2026-10-09 06:00')])->saveQuietly();

        $texts = [
            $service->handleMissedDose($missed, $worker->id),
            $service->handleUnsafeCorrection($original, ['status' => 'given', 'dose_given' => '5 mg', 'correction_reason' => 'Half the dose was given'], $worker->id),
        ];

        foreach ($texts as $incident) {
            $text = $incident->title."\n".$incident->description;
            $this->assertStringNotContainsString('Morphine', $text);
            $this->assertStringNotContainsString('10 mg', $text);
            $this->assertStringNotContainsString('5 mg', $text);
            $this->assertStringContainsString('Controlled medicine', $text);
        }
    }

    /** @return list<int> the incidents whose draft notice reached $user */
    private function draftNoticesFor(User $user): array
    {
        return $user->notifications()->where('type', AppEventNotification::class)->get()
            ->filter(fn ($notice): bool => ($notice->data['event_key'] ?? null) === 'incidents.draft_created')
            ->map(fn ($notice): int => (int) $notice->data['entity_id'])->values()->all();
    }

    private function staff(string $role, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => ($site ?? $this->house)->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => '2026-01-01', 'end_date' => null,
        ]);

        return $user->fresh();
    }

    private function clockedIn(User $worker): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->person->id, 'site_id' => $this->house->id, 'user_id' => $worker->id,
            'starts_at' => $this->nz('2026-10-09 07:00'), 'ends_at' => $this->nz('2026-10-09 15:00'),
            'actual_starts_at' => $this->nz('2026-10-09 07:00'), 'status' => 'in_progress',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function dose(ClientMedication $medication, User $by, array $attributes): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create(array_merge([
            'client_id' => $this->person->id, 'client_medication_id' => $medication->id, 'administered_by' => $by->id,
            'scheduled_for' => $this->nz('2026-10-09 08:00'), 'is_correction' => false,
        ], $attributes));
    }

    private function nz(string $local): Carbon
    {
        return Carbon::parse($local, 'Pacific/Auckland')->utc();
    }
}
