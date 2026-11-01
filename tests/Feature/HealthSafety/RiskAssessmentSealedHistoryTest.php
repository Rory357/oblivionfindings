<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\HsRiskAssessment;
use App\Models\HsRiskAssessmentAcceptance;
use App\Models\Site;
use App\Services\Audit\AuditLogViewService;
use App\Services\HealthSafety\HsRiskAssessmentService;
use App\Services\HealthSafety\RiskAssessmentAcceptanceService;
use App\Support\HealthSafety\RiskAssessmentPresenter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * #13 (boards 13-1 to 13-3, approved by Stephan 2 Nov): every change a person makes to a risk assessment is sealed in
 * the same save; the History tab says whether the chain checks out and what each change changed; a broken chain hides
 * the history and the change buttons and refuses changes; an administrator's recorded reset lets the team work again;
 * the audit page shows a person-linked assessment as who changed what and when only ("Same rule as job 4").
 */
final class RiskAssessmentSealedHistoryTest extends ChemicalExposureCommandTestCase
{
    private function service(): HsRiskAssessmentService { return app(HsRiskAssessmentService::class); }

    private function siteRisk(): HsRiskAssessment
    {
        return $this->service()->create(['title' => 'Hoist transfers in the bathroom', 'risk_description' => 'Two-person transfers',
            'assessable_type' => Site::class, 'assessable_id' => $this->site->id, 'likelihood' => 3, 'consequence' => 3], $this->manager);
    }

    private function detail(HsRiskAssessment $risk): array
    {
        $this->actingAs($this->manager);

        return RiskAssessmentPresenter::detail($risk->fresh(), true, $this->manager);
    }

    public function test_each_change_is_sealed_in_its_save_and_the_history_checks_out_with_what_changed(): void
    {
        $risk = $this->siteRisk();
        $this->service()->activate($risk->fresh(), ['approver_note' => 'Approved for use'], $this->manager);

        $markers = HsRiskAssessmentAcceptance::where('assessment_id', $risk->id)->orderBy('sequence')->get();
        $this->assertSame([1, 2], $markers->pluck('sequence')->all());
        $this->assertSame(['risk_assessment.created', 'risk_assessment.status_changed'], $markers->pluck('action')->all());
        $this->assertSame($markers[0]->id, $markers[1]->prior_id);

        $detail = $this->detail($risk);
        $this->assertSame('checked', $detail['history_integrity']['state']);
        $this->assertSame(2, $detail['history_integrity']['sealed_count']);
        $activated = collect($detail['history'])->firstWhere('title', 'Assessment activated');
        $this->assertSame(2, $activated['sealed']['change']);
        $this->assertContains(['field' => 'status', 'before' => 'draft', 'after' => 'active'], $activated['sealed']['changes']);
        $this->assertTrue($detail['can']['manage']);
    }

    public function test_a_tampered_entry_hides_the_history_refuses_changes_and_an_admin_reset_lets_the_team_work_again(): void
    {
        $risk = $this->siteRisk();
        $first = HsRiskAssessmentAcceptance::where('assessment_id', $risk->id)->sole();
        DB::table('hs_risk_assessment_acceptances')->where('id', $first->id)->update(['business' => json_encode(['note' => 'Rewritten'])]);

        $detail = $this->detail($risk);
        $this->assertSame('broken', $detail['history_integrity']['state']);
        $this->assertSame([], $detail['history']);
        $this->assertFalse($detail['can']['manage']);
        $this->assertFalse($detail['can']['activate']);

        try {
            $this->service()->activate($risk->fresh(), [], $this->manager);
            $this->fail('A broken chain must refuse the change.');
        } catch (HttpException $refused) {
            $this->assertSame(409, $refused->getStatusCode());
        }
        $this->assertSame('draft', $risk->fresh()->status);

        $this->allow($this->manager, 'audit.viewAny');
        $acceptance = app(RiskAssessmentAcceptanceService::class);
        $this->assertSame('broken', $acceptance->chainState($risk->id, $this->manager->fresh())['state']);
        $reset = $acceptance->resetBrokenChain($risk->id, $this->manager->fresh(), 'Investigated: restored from the backup of 1 Nov');
        $this->assertSame(1, $reset['after_sequence']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk_assessment.history_reset', 'auditable_id' => $risk->id]);

        $this->service()->activate($risk->fresh(), [], $this->manager);
        $this->assertSame('active', $risk->fresh()->status);
        $next = HsRiskAssessmentAcceptance::where('assessment_id', $risk->id)->orderByDesc('sequence')->firstOrFail();
        $this->assertSame(2, $next->sequence);
        $this->assertNull($next->prior_id, 'The new chain starts after the reset.');
        $this->assertSame('checked', $acceptance->chainState($risk->id, $this->manager->fresh())['state']);
    }

    public function test_a_reset_is_refused_while_the_history_checks_out(): void
    {
        $risk = $this->siteRisk();
        $this->allow($this->manager, 'audit.viewAny');

        $this->expectException(\InvalidArgumentException::class);
        app(RiskAssessmentAcceptanceService::class)->resetBrokenChain($risk->id, $this->manager->fresh(), 'Nothing wrong');
    }

    public function test_the_audit_page_shows_a_person_linked_assessment_as_who_changed_what_and_when_only(): void
    {
        $this->allow($this->manager, 'clients.viewAny');
        $client = Client::factory()->create(['site_id' => $this->site->id, 'first_name' => 'Aroha', 'last_name' => 'Kingi']);
        $linked = $this->service()->create(['title' => 'Aroha Kingi moving and handling', 'risk_description' => 'Private',
            'assessable_type' => Client::class, 'assessable_id' => $client->id, 'likelihood' => 2, 'consequence' => 4], $this->manager);
        $this->service()->activate($linked->fresh(), [], $this->manager);
        $this->service()->updateResidualRisk($linked->fresh(), 1, 4, true, $this->manager, 'Hoist sling replaced');
        $ordinary = $this->siteRisk();
        $this->service()->activate($ordinary->fresh(), [], $this->manager);
        $this->service()->updateResidualRisk($ordinary->fresh(), 2, 2, true, $this->manager);

        $present = fn (HsRiskAssessment $risk) => app(AuditLogViewService::class)->present(AuditLog::query()
            ->where('auditable_id', $risk->id)->where('action', 'risk_assessment.residual_recorded')->latest('id')->firstOrFail());

        $row = $present($linked);
        $this->assertSame('linked_person', $row['values_hidden']);
        $this->assertSame($linked->reference_number, $row['subject_label']);
        $this->assertNull($row['client']);
        $this->assertSame([], $row['properties']['before']);
        $this->assertSame([], $row['properties']['after']);
        $this->assertContains('residual_likelihood', $row['properties']['fields']);
        $this->assertStringNotContainsString('Aroha', json_encode($row, JSON_THROW_ON_ERROR));

        $row = $present($ordinary);
        $this->assertNull($row['values_hidden']);
        $this->assertSame($ordinary->reference_number.' · Hoist transfers in the bathroom', $row['subject_label']);
        $this->assertSame(2, $row['properties']['after']['residual_likelihood']);
    }
}
