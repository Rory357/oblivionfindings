<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrDocument;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoomAlert;
use App\Models\HsEvent;
use App\Models\Site;
use App\Models\WorkplaceInjury;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\InjuryConnectionsService;
use App\Services\HealthSafety\InjuryWorkspaceService;
use Illuminate\Support\Facades\Storage;

/** Actual scalar service entry/picker boundaries; no temporary routes substitute for root integration. */
class InjuryWorkspaceEntryPickerTest extends InjuryWorkspaceReadTestCase
{
    public function test_capture_only_actor_has_current_capture_options_without_read_or_medical_entry(): void
    {
        $actor = $this->staff(['hazards.create']);
        $dto = app(InjuryWorkspaceService::class)->captureOptions($actor, $this->site->id);
        $this->assertTrue($dto['can']['create']);
        $this->assertTrue($dto['can']['record_reported_details']);
        $this->assertFalse($dto['can']['view_register']);
        $this->assertSame('restricted', $dto['medical_access']);
        $this->assertContains($this->worker->id, array_column($dto['workers'], 'id'));
        $this->assertSame([], $dto['incidents']);
        $this->denied(fn () => $this->detail($actor), 403);
    }

    public function test_capture_staff_picker_excludes_foreign_inactive_unapproved_and_external_people(): void
    {
        $actor = $this->staff(['hazards.create']);
        $foreign = $this->staff([], Site::factory()->create(['is_active' => true]));
        $inactive = $this->staff([]);
        $inactive->hrEmployeeProfile()->update(['is_active' => false]);
        $unapproved = $this->staff([]);
        $unapproved->update(['approved_at' => null]);
        $external = $this->staff([]);
        $external->update(['role' => 'external_clinician']);
        $dto = app(InjuryWorkspaceService::class)->captureOptions($actor, $this->site->id);
        $ids = array_column($dto['workers'], 'id');
        $this->assertContains($this->worker->id, $ids);
        foreach ([$foreign, $inactive, $unapproved, $external] as $person) {
            $this->assertNotContains($person->id, $ids);
        }
    }

    public function test_capture_wrong_site_denial_is_not_replaced_by_a_blank_picker(): void
    {
        $actor = $this->staff(['hazards.create']);
        $this->denied(fn () => app(InjuryWorkspaceService::class)->captureOptions($actor, Site::factory()->create()->id), 404);
    }

    public function test_capture_search_and_page_make_current_people_beyond_first_hundred_selectable(): void
    {
        $actor = $this->staff(['hazards.create']);
        for ($number = 0; $number < 101; $number++) {
            $person = $this->staff([]);
            $person->update(['name' => 'AA Directory '.str_pad((string) $number, 3, '0', STR_PAD_LEFT)]);
        }
        $target = $this->staff([]);
        $target->update(['name' => 'ZZZ Searchable actual worker']);
        $service = app(InjuryWorkspaceService::class);
        $first = $service->captureOptions($actor, $this->site->id);
        $this->assertCount(100, $first['workers']);
        $this->assertTrue($first['worker_picker']['has_more']);
        $this->assertSame(2, $first['worker_picker']['next_page']);
        $this->assertNotContains($target->id, array_column($first['workers'], 'id'));
        $second = $service->captureOptions($actor, $this->site->id, ['page' => 2]);
        $this->assertContains($target->id, array_column($second['workers'], 'id'));
        $searched = $service->captureOptions($actor, $this->site->id, ['search' => 'Searchable actual worker']);
        $this->assertSame([$target->id], array_column($searched['workers'], 'id'));
        $this->assertFalse($searched['worker_picker']['has_more']);
    }

    public function test_factual_assignment_options_distinguish_workers_from_source_authorised_coordinators(): void
    {
        $actor = $this->staff(['hazards.view', 'hazards.manage']);
        $coordinator = $this->staff(['hazards.view']);
        $dto = app(InjuryWorkspaceService::class)->commandOptions($actor, $this->injury->id);
        $this->assertContains($this->worker->id, array_column($dto['workers'], 'id'));
        $this->assertNotContains($this->worker->id, array_column($dto['coordinators'], 'id'));
        $this->assertContains($coordinator->id, array_column($dto['coordinators'], 'id'));
        $this->revoke($coordinator, 'hazards.view');
        $next = app(InjuryWorkspaceService::class)->commandOptions($actor, $this->injury->id);
        $this->assertNotContains($coordinator->id, array_column($next['coordinators'], 'id'));
        $this->assertSame($this->injury->id, $next['injury_id']);
        $this->assertSame($this->worker->id, $next['worker_id']);
    }

    public function test_recovery_picker_needs_both_actual_management_and_private_source_permission(): void
    {
        $service = app(InjuryWorkspaceService::class);
        $this->denied(fn () => $service->recoveryOptions($this->viewer, $this->injury->id), 403);
        $manager = $this->staff(['hazards.view', 'hazards.manage']);
        $this->denied(fn () => $service->recoveryOptions($manager, $this->injury->id), 403);
        $this->grant($manager, 'hr.wellbeing.view');
        $dto = $service->recoveryOptions($manager, $this->injury->id);
        $this->assertSame($this->injury->id, $dto['injury_id']);
        $this->assertSame('restricted', $dto['documents']['access']);
        $this->assertNull($dto['documents']['items']);
    }

    public function test_incident_connection_and_picker_require_independent_incident_and_current_client_authority(): void
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $client->id, 'title' => 'Private incident source title',
        ]));
        $this->injury->updateQuietly(['related_incident_id' => $incident->id]);
        $actor = $this->staff(['hazards.create', 'hazards.view', 'incidents.viewAny']);
        $service = app(InjuryWorkspaceService::class);
        $this->assertSame('restricted', $this->detail($actor)['connections']['incident']['access']);
        $this->assertSame([], $service->captureOptions($actor, $this->site->id)['incidents']);
        $this->grant($actor, 'clients.viewAny');
        $dto = $this->detail($actor);
        $this->assertSame($incident->id, $dto['connections']['incident']['id']);
        $this->assertSame('Private incident source title', $dto['related_incident']['title']);
        $this->assertContains($incident->id, array_column($service->captureOptions($actor, $this->site->id)['incidents'], 'id'));
        $client->update(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        // Moving the Client also splits the incident's own Site from its Client's, so the connection is
        // 'unavailable' (provenance no longer converges), never readable and with no identity.
        $moved = $this->detail($actor);
        $this->assertSame('unavailable', $moved['connections']['incident']['access']);
        $this->assertNull($moved['connections']['incident']['id']);
        $this->assertStringNotContainsString('Private incident source title', json_encode($moved));
        $this->assertSame([], $service->captureOptions($actor, $this->site->id)['incidents']);
    }

    public function test_confidential_and_foreign_incidents_never_leak_through_injury_source_identity(): void
    {
        $actor = $this->staff(['hazards.view', 'incidents.viewAny', 'clients.viewAny']);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => Client::factory()->create(['site_id' => $this->site->id])->id,
            'is_hr_confidential' => true, 'title' => 'Private HR incident title',
        ]));
        $this->injury->updateQuietly(['related_incident_id' => $incident->id]);
        $dto = $this->detail($actor);
        $this->assertSame('restricted', $dto['connections']['incident']['access']);
        $this->assertNull($dto['related_incident']);
        $this->assertStringNotContainsString('Private HR incident title', json_encode($dto));
        $incident->updateQuietly(['site_id' => Site::factory()->create(['is_active' => true])->id, 'is_hr_confidential' => false]);
        $this->assertSame('unavailable', $this->detail($actor)['connections']['incident']['access']);
    }

    public function test_health_safety_and_operational_destinations_keep_independent_grants_and_exact_source_tuple(): void
    {
        $event = HsEvent::withoutEvents(fn () => HsEvent::factory()->create(['source_type' => WorkplaceInjury::class,
            'source_id' => $this->injury->id, 'event_category' => HsEvent::CATEGORY_INJURY,
            'site_id' => $this->site->id, 'staff_id' => $this->worker->id, 'client_id' => null]));
        $alert = ControlRoomAlert::withoutEvents(fn () => ControlRoomAlert::factory()->create(['source' => 'operations',
            'alert_type' => 'operations.workplace_injury', 'site_id' => $this->site->id, 'client_id' => null,
            'context' => ['workplace_injury_id' => $this->injury->id, 'site_id' => $this->site->id, 'user_id' => $this->worker->id]]));
        $event->updateQuietly(['control_room_alert_id' => $alert->id]);
        $viewer = $this->staff(['hr.wellbeing.view']);
        $dto = $this->detail($viewer);
        $this->assertSame('restricted', $dto['connections']['health_safety']['access']);
        $this->assertSame('restricted', $dto['connections']['control_room']['access']);
        $this->assertNull($dto['connections']['health_safety']['id']);
        $this->grant($viewer, 'hazards.view');
        $this->grant($viewer, 'controlRoom.alerts.view');
        $dto = $this->detail($viewer);
        $this->assertSame($event->id, $dto['connections']['health_safety']['id']);
        $this->assertSame($alert->id, $dto['connections']['control_room']['id']);
        $this->assertSame('unconfirmed', $dto['connections']['worksafe']['external_receipt_state']);
        $alert->updateQuietly(['context' => [...$alert->context, 'user_id' => $this->staff([])->id]]);
        $dto = $this->detail($viewer);
        $this->assertSame('unavailable', $dto['connections']['control_room']['access']);
        $this->assertNull($dto['connections']['control_room']['id']);
        $this->assertSame($event->id, $dto['connections']['health_safety']['id']);
    }

    public function test_document_picker_is_independent_of_injury_medical_read_and_exact_employee_bound(): void
    {
        $document = HrDocument::factory()->create(['employee_profile_id' => $this->worker->hrEmployeeProfile->id,
            'title' => 'Exact worker clinical certificate', 'storage_disk' => 'private', 'storage_path' => 'hr/documents/actual.pdf']);
        Storage::disk('private')->put($document->storage_path, 'actual medical document');
        $other = $this->staff([]);
        $foreign = HrDocument::factory()->create(['employee_profile_id' => $other->hrEmployeeProfile->id,
            'title' => 'Other employee private certificate']);
        $access = app(InjuryAccessService::class);
        $connections = app(InjuryConnectionsService::class);
        $read = fn () => $access->withCurrentViewer($this->viewer, fn ($current, $reads, $sites) =>
            $connections->documentOptionsInContext($this->injury, $current, $reads, $sites));
        $this->assertNull($read()['items']);
        $this->grant($this->viewer, 'hr.documents.view');
        $dto = $read();
        $this->assertSame([$document->id], array_column($dto['items'], 'id'));
        $this->assertNotContains($foreign->id, array_column($dto['items'], 'id'));
        $document->update(['is_restricted' => true]);
        $this->assertSame([], $read()['items']);
        $this->grant($this->viewer, 'hr.documents.manage');
        $this->assertSame([$document->id], array_column($read()['items'], 'id'));
    }

    public function test_document_connection_cannot_recover_foreign_employee_from_a_saved_document_id(): void
    {
        $this->grant($this->viewer, 'hr.documents.view');
        $other = $this->staff([]);
        $document = HrDocument::factory()->create(['employee_profile_id' => $other->hrEmployeeProfile->id]);
        $dto = app(InjuryAccessService::class)->withCurrentViewer($this->viewer, fn ($current, $reads, $sites) =>
            app(InjuryConnectionsService::class)->documentInContext($this->injury, $document->id, $current, $reads, $sites));
        $this->assertSame('restricted', $dto['access']);
        $this->assertNull($dto['id']);
        $this->assertNull($dto['title']);
        $this->assertNull($dto['url']);
    }
    public function test_document_picker_and_connection_ignore_stale_caller_worker_tuple(): void
    {
        $this->grant($this->viewer, 'hr.documents.view');
        $other = $this->staff([]);
        $canonical = HrDocument::factory()->create(['employee_profile_id' => $this->worker->hrEmployeeProfile->id,
            'title' => 'Canonical worker document', 'storage_disk' => 'private', 'storage_path' => 'hr/documents/canonical.pdf']);
        $foreign = HrDocument::factory()->create(['employee_profile_id' => $other->hrEmployeeProfile->id,
            'title' => 'Forged caller foreign document', 'storage_disk' => 'private', 'storage_path' => 'hr/documents/foreign.pdf']);
        Storage::disk('private')->put($canonical->storage_path, 'canonical document');
        Storage::disk('private')->put($foreign->storage_path, 'foreign document');
        $stale = $this->injury->fresh();
        $stale->user_id = $other->id;
        $dto = app(InjuryAccessService::class)->withCurrentViewer($this->viewer, fn ($current, $reads, $sites) => [
            'options' => app(InjuryConnectionsService::class)->documentOptionsInContext($stale, $current, $reads, $sites),
            'foreign' => app(InjuryConnectionsService::class)->documentInContext($stale, $foreign->id, $current, $reads, $sites),
        ]);
        $this->assertSame([$canonical->id], array_column($dto['options']['items'], 'id'));
        $this->assertSame('restricted', $dto['foreign']['access']);
        $this->assertNull($dto['foreign']['id']);
        $this->assertStringNotContainsString('Forged caller foreign document', json_encode($dto));
    }

    public function test_recovery_options_use_current_canonical_injury_site_and_aggregate_revision(): void
    {
        $this->grant($this->viewer, 'hazards.manage');
        $this->injury->updateQuietly(['source_revision' => 7]);
        $otherSite = Site::factory()->create(['is_active' => true]);
        $foreign = $this->staff([], $otherSite);
        $dto = app(InjuryWorkspaceService::class)->recoveryOptions($this->viewer, $this->injury->id);
        $this->assertSame(7, $dto['expected_version']);
        $this->assertContains($this->worker->id, array_column($dto['assessors'], 'id'));
        $this->assertNotContains($foreign->id, array_column($dto['assessors'], 'id'));
        $this->assertSame('restricted', $dto['documents']['access']);
    }
    public function test_edit_picker_targets_an_approved_active_site_without_rebinding_historical_worker_or_source(): void
    {
        $manager = $this->staff(['hazards.manage']);
        $targetSite = Site::factory()->create(['is_active' => true]);
        $manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$targetSite->id]]);
        $targetWorker = $this->staff([], $targetSite);
        $targetCoordinator = $this->staff(['hazards.view'], $targetSite);
        $service = app(InjuryWorkspaceService::class);
        $default = $service->commandOptions($manager, $this->injury->id);
        $this->assertSame($this->site->id, $default['site_id']);
        $dto = $service->commandOptions($manager, $this->injury->id, ['site_id' => (string) $targetSite->id]);
        $this->assertSame($this->site->id, $dto['canonical_site_id']);
        $this->assertSame($targetSite->id, $dto['site_id']);
        $this->assertSame($this->worker->id, $dto['worker_id']);
        $this->assertEqualsCanonicalizing([$this->site->id, $targetSite->id], array_column($dto['sites'], 'id'));
        $this->assertContains($targetWorker->id, array_column($dto['workers'], 'id'));
        $this->assertNotContains($this->worker->id, array_column($dto['workers'], 'id'));
        $this->assertContains($targetCoordinator->id, array_column($dto['coordinators'], 'id'));
        $this->assertNotContains($targetWorker->id, array_column($dto['coordinators'], 'id'));
        $this->assertSame($this->site->id, $this->injury->fresh()->site_id);
        $searched = $service->commandOptions($manager, $this->injury->id, ['site_id' => $targetSite->id, 'search' => $targetWorker->name]);
        $this->assertContains($targetWorker->id, array_column($searched['workers'], 'id'));
    }

    public function test_edit_target_site_requires_current_assignment_and_activity_even_with_loaded_profile(): void
    {
        $manager = $this->staff(['hazards.manage']);
        $targetSite = Site::factory()->create(['is_active' => true]);
        $service = app(InjuryWorkspaceService::class);
        $read = fn () => $service->commandOptions($manager, $this->injury->id, ['site_id' => $targetSite->id]);
        $this->denied($read, 404);
        $manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$targetSite->id]]);
        $this->assertSame($targetSite->id, $read()['site_id']);
        $manager->load('hrEmployeeProfile');
        $manager->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $this->denied($read, 404);
        $manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$targetSite->id]]);
        $targetSite->update(['is_active' => false]);
        $this->denied($read, 404);
    }

    public function test_damaged_parent_refuses_command_picker_before_any_directory_or_private_child_query(): void
    {
        $manager = $this->staff(['hazards.manage']);
        $this->version($this->injury, 'injury');
        $this->injury->getConnection()->table('workplace_injuries')->where('id', $this->injury->id)
            ->update(['source_schema_version' => 0, 'source_revision' => 0]);
        \Illuminate\Support\Facades\DB::enableQueryLog();
        try {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            try {
                app(InjuryWorkspaceService::class)->commandOptions($manager, $this->injury->id);
                $this->fail('Unconfirmed source cannot offer command directory choices.');
            } catch (\LogicException) { $this->addToAssertionCount(1); }
            foreach (\Illuminate\Support\Facades\DB::getQueryLog() as $entry) {
                $this->assertStringNotContainsString('offset', strtolower($entry['query']));
                foreach (['return_to_work_plans', 'work_capacity_assessments', 'workplace_injury_attachments'] as $table) {
                    $this->assertStringNotContainsString($table, strtolower($entry['query']));
                }
            }
        } finally { \Illuminate\Support\Facades\DB::disableQueryLog(); }
    }
    public function test_reported_detail_capture_capability_is_current_create_authority_not_enduring_medical_read(): void
    {
        $actor = $this->staff(['hazards.create']);
        $service = app(InjuryWorkspaceService::class);
        $options = $service->captureOptions($actor, $this->site->id);
        $this->assertTrue($options['can']['record_reported_details']);
        $this->assertSame('restricted', $options['medical_access']);
        foreach (['injury', 'notes', 'immediate_treatment', 'acc_claim_number', 'history', 'attachments'] as $key) {
            $this->assertArrayNotHasKey($key, $options);
        }
        $actor->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->revoke($actor, 'hazards.create');
        $this->denied(fn () => $service->captureOptions($actor, $this->site->id), 403);
        $this->grant($actor, 'hazards.create');
        $this->assertTrue($service->captureOptions($actor, $this->site->id)['can']['record_reported_details']);
        $other = Site::factory()->create(['is_active' => true]);
        $actor->hrEmployeeProfile()->update(['primary_site_id' => $other->id, 'secondary_site_ids' => []]);
        $this->denied(fn () => $service->captureOptions($actor, $this->site->id), 404);
        $this->denied(fn () => $this->detail($actor), 403);
    }
}
