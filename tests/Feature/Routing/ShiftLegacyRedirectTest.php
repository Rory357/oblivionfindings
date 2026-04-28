<?php

namespace Tests\Feature\Routing;

use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\RoleScope;
use Illuminate\Auth\Middleware\Authenticate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShiftLegacyRedirectTest extends TestCase
{
    #[DataProvider('legacyRedirects')]
    public function test_legacy_get_routes_redirect_to_operations_successors(string $legacyUrl, string $canonicalUrl): void
    {
        $this->withoutMiddleware([
            Authenticate::class,
            EnsurePermission::class,
            RoleScope::class,
        ]);

        $this->get($legacyUrl)
            ->assertStatus(301)
            ->assertRedirect(url($canonicalUrl));
    }

    public static function legacyRedirects(): array
    {
        return [
            ['/shifts', '/operations/shifts'],
            ['/shifts?status=scheduled', '/operations/shifts?status=scheduled'],
            ['/shifts/create', '/operations/shifts/create'],
            ['/shifts/123', '/operations/shifts/123'],
            ['/shifts/123/edit', '/operations/shifts/123/edit'],
            ['/timesheets', '/operations/timesheets'],
            ['/timesheets/approvals', '/operations/timesheets/approvals'],
            ['/timesheets/create', '/operations/timesheets/create'],
            ['/timesheets/123', '/operations/timesheets/123'],
            ['/timesheets/123/edit', '/operations/timesheets/123/edit'],
            ['/rostering', '/operations/rostering'],
            ['/rostering/week', '/operations/rostering/week'],
        ];
    }
}
