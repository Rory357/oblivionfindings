<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stephan, 25 Oct ("Hide the clinical notes", job 4): exposure audit rows written before this build still hold the
 * worker's symptoms, treatment and narrative in their stored metadata. Audit pages show values only through the shared
 * safe allow-list, so those values never showed; an exposure row now also stops listing which clinical fields changed.
 * The audit CSV never carried change values and still doesn't.
 */
final class ExposureAuditClinicalFieldsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const CLINICAL = ['circumstances', 'symptoms', 'first_aid_given', 'medical_attention_sought', 'medical_treatment', 'medical_outcome'];

    private AuditLog $exposureAudit;

    private AuditLog $ordinaryAudit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $record = SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create());
        // A row as main's generic audit wrote it: every changed attribute, clinical ones included.
        $this->exposureAudit = AuditLog::create(['auditable_type' => SubstanceExposureRecord::class, 'auditable_id' => $record->id,
            'action' => 'substanceexposurerecord.update', 'meta' => [
                'before' => ['symptoms' => 'PRIVATE-OLD-SYMPTOMS', 'medical_treatment' => 'first_aid', 'exposure_type' => 'skin_contact'],
                'after' => ['symptoms' => 'PRIVATE-NEW-SYMPTOMS', 'medical_treatment' => 'hospitalisation', 'medical_outcome' => 'PRIVATE-OUTCOME',
                    'circumstances' => 'PRIVATE-NARRATIVE', 'first_aid_given' => 'PRIVATE-FIRST-AID', 'medical_attention_sought' => true,
                    'exposure_type' => 'inhalation'],
            ]]);
        $this->ordinaryAudit = AuditLog::create(['auditable_type' => User::class, 'auditable_id' => $record->user_id,
            'action' => 'user.updated', 'meta' => ['before' => ['status' => 'pending'], 'after' => ['status' => 'active']]]);
    }

    private function viewer(string $role): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole()->id);

        return $user->fresh();
    }

    /** @param array<string, mixed> $row */
    private function assertNoClinicalValues(array $row): void
    {
        foreach (['before', 'after'] as $side) {
            foreach (self::CLINICAL as $field) {
                $this->assertArrayNotHasKey($field, $row['properties'][$side] ?? [], "{$side}.{$field}");
            }
        }
        // Not even the names of the clinical fields that changed; other changed fields are still listed.
        $this->assertSame([], array_values(array_intersect(self::CLINICAL, $row['properties']['fields'] ?? [])));
        $this->assertContains('exposure_type', $row['properties']['fields'] ?? []);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('hospitalisation', json_encode($row, JSON_THROW_ON_ERROR));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function row(array $rows, AuditLog $log): array
    {
        $found = collect($rows)->firstWhere('id', $log->id);
        $this->assertNotNull($found, 'audit row '.$log->id.' is listed');

        return $found;
    }

    public function test_finance_viewer_sees_the_exposure_row_without_clinical_fields_on_the_settings_audit_page(): void
    {
        $finance = $this->viewer('finance');
        $this->assertTrue($finance->canDo('audit.viewAny'));
        $props = $this->actingAs($finance)->get('/settings/audit-logs')->assertOk()->inertiaProps();

        $this->assertNoClinicalValues($this->row($props['events']['data'], $this->exposureAudit));
        // Values only ever show through the shared safe allow-list (status and the like), so ordinary rows keep theirs.
        $ordinary = $this->row($props['events']['data'], $this->ordinaryAudit);
        $this->assertSame('active', $ordinary['properties']['after']['status']);
    }

    public function test_auditor_sees_the_exposure_row_without_clinical_fields_on_the_audit_log_page(): void
    {
        $auditor = $this->viewer('auditor');
        $props = $this->actingAs($auditor)->get('/audit-logs')->assertOk()->inertiaProps();

        $this->assertNoClinicalValues($this->row($props['logs']['data'], $this->exposureAudit));
        $this->assertSame('pending', $this->row($props['logs']['data'], $this->ordinaryAudit)['properties']['before']['status']);
    }

    public function test_the_audit_csv_lists_the_exposure_row_without_change_values(): void
    {
        $csv = $this->actingAs($this->viewer('finance'))->get('/settings/audit-logs/export')->assertOk()->streamedContent();

        $this->assertStringContainsString('substanceexposurerecord.update', $csv);
        $this->assertStringNotContainsString('PRIVATE-', $csv);
        $this->assertStringNotContainsString('hospitalisation', $csv);
    }
}
