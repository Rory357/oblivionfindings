<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftSeriesEligibilitySampler;
use App\Services\ShiftStaffEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftSeriesEligibilitySamplerTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftSeriesEligibilitySampler $sampler;
    protected Site $site;
    protected Client $client;
    protected ServiceContext $serviceContext;
    protected User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sampler = app(ShiftSeriesEligibilitySampler::class);
        $this->site = Site::factory()->create();
        $this->serviceContext = ServiceContext::factory()->create();
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->staff = User::factory()->create(['approved_at' => now()]);
    }

    // ── First passes, later sampled occurrence fails → blocked ──────────

    public function test_later_occurrence_blocked_by_fatigue_blocks_series(): void
    {
        // Create existing shifts Mon-Fri this week so that adding more
        // days in the same week triggers weekly fatigue limits.
        $baseDate = CarbonImmutable::parse('next Monday');

        // Fill Mon-Fri with 10h shifts (50h total = at weekly max).
        for ($i = 0; $i < 5; $i++) {
            Shift::factory()->create([
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $this->staff->id,
                'starts_at' => $baseDate->addDays($i)->setTime(7, 0),
                'ends_at' => $baseDate->addDays($i)->setTime(17, 0),
                'status' => 'scheduled',
                'created_by' => $this->staff->id,
            ]);
        }

        // Now try to create a series that adds Saturday + Sunday shifts
        // in the same week. The Saturday occurrence should be blocked
        // because adding 10h to the 50h already scheduled exceeds 50h max.
        $saturday = $baseDate->addDays(5); // Saturday
        $sunday = $baseDate->addDays(6);   // Sunday

        $windows = [
            ['starts_at' => $saturday->setTime(7, 0), 'ends_at' => $saturday->setTime(17, 0)],
            ['starts_at' => $sunday->setTime(7, 0), 'ends_at' => $sunday->setTime(17, 0)],
        ];

        $result = $this->sampler->evaluate($windows, $this->staff, $this->shiftTemplate());

        $this->assertFalse($result['passed']);
        $this->assertNotNull($result['blocked_at']);
        $this->assertNotEmpty($result['blocked_at']['reasons']);
    }

    // ── All sampled occurrences pass → series allowed ───────────────────

    public function test_all_samples_pass_allows_series(): void
    {
        // No existing shifts. Create a 2-week Mon-Wed series (6 occurrences).
        $baseDate = CarbonImmutable::parse('next Monday');
        $windows = [];

        for ($week = 0; $week < 2; $week++) {
            for ($day = 0; $day < 3; $day++) {
                $date = $baseDate->addWeeks($week)->addDays($day);
                $windows[] = [
                    'starts_at' => $date->setTime(9, 0),
                    'ends_at' => $date->setTime(17, 0),
                ];
            }
        }

        $result = $this->sampler->evaluate($windows, $this->staff, $this->shiftTemplate());

        $this->assertTrue($result['passed']);
        $this->assertNull($result['blocked_at']);
        $this->assertEquals(6, $result['total_count']);
        $this->assertGreaterThanOrEqual(3, $result['sampled_count']); // at least first/mid/last
    }

    // ── Warning-only occurrence → warnings surfaced ─────────────────────

    public function test_warnings_surfaced_without_blocking(): void
    {
        // Create enough shifts to trigger weekly warning threshold (40h)
        // but not the block threshold (50h).
        $baseDate = CarbonImmutable::parse('next Monday');

        // 4 days x 9h = 36h
        for ($i = 0; $i < 4; $i++) {
            Shift::factory()->create([
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $this->staff->id,
                'starts_at' => $baseDate->addDays($i)->setTime(8, 0),
                'ends_at' => $baseDate->addDays($i)->setTime(17, 0),
                'status' => 'scheduled',
                'created_by' => $this->staff->id,
            ]);
        }

        // Add Friday 8h shift via series → 36 + 8 = 44h → over 40h warning.
        $friday = $baseDate->addDays(4);
        $windows = [
            ['starts_at' => $friday->setTime(8, 0), 'ends_at' => $friday->setTime(16, 0)],
        ];

        $result = $this->sampler->evaluate($windows, $this->staff, $this->shiftTemplate());

        $this->assertTrue($result['passed']); // not blocked
        $this->assertNotEmpty($result['warnings']); // but has warnings
    }

    // ── Unassigned series skips staff eligibility ────────────────────────

    public function test_empty_occurrences_returns_pass(): void
    {
        $result = $this->sampler->evaluate([], $this->staff, $this->shiftTemplate());

        $this->assertTrue($result['passed']);
        $this->assertNull($result['blocked_at']);
        $this->assertEquals(0, $result['sampled_count']);
        $this->assertEquals(0, $result['total_count']);
    }

    // ── Sampling bounded for long series ────────────────────────────────

    public function test_sampling_bounded_for_long_series(): void
    {
        // 52-week daily series = 364 occurrences.
        $baseDate = CarbonImmutable::parse('next Monday');
        $windows = [];
        for ($i = 0; $i < 364; $i++) {
            $date = $baseDate->addDays($i);
            $windows[] = [
                'starts_at' => $date->setTime(9, 0),
                'ends_at' => $date->setTime(17, 0),
            ];
        }

        $indices = $this->sampler->selectSampleIndices($windows);

        $this->assertLessThanOrEqual(
            ShiftSeriesEligibilitySampler::MAX_SAMPLES,
            count($indices),
            'Sample count must be bounded by MAX_SAMPLES'
        );

        // Must include first and last.
        $this->assertContains(0, $indices);
        $this->assertContains(363, $indices);
    }

    public function test_short_series_evaluates_all_occurrences(): void
    {
        $baseDate = CarbonImmutable::parse('next Monday');
        $windows = [];
        for ($i = 0; $i < 3; $i++) {
            $date = $baseDate->addDays($i);
            $windows[] = [
                'starts_at' => $date->setTime(9, 0),
                'ends_at' => $date->setTime(17, 0),
            ];
        }

        $indices = $this->sampler->selectSampleIndices($windows);

        // 3 occurrences <= MAX_SAMPLES, so all should be sampled.
        $this->assertCount(3, $indices);
        $this->assertEquals([0, 1, 2], $indices);
    }

    // ── Failure message includes occurrence date ────────────────────────

    public function test_failure_includes_offending_date(): void
    {
        // Create heavy existing schedule.
        $baseDate = CarbonImmutable::parse('next Monday');
        for ($i = 0; $i < 5; $i++) {
            Shift::factory()->create([
                'client_id' => $this->client->id,
                'site_id' => $this->site->id,
                'service_context_id' => $this->serviceContext->id,
                'user_id' => $this->staff->id,
                'starts_at' => $baseDate->addDays($i)->setTime(7, 0),
                'ends_at' => $baseDate->addDays($i)->setTime(17, 0),
                'status' => 'scheduled',
                'created_by' => $this->staff->id,
            ]);
        }

        $saturday = $baseDate->addDays(5);
        $windows = [
            ['starts_at' => $saturday->setTime(7, 0), 'ends_at' => $saturday->setTime(17, 0)],
        ];

        $result = $this->sampler->evaluate($windows, $this->staff, $this->shiftTemplate());

        $this->assertFalse($result['passed']);
        // Date should be human-readable.
        $this->assertStringContainsString('Sat', $result['blocked_at']['date']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    protected function shiftTemplate(array $overrides = []): array
    {
        return array_merge([
            'user_id' => $this->staff->id,
            'site_id' => $this->site->id,
            'shift_type' => 'standard',
            'is_sleepover' => false,
            'is_on_call' => false,
            'coverage_roles' => [],
            'service_context_id' => $this->serviceContext->id,
        ], $overrides);
    }
}
