<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\Incidents\IncidentHsEventSourceReadService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Speed option B (Stephan, 30 Oct 2026): the per-request proven id sets must admit exactly what the original embedded
 * scopes admit, for every role, on the same data: plain reviews at the viewer's Site and another Site, an
 * incident-linked review, an HR-confidential incident, and first-aid and restraint sources.
 */
class ProvenScopeParityTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['admin', 'health_safety_officer', 'team_lead', 'provider_manager', 'support_worker', 'report_only'];

    public function test_proven_sets_admit_the_same_reviews_and_incidents_as_the_embedded_scopes_for_every_role(): void
    {
        $this->seed(RbacSeeder::class);
        $own = Site::factory()->create(['is_active' => true]);
        $other = Site::factory()->create(['is_active' => true]);
        $creator = User::factory()->create();
        foreach ([$own, $other] as $site) {
            $client = Client::factory()->create(['site_id' => $site->id]);
            HsEvent::factory()->create(['site_id' => $site->id, 'event_category' => 'hazard', 'created_by' => $creator->id]);
            foreach ([false, true] as $confidential) {
                $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
                    'site_id' => $site->id, 'client_id' => $client->id, 'status' => 'submitted', 'submitted_at' => now(),
                    'reported_by' => $creator->id, 'occurred_at' => now(), 'is_hr_confidential' => $confidential,
                ]));
                $event = HsEvent::factory()->create(['site_id' => $site->id, 'client_id' => $client->id, 'event_category' => 'incident',
                    'source_type' => ClientIncident::class, 'source_id' => $incident->id, 'created_by' => $creator->id]);
                $incident->forceFill(['hs_event_id' => $event->id])->saveQuietly();
            }
            $firstAid = FirstAidRecord::factory()->create(['site_id' => $site->id]);
            HsEvent::factory()->create(['site_id' => $site->id, 'event_category' => 'injury',
                'source_type' => FirstAidRecord::class, 'source_id' => $firstAid->id, 'created_by' => $creator->id]);
            $restraint = RestraintEvent::factory()->create(['site_id' => $site->id, 'client_id' => $client->id]);
            HsEvent::factory()->create(['site_id' => $site->id, 'client_id' => $client->id, 'event_category' => 'restraint',
                'source_type' => RestraintEvent::class, 'source_id' => $restraint->id, 'created_by' => $creator->id]);
        }

        $permissions = array_values(array_unique([...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS,
            ...InjuryAccessService::PERMISSIONS, ...IncidentHsEventSourceReadService::PERMISSIONS]));
        $checked = 0;
        foreach (self::ROLES as $role) {
            $viewer = $this->viewer($role, $own);
            HealthSafetyReadContext::within($viewer, $permissions, function (HealthSafetyReadContext $context) use ($viewer, &$checked, $role): void {
                $access = app(HsEventAccessService::class);
                $context->provePageWide();
                $this->assertTrue($context->provesPageWide());
                $proven = $access->applyFirstAidSourceScope(HsEvent::query(), $viewer, $context)->pluck('hs_events.id')->sort()->values()->all();
                $embedded = $access->applyFirstAidSourceScopeEmbedded(HsEvent::query(), $viewer, $context)->pluck('hs_events.id')->sort()->values()->all();
                $this->assertSame($embedded, $proven, "H&S reviews differ for $role");

                $context->consumeSourceViewer(IncidentHsEventSourceReadService::PERMISSIONS, function (User $current, $reads) use ($role): void {
                    $service = app(IncidentHsEventSourceReadService::class);
                    $proven = $reads->query($service->applyIncidentScopeInReads(ClientIncident::query(), $current, $reads))->pluck('client_incidents.id')->sort()->values()->all();
                    $embedded = $reads->query($service->applyIncidentScopeInReadsEmbedded(ClientIncident::query(), $current, $reads))->pluck('client_incidents.id')->sort()->values()->all();
                    $this->assertSame($embedded, $proven, "Incidents differ for $role");
                });
                $checked++;
            });
        }
        $this->assertSame(count(self::ROLES), $checked);
    }

    private function viewer(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role === 'report_only' ? 'support_worker' : $role, 'approved_at' => now()]);
        if ($role === 'report_only') {
            // Reporting keys only: no register or record read.
            $keys = ['incidents.create', 'restraints.report', 'injuries.report', 'safeguarding.create', 'hazards.view'];
            foreach ($keys as $key) {
                $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'test', 'module' => 'Compliance']);
                $user->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
            }
        } else {
            $user->roles()->attach(Role::where('name', $role)->sole());
        }
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => $role, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return $user->fresh();
    }
}
