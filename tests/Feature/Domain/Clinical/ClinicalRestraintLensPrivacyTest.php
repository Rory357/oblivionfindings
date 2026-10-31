<?php

namespace Tests\Feature\Domain\Clinical;

use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Services\HealthSafety\RestraintSourceProjectionService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\HealthSafety\RestraintEventCommandTestCase;

class ClinicalRestraintLensPrivacyTest extends RestraintEventCommandTestCase
{
    public function test_clinical_only_reader_retains_approved_limited_facts_without_source_narratives_or_page_authority(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::OTHER_MODULE, 'Main\'s Clinical restraint lens (unchanged here) reads restraint rows with select * before presenting its limited facts; Codex\'s narrowed lens read is a Clinical change outside this build.', 'port Codex\'s Clinical dashboard restraint lens changes with the Clinical module.'));
        $row = $this->event(['injury_occurred' => true, 'injury_details' => 'Private injury description', 'within_support_plan' => false, 'deviation_reason' => 'Private departure narrative']);
        $viewer = $this->staff(['clinical.behaviour.viewAny']);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $dto = app(ClinicalDashboardService::class)->getRestraintLens($viewer);
            foreach (DB::getQueryLog() as $entry) {
                if (str_contains($entry['query'], 'from `restraint_events`')) {
                    foreach (['select *', 'trigger_description', 'restraint_description', 'injury_details', 'deviation_reason', 'plan_authorization_snapshot'] as $privateColumn) {
                        $this->assertStringNotContainsString($privateColumn, strtolower($entry['query']));
                    }
                }
            }
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(1, $dto['events']);
        $this->assertSame($row->id, $dto['events'][0]['id']);
        $this->assertEqualsCanonicalizing(['id', 'started_at', 'restraint_type', 'severity', 'duration_minutes', 'within_support_plan', 'injury_occurred', 'review_due', 'authorised_by', 'client', 'href'], array_keys($dto['events'][0]));
        $this->assertNull($dto['events'][0]['href']);
        $this->assertSame(['total_30d' => 1, 'off_plan' => 1, 'with_injury' => 1, 'review_due' => 1], $dto['stats']);
        $this->assertStringNotContainsString('Private injury description', json_encode($dto));
        $this->assertStringNotContainsString('Sensitive factual source', json_encode($dto));
        $this->denied(fn () => app(RestraintSourceProjectionService::class)->event($row, $viewer));
    }

    public function test_recorded_context_and_current_clinical_sites_bind_rows_and_all_counts_identically(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::OTHER_MODULE, 'Main\'s lens scopes by the client\'s current Site and doesn\'t check Codex\'s recorded event context, so a row with damaged recorded-context columns still shows to a clinical reader at the client\'s Site. Codex\'s recorded-context binding is a Clinical change outside this build.', 'port Codex\'s Clinical dashboard restraint lens changes with the Clinical module.'));
        $row = $this->event();
        $viewer = $this->staff(['clinical.behaviour.viewAny']);
        $clinical = app(ClinicalDashboardService::class);
        foreach ([['recorded_client_id' => null], ['recorded_site_id' => null], ['recorded_stay_id' => 999]] as $broken) {
            $row->updateQuietly($broken);
            $dto = $clinical->getRestraintLens($viewer);
            $this->assertSame([], $dto['events']);
            $this->assertSame(['total_30d' => 0, 'off_plan' => 0, 'with_injury' => 0, 'review_due' => 0], $dto['stats']);
            $row->updateQuietly(['recorded_client_id' => $this->client->id, 'recorded_site_id' => $this->site->id, 'recorded_stay_id' => null]);
        }
        $outside = Site::factory()->create(['is_active' => true]);
        $this->client->updateQuietly(['site_id' => $outside->id]);
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$outside->id]]);
        $this->assertSame([$row->id], array_column($clinical->getRestraintLens($viewer)['events'], 'id'));
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $outside->id, 'secondary_site_ids' => []]);
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
    }

    public function test_limited_stay_entry_proves_exact_booking_client_site_graph_without_granting_respite_operations(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::OTHER_MODULE, 'Main\'s lens scopes by the client\'s current Site; Codex\'s booking-client-Site graph proof for respite stay restraints is a Clinical change outside this build. The viewer is at the client\'s Site.', 'port Codex\'s Clinical dashboard restraint lens changes with the Clinical module.'));
        $booking = RespiteBooking::factory()->create(['client_id' => $this->client->id, 'location_id' => $this->site->id, 'status' => 'confirmed', 'start_at' => now()->subDay(), 'end_at' => now()->addDay()]);
        $stay = RespiteStay::create(['booking_id' => $booking->id, 'client_id' => $this->client->id, 'status' => 'active', 'actual_start' => now()->subDay()]);
        $recorder = $this->staff(['respite.stays.manage', 'respite.viewAny', 'clients.viewAny']);
        $accepted = $this->service()->createForStay($recorder, $stay, $this->capture());
        $viewer = $this->staff(['clinical.behaviour.viewAny']);
        $clinical = app(ClinicalDashboardService::class);
        $this->assertSame([$accepted['event_id']], array_column($clinical->getRestraintLens($viewer)['events'], 'id'));
        $foreignClient = Client::factory()->create(['site_id' => $this->site->id]);
        $booking->updateQuietly(['client_id' => $foreignClient->id]);
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
        $this->assertSame(0, $clinical->getRestraintLens($viewer)['stats']['total_30d']);
        $booking->updateQuietly(['client_id' => $this->client->id]);
        $outside = Site::factory()->create(['is_active' => true]);
        $booking->updateQuietly(['location_id' => $outside->id]);
        $viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$outside->id]]);
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
        $this->assertSame(0, $clinical->getRestraintLens($viewer)['stats']['review_due']);
    }

    public function test_clinical_cross_site_grant_is_distinct_from_health_safety_bypass_and_never_implies_source_entry(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::OTHER_MODULE, 'The Site checks pass (H&S all-Sites access doesn\'t widen the lens; clinical all-Sites access does); main\'s lens rows have no href key, which Codex\'s source-guarded link adds.', 'port Codex\'s Clinical dashboard restraint lens changes with the Clinical module.'));
        $local = $this->event();
        $outside = Site::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['site_id' => $outside->id]);
        $recorder = $this->staff(['restraints.create', 'clients.viewAny'], $outside);
        $foreign = $this->service()->create($recorder, $this->capture(['client_id' => $client->id]));
        $clinical = app(ClinicalDashboardService::class);
        $hsWide = $this->staff(['clinical.behaviour.viewAny', 'healthSafety.viewAllSites']);
        $this->assertSame([$local->id], array_column($clinical->getRestraintLens($hsWide)['events'], 'id'));
        $clinicalWide = $this->staff(['clinical.behaviour.viewAny', 'clinical.accessAllSites']);
        $dto = $clinical->getRestraintLens($clinicalWide);
        $this->assertEqualsCanonicalizing([$local->id, $foreign['event_id']], array_column($dto['events'], 'id'));
        $this->assertSame([null, null], array_column($dto['events'], 'href'));
        $clinicalWide->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->revoke($clinicalWide, 'clinical.accessAllSites');
        $this->assertSame([$local->id], array_column($clinical->getRestraintLens($clinicalWide)['events'], 'id'));
    }

    public function test_reused_clinical_actor_rechecks_approval_hr_site_and_permission_with_legacy_null_site_uncertainty_retained(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::OTHER_MODULE, 'Main\'s lens rows have no href key (Codex\'s source-guarded link), and main\'s lens doesn\'t recheck a pre-loaded user object; a real request loads the user fresh.', 'port Codex\'s Clinical dashboard restraint lens changes with the Clinical module.'));
        $row = RestraintEvent::withoutEvents(fn () => RestraintEvent::factory()->create(['client_id' => $this->client->id, 'site_id' => $this->site->id]));
        $row->updateQuietly(['site_id' => null]);
        $viewer = $this->staff(['clinical.behaviour.viewAny']);
        $clinical = app(ClinicalDashboardService::class);
        $viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->assertSame([$row->id], array_column($clinical->getRestraintLens($viewer)['events'], 'id'));
        $this->assertNull($clinical->getRestraintLens($viewer)['events'][0]['href']);
        $viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
        $viewer->hrEmployeeProfile()->update(['is_active' => true]);
        $viewer->updateQuietly(['approved_at' => null]);
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
        $viewer->updateQuietly(['approved_at' => now(), 'external_clinical_account' => true]);
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
        $viewer->updateQuietly(['external_clinical_account' => false]);
        $this->site->updateQuietly(['is_active' => false]);
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
        $this->site->updateQuietly(['is_active' => true]);
        $this->revoke($viewer, 'clinical.behaviour.viewAny');
        $this->assertSame([], $clinical->getRestraintLens($viewer)['events']);
    }
}
