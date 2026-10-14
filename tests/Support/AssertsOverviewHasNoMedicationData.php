<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;

/**
 * The home Overview (role workspaces, 753a7f396) carries no medication data:
 * the old dashboard's eMAR widget (emarWidgets — due now, alerts, reviews,
 * stock) is gone, and nothing medication-, dose- or controlled-medicine-shaped
 * is in the page's own props for any reader. eMAR numbers live on the eMAR
 * pages, which keep their own tests. If medication blocks come back to the
 * Overview, these checks must become the person- and controlled-rule checks
 * the old widget had.
 */
trait AssertsOverviewHasNoMedicationData
{
    protected function assertOverviewHasNoMedicationData(TestResponse $response): void
    {
        $response->assertOk();
        $props = $response->inertiaProps();
        $this->assertArrayNotHasKey('emarWidgets', $props);
        // The dashboard's own props (DashboardController::index), not the
        // shared ones (navigation permissions name medication capabilities).
        $page = json_encode(
            array_intersect_key($props, array_flip(['overview', 'role', 'roles', 'sites', 'filters', 'preference'])),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->assertSame(
            0,
            preg_match('/\b(medications?|medicines?|doses?|controlled|emar)\b/i', (string) $page, $hit),
            'The Overview carries medication data: '.($hit[0] ?? ''),
        );
    }
}
