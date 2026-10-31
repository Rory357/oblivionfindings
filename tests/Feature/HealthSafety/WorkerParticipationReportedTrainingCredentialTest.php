<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Models\ComplianceObligation;
use App\Models\AuditLog;
use App\Models\HsRepresentative;
use App\Models\StaffCredential;
use App\Models\User;

/** Actual H&S routes preserve reported facts without asserting an HR qualification. Native UNRUN. */
final class WorkerParticipationReportedTrainingCredentialTest extends RestraintEventCommandTestCase
{
    private const BASE = '/health-safety/worker-participation/representatives';
    private const TYPE = 'HSR Initial Training (NZQA US 29315)';
    private User $representativeUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = $this->staff(['hazards.view', 'hazards.manage']);
        $this->representativeUser = $this->staff(['hazards.view']);
    }

    public function test_create_preserves_reported_dates_days_and_existing_obligation_contract_without_new_credentials(): void
    {
        $this->actingAs($this->actor)->post(self::BASE, $this->values())->assertRedirect()->assertSessionHasNoErrors();
        $record = HsRepresentative::where('user_id', $this->representativeUser->id)->sole();
        $this->assertSame('2026-07-01', $record->initial_training_completed_at->toDateString());
        $this->assertSame(1, $record->training_days_completed);
        $this->assertSame(0, StaffCredential::where('user_id', $this->representativeUser->id)->count());
        $this->assertTrue(ComplianceObligation::where('obligation_code', 'HSR-TERM-'.$record->id)->exists());
        $this->assertTrue(ComplianceObligation::where('obligation_code', 'HSR-TRAINING-'.$record->id)->exists());
    }

    public function test_update_and_repeat_preserve_reported_date_without_creating_qualification(): void
    {
        $record = $this->record(null);
        foreach (['2026-07-01', '2026-07-01', '2026-08-01'] as $date) {
            $this->actingAs($this->actor)->put(self::BASE.'/'.$record->id, ['initial_training_completed_at' => $date])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($date, $record->fresh()->initial_training_completed_at->toDateString());
            $this->assertSame(0, StaffCredential::where('user_id', $this->representativeUser->id)->count());
        }
    }

    public function test_create_cannot_overwrite_any_field_or_history_of_genuine_existing_hr_credential(): void
    {
        $credential = $this->credential();
        $before = $this->credentialSnapshot($credential);
        $history = $this->credentialHistory($credential);
        $this->actingAs($this->actor)->post(self::BASE, $this->values())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $this->credentialSnapshot($credential));
        $this->assertSame($history, $this->credentialHistory($credential));
        $this->assertSame(1, StaffCredential::where('user_id', $this->representativeUser->id)->count());
    }

    public function test_update_cannot_overwrite_any_field_or_history_of_genuine_existing_hr_credential(): void
    {
        $credential = $this->credential();
        $before = $this->credentialSnapshot($credential);
        $history = $this->credentialHistory($credential);
        $record = $this->record(null);
        $this->actingAs($this->actor)->put(self::BASE.'/'.$record->id, ['initial_training_completed_at' => '2026-09-01'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-09-01', $record->fresh()->initial_training_completed_at->toDateString());
        $this->assertSame($before, $this->credentialSnapshot($credential));
        $this->assertSame($history, $this->credentialHistory($credential));
    }

    public function test_clearing_reported_date_does_not_delete_or_reclassify_historical_auto_recorded_credential(): void
    {
        $credential = $this->credential(['issuer' => 'NZQA', 'reference' => 'US 29315',
            'notes' => 'Auto-recorded from the H&S Representative register (initial HSR training).']);
        $before = $this->credentialSnapshot($credential);
        $history = $this->credentialHistory($credential);
        $record = $this->record('2026-07-01');
        $this->actingAs($this->actor)->put(self::BASE.'/'.$record->id, ['initial_training_completed_at' => null])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($record->fresh()->initial_training_completed_at);
        $this->assertSame($before, $this->credentialSnapshot($credential));
        $this->assertSame($history, $this->credentialHistory($credential));
    }

    public function test_multiple_representative_reports_cannot_rewrite_or_duplicate_worker_credential(): void
    {
        $credential = $this->credential();
        $before = $this->credentialSnapshot($credential);
        $history = $this->credentialHistory($credential);
        foreach (['2026-06-01', '2026-09-01'] as $date) {
            $this->actingAs($this->actor)->post(self::BASE, [...$this->values(), 'initial_training_completed_at' => $date])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->assertSame(2, HsRepresentative::where('user_id', $this->representativeUser->id)->count());
        $this->assertSame(1, StaffCredential::where('user_id', $this->representativeUser->id)->count());
        $this->assertSame($before, $this->credentialSnapshot($credential));
        $this->assertSame($history, $this->credentialHistory($credential));
    }

    public function test_genuine_hr_route_still_records_credential_without_granting_hr_write_to_hs_actor(): void
    {
        $this->assertFalse($this->actor->canDo('staff.credentials.updateAny'));
        $this->assertFalse($this->actor->canDo('staff.update'));
        $overrides = $this->actor->permissionOverrides()->orderBy('permissions.id')->pluck('permission_user.allowed', 'permissions.key')->all();
        $hr = $this->staff(['staff.credentials.updateAny']);
        $this->actingAs($hr)->post('/staff/'.$this->representativeUser->id.'/credentials', [
            'type' => self::TYPE, 'issuer' => 'Recorded HR provider', 'issued_at' => '2026-04-05',
            'expires_at' => '2028-04-05', 'reference' => 'Original certificate reference', 'notes' => 'Original HR factual record',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $credential = StaffCredential::where('user_id', $this->representativeUser->id)->sole();
        $before = $this->credentialSnapshot($credential);
        $history = $this->credentialHistory($credential);
        $this->actingAs($this->actor)->post(self::BASE, $this->values())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $this->credentialSnapshot($credential));
        $this->assertSame($history, $this->credentialHistory($credential));
        $this->assertSame($overrides, $this->actor->permissionOverrides()->orderBy('permissions.id')->pluck('permission_user.allowed', 'permissions.key')->all());
        $this->assertFalse($this->actor->fresh()->canDo('staff.credentials.updateAny'));
    }

    public function test_missing_management_permission_preserves_report_and_all_credentials(): void
    {
        $credential = $this->credential(); $record = $this->record(null);
        $before = $this->credentialSnapshot($credential);
        $this->revoke($this->actor, 'hazards.manage');
        $this->actingAs($this->actor)->postJson(self::BASE, $this->values())->assertForbidden();
        $this->actingAs($this->actor)->putJson(self::BASE.'/'.$record->id, ['initial_training_completed_at' => '2026-09-01'])->assertForbidden();
        $this->assertNull($record->fresh()->initial_training_completed_at);
        $this->assertSame(1, HsRepresentative::where('user_id', $this->representativeUser->id)->count());
        $this->assertSame($before, $this->credentialSnapshot($credential));
    }

    private function values(): array
    {
        return ['user_id' => $this->representativeUser->id, 'site_id' => $this->site->id, 'election_method' => 'elected',
            'elected_at' => '2026-01-01', 'term_expires_at' => '2027-01-01', 'training_days_completed' => 1,
            'initial_training_completed_at' => '2026-07-01'];
    }

    private function record(?string $date): HsRepresentative
    {
        return HsRepresentative::create([...$this->values(), 'initial_training_completed_at' => $date, 'status' => 'active', 'created_by' => $this->actor->id]);
    }

    private function credential(array $values = []): StaffCredential
    {
        return StaffCredential::create(['user_id' => $this->representativeUser->id, 'type' => self::TYPE,
            'issuer' => 'Existing provider identity', 'issued_at' => '2026-04-05', 'expires_at' => '2028-04-05',
            'reference' => 'Retained original credential reference', 'notes' => 'Retained private HR notes', ...$values]);
    }

    private function credentialSnapshot(StaffCredential $credential): array
    {
        return $credential->fresh()->getRawOriginal();
    }

    private function credentialHistory(StaffCredential $credential): array
    {
        $history = AuditLog::where('auditable_type', $credential->getMorphClass())->where('auditable_id', $credential->id)
            ->orderBy('id')->get()->map(fn ($audit) => $audit->getRawOriginal())->all();
        $this->assertNotEmpty($history, 'The retained credential audit baseline must exist.');
        $this->assertContains('staffcredential.create', array_column($history, 'action'));
        return $history;
    }
}
