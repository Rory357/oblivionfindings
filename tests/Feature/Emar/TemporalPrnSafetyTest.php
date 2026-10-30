<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Site;
use App\Models\User;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Read-side safety for one proposed given dose; canonical writers own locks and insertion. */
class TemporalPrnSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private ClientMedication $medicine;

    private User $giver;

    private MedicationSafetyService $safety;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05T00:00:00Z'));
        $site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $this->giver = User::factory()->create(['approved_at' => now()]);
        $this->medicine = ClientMedication::query()->create([
            'client_id' => $this->client->id,
            'name' => 'Paracetamol',
            'dosage' => '1g',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'max_per_day' => 4,
            'min_hours_between_doses' => 4,
            'active' => true,
            'state' => 'active',
        ]);
        $this->safety = app(MedicationSafetyService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_live_defaults_keep_existing_limit_threshold_and_near_limit_details(): void
    {
        foreach ([6, 12, 18] as $hours) {
            $this->dose(now()->subHours($hours));
        }

        $result = $this->safety->checkPrnLimits($this->medicine);
        $this->assertFalse($result['blocked']);
        $this->assertTrue($result['near_limit']);
        $this->assertSame(3, $result['details']['count_24h']);
        $this->assertSame(1, $result['details']['remaining']);
        $this->assertFalse($this->safety->checkPrnInterval($this->medicine)['blocked']);

        $this->dose(now()->subHour());
        $this->assertTrue($this->safety->checkPrnLimits($this->medicine)['blocked']);
        $this->assertTrue($this->safety->checkPrnInterval($this->medicine)['blocked']);
    }

    public function test_live_empty_history_and_disabled_limits_retain_their_existing_results(): void
    {
        $result = $this->safety->checkPrnLimits($this->medicine);
        $this->assertFalse($result['blocked']);
        $this->assertFalse($result['near_limit']);
        $this->assertSame(0, $result['details']['count_24h']);
        $this->assertSame(4, $result['details']['remaining']);
        $this->assertFalse($this->safety->checkPrnInterval($this->medicine)['blocked']);

        $this->medicine->max_per_day = null;
        $this->medicine->min_hours_between_doses = 0;
        $this->dose(now());
        $this->assertSame([], $this->safety->checkPrnLimits($this->medicine)['details']);
        $this->assertFalse($this->safety->checkPrnInterval($this->medicine)['blocked']);
    }

    public function test_yesterdays_proposed_dose_uses_its_history_instead_of_todays_usage(): void
    {
        $at = now()->subHours(36);
        $this->medicine->max_per_day = 2;
        $this->dose($at->copy()->subHours(6));
        $this->dose(now()->subHours(8));
        $this->dose(now()->subHours(4));

        $historical = $this->safety->checkPrnLimits($this->medicine, $at);
        $this->assertFalse($historical['blocked']);
        $this->assertSame(1, $historical['details']['count_24h']);
        $this->assertTrue($this->safety->checkPrnLimits($this->medicine)['blocked']);
        $this->assertFalse($this->safety->checkPrnInterval($this->medicine, $at)['blocked']);
    }

    public function test_historical_limit_is_enforced_even_after_those_doses_fall_out_of_todays_window(): void
    {
        $at = now()->subHours(36);
        $this->medicine->max_per_day = 2;
        $this->dose($at->copy()->subHours(12));
        $this->dose($at->copy()->subHours(6));

        $result = $this->safety->checkPrnLimits($this->medicine, $at);
        $this->assertTrue($result['blocked']);
        $this->assertSame(2, $result['details']['count_24h']);
        $this->assertFalse($this->safety->checkPrnLimits($this->medicine)['blocked']);
    }

    public function test_a_historical_insert_cannot_exceed_an_affected_later_rolling_window(): void
    {
        $at = now()->subHours(36);
        $this->medicine->max_per_day = 2;
        $this->dose($at->copy()->subHours(4));
        $later = $at->copy()->addHours(4);
        $this->dose($later);
        $before = ClientMedicationAdministration::query()->count();

        $result = $this->safety->checkPrnLimits($this->medicine, $at);
        $this->assertTrue($result['blocked']);
        $this->assertSame(1, $result['details']['count_24h']);
        $this->assertSame(3, $result['details']['affected_count_24h']);
        $this->assertSame($later->toIso8601String(), $result['details']['affected_window_at']);
        $this->assertSame($before, ClientMedicationAdministration::query()->count());
    }

    public function test_proposed_dose_is_counted_once_and_expired_history_drops_out_of_later_windows(): void
    {
        $at = now()->subHours(36);
        $this->medicine->max_per_day = 2;
        $this->dose($at->copy()->subHours(23));
        $this->dose($at->copy()->addHours(4));
        $this->dose($at->copy()->addHours(25));

        $result = $this->safety->checkPrnLimits($this->medicine, $at);
        $this->assertFalse($result['blocked']);
        $this->assertSame(1, $result['details']['count_24h']);
        $this->assertArrayNotHasKey('affected_window_at', $result['details']);
    }

    public static function rollingBoundaries(): array
    {
        return [
            'inclusive lower boundary' => [-86400, true],
            'one second before lower boundary' => [-86401, false],
            'inclusive affected-window upper boundary' => [86400, true],
            'one second after affected-window upper boundary' => [86401, false],
        ];
    }

    #[DataProvider('rollingBoundaries')]
    public function test_existing_inclusive_24_hour_boundary_is_preserved(int $seconds, bool $blocked): void
    {
        $at = now()->subHours(36);
        $this->medicine->max_per_day = 1;
        $this->dose($at->copy()->addSeconds($seconds));

        $this->assertSame($blocked, $this->safety->checkPrnLimits($this->medicine, $at)['blocked']);
    }

    public static function intervalBoundaries(): array
    {
        return [
            'predecessor too close' => [-14399, true],
            'predecessor exactly four hours' => [-14400, false],
            'successor too close' => [14399, true],
            'successor exactly four hours' => [14400, false],
            'same instant already recorded' => [0, true],
        ];
    }

    #[DataProvider('intervalBoundaries')]
    public function test_interval_checks_both_neighbors_using_signed_elapsed_time(int $seconds, bool $blocked): void
    {
        $at = now()->subHours(36);
        $this->dose($at->copy()->addSeconds($seconds));

        $result = $this->safety->checkPrnInterval($this->medicine, $at);
        $this->assertSame($blocked, $result['blocked']);
        if ($blocked && $seconds > 0) {
            $this->assertArrayHasKey('next_administered_at', $result['details']);
        }
    }

    public static function nzInstants(): array
    {
        return [
            'NZDT' => ['2026-10-03T10:00:00+13:00'],
            'NZST' => ['2026-06-15T10:00:00+12:00'],
            'spring clock change' => ['2026-09-27T03:00:00+13:00'],
            'autumn repeated hour' => ['2026-04-05T02:30:00+12:00'],
        ];
    }

    #[DataProvider('nzInstants')]
    public function test_bounds_use_real_elapsed_hours_and_do_not_mutate_the_supplied_nz_instant(string $instant): void
    {
        $at = Carbon::parse($instant);
        $original = $at->toIso8601String();
        $utc = $at->copy()->utc();
        $this->medicine->max_per_day = 2;
        $this->dose($utc->copy()->subHours(20));
        $this->dose($utc->copy()->subHours(24)->subSecond());

        $this->assertSame(1, $this->safety->checkPrnLimits($this->medicine, $at)['details']['count_24h']);
        $this->assertFalse($this->safety->checkPrnInterval($this->medicine, $at)['blocked']);
        $this->assertSame($original, $at->toIso8601String());
    }

    public function test_only_effective_given_evidence_for_the_same_person_and_order_counts(): void
    {
        $at = now()->subHours(36);
        $root = $this->dose($at->copy()->subHour());
        $this->dose($at->copy()->subMinutes(30), [
            'is_correction' => true,
            'corrected_of_id' => $root->id,
            'correction_status' => 'approved',
            'correction_approved_at' => now()->subMinute(),
        ]);
        $this->dose($at->copy()->subHours(6), [
            'is_correction' => true,
            'corrected_of_id' => $root->id,
            'correction_status' => 'approved',
            'correction_approved_at' => now(),
        ]);
        foreach (['pending', 'rejected'] as $status) {
            $this->dose($at->copy()->subMinute(), [
                'is_correction' => true,
                'corrected_of_id' => $root->id,
                'correction_status' => $status,
            ]);
        }
        foreach (['refused', 'withheld', 'missed'] as $status) {
            $this->dose($at->copy()->subMinute(), ['status' => $status]);
        }
        $otherSite = Site::factory()->create(['is_active' => true]);
        $otherPerson = Client::factory()->create(['site_id' => $otherSite->id]);
        $this->dose($at->copy()->subMinute(), ['client_id' => $otherPerson->id]);
        $otherOrder = ClientMedication::query()->create([
            'client_id' => $this->client->id, 'name' => 'Other PRN', 'dosage' => '1 tablet',
            'frequency' => 'As needed', 'dose_times' => [], 'is_prn' => true, 'active' => true, 'state' => 'active',
        ]);
        $this->dose($at->copy()->subMinute(), ['client_medication_id' => $otherOrder->id]);

        $result = $this->safety->checkPrnLimits($this->medicine, $at);
        $this->assertSame(1, $result['details']['count_24h']);
        $this->assertFalse($result['blocked']);
        $this->assertFalse($this->safety->checkPrnInterval($this->medicine, $at)['blocked']);
    }

    public function test_an_approved_non_given_correction_removes_its_original_given_dose(): void
    {
        $at = now()->subHours(36);
        $root = $this->dose($at->copy()->subMinute());
        $this->dose($at->copy()->subMinute(), [
            'status' => 'withheld', 'is_correction' => true, 'corrected_of_id' => $root->id,
            'correction_status' => 'approved', 'correction_approved_at' => now(),
        ]);

        $this->assertSame(0, $this->safety->checkPrnLimits($this->medicine, $at)['details']['count_24h']);
        $this->assertFalse($this->safety->checkPrnInterval($this->medicine, $at)['blocked']);
    }

    public function test_full_safety_check_passes_the_clinical_time_to_both_prn_checks(): void
    {
        $at = now()->subHours(36);
        $this->medicine->max_per_day = 1;
        $this->dose($at->copy()->subHour());

        $result = $this->safety->performSafetyCheck($this->client, $this->medicine, $at);
        $types = array_column($result['warnings'], 'type');
        $this->assertContains('prn_limit', $types);
        $this->assertContains('prn_interval', $types);
    }

    private function dose(Carbon $at, array $attributes = []): ClientMedicationAdministration
    {
        return ClientMedicationAdministration::query()->create([
            'client_id' => $this->client->id,
            'client_medication_id' => $this->medicine->id,
            'administered_by' => $this->giver->id,
            'administered_at' => $at->copy()->utc(),
            'status' => 'given',
            ...$attributes,
        ]);
    }
}
