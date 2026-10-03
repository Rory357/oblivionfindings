<?php

namespace Tests\Support;

use App\Http\Controllers\Emar\AuditLogController;
use App\Http\Controllers\MedicationAuditController;
use App\Models\User;
use App\Services\Medication\DoseSlots\DoseOmissions;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;

/** Keep retained evidence projection checks distinct from the canonical ledger screen. */
trait ReadsRetainedMedicationAuditEvidence
{
    private function canonicalGet(User $actor, string $routeName, array $query = []): TestResponse
    {
        $response = $this->actingAs($actor)->get(route($routeName, $query));
        if ($response->isRedirect()) {
            $location = $response->headers->get('Location');
            $this->assertStringContainsString('/emar/reports', $location);

            return $this->get($location);
        }

        return $response;
    }

    private function retainedAuditFeed(User $actor, array $query = []): TestResponse
    {
        $this->canonicalGet($actor, 'emar.audit', $query)->assertOk()
            ->assertInertia(fn ($page) => $page->component('emar/reports/hub')->where('filters.view', 'audit')->where('can.audit', true));
        $request = $this->auditEvidenceRequest($actor, $query);

        return TestResponse::fromBaseResponse(app(AuditLogController::class)
            ->index($request, app(DoseOmissions::class))->toResponse($request));
    }

    private function retainedAuditLogs(User $actor, array $query = []): TestResponse
    {
        $this->canonicalGet($actor, 'medications.audit.index', $query)->assertOk()
            ->assertInertia(fn ($page) => $page->component('emar/reports/hub')->where('filters.view', 'audit')->where('can.audit', true));
        $request = $this->auditEvidenceRequest($actor, $query);

        return TestResponse::fromBaseResponse(app(MedicationAuditController::class)->index($request)->toResponse($request));
    }

    private function auditEvidenceRequest(User $actor, array $query): Request
    {
        $request = Request::create('/retained-audit-evidence', 'GET', $query);
        $request->setUserResolver(fn () => $actor);

        return $request;
    }
}
