<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\CompetencyAcknowledgement;
use App\Services\Medication\CompetencyPolicySettings;
use App\Services\Medication\MedicationAdministratorCompetencyPolicy;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * eMAR P11 chunk 5 (Q-E) — competency exemptions: granted for one person at
 * their own house, by someone who can approve exemptions there, never for
 * themselves, ending within the organisation's longest exemption; ended early
 * only with a reason. Each is audited.
 */
class CompetencyExemptionRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Site $house;

    private Site $otherHouse;

    private User $approver;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->house = Site::factory()->create(['is_active' => true, 'name' => 'Kōwhai House']);
        $this->otherHouse = Site::factory()->create(['is_active' => true, 'name' => 'Rimu House']);
        $this->approver = $this->staff($this->house, ['medications.view', 'medications.competency.exempt']);
        $this->worker = $this->staff($this->house, ['medications.view', 'medications.administer.record'], 'Ben Carter');
    }

    public function test_an_exemption_is_granted_for_their_own_house_until_its_end_date_and_audited(): void
    {
        $until = now('Pacific/Auckland')->addDays(10);
        $this->actingAs($this->approver)
            ->from('/emar/competency')
            ->post(route('emar.competency.exemptions.store'), $this->grant(['ends_on' => $until->toDateString()]))
            ->assertRedirect('/emar/competency')
            ->assertSessionHas('success', 'Exemption granted. Ben Carter can record doses as given at Kōwhai House until '.$until->format('j M Y').'. It ends by itself.');

        $exemption = MedicationCompetencyExemption::query()->sole();
        $this->assertSame($this->worker->id, (int) $exemption->user_id);
        $this->assertSame($this->house->id, (int) $exemption->site_id);
        $this->assertSame($this->approver->id, (int) $exemption->approved_by);
        // The end of the chosen NZ day, stored as a UTC instant.
        $this->assertSame(
            $until->copy()->endOfDay()->utc()->format('Y-m-d H:i:s'),
            $exemption->expires_at->copy()->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.competency.exemption.approved')->count());

        $decision = app(MedicationAdministratorCompetencyPolicy::class)->evaluate($this->worker, $this->house->id, now());
        $this->assertTrue($decision['allowed']);
        $this->assertSame('exempt', $decision['state']);
        // One house only.
        $this->assertFalse(app(MedicationAdministratorCompetencyPolicy::class)->evaluate($this->worker, $this->otherHouse->id, now())['allowed']);

        // Already exempt there: not twice.
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant())
            ->assertSessionHasErrors(['user_id' => 'Ben Carter already has an exemption at Kōwhai House.']);
    }

    public function test_the_end_date_is_within_the_longest_exemption(): void
    {
        $from = now('Pacific/Auckland');
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant(['ends_on' => $from->copy()->addDays(31)->toDateString()]))
            ->assertSessionHasErrors(['ends_on' => 'That’s longer than your organisation allows (30 days). Choose '.$from->copy()->addDays(30)->format('j M Y').' or earlier.']);

        AppSetting::query()->create(['key' => CompetencyPolicySettings::LONGEST_EXEMPTION_DAYS, 'value' => '7']);
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant(['ends_on' => $from->copy()->addDays(8)->toDateString()]))
            ->assertSessionHasErrors(['ends_on' => 'That’s longer than your organisation allows (7 days). Choose '.$from->copy()->addDays(7)->format('j M Y').' or earlier.']);
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant(['ends_on' => $from->copy()->addDays(7)->toDateString()]))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, MedicationCompetencyExemption::query()->count());
    }

    public function test_nobody_exempts_themselves_or_anyone_outside_their_houses(): void
    {
        // Not for yourself.
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant(['user_id' => $this->approver->id]))
            ->assertForbidden();

        // Not at a house you can't reach, and only at the person's own house.
        $elsewhere = $this->staff($this->otherHouse, ['medications.administer.record']);
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant(['site_id' => $this->otherHouse->id]))
            ->assertNotFound();
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant(['user_id' => $elsewhere->id]))
            ->assertNotFound();

        // Not without the permission.
        $lead = $this->staff($this->house, ['medications.view']);
        $this->actingAs($lead)
            ->post(route('emar.competency.exemptions.store'), $this->grant())
            ->assertForbidden();

        $this->assertSame(0, MedicationCompetencyExemption::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'medications.competency.exemption.approved')->count());
    }

    public function test_someone_with_a_current_assessment_needs_no_exemption_and_none_starts_in_the_past(): void
    {
        $this->currentAssessment($this->worker);
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant())
            ->assertSessionHasErrors(['user_id' => 'Ben Carter has a current assessment — no exemption is needed.']);

        $other = $this->staff($this->house, ['medications.administer.record']);
        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.store'), $this->grant([
                'user_id' => $other->id,
                'starts_on' => now('Pacific/Auckland')->subDay()->toDateString(),
            ]))
            ->assertSessionHasErrors(['starts_on' => 'An exemption can’t start in the past.']);
    }

    public function test_an_exemption_ends_early_only_with_a_reason_and_only_by_someone_at_the_house(): void
    {
        $this->actingAs($this->approver)->post(route('emar.competency.exemptions.store'), $this->grant())->assertSessionHasNoErrors();
        $exemption = MedicationCompetencyExemption::query()->sole();

        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.end', $exemption), ['reason' => 'done'])
            ->assertSessionHasErrors(['reason' => 'Say why, in at least 10 characters.']);

        $outsider = $this->staff($this->otherHouse, ['medications.view', 'medications.competency.exempt']);
        $this->actingAs($outsider)
            ->post(route('emar.competency.exemptions.end', $exemption), ['reason' => 'Renewal done on 2 October'])
            ->assertNotFound();
        $this->assertNull($exemption->fresh()->revoked_at);

        $this->actingAs($this->approver)
            ->post(route('emar.competency.exemptions.end', $exemption), ['reason' => 'Renewal done on 2 October'])
            ->assertSessionHas('success', 'Exemption ended. They can’t record doses as given until they have a current assessment.');
        $ended = $exemption->fresh();
        $this->assertNotNull($ended->revoked_at);
        $this->assertSame('Renewal done on 2 October', $ended->revocation_reason);
        $this->assertSame(1, AuditLog::query()->where('action', 'medications.competency.exemption.revoked')->count());
        $this->assertFalse(app(MedicationAdministratorCompetencyPolicy::class)->evaluate($this->worker, $this->house->id, now())['allowed']);
    }

    /** @return array<string, mixed> */
    private function grant(array $over = []): array
    {
        return array_merge([
            'user_id' => $this->worker->id,
            'site_id' => $this->house->id,
            'reason' => 'Renewal booked for 9 October — the assessor is on leave',
            'starts_on' => now('Pacific/Auckland')->toDateString(),
            'ends_on' => now('Pacific/Auckland')->addDays(5)->toDateString(),
        ], $over);
    }

    private function currentAssessment(User $user): void
    {
        $assessor = $this->staff($this->house, ['medications.orders.manage']);
        $assessment = new MedicationCompetencyAssessment;
        $assessment->forceFill([
            ...collect(array_keys(CompetencyAcknowledgement::AREAS))->mapWithKeys(fn (string $key) => [$key => true])->all(),
            'user_id' => $user->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'initial',
            'assessment_date' => now('Pacific/Auckland')->subMonth()->toDateString(),
            'expiry_date' => now('Pacific/Auckland')->addMonths(11)->toDateString(),
            'status' => 'passed',
            'total_score' => 12,
            'pass_threshold' => 10,
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth(),
        ])->save();
    }

    /** @param  list<string>  $permissions */
    private function staff(Site $site, array $permissions, ?string $name = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), ...($name ? ['name' => $name] : [])]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subDay(),
            'end_date' => null,
        ]);

        return $user->fresh();
    }
}
