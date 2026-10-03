<?php

namespace Tests\Support;

use App\Models\User;
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

        return $this->get(route('emar.reports.history', $query));
    }

    private function retainedAuditLogs(User $actor, array $query = []): TestResponse
    {
        $this->canonicalGet($actor, 'medications.audit.index', $query)->assertOk()
            ->assertInertia(fn ($page) => $page->component('emar/reports/hub')->where('filters.view', 'audit')->where('can.audit', true));

        return $this->get(route('emar.reports.history_logs', $query));
    }
}
