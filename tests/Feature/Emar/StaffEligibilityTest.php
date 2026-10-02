<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationCompetencyExemption;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\CompetencyAcknowledgement;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR P11 chunk 6 — Safety & oversight › Staff eligibility: each person's
 * status comes from the competency policy that recording a dose uses, the
 * register covers the reader's houses, and each row says what this reader
 * may do. Meds today carries the worker's own eligibility for its meter.
 */
class StaffEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Site $house;

    private User $lead;

    private User $assessor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->house = Site::factory()->create(['is_active' => true, 'name' => 'Kōwhai House']);
        $this->lead = $this->staff('admin', ['medications.view', 'medications.orders.manage', 'medications.competency.exempt', 'medications.witness_pin.reset', 'sites.viewAll'], name: 'Hana Kereama');
        $this->assessor = $this->staff('admin', ['medications.view', 'medications.orders.manage'], name: 'Assessor');
    }

    public function test_each_status_comes_from_the_competency_policy(): void
    {
        $current = $this->worker('Current Person');
        $this->assessment($current, ['expiry_date' => $this->day(200)]);
        $due = $this->worker('Due Person');
        $this->assessment($due, ['expiry_date' => $this->day(10)]);
        $expired = $this->worker('Expired Person');
        $this->assessment($expired, ['assessment_date' => $this->day(-400), 'expiry_date' => $this->day(-5)]);
        $restricted = $this->worker('Restricted Person');
        $this->assessment($restricted, ['restricted' => true, 'restriction_notes' => 'Supervised practice']);
        $failed = $this->worker('Failed Person');
        $this->assessment($failed, ['status' => 'failed', 'safety_checks' => false, 'staff_acknowledged_at' => null]);
        $none = $this->worker('None Person');
        $waiting = $this->worker('Waiting Person');
        $this->assessment($waiting, ['staff_acknowledged_at' => null]);
        $renewing = $this->worker('Renewing Person');
        $this->assessment($renewing, ['assessment_date' => $this->day(-300), 'expiry_date' => $this->day(60)]);
        $this->assessment($renewing, ['staff_acknowledged_at' => null]);
        $exempt = $this->worker('Exempt Person');
        MedicationCompetencyExemption::query()->create([
            'user_id' => $exempt->id, 'site_id' => $this->house->id,
            'scope' => MedicationCompetencyExemption::SCOPE_ADMINISTRATION,
            'reason' => 'Renewal booked — assessor on leave until then',
            'approved_by' => $this->lead->id, 'approved_at' => now(),
            'starts_at' => now()->subHour(), 'expires_at' => now()->addDays(5),
        ]);

        $people = $this->people();
        $this->assertRow($people, $current, ['st' => 'current', 'status' => 'current', 'until' => $this->day(200)]);
        $this->assertRow($people, $due, ['st' => 'current', 'status' => 'due', 'days' => 10]);
        $this->assertRow($people, $expired, ['st' => 'expired', 'status' => 'expired']);
        $this->assertRow($people, $restricted, ['st' => 'restricted', 'status' => 'restricted']);
        $this->assertSame('Supervised practice', $people->firstWhere('id', $restricted->id)['assessment']['restriction_notes']);
        $this->assertRow($people, $failed, ['st' => 'failed', 'status' => 'failed']);
        $this->assertSame('no', $people->firstWhere('id', $failed->id)['assessment']['res']['safety_checks']);
        $this->assertRow($people, $none, ['st' => 'none', 'status' => 'none', 'assessment' => null]);
        $this->assertRow($people, $waiting, ['st' => 'ack', 'status' => 'ack', 'prev_valid' => null]);
        // A new assessment waits; the previous one still counts until it's acknowledged.
        $this->assertRow($people, $renewing, ['st' => 'ack', 'status' => 'ack', 'prev_valid' => $this->day(60)]);
        // Their earlier assessment is still listed, as the old register listed every one.
        $this->assertSame([$this->day(-300)], collect($people->firstWhere('id', $renewing->id)['history'])->pluck('assessed')->all());
        $this->assertRow($people, $exempt, ['st' => 'none', 'status' => 'exempt']);
        $this->assertSame('Kōwhai House', $people->firstWhere('id', $exempt->id)['exemption']['house']);
    }

    public function test_the_register_covers_the_readers_houses_and_people_who_record_doses(): void
    {
        $here = $this->worker('Here Person');
        $elsewhere = $this->worker('Elsewhere Person', Site::factory()->create(['is_active' => true]));
        $office = $this->staff('support_worker', [], name: 'Office Person');

        $reader = $this->staff('support_worker', ['medications.view'], name: 'Reader');
        $ids = $this->people($reader)->pluck('id');
        $this->assertTrue($ids->contains($here->id));
        $this->assertFalse($ids->contains($elsewhere->id));

        // Someone who doesn't record doses joins once they have an assessment on file.
        $recordsDoses = $office->canDo('medications.administer.record');
        $this->assertSame($recordsDoses, $ids->contains($office->id));
        if (! $recordsDoses) {
            $this->assessment($office);
            $this->assertTrue($this->people($reader)->pluck('id')->contains($office->id));
        }

        $outsider = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->actingAs($outsider)->get('/emar/safety/eligibility')->assertForbidden();
    }

    public function test_each_row_says_what_this_reader_may_do(): void
    {
        $none = $this->worker('None Person');
        $current = $this->worker('Current Person');
        $this->assessment($current);
        $otherLead = $this->staff('team_lead', ['medications.administer.record'], name: 'Other Lead');

        $people = $this->people();
        $self = $people->firstWhere('id', $this->lead->id);
        $this->assertFalse($self['can']['assess']);
        $this->assertFalse($self['can']['exempt']);
        $this->assertTrue($people->firstWhere('id', $none->id)['can']['assess']);
        $this->assertTrue($people->firstWhere('id', $none->id)['can']['exempt']);
        // A current assessment needs no exemption.
        $this->assertFalse($people->firstWhere('id', $current->id)['can']['exempt']);
        // Workers get a PIN by default. This reader has all-sites authority,
        // so they may reset anyone's, even another lead's.
        $this->assertTrue($people->firstWhere('id', $current->id)['can']['reset_pin']);
        $this->assertTrue($people->firstWhere('id', $otherLead->id)['can']['reset_pin']);

        $reader = $this->staff('support_worker', ['medications.view'], name: 'Reader');
        $this->actingAs($reader)->get('/emar/safety/eligibility')
            ->assertInertia(fn (Assert $page) => $page
                ->where('can', ['assess' => false, 'exempt' => false, 'reset_pins' => false])
                ->where('clients', []));
    }

    public function test_exemptions_list_their_state_and_who_can_end_them(): void
    {
        $worker = $this->worker('Ben Carter');
        $make = fn (array $over) => MedicationCompetencyExemption::query()->create(array_merge([
            'user_id' => $worker->id, 'site_id' => $this->house->id,
            'scope' => MedicationCompetencyExemption::SCOPE_ADMINISTRATION,
            'reason' => 'Renewal booked — assessor on leave until then',
            'approved_by' => $this->lead->id, 'approved_at' => now()->subDays(20),
            'starts_at' => now()->subDays(20), 'expires_at' => now()->addDays(5),
        ], $over));
        $active = $make([]);
        $ended = $make(['expires_at' => now()->subDays(2)]);
        $revoked = $make(['revoked_at' => now()->subDay(), 'revoked_by' => $this->lead->id, 'revocation_reason' => 'Renewal done on 1 October']);

        $this->actingAs($this->lead)->get('/emar/safety/eligibility')
            ->assertInertia(fn (Assert $page) => $page
                ->where('exemptions.0.id', $active->id)
                ->where('exemptions.0.status', 'active')
                ->where('exemptions.0.can_end', true)
                ->where('exemptions', fn ($list) => collect($list)->firstWhere('id', $ended->id)['status'] === 'ended'
                    && collect($list)->firstWhere('id', $revoked->id)['status'] === 'revoked'
                    && collect($list)->firstWhere('id', $revoked->id)['end_reason'] === 'Renewal done on 1 October'
                    && collect($list)->firstWhere('id', $revoked->id)['can_end'] === false));
    }

    public function test_meds_today_carries_the_workers_own_eligibility(): void
    {
        $worker = $this->worker('Priya Shah');
        $this->assessment($worker, ['expiry_date' => $this->day(12)]);

        $this->actingAs($worker)->get(route('meds.today'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('my_eligibility.person.id', $worker->id)
                ->where('my_eligibility.person.status', 'due')
                ->where('my_eligibility.person.records_doses', true)
                ->where('my_eligibility.pending', null)
                ->where('my_eligibility.policy.renewal_days', 30)
                ->has('my_eligibility.areas', 12));
    }

    private function day(int $offset): string
    {
        return now('Pacific/Auckland')->startOfDay()->addDays($offset)->toDateString();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function people(?User $reader = null): Collection
    {
        $people = null;
        $this->actingAs($reader ?? $this->lead)->get('/emar/safety/eligibility')
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$people) {
                $people = collect($page->toArray()['props']['people']);

                return $page->component('emar/StaffEligibility');
            });

        return $people;
    }

    /** @param  array<string, mixed>  $expected */
    private function assertRow(Collection $people, User $user, array $expected): void
    {
        $row = $people->firstWhere('id', $user->id);
        $this->assertNotNull($row, $user->name.' is missing from the register');
        foreach ($expected as $key => $value) {
            $this->assertSame($value, $row[$key], $user->name.': '.$key);
        }
    }

    /** A full assessment, as the app records one; passed, declared and acknowledged unless overridden. */
    private function assessment(User $user, array $over = []): MedicationCompetencyAssessment
    {
        return MedicationCompetencyAssessment::query()->forceCreate(array_merge(
            collect(array_keys(CompetencyAcknowledgement::AREAS))->mapWithKeys(fn (string $key) => [$key => true])->all(),
            [
                'user_id' => $user->id,
                'assessor_id' => $this->assessor->id,
                'assessment_type' => 'annual',
                'status' => 'passed',
                'assessment_date' => $this->day(-30),
                'expiry_date' => $this->day(335),
                'total_score' => 12,
                'pass_threshold' => 10,
                'assessor_declared_at' => now()->subDays(30),
                'staff_acknowledged_at' => now()->subDays(29),
            ],
            $over,
        ));
    }

    private function worker(string $name, ?Site $site = null): User
    {
        return $this->staff('support_worker', ['medications.view', 'medications.administer.record'], $site, $name);
    }

    /** @param  list<string>  $permissions */
    private function staff(string $role, array $permissions, ?Site $site = null, ?string $name = null): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now(), ...($name ? ['name' => $name] : [])]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', $role)->firstOrFail()->id]);
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', $permissions)->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => ($site ?? $this->house)->id,
            'secondary_site_ids' => [],
            'is_active' => true,
            'start_date' => today()->subMonth(),
            'end_date' => null,
        ]);

        return $user->fresh();
    }
}
