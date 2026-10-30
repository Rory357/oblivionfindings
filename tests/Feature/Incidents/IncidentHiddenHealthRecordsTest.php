<?php

namespace Tests\Feature\Incidents;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidRecord;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Approved drawing D3 (MASTER for Stephan, 30 Oct): on the one accident record, a linked health record the viewer may
 * not open shows only "A health record — hidden, you don't have access", and its timeline entry only "Health record
 * added — hidden". The page payload carries when it was added and nothing else.
 */
class IncidentHiddenHealthRecordsTest extends TestCase
{
    use RefreshDatabase;

    private const RESTRAINT_REF = 'RE-D3-PRIVATE-77';

    private const FIRST_AID_PERSON = 'Hemi Private-Firstaid';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_a_viewer_without_access_sees_only_that_hidden_health_records_exist(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $viewer = $this->teamLead($site);
        // The incident reader may not open restraints or first aid.
        $this->deny($viewer, ['restraints.view', 'restraints.review', 'restraints.manage', 'hazards.view', 'hazards.manage']);
        [$incident] = $this->incidentWithLinkedHealthRecords($site, $viewer);

        $response = $this->actingAs($viewer->fresh())->get('/incidents/'.$incident->id)->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('incidents/show')
            ->where('detail.restraint_events', [])
            ->where('detail.first_aid_records', [])
            ->has('detail.hidden_health_records', 2)
            ->has('detail.hidden_health_records.0', fn (Assert $row) => $row->has('at'))
            ->has('detail.hidden_health_records.1', fn (Assert $row) => $row->has('at'))
        );

        $body = $response->getContent();
        foreach ([self::RESTRAINT_REF, self::FIRST_AID_PERSON] as $private) {
            $this->assertStringNotContainsString($private, $body, 'A hidden health record leaks: '.$private);
        }
    }

    public function test_a_viewer_with_restraint_access_sees_the_restraint_and_nothing_hidden_for_it(): void
    {
        $site = Site::factory()->create(['is_active' => true]);
        $viewer = $this->teamLead($site);
        $this->allow($viewer, ['restraints.view', 'clients.viewAny']);
        [$incident] = $this->incidentWithLinkedHealthRecords($site, $viewer, withFirstAid: false);

        $this->actingAs($viewer->fresh())->get('/incidents/'.$incident->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('detail.restraint_events', 1)
                ->where('detail.restraint_events.0.reference', self::RESTRAINT_REF)
                ->has('detail.restraint_events.0.started_at')
                ->where('detail.hidden_health_records', [])
            );
    }

    private function teamLead(Site $site): User
    {
        $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', 'team_lead')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => 'team_lead',
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        // Every viewer here can read the incident itself; only the linked records differ.
        $this->allow($user, ['incidents.viewAny']);

        return $user->fresh();
    }

    /** @param  array<int, string>  $keys */
    private function deny(User $user, array $keys): void
    {
        $this->override($user, $keys, false);
    }

    /** @param  array<int, string>  $keys */
    private function allow(User $user, array $keys): void
    {
        $this->override($user, $keys, true);
    }

    /** @param  array<int, string>  $keys */
    private function override(User $user, array $keys, bool $allowed): void
    {
        $ids = collect($keys)->map(fn (string $key) => Permission::firstOrCreate(['key' => $key], ['description' => $key])->id);
        $user->permissionOverrides()->syncWithoutDetaching($ids->mapWithKeys(fn ($id) => [$id => ['allowed' => $allowed]])->all());
    }

    /** @return array{0: ClientIncident, 1: RestraintEvent, 2: ?FirstAidRecord} */
    private function incidentWithLinkedHealthRecords(Site $site, User $reporter, bool $withFirstAid = true): array
    {
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->atSite($site)->submitted()->create([
            'client_id' => $client->id, 'reported_by' => $reporter->id, 'submitted_at' => now(),
        ]));
        $restraint = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create([
            'site_id' => $site->id, 'client_id' => $client->id, 'related_incident_id' => $incident->id,
            'reference_number' => self::RESTRAINT_REF, 'behaviour_support_plan_id' => null, 'behaviour_support_plan_version_id' => null,
        ]));
        $firstAid = $withFirstAid ? FirstAidRecord::withoutEvents(fn () => FirstAidRecord::factory()->create([
            'site_id' => $site->id, 'client_id' => $client->id, 'treated_person_type' => 'client',
            'treated_person_name' => self::FIRST_AID_PERSON, 'related_incident_id' => $incident->id,
        ])) : null;

        return [$incident->fresh(), $restraint->fresh(), $firstAid?->fresh()];
    }
}
