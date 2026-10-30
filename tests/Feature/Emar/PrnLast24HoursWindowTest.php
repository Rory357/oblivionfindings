<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Site;
use App\Models\User;
use App\Services\Emar\MedsBoardPayloadService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EM-02 (2): Meds today's as-needed card counts doses given in the last 24
 * hours. The board passes an NZ "now"; bound raw into SQL against the UTC
 * administered_at column, it counted only the last ~11–13 hours, so the card
 * under-counted doses given and over-stated doses remaining.
 */
class PrnLast24HoursWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nzMoments(): array
    {
        return [
            'NZDT morning (UTC+13)' => ['2026-10-05 09:30:00'],
            'NZST evening (UTC+12)' => ['2026-06-15 21:45:00'],
            'just after NZ midnight' => ['2026-10-06 00:20:00'],
        ];
    }

    #[DataProvider('nzMoments')]
    public function test_the_board_counts_as_needed_doses_over_a_real_24_hours(string $nzMoment): void
    {
        $timezone = config('app.worker_timezone', 'Pacific/Auckland');
        Carbon::setTestNow(Carbon::parse($nzMoment, $timezone)->utc());

        $site = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
        $recorder = User::factory()->create(['approved_at' => now()]);
        $prn = ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => 'Paracetamol',
            'dosage' => '1g',
            'frequency' => 'As needed',
            'dose_times' => [],
            'is_prn' => true,
            'max_per_day' => 4,
            'active' => true,
            'state' => 'active',
        ]);
        foreach ([25, 20, 2] as $hoursAgo) {
            ClientMedicationAdministration::query()->create([
                'client_id' => $client->id,
                'client_medication_id' => $prn->id,
                'administered_by' => $recorder->id,
                'administered_at' => now()->subHours($hoursAgo),
                'status' => 'given',
            ]);
        }

        // Meds today passes the worker's (NZ) clock, as WorkerMedsController does.
        $row = collect(app(MedsBoardPayloadService::class)->prnMedications(
            [$client->id],
            Carbon::now($timezone),
        ))->sole();

        $this->assertSame(2, $row['given_last_24h']);
        $this->assertSame(2, $row['remaining_today']);
        // The board now agrees with the server's own limit check.
        $this->assertSame($row['given_last_24h'], $prn->fresh()->prn_count_last24_hours);
    }
}
