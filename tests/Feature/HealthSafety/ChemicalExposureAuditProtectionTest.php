<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\SafeOperationalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Audit persistence protection only; these tests do not certify source command acceptance or audit readership. */
final class ChemicalExposureAuditProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function meta(): array
    {
        return ['actor_id' => 7, 'idempotency_key' => '00000000-0000-4000-8000-000000000007',
            'root_type' => 'exposure', 'root_id' => 13, 'source_type' => 'exposure', 'source_id' => 13,
            'expected_version' => null, 'version' => 1, 'source_version_id' => 17, 'changed' => true,
            'source_content_digest' => str_repeat('a', 64), 'source_tuple_digest' => str_repeat('b', 64)];
    }

    private function assertExactMeta(array $expected, array $actual): void
    {
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        $this->assertSame($expected, $actual);
    }

    public function test_exact_scalar_acceptance_evidence_survives_without_clinical_or_request_material(): void
    {
        $meta = $this->meta();
        $safe = SafeOperationalData::auditMeta([...$meta, 'symptoms' => 'PRIVATE-SYMPTOMS',
            'medical_treatment' => 'PRIVATE-TREATMENT', 'root_after' => ['circumstances' => 'PRIVATE-BODY'],
            'actor_snapshot' => ['name' => 'PRIVATE-NAME'], 'storage_path' => 'private/PRIVATE-FILE',
            'source_tuple' => ['before' => ['site_id' => 29, 'original_context' => ['client_id' => 31]],
                'after' => ['worker_id' => 7, 'original_context' => ['incident_id' => 13]]],
            'tenant_id' => 99, 'organization_id' => 99, 'url' => 'PRIVATE-URL'], new SubstanceExposureRecord, []);
        $this->assertExactMeta($meta, $safe);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($safe, JSON_THROW_ON_ERROR));
    }

    public function test_noop_preserves_null_digest_version_pointer_and_zero_expected_revision(): void
    {
        $meta = [...$this->meta(), 'expected_version' => 0, 'version' => 0,
            'source_version_id' => null, 'source_content_digest' => null, 'changed' => false];
        $this->assertExactMeta($meta, SafeOperationalData::auditMeta($meta, new SubstanceExposureRecord, []));
    }

    public function test_malformed_evidence_is_not_coerced_into_valid_acceptance(): void
    {
        $meta = ['actor_id' => '7', 'root_id' => -1, 'source_id' => 0, 'expected_version' => '0',
            'version' => -1, 'source_version_id' => 0, 'changed' => 'true', 'root_type' => 'catalogue',
            'source_type' => 'PRIVATE-FREE-TEXT', 'idempotency_key' => 'PRIVATE-NOT-A-UUID',
            'source_content_digest' => 'PRIVATE-NOT-A-DIGEST', 'source_tuple_digest' => ['PRIVATE-TUPLE']];
        $this->assertSame([], SafeOperationalData::auditMeta($meta, new SubstanceExposureRecord, []));
    }

    public function test_original_tuple_anchor_accepts_only_typed_lowercase_digest_and_never_the_tuple(): void
    {
        foreach ([1, true, str_repeat('B', 64), str_repeat('b', 63), str_repeat('b', 65), ['site_id' => 29]] as $invalid) {
            $safe = SafeOperationalData::auditMeta(['source_tuple_digest' => $invalid,
                'source_tuple' => ['after' => ['worker_id' => 7, 'site_id' => 29]]], new SubstanceExposureRecord, []);
            $this->assertSame([], $safe);
        }
        $this->assertSame(['source_tuple_digest' => null], SafeOperationalData::auditMeta(
            ['source_tuple_digest' => null], new SubstanceExposureRecord, []));
    }

    public function test_exposure_contract_does_not_broaden_unrelated_protected_domain_metadata(): void
    {
        $device = new \App\Domain\SecurityDevices\Models\Device;
        $this->assertSame([], SafeOperationalData::auditMeta($this->meta(), $device, []));
        $this->assertFalse(SafeOperationalData::protectsRequestContext(new \App\Models\HazardousSubstance));
        $this->assertFalse(SafeOperationalData::protectsRequestContext(null));
    }

    public function test_all_actual_private_exposure_subject_classes_protect_request_context(): void
    {
        foreach ([SubstanceExposureRecord::class, \App\Models\ChemicalExposureReview::class,
            \App\Models\ChemicalExposureFollowup::class, \App\Models\ChemicalExposureEvidence::class,
            \App\Models\ChemicalExposureSourceVersion::class, \App\Models\ChemicalExposureCommandReceipt::class,
            \App\Models\ChemicalExposureEvidenceStaging::class, \App\Models\ChemicalExposureEvidenceCleanup::class,
            \App\Models\ChemicalExposureSourceDelivery::class, \App\Models\ChemicalExposureHsProjection::class] as $class) {
            $this->assertTrue(SafeOperationalData::protectsRequestContext(new $class), $class);
        }
    }

    public function test_only_exposure_hs_events_protect_request_context_including_source_type_replacement(): void
    {
        $event = new \App\Models\HsEvent(['source_type' => SubstanceExposureRecord::class]);
        $this->assertTrue(SafeOperationalData::protectsRequestContext($event));
        $event->syncOriginal();
        $event->source_type = \App\Models\ClientIncident::class;
        $this->assertTrue(SafeOperationalData::protectsRequestContext($event));
        $ordinary = new \App\Models\HsEvent(['source_type' => \App\Models\ClientIncident::class]);
        $this->assertFalse(SafeOperationalData::protectsRequestContext($ordinary));
        $this->assertFalse(SafeOperationalData::protectsRequestContext(new \App\Models\ChemicalCommandIntent(['source_type' => 'catalogue'])));
    }

    public function test_legacy_clinical_snapshots_do_not_survive_the_safe_value_boundary(): void
    {
        $safe = SafeOperationalData::auditMeta(['before' => ['symptoms' => 'PRIVATE-BEFORE', 'status' => 'open'],
            'after' => ['medical_outcome' => 'PRIVATE-AFTER', 'circumstances' => 'PRIVATE-CIRCUMSTANCES', 'status' => 'closed']],
            new SubstanceExposureRecord, []);
        $this->assertSame(['before' => ['status' => 'open'], 'after' => ['status' => 'closed']], $safe);
    }

    public function test_actual_logger_persists_exact_receipt_evidence_without_ip_agent_or_private_body(): void
    {
        $actor = User::factory()->create();
        // Deliberately an audit subject, not a producer-created private source.
        // No source provenance/read authority is inferred from this fixture.
        $subject = new SubstanceExposureRecord;
        $subject->forceFill(['id' => 13, 'site_id' => 29]);
        $request = Request::create('/health-safety/chemicals/2/exposures', 'POST', ['symptoms' => 'PRIVATE-REQUEST'],
            [], [], ['REMOTE_ADDR' => '203.0.113.12', 'HTTP_USER_AGENT' => 'PRIVATE-BROWSER']);
        $request->setUserResolver(fn () => $actor);
        $meta = [...$this->meta(), 'actor_id' => (int) $actor->id];
        AuditLogger::logOrFail('chemical.exposure.create_exposure', $subject,
            [...$meta, 'symptoms' => 'PRIVATE-SYMPTOMS', 'root_after' => ['medical_treatment' => 'PRIVATE-TREATMENT']], $request);
        $audit = AuditLog::where('action', 'chemical.exposure.create_exposure')->sole();
        $this->assertSame((int) $actor->id, (int) $audit->user_id);
        $this->assertSame($subject->getMorphClass(), $audit->auditable_type);
        $this->assertSame(13, (int) $audit->auditable_id);
        $this->assertExactMeta(['scope' => ['site_id' => 29], ...$meta], $audit->meta);
        $this->assertNull($audit->ip_address);
        $this->assertNull($audit->user_agent);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($audit->attributesToArray(), JSON_THROW_ON_ERROR));
    }
}
