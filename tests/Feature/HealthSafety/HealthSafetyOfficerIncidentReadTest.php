<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\HsEventAccessService;
use App\Services\HealthSafety\HsEventService;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Incidents\IncidentIndependenceTestCase;

/**
 * Stephan (25 Oct 2026): H&S officers read incidents, so incident-linked H&S work
 * shows for them, still limited by the incident Site rule and privacy rules.
 */
final class HealthSafetyOfficerIncidentReadTest extends IncidentIndependenceTestCase
{
    private Site $otherSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->otherSite = Site::factory()->create(['is_active' => true]);
    }

    /** Seeded health_safety_officer role, current HR profile at $site (null = no Site, like the demo officers). */
    private function officer(?Site $site): User
    {
        $user = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now(), 'external_clinical_account' => false]);
        $user->roles()->attach(Role::where('name', 'health_safety_officer')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site?->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);

        return $user;
    }

    /** An incident with its original H&S event linked both ways. */
    private function linkedEvent(Site $site, array $incident = []): HsEvent
    {
        $root = $this->incident(['site_id' => $site->id, 'client_id' => Client::factory()->create(['site_id' => $site->id])->id, ...$incident]);
        $event = app(HsEventService::class)->recordEvent([
            'source' => $root, 'event_category' => HsEvent::CATEGORY_INCIDENT, 'severity' => HsEvent::SEVERITY_HIGH,
            'site_id' => $root->site_id, 'client_id' => $root->client_id, 'created_by' => $this->manager->id, 'occurred_at' => now()->subMinute(),
        ]);
        DB::table('client_incidents')->where('id', $root->id)->update(['hs_event_id' => $event->id]);

        return $event->fresh();
    }

    /** @return list<int> */
    private function readable(User $viewer, array $events): array
    {
        $ids = array_map(fn (HsEvent $event) => (int) $event->id, $events);

        return HealthSafetyReadContext::within($viewer, array_unique([
            ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
        ]), fn ($context) => app(HsEventAccessService::class)
            ->applyReadableScope(HsEvent::query()->whereIn('hs_events.id', $ids), $viewer, $context)
            ->orderBy('hs_events.id')->pluck('hs_events.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_the_officer_role_reads_incidents(): void
    {
        $officer = $this->officer($this->site);

        $this->assertTrue($officer->canDo('incidents.viewAny'));
        $this->assertTrue($officer->canDo('healthSafety.viewAllSites'));
        $this->assertFalse($officer->canDo('hr.cases.view'));
    }

    public function test_a_site_bound_officer_sees_incident_linked_work_only_at_their_sites(): void
    {
        $officer = $this->officer($this->site);
        // Without the H&S all-Sites grant the officer is held to their own Sites.
        $this->allow($officer, 'healthSafety.viewAllSites', false);
        $own = $this->linkedEvent($this->site);
        $elsewhere = $this->linkedEvent($this->otherSite);
        $confidential = $this->linkedEvent($this->site, ['is_hr_confidential' => true]);

        $this->assertSame([(int) $own->id], $this->readable($officer, [$own, $elsewhere, $confidential]));
    }

    public function test_the_all_sites_grant_widens_sites_but_never_the_privacy_rule(): void
    {
        // Demo-style officer: a current HR profile with no Site, relying on the role's H&S all-Sites grant.
        $officer = $this->officer(null);
        $own = $this->linkedEvent($this->site);
        $elsewhere = $this->linkedEvent($this->otherSite);
        $confidential = $this->linkedEvent($this->site, ['is_hr_confidential' => true]);

        $this->assertSame([(int) $own->id, (int) $elsewhere->id], $this->readable($officer, [$own, $elsewhere, $confidential]));
    }

    public function test_without_incident_read_the_officer_still_sees_the_event_but_not_the_incident(): void
    {
        // Stephan, 25 Oct ("event yes, incident no"): the H&S event shows at the officer's Site; the incident itself,
        // and an HR-confidential incident's event, stay behind their own rules.
        $officer = $this->officer($this->site);
        $this->allow($officer, 'incidents.viewAny', false);
        $own = $this->linkedEvent($this->site);
        $confidential = $this->linkedEvent($this->site, ['is_hr_confidential' => true]);

        $this->assertSame([(int) $own->id], $this->readable($officer, [$own, $confidential]));
        $this->assertFalse($this->incidentReadable($officer, $own));
        $this->allow($officer, 'incidents.viewAny', true);
        $this->assertTrue($this->incidentReadable($officer->fresh(), $own));
    }

    private function incidentReadable(User $viewer, HsEvent $event): bool
    {
        return HealthSafetyReadContext::within($viewer, array_unique([
            ...FirstAidAccessService::PERMISSIONS, ...RestraintAccessService::PERMISSIONS, ...InjuryAccessService::PERMISSIONS,
        ]), fn ($context): bool => $context->query($context->incidentQueryForEvent($event))->exists());
    }
}
