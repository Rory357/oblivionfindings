<?php

namespace Tests\Feature\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Permission;
use App\Models\ServiceAgreement;
use App\Models\ServiceAgreementStatusChange;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class ServiceAgreementLifecycleAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_agreement_transitions_atomically(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $manager = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        $agreement = ServiceAgreement::create([
            'client_id' => $client->id,
            'title' => 'Residential Support Agreement',
            'agreement_type' => 'ndis',
            'status' => 'draft',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'created_by' => $manager->id,
        ]);
        $this->actingAs($manager)->post(route('operations.service_agreements.submit_for_approval', $agreement))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($approver)->post(route('operations.service_agreements.approve', $agreement), ['notes' => 'Independent review complete'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $approvalFields = ['submitted_for_approval_at', 'submitted_for_approval_by', 'approved_at', 'approved_by'];
        $approvalBefore = Arr::only($agreement->fresh()->getRawOriginal(), $approvalFields);
        $this->assertNotSame($manager->id, $approver->id);
        $this->assertSame('active', $agreement->fresh()->status);

        $this->actingAs($manager)
            ->post(route('operations.service_agreements.transition', $agreement), [
                'status' => 'suspended', 'reason' => 'Support paused for review',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();
        $agreement->refresh();
        $this->assertSame('suspended', $agreement->status);
        $this->assertNotNull($agreement->suspended_at);
        $this->assertSame('Support paused for review', $agreement->suspended_reason);
        $this->assertSame($approver->id, (int) $agreement->approved_by);
        $this->assertSame(3, $agreement->statusChanges()->count());
        $this->assertDatabaseHas('service_agreement_status_changes', [
            'service_agreement_id' => $agreement->id, 'from_status' => 'active', 'to_status' => 'suspended',
            'changed_by' => $manager->id, 'reason' => 'Support paused for review',
        ]);

        $this->actingAs($manager)
            ->post(route('operations.service_agreements.transition', $agreement), [
                'status' => 'active', 'reason' => 'Approved support can resume',
            ])
            ->assertRedirect()->assertSessionHasNoErrors();
        $agreement->refresh();
        $this->assertSame('active', $agreement->status);
        $this->assertNotNull($agreement->resumed_at);
        $this->assertSame($approvalBefore, Arr::only($agreement->getRawOriginal(), $approvalFields));
        $this->assertSame(4, $agreement->statusChanges()->count());
        $this->assertDatabaseHas('service_agreement_status_changes', [
            'service_agreement_id' => $agreement->id, 'from_status' => 'suspended', 'to_status' => 'active',
            'changed_by' => $manager->id, 'reason' => 'Approved support can resume',
        ]);
    }

    public function test_submit_for_approval_and_approve_are_atomic(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $manager = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        $agreement = ServiceAgreement::create([
            'client_id' => $client->id,
            'title' => 'Day Program Agreement',
            'agreement_type' => 'ndis',
            'status' => 'draft',
            'starts_at' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);

        $this->actingAs($manager)
            ->post("/operations/service-agreements/{$agreement->id}/submit-for-approval")
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $agreement->refresh();
        $this->assertSame('pending_approval', $agreement->status);
        $this->assertNotNull($agreement->submitted_for_approval_at);
        $this->assertSame($manager->id, (int) $agreement->submitted_for_approval_by);
        $this->assertSame(1, $agreement->statusChanges()->count());
        $before = $agreement->getRawOriginal();
        $historyBefore = $agreement->statusChanges()->get()->map->getRawOriginal()->all();
        $auditBefore = AuditLog::query()->count();
        $this->actingAs($manager)
            ->post("/operations/service-agreements/{$agreement->id}/approve", ['notes' => 'Unaccepted self review'])
            ->assertForbidden();
        $this->assertSame($before, $agreement->fresh()->getRawOriginal());
        $this->assertSame($historyBefore, $agreement->statusChanges()->get()->map->getRawOriginal()->all());
        $this->assertSame($auditBefore, AuditLog::query()->count());

        $this->actingAs($approver)
            ->post("/operations/service-agreements/{$agreement->id}/approve", [
                'notes' => 'Executive review complete',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $agreement->refresh();
        $this->assertSame('active', $agreement->status);
        $this->assertNotNull($agreement->approved_at);
        $this->assertSame($approver->id, (int) $agreement->approved_by);
        $this->assertSame($manager->id, (int) $agreement->submitted_for_approval_by);
        $this->assertSame(2, $agreement->statusChanges()->count());
        $this->assertDatabaseHas('service_agreement_status_changes', [
            'service_agreement_id' => $agreement->id,
            'from_status' => 'pending_approval',
            'to_status' => 'active',
            'changed_by' => $approver->id,
            'notes' => 'Executive review complete',
        ]);
    }

    public function test_draft_activation_is_rejected_without_status_or_history_changes(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $manager = $this->managerAtSite($site);
        $agreement = ServiceAgreement::create([
            'client_id' => $client->id,
            'title' => 'Unapproved Support Agreement',
            'agreement_type' => 'ndis',
            'status' => 'draft',
            'starts_at' => now()->toDateString(),
            'created_by' => $manager->id,
        ]);
        $before = $this->lifecycleSnapshot($agreement);
        $this->assertSame(0, $agreement->statusChanges()->count());

        $this->actingAs($manager)
            ->post("/operations/service-agreements/{$agreement->id}/transition", [
                'status' => 'active',
                'reason' => 'Client signed agreement',
            ])
            ->assertUnprocessable();

        $this->assertSame($before, $this->lifecycleSnapshot($agreement));
        $this->assertSame(0, $agreement->statusChanges()->count());
    }

    public function test_pending_activation_must_use_independent_approval(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $submitter = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        $agreement = ServiceAgreement::create([
            'client_id' => $client->id, 'title' => 'Pending Support Agreement', 'agreement_type' => 'ndis',
            'status' => 'draft', 'starts_at' => now()->toDateString(), 'created_by' => $submitter->id,
        ]);
        $this->actingAs($submitter)->post(route('operations.service_agreements.submit_for_approval', $agreement))
            ->assertRedirect()->assertSessionHasNoErrors();
        $before = $this->lifecycleSnapshot($agreement);
        foreach ([$submitter, $approver] as $actor) {
            $this->actingAs($actor)->post(route('operations.service_agreements.transition', $agreement), [
                'status' => 'active', 'reason' => 'Generic status change cannot approve',
            ])->assertUnprocessable();
            $this->assertSame($before, $this->lifecycleSnapshot($agreement));
        }
        $this->actingAs($approver)->post(route('operations.service_agreements.approve', $agreement))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('active', $agreement->fresh()->status);
        $this->assertSame($approver->id, (int) $agreement->fresh()->approved_by);
    }

    public function test_unapproved_paused_states_cannot_manufacture_activation(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $submitter = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        foreach (['suspended', 'under_review'] as $state) {
            $agreement = ServiceAgreement::create([
                'client_id' => $client->id, 'title' => 'Unapproved '.$state, 'agreement_type' => 'ndis',
                'status' => 'draft', 'starts_at' => now()->toDateString(), 'created_by' => $submitter->id,
            ]);
            $this->actingAs($submitter)->post(route('operations.service_agreements.transition', $agreement), ['status' => $state])
                ->assertRedirect()->assertSessionHasNoErrors();
            $before = $this->lifecycleSnapshot($agreement);
            foreach ([$submitter, $approver] as $actor) {
                $this->actingAs($actor)->post(route('operations.service_agreements.transition', $agreement), ['status' => 'active'])
                    ->assertUnprocessable();
                $this->assertSame($before, $this->lifecycleSnapshot($agreement));
            }
        }
    }

    public function test_generic_pending_status_cannot_manufacture_submission_authority(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $submitter = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        $agreement = ServiceAgreement::create([
            'client_id' => $client->id, 'title' => 'Unsubmitted Agreement', 'agreement_type' => 'ndis',
            'status' => 'draft', 'starts_at' => now()->toDateString(), 'created_by' => $submitter->id,
        ]);
        $this->actingAs($submitter)->post(route('operations.service_agreements.transition', $agreement), ['status' => 'pending_approval'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($agreement->fresh()->submitted_for_approval_by);
        $this->assertNull($agreement->fresh()->submitted_for_approval_at);
        $before = $this->lifecycleSnapshot($agreement);
        foreach ([$submitter, $approver] as $actor) {
            $this->actingAs($actor)->post(route('operations.service_agreements.approve', $agreement))
                ->assertUnprocessable();
            $this->assertSame($before, $this->lifecycleSnapshot($agreement));
        }
    }

    public function test_invalid_retained_approval_does_not_authorise_resume(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $submitter = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        foreach (['self_approved', 'approval_precedes_submission'] as $invalid) {
            $agreement = ServiceAgreement::create([
                'client_id' => $client->id, 'title' => 'Invalid retained '.$invalid, 'agreement_type' => 'ndis',
                'status' => 'suspended', 'starts_at' => now()->toDateString(), 'created_by' => $submitter->id,
                'submitted_for_approval_by' => $submitter->id, 'submitted_for_approval_at' => now()->subDay(),
                'approved_by' => $invalid === 'self_approved' ? $submitter->id : $approver->id,
                'approved_at' => $invalid === 'self_approved' ? now()->subHour() : now()->subDays(2),
            ]);
            $before = $this->lifecycleSnapshot($agreement);
            $this->actingAs($approver)->post(route('operations.service_agreements.transition', $agreement), ['status' => 'active'])
                ->assertUnprocessable();
            $this->assertSame($before, $this->lifecycleSnapshot($agreement));
        }
    }

    public function test_create_cannot_manufacture_active_status_or_approval(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $creator = $this->managerAtSite($site, ['service_agreements.create']);
        $before = [
            ServiceAgreement::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
            ServiceAgreementStatusChange::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
            AuditLog::query()->count(),
        ];
        $data = [
            'client_id' => $client->id, 'title' => 'New Agreement', 'agreement_type' => 'ndis',
            'status' => 'active', 'approved_by' => $creator->id, 'approved_at' => now()->toIso8601String(),
        ];
        $this->actingAs($creator)->postJson(route('operations.service_agreements.store'), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame($before, [
            ServiceAgreement::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
            ServiceAgreementStatusChange::query()->orderBy('id')->get()->map->getRawOriginal()->all(),
            AuditLog::query()->count(),
        ]);

        unset($data['status']);
        $this->actingAs($creator)->post(route('operations.service_agreements.store'), $data)
            ->assertRedirect()->assertSessionHasNoErrors();
        $agreement = ServiceAgreement::query()->where('title', 'New Agreement')->sole();
        $this->assertSame('draft', $agreement->status);
        $this->assertSame($creator->id, (int) $agreement->created_by);
        $this->assertNull($agreement->submitted_for_approval_by);
        $this->assertNull($agreement->submitted_for_approval_at);
        $this->assertNull($agreement->approved_by);
        $this->assertNull($agreement->approved_at);
        $this->assertSame(0, $agreement->statusChanges()->count());
    }

    public function test_ordinary_updates_cannot_activate_unapproved_or_paused_agreements(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $submitter = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        foreach (['draft', 'pending_approval', 'suspended', 'under_review'] as $state) {
            $agreement = ServiceAgreement::create([
                'client_id' => $client->id, 'title' => 'Retained '.$state, 'agreement_type' => 'ndis',
                'status' => 'draft', 'starts_at' => now()->toDateString(), 'created_by' => $submitter->id,
            ]);
            if ($state !== 'draft') {
                $this->actingAs($submitter)->post(route('operations.service_agreements.submit_for_approval', $agreement))
                    ->assertRedirect()->assertSessionHasNoErrors();
            }
            if (in_array($state, ['suspended', 'under_review'], true)) {
                $this->actingAs($approver)->post(route('operations.service_agreements.approve', $agreement))
                    ->assertRedirect()->assertSessionHasNoErrors();
                $this->actingAs($submitter)->post(route('operations.service_agreements.transition', $agreement), ['status' => $state])
                    ->assertRedirect()->assertSessionHasNoErrors();
                $this->assertSame($approver->id, (int) $agreement->fresh()->approved_by);
            }
            $this->assertSame($state, $agreement->fresh()->status);
            $before = $this->lifecycleSnapshot($agreement);
            foreach ([$submitter, $approver] as $actor) {
                $this->actingAs($actor)->put(route('operations.service_agreements.update', $agreement), [
                    'status' => 'active', 'title' => 'Unaccepted ordinary activation',
                    'submitted_for_approval_by' => $submitter->id, 'submitted_for_approval_at' => now()->subDay()->toIso8601String(),
                    'approved_by' => $approver->id, 'approved_at' => now()->toIso8601String(),
                ])->assertUnprocessable();
                $this->assertSame($before, $this->lifecycleSnapshot($agreement));
            }
        }
    }

    public function test_approved_active_agreement_remains_editable_without_changing_approval_or_history(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $submitter = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        $agreement = ServiceAgreement::create([
            'client_id' => $client->id, 'title' => 'Approved Agreement', 'agreement_type' => 'ndis',
            'status' => 'draft', 'starts_at' => now()->toDateString(), 'created_by' => $submitter->id,
        ]);
        $this->actingAs($submitter)->post(route('operations.service_agreements.submit_for_approval', $agreement))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($approver)->post(route('operations.service_agreements.approve', $agreement))
            ->assertRedirect()->assertSessionHasNoErrors();
        $proofFields = ['submitted_for_approval_by', 'submitted_for_approval_at', 'approved_by', 'approved_at'];
        $proof = Arr::only($agreement->fresh()->getRawOriginal(), $proofFields);
        $history = $agreement->statusChanges()->orderBy('id')->get()->map->getRawOriginal()->all();

        $this->actingAs($submitter)->put(route('operations.service_agreements.update', $agreement), [
            'status' => 'active', 'title' => 'Updated Approved Agreement',
            'approved_by' => $submitter->id, 'approved_at' => now()->subYear()->toIso8601String(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Updated Approved Agreement', $agreement->fresh()->title);
        $this->actingAs($approver)->put(route('operations.service_agreements.update', $agreement), [
            'notes' => 'Contact details reviewed',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $agreement->refresh();
        $this->assertSame('active', $agreement->status);
        $this->assertSame('Contact details reviewed', $agreement->notes);
        $this->assertSame($proof, Arr::only($agreement->getRawOriginal(), $proofFields));
        $this->assertSame($history, $agreement->statusChanges()->orderBy('id')->get()->map->getRawOriginal()->all());
    }

    public function test_valid_older_retained_independent_approval_still_authorises_resume(): void
    {
        $site = Site::factory()->create();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $submitter = $this->managerAtSite($site);
        $approver = $this->managerAtSite($site);
        foreach (['suspended', 'under_review'] as $state) {
            $agreement = ServiceAgreement::create([
                'client_id' => $client->id, 'title' => 'Older Approved '.$state, 'agreement_type' => 'ndis',
                'status' => $state, 'created_by' => $submitter->id,
                'submitted_for_approval_by' => $submitter->id, 'submitted_for_approval_at' => now()->subMonths(6),
                'approved_by' => $approver->id, 'approved_at' => now()->subMonths(5),
            ]);
            $proofFields = ['submitted_for_approval_by', 'submitted_for_approval_at', 'approved_by', 'approved_at'];
            $proof = Arr::only($agreement->fresh()->getRawOriginal(), $proofFields);
            $this->actingAs($submitter)->post(route('operations.service_agreements.transition', $agreement), ['status' => 'active'])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame('active', $agreement->fresh()->status);
            $this->assertSame($proof, Arr::only($agreement->fresh()->getRawOriginal(), $proofFields));
            $this->assertSame(1, $agreement->statusChanges()->count());
            $this->assertDatabaseHas('service_agreement_status_changes', [
                'service_agreement_id' => $agreement->id, 'from_status' => $state, 'to_status' => 'active',
                'changed_by' => $submitter->id,
            ]);
        }
    }

    private function lifecycleSnapshot(ServiceAgreement $agreement): array
    {
        return [
            $agreement->fresh()->getRawOriginal(),
            $agreement->statusChanges()->orderBy('id')->get()->map->getRawOriginal()->all(),
            AuditLog::query()->count(),
        ];
    }

    private function managerAtSite(Site $site, array $permissionKeys = ['service_agreements.update']): User
    {
        $manager = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'employee_number' => 'EMP-'.$manager->id,
            'work_email' => $manager->email,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => now('Pacific/Auckland')->subYear()->toDateString(),
            'end_date' => null,
            'is_active' => true,
        ]);
        foreach ($permissionKeys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'Operations', 'module' => 'Operations']);
            $manager->permissionOverrides()->attach($permission, ['allowed' => true]);
        }

        return $manager;
    }
}
