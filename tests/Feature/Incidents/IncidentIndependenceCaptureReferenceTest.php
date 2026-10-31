<?php

namespace Tests\Feature\Incidents;

use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Site;
use App\Services\Incidents\IncidentIndependenceIntakeService as Intake;
use App\Services\Incidents\IncidentRoutingSettings;
use Illuminate\Support\Facades\DB;

/** Contextual real references only; no directory grant or invented historical identity. Native execution pending. */
final class IncidentIndependenceCaptureReferenceTest extends IncidentIndependenceTestCase
{
    private function configureHeadOfficeLead(array $configuration = []): void
    {
        $headOffice = Site::factory()->create(['name' => 'Synthetic Head Office']);
        $this->lead->hrEmployeeProfile()->update(['primary_site_id' => $headOffice->id, 'secondary_site_ids' => []]);
        $this->allow($this->lead, 'healthSafety.viewAllSites');
        $this->settings($configuration ?: $this->config());
    }

    public function test_actual_audited_site_lead_at_head_office_can_be_named_and_excluded_by_capture_only_reporter(): void
    {
        $this->configureHeadOfficeLead();
        $dto = app(Intake::class)->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id);
        $this->assertSame([['id' => (int) $this->lead->id, 'name' => $this->lead->name]], $dto['candidates']);
        $this->assertSame((int) $this->reporter->id, $dto['viewer_id']);
        $this->assertFalse($this->reporter->fresh()->canDo(IncidentRoutingSettings::PERMISSION));
        $this->assertFalse($this->reporter->fresh()->canDo('incidents.owner.assign'));
        $receipt = $this->accept($this->command());
        $this->assertDatabaseHas('incident_owner_exclusions', ['incident_id' => $receipt['incident_id'], 'user_id' => $this->lead->id]);
    }

    public function test_unrelated_global_site_reader_is_not_added_to_capture_roster(): void
    {
        $this->configureHeadOfficeLead();
        $otherSite = Site::factory()->create();
        $unrelated = $this->staff(['healthSafety.viewAllSites', 'incidents.viewAny', 'incidents.owner.hold'], $otherSite);
        $dto = app(Intake::class)->captureCandidates($this->reporter, $this->context(), selectedId: (int) $unrelated->id);
        $this->assertSame([], $dto['candidates']);
        $command = $this->command(); $command['independent_handling']['involved_user_ids'] = [(int) $unrelated->id];
        $this->validation(fn () => $this->accept($command), 'independent_handling.involved_user_ids');
    }

    public function test_only_current_cover_interval_adds_the_exact_head_office_cover_reference(): void
    {
        $headOffice = Site::factory()->create();
        $this->cover->hrEmployeeProfile()->update(['primary_site_id' => $headOffice->id, 'secondary_site_ids' => []]);
        $this->allow($this->cover, 'healthSafety.viewAllSites');
        $configuration = $this->config();
        $configuration['routes'][0]['cover_user_id'] = (int) $this->cover->id;
        $configuration['routes'][0]['cover_from'] = '2026-10-12T02:00:00Z';
        $configuration['routes'][0]['cover_until'] = '2026-10-12T04:00:00Z';
        $this->configureHeadOfficeLead($configuration);
        $service = app(Intake::class);
        $this->assertCount(1, $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->cover->id)['candidates']);
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T04:00:00Z'));
        $this->assertSame([], $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->cover->id)['candidates']);
        $this->assertCount(1, $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
    }

    public function test_future_expired_and_restricted_only_rule_do_not_disclose_head_office_recipient(): void
    {
        $this->allow($this->lead, 'incidents.owner.holdRestricted');
        $configuration = $this->config(); $configuration['routes'][0]['effective_from'] = '2026-10-13T00:00:00Z';
        $this->configureHeadOfficeLead($configuration);
        $service = app(Intake::class);
        $this->assertSame([], $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
        $configuration = $this->config(); $configuration['routes'][0]['effective_until'] = '2026-10-12T01:00:00Z';
        $this->settings($configuration, 1);
        $this->assertSame([], $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
        $configuration = $this->config(); $configuration['routes'][0]['scope'] = 'restricted';
        $this->settings($configuration, 2);
        $this->assertSame([], $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
    }

    public function test_damaged_live_routing_cannot_certify_a_capture_reference(): void
    {
        $this->configureHeadOfficeLead();
        $setting = AppSetting::where('key', IncidentRoutingSettings::KEY)->sole();
        $changed = $setting->value; $changed['routes'][0]['effective_from'] = '2026-10-11T00:00:00.000000Z';
        $setting->value = $changed; $setting->saveOrFail();
        $this->assertSame([], app(Intake::class)->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
        $this->validation(fn () => $this->accept($this->command()), 'independent_handling.involved_user_ids');
    }

    public function test_current_global_scope_and_staff_withdrawal_remove_configured_reference_and_restore_it(): void
    {
        $this->configureHeadOfficeLead();
        $this->lead->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        $service = app(Intake::class);
        $this->allow($this->lead, 'healthSafety.viewAllSites', false);
        $this->assertSame([], $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
        $this->allow($this->lead, 'healthSafety.viewAllSites');
        $this->assertCount(1, $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
        $this->lead->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame([], $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
        $this->lead->hrEmployeeProfile()->update(['is_active' => true]);
        $this->assertCount(1, $service->captureCandidates($this->reporter, $this->context(), selectedId: (int) $this->lead->id)['candidates']);
    }

    public function test_unapproved_capture_context_is_denied_before_routing_configuration_or_roster_materialisation(): void
    {
        $this->configureHeadOfficeLead();
        $other = Site::factory()->create(); $client = Client::factory()->create(['site_id' => $other->id]);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->denial(fn () => app(Intake::class)->captureCandidates($this->reporter, ['client_id' => (int) $client->id, 'site_id' => (int) $other->id, 'shift_id' => null]), 404);
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('app_settings', strtolower($query['query']));
            $this->assertStringNotContainsString('health_safety_setting_changes', strtolower($query['query']));
        }
    }
}
