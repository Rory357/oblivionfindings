<?php

namespace Tests\Feature\HealthSafety;

use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsCorrectiveAction;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\HsKpiService;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G1 — Health & Safety KPI calc service. Covers the NZ LTIFR/TRIFR formulas, the
 * recordable-injury rule (first-aid excluded), null-on-zero-hours, days-since-LTI,
 * corrective-actions-closed-on-time % and the near-miss:incident ratio.
 *
 * Frequency-rate magnitudes here are deterministic fixtures (1,000 hours denominator),
 * not realistic field values.
 */
class HsKpiServiceTest extends TestCase
{
    use RefreshDatabase;

    private HsKpiService $svc;

    private Client $client;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(HsKpiService::class);
        $this->client = Client::factory()->create();
        $this->staff = User::factory()->create();
    }

    /** Seed worked hours via a billing entry (the LTIFR/TRIFR denominator). */
    private function bookHours(float $hours, ?Site $site = null, ?CarbonInterface $date = null): void
    {
        BillingEntry::create([
            'client_id' => $this->client->id,
            'staff_id' => $this->staff->id,
            'service_date' => $date ?? now()->subMonths(2),
            'hours' => $hours,
            'rate' => 0,
            'amount' => 0,
            'site_name_snapshot' => $site?->name,
        ]);
    }

    // ──────────────────────────────────────────────────────
    // Denominator
    // ──────────────────────────────────────────────────────

    public function test_total_hours_worked_sums_billing_entries(): void
    {
        $this->bookHours(600);
        $this->bookHours(400);

        $this->assertEquals(1000.0, $this->svc->totalHoursWorked());
    }

    public function test_total_hours_worked_scopes_by_site_via_snapshot(): void
    {
        $maple = Site::factory()->create(['name' => 'Maple House']);
        $rata = Site::factory()->create(['name' => 'Rata House']);
        $this->bookHours(1000, $maple);
        $this->bookHours(250, $rata);

        $this->assertEquals(1000.0, $this->svc->totalHoursWorked(null, null, $maple->id));
        $this->assertEquals(250.0, $this->svc->totalHoursWorked(null, null, $rata->id));
    }

    // ──────────────────────────────────────────────────────
    // Lagging rates + recordable rule
    // ──────────────────────────────────────────────────────

    public function test_ltifr_trifr_and_severity_rate_apply_the_recordable_rule(): void
    {
        $this->bookHours(1000);

        // Lost-time (also recordable), 5 lost days.
        WorkplaceInjury::factory()->create([
            'injury_date' => now()->subMonths(2),
            'lost_time_days' => 5,
            'medical_treatment_type' => 'none',
            'worksafe_notifiable' => false,
        ]);
        // Recordable via medical treatment, no lost time.
        WorkplaceInjury::factory()->create([
            'injury_date' => now()->subMonths(3),
            'lost_time_days' => 0,
            'medical_treatment_type' => 'hospital',
            'worksafe_notifiable' => false,
        ]);
        // First-aid only — NOT recordable.
        WorkplaceInjury::factory()->create([
            'injury_date' => now()->subMonths(1),
            'lost_time_days' => 0,
            'medical_treatment_type' => 'first_aid',
            'worksafe_notifiable' => false,
        ]);

        // LTIFR = 1 lost-time / 1000 hrs × 1e6 = 1000.0
        $this->assertEquals(1000.0, $this->svc->ltifr());
        // TRIFR = 2 recordable / 1000 hrs × 1e6 = 2000.0
        $this->assertEquals(2000.0, $this->svc->trifr());
        // Severity rate = 5 lost days / 1000 hrs × 1e6 = 5000.0
        $this->assertEquals(5000.0, $this->svc->injurySeverityRate());
    }

    public function test_rates_are_null_when_no_hours_worked(): void
    {
        WorkplaceInjury::factory()->create([
            'injury_date' => now()->subMonths(2),
            'lost_time_days' => 3,
        ]);

        // No billing entries booked → denominator 0 → null (no divide-by-zero figure).
        $this->assertNull($this->svc->ltifr());
        $this->assertNull($this->svc->trifr());
        $this->assertNull($this->svc->injurySeverityRate());
    }

    public function test_days_since_lost_time_injury_ignores_non_lost_time(): void
    {
        WorkplaceInjury::factory()->create([
            'injury_date' => now()->subDays(40),
            'lost_time_days' => 2,
        ]);
        // More recent but NOT lost-time — must be ignored.
        WorkplaceInjury::factory()->create([
            'injury_date' => now()->subDays(5),
            'lost_time_days' => 0,
        ]);

        $this->assertEquals(40, $this->svc->daysSinceLostTimeInjury());
    }

    public function test_days_since_lost_time_injury_null_when_none(): void
    {
        $this->assertNull($this->svc->daysSinceLostTimeInjury());
    }

    // ──────────────────────────────────────────────────────
    // Leading
    // ──────────────────────────────────────────────────────

    public function test_actions_closed_on_time_pct(): void
    {
        // Closed on time (completed before due date).
        HsCorrectiveAction::factory()->create([
            'due_date' => now()->subDays(10),
            'completed_at' => now()->subDays(12),
            'status' => 'completed',
        ]);
        // Closed late (completed after due date).
        HsCorrectiveAction::factory()->create([
            'due_date' => now()->subDays(5),
            'completed_at' => now()->subDays(2),
            'status' => 'completed',
        ]);

        // 1 of 2 actions due in the window closed on time → 50%.
        $this->assertEquals(50.0, $this->svc->actionsClosedOnTimePct());
    }

    public function test_actions_closed_on_time_null_when_none_due(): void
    {
        $this->assertNull($this->svc->actionsClosedOnTimePct());
    }

    public function test_near_miss_to_incident_ratio(): void
    {
        // Denominator: 2 recordable injuries (both lost-time).
        WorkplaceInjury::factory()->count(2)->create([
            'injury_date' => now()->subMonths(2),
            'lost_time_days' => 1,
        ]);
        // Numerator: 6 near misses in the trailing-12-month window.
        ClientIncident::factory()->count(6)->create([
            'type' => 'near_miss',
            'occurred_at' => now()->subMonths(2),
        ]);

        // 6 near misses ÷ 2 recordable incidents = 3.0×
        $this->assertEquals(3.0, $this->svc->nearMissToIncidentRatio());
    }

    public function test_near_miss_ratio_null_when_no_recordable_incidents(): void
    {
        ClientIncident::factory()->count(3)->create([
            'type' => 'near_miss',
            'occurred_at' => now()->subMonths(2),
        ]);

        $this->assertNull($this->svc->nearMissToIncidentRatio());
    }

    // ──────────────────────────────────────────────────────
    // Bundle
    // ──────────────────────────────────────────────────────

    public function test_leading_lagging_bundle_shape(): void
    {
        $bundle = $this->svc->leadingLagging();

        $this->assertArrayHasKey('lagging', $bundle);
        $this->assertArrayHasKey('leading', $bundle);
        $this->assertArrayHasKey('ltifr', $bundle['lagging']);
        $this->assertArrayHasKey('trifr', $bundle['lagging']);
        $this->assertArrayHasKey('days_since_lti', $bundle['lagging']);
        $this->assertArrayHasKey('near_miss_ratio', $bundle['leading']);
        $this->assertArrayHasKey('actions_on_time_pct', $bundle['leading']);
        $this->assertArrayHasKey('training_pct', $bundle['leading']);
        $this->assertArrayHasKey('open_hazards', $bundle['leading']);
    }

    public function test_monthly_frequency_rates_returns_twelve_points(): void
    {
        $rates = $this->svc->monthlyFrequencyRates();

        $this->assertCount(12, $rates);
        $this->assertArrayHasKey('month', $rates[0]);
        $this->assertArrayHasKey('ltifr', $rates[0]);
        $this->assertArrayHasKey('trifr', $rates[0]);
    }
}
