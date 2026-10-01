<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\CompetencyAcknowledgement;
use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\WitnessPinSettings;
use App\Services\MedicationOverviewService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 chunk 5 — Settings › Staff & PINs: competency values (how long an
 * assessment lasts, the pass mark, every core area, the observed minimum,
 * the renewal reminder), the longest exemption and the witness PIN rules are
 * organisation settings, saved through the settings page, read by one reader
 * and enforced where assessments are recorded.
 */
class CompetencyPolicySettingsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $manager;

    private User $assessor;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->manager = $this->staff('support_worker', ['medications.settings.manage', 'medications.view', 'sites.viewAll']);
        $this->assessor = $this->staff('admin', ['medications.view', 'medications.orders.manage']);
        $this->worker = $this->staff('support_worker', ['medications.view', 'medications.administer.record']);
    }

    public function test_the_defaults_are_todays_rules_and_show_as_not_reviewed(): void
    {
        $policy = app(CompetencyPolicySettings::class);
        $this->assertSame(12, $policy->validityMonths());
        $this->assertSame(10, $policy->passMark());
        $this->assertFalse($policy->coreAreasMustPass());
        $this->assertNull($policy->observedMinimum());
        $this->assertSame(30, $policy->renewalReminderDays());
        $this->assertSame(30, $policy->longestExemptionDays());

        $this->actingAs($this->manager)
            ->get('/emar/settings')
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.values.elig', [
                    'validity' => '12',
                    'pass_mark' => '10',
                    'core_must_pass' => 'no',
                    'observed_minimum' => 'off',
                    'reminder' => '30',
                    'longest_exemption' => '30',
                ])
                ->where('settings.reviewed.elig.validity', null)
                ->where('settings.definitions.elig.observed_minimum.when_not_configured', 'No minimum is asked for')
                ->where('settings.definitions.pin.max_attempts.range', [3, 10])
                ->where('settings.definitions.pin.renewal_months.off_label', 'No renewal'));
    }

    public function test_saved_values_decide_how_an_assessment_is_recorded(): void
    {
        $this->saveStaff([
            ['elig', 'validity', '6', '12'],
            ['elig', 'pass_mark', '11', '10'],
            ['elig', 'core_must_pass', 'yes', 'no'],
            ['elig', 'observed_minimum', '2', 'off'],
            ['elig', 'reminder', '45', '30'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.competency_policy.updated')->count());
        $policy = app(CompetencyPolicySettings::class);
        $this->assertSame(6, $policy->validityMonths());
        $this->assertSame(11, $policy->passMark());
        $this->assertTrue($policy->coreAreasMustPass());
        $this->assertSame(2, $policy->observedMinimum());
        $this->assertSame(45, $policy->renewalReminderDays());

        // Fewer observed administrations than the organisation asks for: not saved.
        $this->recordAssessment(['observed_rounds' => [['resident' => 'Client A']]])
            ->assertSessionHasErrors(['observed_rounds' => 'Log at least 2 observed administrations — your organisation asks for 2 (Settings › Staff & PINs). 1 logged.']);
        $this->assertSame(0, MedicationCompetencyAssessment::query()->count());

        // 11 of 12 meets the pass mark, but a core area wasn't passed.
        $this->recordAssessment(['documentation' => false])->assertSessionHasNoErrors();
        $failed = MedicationCompetencyAssessment::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame(11, $failed->pass_threshold);

        // 11 of 12 with every core area passed: passed, ending 6 months on at the latest.
        $this->recordAssessment(['insulin_competent' => false])->assertSessionHasNoErrors();
        $passed = MedicationCompetencyAssessment::query()->latest('id')->firstOrFail();
        $this->assertSame('passed', $passed->status);
        $this->assertSame(
            now('Pacific/Auckland')->startOfDay()->addMonthsNoOverflow(6)->toDateString(),
            $passed->expiry_date->toDateString(),
        );

        $this->recordAssessment(['expiry_date' => now('Pacific/Auckland')->addMonths(7)->toDateString()])
            ->assertSessionHasErrors('expiry_date');
    }

    public function test_with_the_defaults_only_the_pass_mark_counts(): void
    {
        $this->recordAssessment(['documentation' => false, 'error_reporting' => false])->assertSessionHasNoErrors();
        $this->assertSame('passed', MedicationCompetencyAssessment::query()->sole()->status);
    }

    public function test_a_value_outside_its_range_isnt_saved_and_pin_rules_take_any_number_in_range(): void
    {
        $this->saveStaff([['elig', 'validity', '37', '12']])->assertSessionHasErrors('changes.0.value');
        $this->saveStaff([['elig', 'observed_minimum', '0', 'off']])->assertSessionHasErrors('changes.0.value');
        $this->saveStaff([['pin', 'max_attempts', '11', '5']])->assertSessionHasErrors('changes.0.value');
        $this->saveStaff([['pin', 'renewal_months', '25', 'none']])->assertSessionHasErrors('changes.0.value');

        $this->saveStaff([
            ['pin', 'max_attempts', '4', '5'],
            ['pin', 'lockout_minutes', '45', '15'],
            ['pin', 'renewal_months', '18', 'none'],
        ])->assertSessionHasNoErrors();
        $pins = app(WitnessPinSettings::class);
        $this->assertSame(4, $pins->maxAttempts());
        $this->assertSame(45, $pins->lockoutMinutes());
        $this->assertSame(18, $pins->renewalMonths());

        // Switching renewal off loosens a check: it asks for confirmation first.
        $this->saveStaff([['pin', 'renewal_months', 'none', '18']])->assertSessionHasErrors();
        $this->saveStaff([['pin', 'renewal_months', 'none', '18']], true)->assertSessionHasNoErrors();
        $this->assertNull($pins->renewalMonths());
    }

    public function test_a_setting_that_isnt_configured_cant_be_kept_as_it_is(): void
    {
        $this->actingAs($this->manager)
            ->from('/emar/settings')
            ->post('/emar/settings/keep', ['items' => [['group' => 'elig', 'key' => 'observed_minimum']]])
            ->assertSessionHasErrors(['items.0.key' => '“Minimum observed administrations” isn’t configured — choose a value instead.']);

        $this->actingAs($this->manager)
            ->from('/emar/settings')
            ->post('/emar/settings/keep', ['items' => [['group' => 'elig', 'key' => 'pass_mark']]])
            ->assertSessionHasNoErrors();
    }

    public function test_the_renewal_reminder_drives_the_register_and_the_dashboard(): void
    {
        $this->recordAssessment(['expiry_date' => now('Pacific/Auckland')->addDays(40)->toDateString()])->assertSessionHasNoErrors();
        // The register counts an assessment once the worker acknowledges it.
        $this->actingAs($this->worker)
            ->post(route('emar.competency.acknowledge', MedicationCompetencyAssessment::query()->sole()))
            ->assertRedirect();
        $reader = $this->manager;

        $this->actingAs($reader)->get('/emar/competency')
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.expiring', 0)
                ->where('policy.renewal_days', 30)
                ->where('policy.core_must_pass', false)
                ->where('policy.observed_minimum', null));
        $before = app(MedicationOverviewService::class)->payload(null, $reader);
        $this->assertSame(0, $before['stats']['expiringCompetencies']);
        $this->assertSame(0, $before['compliance']['competencyExpiring']);

        $this->saveStaff([['elig', 'reminder', '45', '30']])->assertSessionHasNoErrors();
        app()->forgetScopedInstances();

        $this->actingAs($reader)->get('/emar/competency')
            ->assertInertia(fn (Assert $page) => $page
                ->where('kpis.expiring', 1)
                ->where('policy.renewal_days', 45));
        $after = app(MedicationOverviewService::class)->payload(null, $reader);
        $this->assertSame(1, $after['stats']['expiringCompetencies']);
        $this->assertSame(1, $after['compliance']['competencyExpiring']);
    }

    public function test_a_save_is_read_in_the_same_request(): void
    {
        $policy = app(CompetencyPolicySettings::class);
        $this->assertSame(10, $policy->passMark());
        $this->saveStaff([['elig', 'pass_mark', '12', '10']])->assertSessionHasNoErrors();
        $this->assertSame(12, $policy->passMark());
    }

    /** @param  list<array{0: string, 1: string, 2: string, 3: string}>  $changes */
    private function saveStaff(array $changes, bool $confirmLoosening = false)
    {
        return $this->actingAs($this->manager)
            ->from('/emar/settings')
            ->put('/emar/settings/changes', [
                'view' => 'staff',
                'changes' => array_map(fn (array $c): array => [
                    'group' => $c[0],
                    'key' => $c[1],
                    'site_id' => null,
                    'value' => $c[2],
                    'from' => $c[3],
                ], $changes),
                ...($confirmLoosening ? ['confirm_loosening' => true] : []),
            ]);
    }

    /** Record an assessment through the app, as an assessor does: every area passed unless overridden. */
    private function recordAssessment(array $over = [])
    {
        $areas = collect(array_keys(CompetencyAcknowledgement::AREAS))->mapWithKeys(fn (string $key) => [$key => true])->all();

        return $this->actingAs($this->assessor)
            ->from('/emar/competency')
            ->post('/emar/competency', array_merge($areas, [
                'user_id' => $this->worker->id,
                'assessment_type' => 'initial',
                'assessment_date' => now('Pacific/Auckland')->toDateString(),
                'assessor_declared' => true,
                'observed_rounds' => [['resident' => 'Client A'], ['resident' => 'Client B']],
            ], $over));
    }

    /** @param  list<string>  $permissions */
    private function staff(string $role, array $permissions): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        return $user->fresh();
    }
}
