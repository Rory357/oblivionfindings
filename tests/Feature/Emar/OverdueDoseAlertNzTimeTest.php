<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDashboardAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationAlertService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EM-02 (1): the overdue-dose dashboard alert (and its Control Room signal)
 * read "08:00" on a UTC date, so an NZ morning dose was never raised and an
 * evening dose was raised at breakfast. Dose times are New Zealand times.
 * The clock is frozen in UTC, as production runs.
 */
class OverdueDoseAlertNzTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function moments(): array
    {
        return [
            'NZDT 9:30 am — the 8:00 am dose is overdue' => ['2026-10-05 09:30:00', '08:00', true],
            'NZDT 9:30 am — the 8:00 pm dose is not due yet' => ['2026-10-05 09:30:00', '20:00', false],
            'NZST 8:10 am — the 7:00 am dose is overdue' => ['2026-06-15 08:10:00', '07:00', true],
            '12:40 am — last night\'s 11:00 pm dose is overdue' => ['2026-10-06 00:40:00', '23:00', true],
            'NZDT 12:30 pm — more than 3 hours after the 8:00 am dose' => ['2026-10-05 12:30:00', '08:00', false],
        ];
    }

    #[DataProvider('moments')]
    public function test_overdue_doses_are_judged_on_the_new_zealand_clock(string $nzNow, string $doseTime, bool $overdue): void
    {
        Carbon::setTestNow(Carbon::parse($nzNow, $this->timezone())->utc());
        $client = $this->client();
        $this->medication($client, 'Metformin 500 mg', $doseTime);

        app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

        $alert = $this->overdueAlert($client);
        if ($overdue) {
            $this->assertNotNull($alert, "The {$doseTime} dose should be overdue at {$nzNow} NZ time.");
            $this->assertStringContainsString('1 overdue dose(s): Metformin 500 mg', $alert->message);
        } else {
            $this->assertNull($alert, "The {$doseTime} dose should not be overdue at {$nzNow} NZ time.");
        }
    }

    public function test_a_recorded_nz_morning_dose_is_not_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:30:00', $this->timezone())->utc());
        $client = $this->client();
        $recorded = $this->medication($client, 'Levothyroxine', '08:00');
        $this->medication($client, 'Metformin 500 mg', '08:30');
        ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $recorded->id,
            'administered_by' => User::factory()->create(['approved_at' => now()])->id,
            'scheduled_for' => Carbon::parse('2026-10-05 08:00:00', $this->timezone())->utc(),
            'administered_at' => Carbon::parse('2026-10-05 08:05:00', $this->timezone())->utc(),
            'status' => 'given',
        ]);

        app(MedicationAlertService::class)->generateClientAlerts($client->fresh());

        $alert = $this->overdueAlert($client);
        $this->assertNotNull($alert);
        $this->assertStringContainsString('1 overdue dose(s): Metformin 500 mg', $alert->message);
        $this->assertStringNotContainsString('Levothyroxine', $alert->message);
    }

    private function timezone(): string
    {
        return (string) config('app.worker_timezone', 'Pacific/Auckland');
    }

    private function client(): Client
    {
        return Client::factory()->create([
            'site_id' => Site::factory()->create(['is_active' => true])->id,
            'status' => 'active',
            'suppress_med_admin_alerts' => false,
        ]);
    }

    private function medication(Client $client, string $name, string $doseTime): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'dosage' => '1 tablet',
            'frequency' => 'Daily',
            'dose_times' => [$doseTime],
            'is_prn' => false,
            'controlled_drug' => false,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => '2026-01-01',
            'end_date' => null,
        ]);
    }

    private function overdueAlert(Client $client): ?MedicationDashboardAlert
    {
        return MedicationDashboardAlert::query()
            ->where('client_id', $client->id)
            ->where('alert_type', 'overdue')
            ->where('status', 'active')
            ->first();
    }
}
