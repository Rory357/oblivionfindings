<?php

namespace Tests\Feature\Emar;

use App\Models\Client;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\ServiceContext;
use App\Models\User;
use App\Services\GuidedRoundService;
use Carbon\Carbon;
use Database\Seeders\MedicationRoundsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The rounds demo seeder must produce a populated /emar/rounds for TODAY:
 * one round per Site per dosed slot (a round covers exactly one Site) whose
 * doses ("cells") actually resolve through the scheduling pipeline, with the
 * Morning-partial / Midday-in-progress / rest-pending story, and it must be
 * idempotent (no duplicate residents, templates, rounds or doses on re-run).
 */
class MedicationRoundsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** The slots each demo Site's residents are dosed in. */
    private const ROUNDS_BY_SITE = [
        'Kauri Lodge' => ['Afternoon Round', 'Evening Round', 'Midday Round', 'Morning Round', 'Night Round'],
        'Kowhai Villa' => ['Afternoon Round', 'Morning Round', 'Night Round'],
        'Rata House' => ['Evening Round', 'Midday Round', 'Morning Round'],
    ];

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

    private function demoContextId(): int
    {
        $ctxId = ServiceContext::where('name', 'Rounds Demo (eMAR)')->value('id');
        $this->assertNotNull($ctxId);

        return (int) $ctxId;
    }

    /** @return Collection<int, MedicationRound> */
    private function todaysRounds(): Collection
    {
        return MedicationRound::where('service_context_id', $this->demoContextId())
            ->whereDate('round_date', today())
            ->with('site:id,name')
            ->get();
    }

    /**
     * Cells of every Site's round with this name. Controlled doses are included
     * so the demo's Oxycodone counts; cells() hides them by default.
     */
    private function cells(Collection $rounds, string $name): array
    {
        $svc = app(GuidedRoundService::class);

        return $rounds->where('name', $name)
            ->flatMap(fn (MedicationRound $round) => $svc->cells($round, includeControlled: true))
            ->all();
    }

    private function statuses(Collection $rounds): array
    {
        return $rounds->pluck('status')->unique()->values()->all();
    }

    public function test_seeds_todays_rounds_with_live_cells_and_recorded_statuses(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        User::factory()->create(['role' => 'support_worker']);

        $this->seed(MedicationRoundsDemoSeeder::class);

        $rounds = $this->todaysRounds();
        $this->assertSame(
            self::ROUNDS_BY_SITE,
            $rounds->groupBy(fn (MedicationRound $round) => $round->site?->name)
                ->map(fn (Collection $siteRounds) => $siteRounds->pluck('name')->sort()->values()->all())
                ->sortKeys()
                ->all(),
        );

        // Morning: 8 doses across the 3 Sites, 7 given + 1 refused → partial.
        $morning = $this->cells($rounds, 'Morning Round');
        $this->assertCount(8, $morning);
        $this->assertSame(7, $this->tally($morning)['given'] ?? 0);
        $this->assertSame(1, $this->tally($morning)['refused'] ?? 0);
        $this->assertSame(['partial'], $this->statuses($rounds->where('name', 'Morning Round')));

        // Midday: 5 doses, 2 given + 3 still due → in_progress.
        $midday = $this->cells($rounds, 'Midday Round');
        $this->assertCount(5, $midday);
        $this->assertSame(2, $this->tally($midday)['given'] ?? 0);
        $this->assertSame(3, $this->tally($midday)['due'] ?? 0);
        $this->assertSame(['in_progress'], $this->statuses($rounds->where('name', 'Midday Round')));

        // Remaining rounds: pending, everything due.
        $this->assertCount(3, $this->cells($rounds, 'Afternoon Round'));
        $this->assertCount(5, $this->cells($rounds, 'Evening Round'));
        $this->assertCount(3, $this->cells($rounds, 'Night Round'));
        $this->assertSame(['pending'], $this->statuses($rounds->whereNotIn('name', ['Morning Round', 'Midday Round'])));

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
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        User::factory()->create(['role' => 'support_worker']);

        $this->seed(MedicationRoundsDemoSeeder::class);
        $this->seed(MedicationRoundsDemoSeeder::class);

        $ctxId = $this->demoContextId();
        $this->assertSame(8, Client::where('service_context_id', $ctxId)->count());
        $this->assertSame(11, MedicationRoundTemplate::where('service_context_id', $ctxId)->count());
        $this->assertSame(10, ClientMedicationAdministration::where('service_context_id', $ctxId)->count());

        $rounds = $this->todaysRounds();
        $this->assertCount(11, $rounds);
        $this->assertCount(8, $this->cells($rounds, 'Morning Round'));
    }

    public function test_reseeding_deactivates_legacy_all_site_templates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        User::factory()->create(['role' => 'support_worker']);
        $context = ServiceContext::query()->create([
            'name' => 'Rounds Demo (eMAR)',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $legacy = MedicationRoundTemplate::query()->create([
            'name' => 'Morning Round',
            'service_context_id' => $context->id,
            'site_id' => null,
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'days_of_week' => [],
            'active' => true,
        ]);

        $this->seed(MedicationRoundsDemoSeeder::class);

        $this->assertFalse($legacy->fresh()->active);
        $this->assertSame(0, MedicationRoundTemplate::where('service_context_id', $context->id)
            ->whereNull('site_id')
            ->where('active', true)
            ->count());
    }
}
