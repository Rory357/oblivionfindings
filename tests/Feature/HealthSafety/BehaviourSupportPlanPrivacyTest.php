<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\Permission;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Models\RestraintEvent;
use App\Models\Site;

final class BehaviourSupportPlanPrivacyTest extends BehaviourSupportPlanTestCase
{
    public function test_settings_grant_or_site_bypass_never_supplies_confidential_client_read(): void
    {
        $plan = $this->makePlan();
        $settingsOnly = $this->staff(['healthSafety.settings.manage', 'healthSafety.viewAllSites', 'restraints.manage']);
        $this->denied(fn () => $this->plans()->update($plan, $settingsOnly, $this->command($plan, ['notes' => 'Denied'])));
        $foreign = Client::factory()->for($this->site)->create();
        $assigned = $this->staff(['restraints.manage', 'clients.viewAssigned', 'healthSafety.viewAllSites']);
        $foreign->supportWorkers()->attach($assigned->id);
        $this->denied(fn () => $this->plans()->update($plan, $assigned, $this->command($plan, ['notes' => 'Denied private Client'])));
    }

    public function test_source_reader_and_review_workspace_are_separate_capabilities(): void
    {
        $plan = $this->makePlan();
        $can = $this->access()->commandCan($plan, $this->planReviewer);
        $this->assertFalse($can['view']);
        $this->assertTrue($can['workspace']);
        $this->assertTrue($can['review']);
        $this->assertFalse($can['edit']);
        $this->assertFalse($can['approve']);
        $this->assertFalse($can['activate']);
    }

    public function test_original_and_current_site_authority_are_both_required_after_client_moves(): void
    {
        $plan = $this->makePlan();
        $otherSite = Site::factory()->create();
        $this->client->update(['site_id' => $otherSite->id]);
        $this->denied(fn () => $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Cannot move history'])));
        $profile = $this->planManager->hrEmployeeProfile()->firstOrFail();
        $profile->update(['secondary_site_ids' => [$otherSite->id]]);
        $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Both Sites currently authorised']));
        $this->assertSame($this->site->id, $plan->fresh()->source_site_id);
        $this->assertSame($this->site->id, $plan->fresh()->candidateVersion->site_id);
        $profile->update(['primary_site_id' => $otherSite->id, 'secondary_site_ids' => []]);
        $this->denied(fn () => $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Original Site revoked'])));
    }

    public function test_inactive_unapproved_future_ended_and_external_identities_deny_source_commands(): void
    {
        $plan = $this->makePlan();
        foreach ([
            ['user' => ['approved_at' => null], 'hr' => []],
            ['user' => [], 'hr' => ['is_active' => false]],
            ['user' => [], 'hr' => ['start_date' => '2026-12-01']],
            ['user' => [], 'hr' => ['end_date' => '2026-10-01']],
            ['user' => ['external_clinical_account' => true], 'hr' => []],
            ['user' => ['role' => 'client'], 'hr' => []],
        ] as $case) {
            $actor = $this->staff(['restraints.manage', 'clients.viewAny'], $case['user']);
            $actor->hrEmployeeProfile()->update($case['hr']);
            $this->denied(fn () => $this->plans()->update($plan, $actor, $this->command($plan, ['title' => 'Denied identity'])));
        }
    }

    public function test_site_archive_and_loaded_permission_revocation_are_fresh(): void
    {
        $plan = $this->makePlan();
        $this->planManager->load('roles.permissions', 'permissionOverrides');
        $permission = Permission::where('key', 'clients.viewAny')->sole();
        $this->planManager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => false]);
        $this->denied(fn () => $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Denied'])));
        $this->planManager->permissionOverrides()->updateExistingPivot($permission->id, ['allowed' => true]);
        $this->site->update(['archived_at' => now()]);
        $this->denied(fn () => $this->plans()->update($plan, $this->planManager, $this->command($plan, ['notes' => 'Archived Site'])));
    }

    public function test_schema_one_recorded_event_tuple_tampering_is_not_accepted_as_a_different_source(): void
    {
        $event = RestraintEvent::factory()->for($this->client)->for($this->site)->create(['source_schema_version' => 1, 'recorded_client_id' => $this->client->id, 'recorded_site_id' => $this->site->id, 'recorded_stay_id' => null]);
        $viewer = $this->staff(['restraints.view', 'clients.viewAny']);
        $this->access()->withCurrentViewer($viewer, fn ($current, $reads, $sites) => $this->access()->assertEvent($event, $current, $reads, $sites));
        $event->recorded_client_id = $this->client->id + 1;
        $this->denied(fn () => $this->access()->withCurrentViewer($viewer, fn ($current, $reads, $sites) => $this->access()->assertEvent($event, $current, $reads, $sites)));
    }

    public function test_exact_stay_booking_client_and_effective_site_are_checked_before_plan_connection(): void
    {
        $other = Client::factory()->for($this->site)->create();
        $booking = RespiteBooking::factory()->create(['client_id' => $other->id, 'location_id' => $this->site->id]);
        $stay = RespiteStay::factory()->create(['client_id' => $other->id, 'booking_id' => $booking->id]);
        $this->denied(fn () => $this->access()->withCurrentViewer($this->planManager, fn ($current, $reads, $sites) => $this->access()->assertStay($stay->id, $this->client->id, $this->site->id, $current, $reads, $sites)));
    }

    public function test_designated_signoff_workspace_is_not_an_ordinary_plan_read_grant(): void
    {
        $plan = $this->makePlan();
        $can = $this->access()->commandCan($plan, $this->planSpecialist);
        $this->assertTrue($can['approve']);
        $this->assertFalse($can['view']);
        $this->assertFalse($can['workspace']);
        $this->access()->withCurrentViewer($this->planSpecialist, function ($current, $reads, $sites) use ($plan): void {
            $this->access()->assertPlan($plan->fresh(), $current, $reads, $sites, 'approve');
            $this->access()->assertVersion($plan->fresh()->candidateVersion, $plan->fresh(), $current, $reads, $sites);
        });
        $this->denied(fn () => $this->access()->withCurrentViewer($this->planSpecialist, fn ($current, $reads, $sites) => $this->access()->assertPlan($plan->fresh(), $current, $reads, $sites, 'view')));
    }
}
