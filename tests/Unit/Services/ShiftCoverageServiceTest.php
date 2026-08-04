<?php

namespace Tests\Unit\Services;

use App\Models\Client;
use App\Models\CoverageGapAcknowledgement;
use App\Models\CoverageReservation;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\SiteCoverageRequirement;
use App\Models\User;
use App\Services\CoverageReservationService;
use App\Services\ShiftCoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftCoverageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ShiftCoverageService $service;

    protected Site $site;

    protected Client $client;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShiftCoverageService::class);
        $this->site = Site::factory()->create();
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id,
        ]);
        $this->serviceContext = ServiceContext::factory()->create();
    }

    public function test_range_coverage_calculates_required_assigned_deficit_and_role_shortage(): void
    {
        $rule = $this->makeRequirement([
            'minimum_staff' => 2,
            'role_requirements' => [
                'driver' => 1,
                'caregiver' => 1,
            ],
        ]);

        $driver = User::factory()->create();
        $this->makeShift([
            'user_id' => $driver->id,
            'coverage_roles' => ['driver'],
        ]);

        $windows = $this->service->buildRangeCoverage(
            now()->addDay()->setTime(9, 0),
            now()->addDay()->setTime(11, 0),
            $this->site->id,
        );

        $window = collect($windows)->firstWhere('rule_id', $rule->id);

        $this->assertNotNull($window);
        $this->assertSame(2, $window['required_staff']);
        $this->assertSame(1, $window['assigned_staff']);
        $this->assertSame(1, $window['missing_staff']);
        $this->assertSame('under', $window['coverage_state']);
        $this->assertSame('driver', $window['role_shortages'][0]['key']);
        $this->assertSame(1, $window['role_shortages'][0]['missing']);
    }

    public function test_expired_reservations_do_not_count_toward_coverage_slots(): void
    {
        $rule = $this->makeRequirement([
            'minimum_staff' => 1,
        ]);

        CoverageReservation::query()->create([
            'site_id' => $this->site->id,
            'coverage_requirement_id' => $rule->id,
            'reserved_by_user_id' => User::factory()->create()->id,
            'reservation_token' => 'expired-slot',
            'status' => CoverageReservationService::STATUS_ACTIVE,
            'reason' => 'quick_fill',
            'window_starts_at' => now()->addDay()->setTime(9, 0),
            'window_ends_at' => now()->addDay()->setTime(10, 0),
            'expires_at' => now()->subMinute(),
        ]);

        $window = collect($this->service->buildRangeCoverage(
            now()->addDay()->setTime(9, 0),
            now()->addDay()->setTime(10, 0),
            $this->site->id,
        ))->firstWhere('rule_id', $rule->id);

        $headcountSlot = collect($window['coverage_slots'])->firstWhere('kind', 'headcount');

        $this->assertNotNull($headcountSlot);
        $this->assertSame('available', $headcountSlot['status']);
    }

    public function test_window_boundaries_are_stable(): void
    {
        $rule = $this->makeRequirement([
            'minimum_staff' => 1,
        ]);

        $staff = User::factory()->create();
        $this->makeShift([
            'user_id' => $staff->id,
            'starts_at' => now()->addDay()->setTime(10, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $window = collect($this->service->buildRangeCoverage(
            now()->addDay()->setTime(9, 0),
            now()->addDay()->setTime(10, 0),
            $this->site->id,
        ))->firstWhere('rule_id', $rule->id);

        $this->assertNotNull($window);
        $this->assertSame(0, $window['assigned_staff']);
        $this->assertSame(1, $window['missing_staff']);
    }

    public function test_coverage_status_for_shift_keeps_role_gap_visible_when_open_shift_lacks_required_role(): void
    {
        $shift = $this->makeShift([
            'user_id' => null,
            'coverage_roles' => [],
        ]);

        $this->makeRequirement([
            'minimum_staff' => 1,
            'role_requirements' => [
                'driver' => 1,
            ],
        ]);

        $status = $this->service->coverageStatusForShift($shift->fresh());

        $this->assertNotNull($status);
        $this->assertTrue($status['has_role_gap']);
        $this->assertTrue($status['has_planned_role_gap']);
        $this->assertSame('mixed_open', $status['gap_kind']);
    }

    public function test_partial_window_uncovered_slices_show_remaining_uncovered_time(): void
    {
        $rule = $this->makeRequirement([
            'minimum_staff' => 1,
            'starts_time' => now()->addDay()->setTime(9, 0)->format('H:i'),
            'ends_time' => now()->addDay()->setTime(13, 0)->format('H:i'),
        ]);

        $this->makeShift([
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $window = collect($this->service->buildRangeCoverage(
            now()->addDay()->setTime(9, 0),
            now()->addDay()->setTime(13, 0),
            $this->site->id,
        ))->firstWhere('rule_id', $rule->id);

        $this->assertNotNull($window);
        $this->assertSame('under', $window['coverage_state']);
        $this->assertCount(1, $window['partial_window_uncovered_slices']);
        $this->assertSame(
            now()->addDay()->setTime(11, 0)->toIso8601String(),
            $window['partial_window_uncovered_slices'][0]['starts_at'],
        );
        $this->assertSame(
            now()->addDay()->setTime(13, 0)->toIso8601String(),
            $window['partial_window_uncovered_slices'][0]['ends_at'],
        );
    }

    public function test_role_shortage_uses_worst_slice_not_best_slice(): void
    {
        $rule = $this->makeRequirement([
            'minimum_staff' => 1,
            'starts_time' => now()->addDay()->setTime(9, 0)->format('H:i'),
            'ends_time' => now()->addDay()->setTime(13, 0)->format('H:i'),
            'role_requirements' => [
                'caregiver' => 1,
            ],
        ]);

        $this->makeShift([
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(11, 0),
        ]);

        $window = collect($this->service->buildRangeCoverage(
            now()->addDay()->setTime(9, 0),
            now()->addDay()->setTime(13, 0),
            $this->site->id,
        ))->firstWhere('rule_id', $rule->id);

        $this->assertNotNull($window);
        $this->assertSame('caregiver', $window['role_shortages'][0]['key']);
        $this->assertSame(1, $window['role_shortages'][0]['missing']);
    }

    public function test_active_acknowledgement_is_attached_to_coverage_alerts(): void
    {
        $rule = $this->makeRequirement(['minimum_staff' => 1]);
        $actor = User::factory()->create();
        $window = collect($this->service->buildRangeCoverage(
            now()->addDay()->setTime(9, 0),
            now()->addDay()->setTime(10, 0),
            $this->site->id,
        ))->firstWhere('rule_id', $rule->id);

        CoverageGapAcknowledgement::query()->create([
            'site_id' => $this->site->id,
            'coverage_requirement_id' => $rule->id,
            'coverage_window_key' => $window['coverage_window_key'],
            'window_starts_at' => $window['starts_at'],
            'window_ends_at' => $window['ends_at'],
            'state' => CoverageGapAcknowledgement::STATE_ACKED,
            'reason' => 'Calling team',
            'actor_user_id' => $actor->id,
            'created_at' => now(),
        ]);

        $freshWindow = collect($this->service->buildRangeCoverage(
            now()->addDay()->setTime(9, 0),
            now()->addDay()->setTime(10, 0),
            $this->site->id,
        ))->firstWhere('rule_id', $rule->id);

        $this->assertSame('acked', $freshWindow['acknowledgement']['state']);
        $this->assertSame('Calling team', $freshWindow['acknowledgement']['reason']);
        $this->assertSame($actor->id, $freshWindow['acknowledgement']['actor']['id']);
    }

    protected function makeRequirement(array $attributes = []): SiteCoverageRequirement
    {
        $start = now()->addDay()->setTime(9, 0);
        $end = now()->addDay()->setTime(10, 0);

        return SiteCoverageRequirement::query()->create(array_merge([
            'organization_id' => null,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'name' => 'Morning coverage',
            'coverage_type' => 'custom',
            'day_of_week' => strtolower($start->format('D')),
            'starts_time' => $start->format('H:i'),
            'ends_time' => $end->format('H:i'),
            'minimum_staff' => 1,
            'role_requirements' => [],
            'allow_overstaffing' => true,
            'is_active' => true,
        ], $attributes));
    }

    protected function makeShift(array $attributes = []): Shift
    {
        return Shift::factory()->create(array_merge([
            'client_id' => $this->client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => User::factory(),
            'starts_at' => now()->addDay()->setTime(9, 0),
            'ends_at' => now()->addDay()->setTime(10, 0),
            'status' => 'scheduled',
            'coverage_roles' => [],
            'created_by' => User::factory(),
        ], $attributes));
    }
}
