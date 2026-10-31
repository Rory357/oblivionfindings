<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsRiskAssessment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Speed R2 (31 Oct): the risk assessment register proves its readable rows once per page instead of fetching every
 * row again through the full guard (four guarded queries per row; 28–34 s on the .com). The rows are the same (the
 * PROOF_PARITY lane compares every row with the per-row proof); this test pins the cost and the superseded link.
 */
class RiskAssessmentRegisterProvenRowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_the_register_query_count_does_not_grow_with_its_rows(): void
    {
        $officer = $this->officer();
        $site = Site::factory()->create(['is_active' => true]);
        $make = fn (int $count) => HsRiskAssessment::factory()->count($count)->active()->create(['assessable_type' => Site::class, 'assessable_id' => $site->id]);

        $make(2);
        [$few, $fewQueries] = $this->measure(fn () => $this->actingAs($officer)->get('/health-safety/risk-assessments', $this->inertia()));
        $make(10);
        [$many, $manyQueries] = $this->measure(fn () => $this->actingAs($officer)->get('/health-safety/risk-assessments', $this->inertia()));

        $few->assertOk()->assertJsonCount(2, 'props.assessments.data');
        $many->assertOk()->assertJsonCount(12, 'props.assessments.data');
        // Ten more rows used to add about 145 queries (four guarded reads per row, the superseded lookup and the final
        // barrier's replays). The PROOF_PARITY lane runs that per-row proof on purpose, so it can't count here.
        if (! \App\Support\HealthSafety\ProofParity::$enabled) {
            $this->assertLessThan(10, $manyQueries - $fewQueries, "Ten more rows cost {$fewQueries} → {$manyQueries} queries.");
        }
    }

    public function test_a_superseded_row_still_points_at_its_next_version_and_others_point_nowhere(): void
    {
        $officer = $this->officer();
        $site = Site::factory()->create(['is_active' => true]);
        $next = HsRiskAssessment::factory()->active()->create(['assessable_type' => Site::class, 'assessable_id' => $site->id]);
        $old = HsRiskAssessment::factory()->active()->create(['assessable_type' => Site::class, 'assessable_id' => $site->id]);
        $old->forceFill(['status' => HsRiskAssessment::STATUS_SUPERSEDED, 'superseded_by_id' => $next->id])->save();

        $rows = collect($this->actingAs($officer)->get('/health-safety/risk-assessments', $this->inertia())
            ->assertOk()->json('props.assessments.data'))->keyBy('id');

        $this->assertSame($next->id, $rows[$old->id]['superseded_by_id']);
        $this->assertNull($rows[$next->id]['superseded_by_id']);
    }

    private function officer(): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'health_safety_officer')->sole());

        return $user->fresh();
    }

    /** @return array<string, string> */
    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())];
    }

    private int $queries = 0;

    private bool $listening = false;

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: int} */
    private function measure(callable $request): array
    {
        if (! $this->listening) {
            DB::listen(function (): void {
                $this->queries++;
            });
            $this->listening = true;
        }
        $before = $this->queries;
        $response = $request();

        return [$response, $this->queries - $before];
    }
}
