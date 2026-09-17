<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Jobs\CaptureRiskHeatmapSnapshot;
use App\Domain\Governance\Models\ComplianceEvidence;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * Compliance statuses follow the calendar (audit P0-9), evidence can be
 * opened by the people who can view the requirement, and every framework is
 * counted.
 */
class GovernanceComplianceStatusTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    private function nzDate(int $days): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', ComplianceObligation::nzToday())->addDays($days)->toDateString();
    }

    /** Create a requirement, then force its stored due date/status as a stale row would have. */
    private function requirement(User $owner, int $dueInDays, array $overrides = [], ?string $storedStatus = 'not_due'): ComplianceObligation
    {
        static $sequence = 0;
        $sequence++;

        $obligation = $this->createComplianceObligation($owner, array_merge([
            'obligation_code' => 'REQ-'.$sequence,
            'obligation_title' => 'Requirement '.$sequence,
        ], $overrides));

        DB::table('compliance_obligations')->where('id', $obligation->id)->update([
            'due_date' => $this->nzDate($dueInDays),
            'next_due_date' => $this->nzDate($dueInDays),
            'status' => $overrides['status'] ?? $storedStatus,
        ]);

        return $obligation->fresh();
    }

    public function test_overdue_comes_from_the_due_date_even_when_the_stored_status_is_stale(): void
    {
        $admin = $this->createAdminUser();
        $stale = $this->requirement($admin, -12, [], 'not_due');

        $this->actingAs($admin)
            ->get('/governance/compliance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.totals.overdue', 1)
                ->where('summary.total_overdue', 1)
                ->where('obligations.data.0.id', $stale->id)
                ->where('obligations.data.0.status', 'overdue')
                ->where('obligations.data.0.days_until_due', -12));

        $this->actingAs($admin)
            ->get('/governance/compliance?status=overdue')
            ->assertInertia(fn (Assert $page) => $page
                ->has('obligations.data', 1)
                ->where('obligations.data.0.id', $stale->id));
    }

    public function test_due_soon_means_the_next_30_days_not_overdue_and_not_cancelled(): void
    {
        $admin = $this->createAdminUser();
        $this->requirement($admin, 5);
        $this->requirement($admin, 30);
        $this->requirement($admin, 45);
        $this->requirement($admin, -1);
        $this->requirement($admin, 5, ['status' => 'cancelled']);
        $this->requirement($admin, -40, ['status' => 'complete']);

        $this->actingAs($admin)
            ->get('/governance/compliance')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.totals.total', 6)
                ->where('summary.totals.counted', 5)
                ->where('summary.totals.due_soon', 2)
                ->where('summary.totals.overdue', 1)
                ->where('summary.totals.not_due', 1)
                ->where('summary.totals.complete', 1)
                ->where('summary.totals.cancelled', 1)
                // On time: done, or open and not overdue — out of all but cancelled.
                ->where('summary.totals.on_time', 4));

        $this->actingAs($admin)
            ->get('/governance/compliance?status=due_soon')
            ->assertInertia(fn (Assert $page) => $page->has('obligations.data', 2));

        $this->actingAs($admin)
            ->get('/governance/compliance?status=on_time')
            ->assertInertia(fn (Assert $page) => $page->has('obligations.data', 4));
    }

    public function test_the_summary_counts_every_framework_including_funding_contracts(): void
    {
        $admin = $this->createAdminUser();
        foreach (['privacy_act', 'funding_msd', 'funding_acc', 'funding_dss', 'code_of_rights'] as $framework) {
            $this->requirement($admin, 60, ['framework' => $framework]);
        }

        $response = $this->actingAs($admin)->get('/governance/compliance')->assertOk();

        $byFramework = $response->inertiaProps('summary.by_framework');
        foreach (array_keys(ComplianceObligation::frameworkOptions()) as $framework) {
            $this->assertArrayHasKey($framework, $byFramework);
        }
        $this->assertSame(1, $byFramework['funding_msd']['counted']);
        $this->assertSame(1, $byFramework['code_of_rights']['counted']);
        $this->assertSame(5, $response->inertiaProps('summary.totals.counted'));
        $this->assertSame(5, $response->inertiaProps('obligations.total'));
        $this->assertSame(
            'Code of Rights (Health and Disability Commissioner)',
            collect($response->inertiaProps('frameworks'))->firstWhere('value', 'code_of_rights')['label'],
        );
    }

    public function test_the_daily_refresh_stores_statuses_from_due_dates(): void
    {
        $admin = $this->createAdminUser();
        $overdue = $this->requirement($admin, -3, [], 'not_due');
        $dueSoon = $this->requirement($admin, 10, [], 'not_due');
        $later = $this->requirement($admin, 90, [], 'overdue');
        $done = $this->requirement($admin, -3, ['status' => 'complete']);

        $this->artisan('governance:refresh-compliance-statuses')->assertSuccessful();

        $this->assertSame('overdue', DB::table('compliance_obligations')->where('id', $overdue->id)->value('status'));
        $this->assertSame('due_soon', DB::table('compliance_obligations')->where('id', $dueSoon->id)->value('status'));
        $this->assertSame('not_due', DB::table('compliance_obligations')->where('id', $later->id)->value('status'));
        $this->assertSame('complete', DB::table('compliance_obligations')->where('id', $done->id)->value('status'));
    }

    public function test_status_refresh_and_risk_snapshots_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events());

        $refresh = $events->first(fn ($event) => str_contains((string) ($event->command ?? ''), 'governance:refresh-compliance-statuses'));
        $this->assertNotNull($refresh);
        $this->assertSame('10 0 * * *', $refresh->expression);
        $this->assertSame('Pacific/Auckland', (string) $refresh->timezone);

        $snapshot = $events->first(fn ($event) => ($event->description ?? null) === CaptureRiskHeatmapSnapshot::class);
        $this->assertNotNull($snapshot);
        $this->assertSame('0 6 1 * *', $snapshot->expression);
        $this->assertSame('Pacific/Auckland', (string) $snapshot->timezone);
    }

    public function test_evidence_opens_for_people_who_can_view_the_requirement_only(): void
    {
        Storage::fake('local');
        $admin = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $outsider = $this->createUserWithRole('support_worker');
        $obligation = $this->createComplianceObligation($admin);
        $other = $this->createComplianceObligation($admin, ['obligation_code' => 'OTHER-1']);

        $this->actingAs($admin)
            ->post("/governance/compliance/{$obligation->id}/evidence", [
                'evidence_type' => 'document',
                'title' => 'Annual return receipt',
                'file' => UploadedFile::fake()->create('Annual return receipt.pdf', 40, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $evidence = ComplianceEvidence::query()->sole();
        $this->assertSame('Annual return receipt.pdf', $evidence->original_name);

        $this->actingAs($admin)
            ->get("/governance/compliance/{$obligation->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('obligation.evidence.0.file_name', 'Annual return receipt.pdf')
                ->where('obligation.evidence.0.download_url', "/governance/compliance/{$obligation->id}/evidence/{$evidence->id}/download")
                ->where('obligation.evidence.0.expired', false)
                ->missing('obligation.evidence.0.file_path'));

        $download = $this->actingAs($member)
            ->get("/governance/compliance/{$obligation->id}/evidence/{$evidence->id}/download");
        $download->assertOk();
        $this->assertStringContainsString('Annual return receipt.pdf', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('sandbox', (string) $download->headers->get('Content-Security-Policy'));

        $this->actingAs($outsider)
            ->get("/governance/compliance/{$obligation->id}/evidence/{$evidence->id}/download")
            ->assertForbidden();

        $this->actingAs($admin)
            ->get("/governance/compliance/{$other->id}/evidence/{$evidence->id}/download")
            ->assertNotFound();
    }

    public function test_fields_that_were_fixed_when_added_can_be_corrected_and_are_logged(): void
    {
        $admin = $this->createAdminUser();
        $obligation = $this->createComplianceObligation($admin, ['framework' => 'privacy_act', 'evidence_required' => true]);

        $this->actingAs($admin)
            ->put("/governance/compliance/{$obligation->id}", [
                'framework' => 'code_of_rights',
                'obligation_reference' => 'HDC-R4',
                'requirements' => 'Complaints process reviewed by the board',
                'frequency' => 'quarterly',
                'priority' => 'high',
                'evidence_required' => false,
            ])
            ->assertSessionHasNoErrors();

        $obligation->refresh();
        $this->assertSame('code_of_rights', $obligation->framework);
        $this->assertSame('HDC-R4', $obligation->obligation_code);
        $this->assertSame('quarterly', $obligation->frequency);
        $this->assertSame('high', $obligation->priority);
        $this->assertFalse((bool) $obligation->evidence_required);

        $logged = DB::table('governance_audit_log')
            ->where('action', 'compliance.updated')
            ->where('resource_id', $obligation->id)
            ->value('metadata');
        $this->assertNotNull($logged);
        $this->assertContains('framework', json_decode((string) $logged, true)['fields']);

        $this->actingAs($admin)
            ->put("/governance/compliance/{$obligation->id}", ['framework' => 'not_a_framework'])
            ->assertSessionHasErrors(['framework' => 'Choose one of the listed laws, standards or funding contracts.']);
    }

    public function test_a_blank_due_date_is_set_from_how_often_it_is_due_and_evidence_can_be_optional(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin)
            ->post('/governance/compliance', [
                'framework' => 'charities',
                'title' => 'Annual return',
                'description' => 'File the annual return',
                'frequency' => 'annual',
                'due_date' => '',
                'evidence_required' => false,
            ])
            ->assertSessionHasNoErrors();

        $obligation = ComplianceObligation::query()->where('obligation_title', 'Annual return')->sole();
        $this->assertNotNull($obligation->due_date);
        $this->assertFalse((bool) $obligation->evidence_required);
    }
}
