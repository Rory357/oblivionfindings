<?php

namespace Tests\Feature\Routing;

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RoleScope;
use Illuminate\Auth\Middleware\Authenticate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyShiftWriteRedirectsTest extends TestCase
{
    /**
     * 308 preserves the HTTP method and request body, so a client POSTing to
     * a legacy URL is transparently re-issued against the canonical one.
     */
    #[DataProvider('legacyWriteRedirects')]
    public function test_legacy_write_routes_return_308_to_canonical_operations_successor(
        string $method,
        string $legacyUrl,
        string $canonicalUrl,
    ): void {
        $this->withoutMiddleware([
            Authenticate::class,
            EnsurePermission::class,
            RoleScope::class,
        ]);

        $this->call($method, $legacyUrl, ['probe' => 1])
            ->assertStatus(308)
            ->assertRedirect(url($canonicalUrl));
    }

    public function test_legacy_write_redirect_preserves_query_string(): void
    {
        $this->withoutMiddleware([
            Authenticate::class,
            EnsurePermission::class,
            RoleScope::class,
        ]);

        $this->call('POST', '/timesheets/bulk-approve?reviewer=42')
            ->assertStatus(308)
            ->assertRedirect(url('/operations/timesheets/bulk-approve?reviewer=42'));
    }

    public static function legacyWriteRedirects(): array
    {
        return [
            ['POST', '/shifts', '/operations/shifts'],
            ['POST', '/shifts/series', '/operations/shifts/series'],
            ['PUT', '/shifts/123', '/operations/shifts/123'],
            ['POST', '/shifts/123/assign', '/operations/shifts/123/assign'],
            ['POST', '/shifts/123/unassign', '/operations/shifts/123/unassign'],
            ['PATCH', '/shifts/123/start', '/operations/shifts/123/start'],
            ['PATCH', '/shifts/123/complete', '/operations/shifts/123/complete'],
            ['PATCH', '/shifts/123/cancel', '/operations/shifts/123/cancel'],
            ['PATCH', '/shifts/123/reopen', '/operations/shifts/123/reopen'],
            ['POST', '/shifts/123/replacement-request', '/operations/shifts/123/replacement-request'],
            ['PATCH', '/shifts/123/replacement-request/cancel', '/operations/shifts/123/replacement-request/cancel'],
            ['PATCH', '/shifts/123/tasks/456', '/operations/shifts/123/tasks/456'],
            ['POST', '/timesheets', '/operations/timesheets'],
            ['PUT', '/timesheets/123', '/operations/timesheets/123'],
            ['POST', '/timesheets/123/submit', '/operations/timesheets/123/submit'],
            ['POST', '/timesheets/123/resubmit', '/operations/timesheets/123/resubmit'],
            ['POST', '/timesheets/123/approve', '/operations/timesheets/123/approve'],
            ['POST', '/timesheets/123/reject', '/operations/timesheets/123/reject'],
            ['POST', '/timesheets/123/return', '/operations/timesheets/123/return'],
            ['POST', '/timesheets/bulk-approve', '/operations/timesheets/bulk-approve'],
            ['POST', '/timesheets/bulk-return', '/operations/timesheets/bulk-return'],
            ['POST', '/timesheets/bulk-reject', '/operations/timesheets/bulk-reject'],
        ];
    }
}
