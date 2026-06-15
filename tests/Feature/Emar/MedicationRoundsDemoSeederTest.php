<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\MedicationRound;
use App\Models\ServiceContext;
use App\Models\User;
use App\Services\GuidedRoundService;
use Carbon\Carbon;
use Database\Seeders\MedicationRoundsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rounds demo seeder must produce a populated /emar/rounds for TODAY:
 * 5 rounds whose doses ("cells") actually resolve through the scheduling
 * pipeline, with the Morning-partial / Midday-in-progress / rest-pending story,
 * and it must be idempotent (no duplicate residents on re-run).
 */
class MedicationRoundsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function tally(array $cells): array
    {
        $t = [];
        foreach ($cells as $c) {
            $t[$c['status']] = ($t[$c['status']] ?? 0) + 1;
        }

        return $t;
    }

    public function test_seeds_todays_rounds_with_live_cells_and_recorded_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        User::factory()->create(['role' => 'support_worker']);

        $this->seed(MedicationRoundsDemoSeeder::class);

        $ctxId = ServiceContext::where('name', 'Rounds Demo (eMAR)')->value('id');
        $this->assertNotNull($ctxId);

        $rounds = MedicationRound::where('service_context_id', $ctxId)
            ->whereDate('round_date', today())
            ->get()
            ->keyBy('name');
        $this->assertCount(5, $rounds);

        $svc = app(GuidedRoundService::class);
        $cells = fn (string $name) => $svc->cells($rounds[$name]);

        // Morning: 8 doses, 7 given + 1 refused → partial.
        $morning = $cells('Morning Round');
        $this->assertCount(8, $morning);
        $this->assertSame(7, $this->tally($morning)['given'] ?? 0);
        $this->assertSame(1, $this->tally($morning)['refused'] ?? 0);
        $this->assertSame('partial', $rounds['Morning Round']->status);

        // Midday: 5 doses, 2 given + 3 still due → in_progress.
        $midday = $cells('Midday Round');
        $this->assertCount(5, $midday);
        $this->assertSame(2, $this->tally($midday)['given'] ?? 0);
        $this->assertSame(3, $this->tally($midday)['due'] ?? 0);
        $this->assertSame('in_progress', $rounds['Midday Round']->status);

        // Remaining rounds: pending, everything due.
        $this->assertCount(3, $cells('Afternoon Round'));
        $this->assertCount(5, $cells('Evening Round'));
        $this->assertCount(3, $cells('Night Round'));
        $this->assertSame('pending', $rounds['Afternoon Round']->status);

        // The insulin dose carries a recorded blood-glucose reading.
        $insulin = collect($morning)->firstWhere('medication_name', 'Insulin Lantus');
        $this->assertNotNull($insulin);
        $this->assertSame('given', $insulin['status']);
        $this->assertNotNull($insulin['blood_glucose_level']);

        // Residents span the three demo sites (so the Site filter is meaningful).
        $this->assertSame(3, collect($morning)->pluck('site_name')->unique()->count());
    }

    public function test_reseeding_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        User::factory()->create(['role' => 'support_worker']);

        $this->seed(MedicationRoundsDemoSeeder::class);
        $this->seed(MedicationRoundsDemoSeeder::class);

        $ctxId = ServiceContext::where('name', 'Rounds Demo (eMAR)')->value('id');
        $this->assertSame(8, Client::where('service_context_id', $ctxId)->count());
        $this->assertSame(5, MedicationRound::where('service_context_id', $ctxId)->whereDate('round_date', today())->count());

        $morning = MedicationRound::where('service_context_id', $ctxId)->where('name', 'Morning Round')->first();
        $this->assertCount(8, app(GuidedRoundService::class)->cells($morning));
    }
}
