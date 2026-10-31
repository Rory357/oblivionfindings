<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Support\HealthSafety\FirstAidPresenter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstAidHistoryPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house']);
        $this->viewer = User::factory()->frontlineWorker()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->viewer->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach (['hazards.view' => true, 'hazards.manage' => true, 'healthSafety.viewAllSites' => false, 'clients.viewAny' => false, 'clients.viewAssigned' => true] as $key => $allowed) {
            $this->viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => $allowed]]);
        }
    }

    public function test_known_denied_old_client_snapshot_omits_every_narrative_child_label_and_entry(): void
    {
        $current = $this->client(true);
        $old = $this->client(false);
        $record = $this->record($current);
        $audit = $this->audit($record, ['site_id' => $this->site->id, 'treated_person_type' => 'client', 'client_id' => $old->id, 'treated_person_id' => null,
            'treated_person_name' => 'Hidden former person', 'injury_illness_description' => 'Hidden former injury', 'treatment_given' => 'Hidden former treatment', 'first_aider_notes' => 'Hidden former notes'],
            ['note' => 'Hidden former correction', 'names' => ['Hidden former evidence.pdf'], 'followup_snapshot' => ['notes' => 'Hidden former followup']]);
        $detail = app(FirstAidPresenter::class)->detail($record, $this->viewer);
        $this->assertSame($current->id, $detail['client_id']);
        $this->assertNull(collect($detail['history'])->firstWhere('id', 'audit-'.$audit->id));
        $this->assertStringNotContainsString('Hidden former', json_encode($detail));
        $this->assertArrayNotHasKey('hidden_history_count', $detail);
    }

    public function test_known_denied_old_site_is_not_reauthorized_through_the_current_site_tuple(): void
    {
        $record = $this->record();
        $foreign = Site::factory()->create();
        $audit = $this->audit($record, ['site_id' => $foreign->id, 'treated_person_type' => 'staff', 'client_id' => null, 'treated_person_id' => null,
            'treatment_given' => 'Hidden former Site treatment'], ['note' => 'Hidden former Site notes']);
        $history = app(FirstAidPresenter::class)->detail($record, $this->viewer)['history'];
        $this->assertNull(collect($history)->firstWhere('id', 'audit-'.$audit->id));
        $this->assertStringNotContainsString('Hidden former Site', json_encode($history));
    }

    public function test_incomplete_source_snapshot_exposes_no_clinical_note_or_evidence_from_current_source_fallback(): void
    {
        $record = $this->record();
        $audit = $this->audit($record, ['treatment_given' => 'Unproven historical treatment', 'treated_person_name' => 'Unproven historical person'],
            ['note' => 'Unproven historical notes', 'names' => ['Unproven historical evidence.pdf']]);
        $detail = app(FirstAidPresenter::class)->detail($record, $this->viewer);
        $history = collect($detail['history'])->firstWhere('id', 'audit-'.$audit->id);
        $this->assertTrue($history['legacy']);
        $this->assertNull($history['note']);
        $this->assertSame([], $history['snapshot']);
        $this->assertStringNotContainsString('Unproven historical', json_encode($detail));
    }

    public function test_independently_readable_old_client_history_remains_exact_until_that_assignment_is_revoked(): void
    {
        $current = $this->client(true);
        $old = $this->client(true);
        $record = $this->record($current);
        $audit = $this->audit($record, ['site_id' => $this->site->id, 'treated_person_type' => 'client', 'client_id' => $old->id, 'treated_person_id' => null,
            'treated_person_name' => 'Recorded former person', 'treatment_given' => 'Recorded former treatment'], ['note' => 'Recorded former correction']);
        $history = collect(app(FirstAidPresenter::class)->detail($record, $this->viewer)['history'])->firstWhere('id', 'audit-'.$audit->id);
        $this->assertFalse($history['legacy']);
        $this->assertSame($old->id, $history['snapshot']['client_id']);
        $this->assertSame('Recorded former person', $history['snapshot']['treated_person_name']);
        $this->assertSame('Recorded former treatment', $history['snapshot']['treatment_given']);
        $old->supportWorkers()->detach($this->viewer->id);
        $this->viewer->load('permissionOverrides', 'roles');
        $detail = app(FirstAidPresenter::class)->detail($record, $this->viewer);
        $this->assertSame($current->id, $detail['client_id']);
        $this->assertNull(collect($detail['history'])->firstWhere('id', 'audit-'.$audit->id));
        $this->assertStringNotContainsString('Recorded former', json_encode($detail));
    }

    private function client(bool $assigned): Client
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        if ($assigned) {
            $client->supportWorkers()->attach($this->viewer->id);
        }

        return $client;
    }

    private function record(?Client $client = null): FirstAidRecord
    {
        $record = FirstAidRecord::factory()->create(['site_id' => $this->site->id, 'first_aider_id' => $this->viewer->id, 'created_by' => $this->viewer->id,
            'treated_person_type' => $client ? 'client' : 'staff', 'treated_person_id' => null, 'client_id' => $client?->id, 'ambulance_called' => false, 'treatment_outcome' => 'returned_to_activity']);
        $record->forceFill(['lock_version' => 1])->saveQuietly();

        return $record;
    }

    private function audit(FirstAidRecord $record, array $snapshot, array $meta): AuditLog
    {
        return AuditLog::create(['user_id' => $this->viewer->id, 'action' => 'first_aid.evidence_added', 'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
            'meta' => ['snapshot' => $snapshot, ...$meta]]);
    }
}
