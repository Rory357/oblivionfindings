<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\GovernanceSetting;
use App\Models\Site;
use App\Services\HealthSafety\WorkerParticipationTrainingReviewerResolver;
use Inertia\Testing\AssertableInertia;

/** Actual settings requests; native UNRUN until application admission. */
final class WorkerParticipationTrainingReviewerSettingsTest extends RestraintEventCommandTestCase
{
    private const URL = '/governance/settings';
    private int $reviewerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->staff(['governance.settings.view', 'governance.settings.manage', 'hazards.view']);
        $this->reviewerId = (int) $this->staff(['hazards.view', 'governance.compliance.manage', 'governance.compliance.view'])->id;
    }

    public function test_default_assignment_persists_through_the_existing_settings_endpoint(): void
    {
        $this->save(['default_user_id' => $this->reviewerId, 'expected_default_user_id' => null]);
        $this->assertSame($this->reviewerId, $this->configuration()['default_user_id']);
        $this->assertSame($this->reviewerId, app(WorkerParticipationTrainingReviewerResolver::class)->reviewerId($this->site->id));
        $this->assertNull(app(WorkerParticipationTrainingReviewerResolver::class)->user(null));
    }

    public function test_blank_patch_does_not_materialise_an_unaudited_setting(): void
    {
        $this->save([]);
        $this->save(['sites' => []]);
        $this->assertFalse(GovernanceSetting::query()->where('key', WorkerParticipationTrainingReviewerResolver::KEY)->exists());
    }

    public function test_explicit_unassigned_and_removing_override_have_distinct_effects(): void
    {
        $this->store(['default_user_id' => $this->reviewerId, 'site_overrides' => []]);
        $this->save(['sites' => [$this->siteChange('unassigned', 'inherit', null)]]);
        $resolver = app(WorkerParticipationTrainingReviewerResolver::class);
        $this->assertNull($resolver->reviewerId($this->site->id));
        $this->save(['sites' => [$this->siteChange('inherit', 'unassigned', null)]]);
        $this->assertSame($this->reviewerId, $resolver->reviewerId($this->site->id));
    }

    public function test_scoped_save_preserves_hidden_and_unavailable_stored_assignments(): void
    {
        $hidden = Site::factory()->create(['is_active' => true]);
        $gone = $this->staff(['hazards.view', 'governance.compliance.manage', 'governance.compliance.view']);
        $gone->update(['approved_at' => null]);
        $this->store(['default_user_id' => (int) $gone->id,
            'site_overrides' => [(string) $hidden->id => (int) $gone->id]]);
        $this->save(['sites' => [$this->siteChange('person', 'inherit', null, $this->reviewerId)]]);
        $saved = $this->configuration();
        $this->assertSame((int) $gone->id, $saved['default_user_id']);
        $this->assertSame((int) $gone->id, $saved['site_overrides'][(string) $hidden->id]);
        $this->assertNull(app(WorkerParticipationTrainingReviewerResolver::class)->user($hidden->id));
    }

    public function test_same_field_conflict_refuses_without_losing_another_site_change(): void
    {
        $this->store(['default_user_id' => $this->reviewerId, 'site_overrides' => []]);
        $this->actingAs($this->actor)->put(self::URL, ['training_reviewers_patch' => [
            'default_user_id' => null, 'expected_default_user_id' => null,
            'sites' => [$this->siteChange('unassigned', 'inherit', null)],
        ]])->assertSessionHasErrors('training_reviewers_patch.default_user_id');
        $this->assertSame(['default_user_id' => $this->reviewerId, 'site_overrides' => []], $this->configuration());
    }

    public function test_unrelated_default_change_does_not_conflict_with_a_site_only_patch(): void
    {
        $this->store(['default_user_id' => $this->reviewerId, 'site_overrides' => []]);
        $this->save(['sites' => [$this->siteChange('unassigned', 'inherit', null)]]);
        $this->assertSame($this->reviewerId, $this->configuration()['default_user_id']);
    }

    public function test_stale_site_expected_value_is_refused_and_duplicate_site_does_not_partially_save(): void
    {
        $this->store(['default_user_id' => null, 'site_overrides' => [(string) $this->site->id => null]]);
        $this->actingAs($this->actor)->put(self::URL, ['training_reviewers_patch' => [
            'sites' => [$this->siteChange('person', 'inherit', null, $this->reviewerId)],
        ]])->assertSessionHasErrors('training_reviewers_patch.sites.0');
        $this->actingAs($this->actor)->put(self::URL, ['training_reviewers_patch' => [
            'sites' => [$this->siteChange('inherit', 'unassigned', null), $this->siteChange('inherit', 'unassigned', null)],
        ]])->assertSessionHasErrors('training_reviewers_patch.sites');
        $this->assertNull($this->configuration()['site_overrides'][(string) $this->site->id]);
    }

    public function test_revoked_reviewer_capability_changes_resolution_without_changing_configuration(): void
    {
        $this->store(['default_user_id' => $this->reviewerId, 'site_overrides' => []]);
        $resolver = app(WorkerParticipationTrainingReviewerResolver::class);
        $this->assertNotNull($resolver->user($this->site->id));
        $reviewer = \App\Models\User::findOrFail($this->reviewerId);
        $permission = \App\Models\Permission::where('key', 'governance.compliance.manage')->sole();
        $reviewer->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->assertNull($resolver->user($this->site->id));
        $this->assertSame($this->reviewerId, $this->configuration()['default_user_id']);
    }

    public function test_numeric_string_person_and_hidden_site_changes_are_rejected(): void
    {
        $this->actingAs($this->actor)->put(self::URL, ['training_reviewers_patch' => [
            'default_user_id' => (string) $this->reviewerId, 'expected_default_user_id' => null,
        ]])->assertSessionHasErrors('training_reviewers_patch.default_user_id');
        $hidden = Site::factory()->create(['is_active' => true]);
        $change = $this->siteChange('unassigned', 'inherit', null); $change['site_id'] = (int) $hidden->id;
        $this->actingAs($this->actor)->put(self::URL, ['training_reviewers_patch' => ['sites' => [$change]]])
            ->assertSessionHasErrors('training_reviewers_patch.sites.0.site_id');
    }

    public function test_inactive_staff_wrong_site_or_missing_independent_capability_cannot_be_assigned(): void
    {
        $other = Site::factory()->create(['is_active' => true]);
        $wrongSite = $this->staff(['hazards.view', 'governance.compliance.manage', 'governance.compliance.view'], $other);
        $missingCapability = $this->staff(['hazards.view']);
        $inactive = $this->staff(['hazards.view', 'governance.compliance.manage', 'governance.compliance.view']);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $manageOnly = $this->staff(['hazards.view', 'governance.compliance.manage']);
        foreach ([$wrongSite, $missingCapability, $inactive, $manageOnly] as $person) {
            $this->actingAs($this->actor)->put(self::URL, ['training_reviewers_patch' => [
                'sites' => [$this->siteChange('person', 'inherit', null, (int) $person->id)],
            ]])->assertSessionHasErrors('training_reviewers_patch.sites.0.reviewer_id');
        }
    }

    public function test_scoped_page_omits_hidden_site_and_unavailable_reviewer_name(): void
    {
        $hidden = Site::factory()->create(['is_active' => true]);
        $gone = $this->staff(['hazards.view', 'governance.compliance.manage', 'governance.compliance.view']); $gone->update(['approved_at' => null]);
        $this->store(['default_user_id' => (int) $gone->id, 'site_overrides' => [(string) $hidden->id => $this->reviewerId]]);
        $this->actingAs($this->actor)->get(self::URL)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('trainingReviewers.sites', 1)->where('trainingReviewers.sites.0.id', $this->site->id)
            ->where('trainingReviewers.default.name', null)->where('trainingReviewers.default.eligible', false));
    }

    public function test_view_permission_does_not_grant_settings_write_or_directory_candidates(): void
    {
        $this->store(['default_user_id' => $this->reviewerId, 'site_overrides' => [(string) $this->site->id => $this->reviewerId]]);
        $viewer = $this->staff(['governance.settings.view', 'hazards.view']);
        $this->actingAs($viewer)->get(self::URL)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('trainingReviewers.can_manage', false)->has('trainingReviewers.defaultCandidates', 0)
            ->where('trainingReviewers.default.id', $this->reviewerId)->where('trainingReviewers.default.name', null)
            ->where('trainingReviewers.default.eligible', null)
            ->where('trainingReviewers.sites.0.reviewer.id', $this->reviewerId)
            ->where('trainingReviewers.sites.0.reviewer.name', null)->where('trainingReviewers.sites.0.reviewer.eligible', null));
        $this->actingAs($viewer)->put(self::URL, ['training_reviewers_patch' => ['default_user_id' => null, 'expected_default_user_id' => null]])->assertForbidden();
    }

    public function test_non_person_changes_refuse_a_stale_named_reviewer_field(): void
    {
        foreach (['inherit', 'unassigned'] as $mode) {
            $change = $this->siteChange($mode, 'inherit', null);
            $change['reviewer_id'] = $this->reviewerId;
            $this->actingAs($this->actor)->put(self::URL, ['training_reviewers_patch' => ['sites' => [$change]]])
                ->assertSessionHasErrors('training_reviewers_patch.sites.0.reviewer_id');
        }
    }

    private function siteChange(string $mode, string $expectedMode, ?int $expected, ?int $reviewer = null): array
    {
        $change = ['site_id' => (int) $this->site->id, 'mode' => $mode, 'expected_mode' => $expectedMode, 'expected_reviewer_id' => $expected];
        if ($mode === 'person') { $change['reviewer_id'] = $reviewer; }
        return $change;
    }

    private function save(array $patch): void
    {
        $this->actingAs($this->actor)->put(self::URL, ['training_reviewers_patch' => $patch])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function store(array $configuration): void
    {
        GovernanceSetting::set(WorkerParticipationTrainingReviewerResolver::KEY, $configuration);
    }

    private function configuration(): array
    {
        return app(WorkerParticipationTrainingReviewerResolver::class)->configuration();
    }
}
