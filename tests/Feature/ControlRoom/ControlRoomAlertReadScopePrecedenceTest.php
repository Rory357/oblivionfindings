<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ControlRoomAlertReadScopePrecedenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_alert_site_cannot_be_overridden_by_a_local_client_or_context(): void
    {
        [$localSite, $foreignSite] = [Site::factory()->create(), Site::factory()->create()];
        $user = $this->siteScopedUser($localSite);
        $localClient = Client::factory()->create(['site_id' => $localSite->id]);
        $alert = $this->makeAlert([
            'site_id' => $foreignSite->id,
            'client_id' => $localClient->id,
            'context' => ['site_id' => $localSite->id],
        ]);

        $this->assertScopeAndRecordAuthorizationAgree($user, $alert, false);
    }

    public function test_foreign_client_site_cannot_be_overridden_by_local_context_when_alert_site_is_null(): void
    {
        [$localSite, $foreignSite] = [Site::factory()->create(), Site::factory()->create()];
        $user = $this->siteScopedUser($localSite);
        $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
        $alert = $this->makeAlert([
            'site_id' => null,
            'client_id' => $foreignClient->id,
            'context' => ['site_id' => $localSite->id],
        ]);

        $this->assertScopeAndRecordAuthorizationAgree($user, $alert, false);
    }

    public function test_local_context_is_used_when_alert_and_client_have_no_site(): void
    {
        $localSite = Site::factory()->create();
        $user = $this->siteScopedUser($localSite);
        $siteLessClient = Client::factory()->create(['site_id' => null]);
        $alert = $this->makeAlert([
            'site_id' => null,
            'client_id' => $siteLessClient->id,
            'context' => [
                'shift_context' => [
                    'site' => ['id' => $localSite->id],
                ],
            ],
        ]);

        $this->assertScopeAndRecordAuthorizationAgree($user, $alert, true);
    }

    public function test_first_attributable_context_site_cannot_be_overridden_by_a_later_local_context_path(): void
    {
        [$localSite, $foreignSite] = [Site::factory()->create(), Site::factory()->create()];
        $user = $this->siteScopedUser($localSite);
        $alert = $this->makeAlert([
            'site_id' => null,
            'client_id' => null,
            'context' => [
                'site_id' => $foreignSite->id,
                'shift_context' => [
                    'site' => ['id' => $localSite->id],
                ],
            ],
        ]);

        $this->assertScopeAndRecordAuthorizationAgree($user, $alert, false);
    }

    public function test_alert_without_any_attributable_site_fails_closed(): void
    {
        $localSite = Site::factory()->create();
        $user = $this->siteScopedUser($localSite);
        $alert = $this->makeAlert([
            'site_id' => null,
            'client_id' => null,
            'context' => [],
        ]);

        $this->assertScopeAndRecordAuthorizationAgree($user, $alert, false);
    }

    private function assertScopeAndRecordAuthorizationAgree(
        User $user,
        ControlRoomAlert $alert,
        bool $expected,
    ): void {
        $siteAccess = app(UserSiteAccessService::class);
        $query = ControlRoomAlert::query()->whereKey($alert);
        $isVisible = $siteAccess->applyAlertScope($query, $user)->exists();

        $this->assertSame($expected, $isVisible, 'The alert read scope returned the wrong visibility.');

        $recordAuthorizationPassed = true;

        try {
            $siteAccess->assertCanAccessAlert($user, $alert);
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $recordAuthorizationPassed = false;
        }

        $this->assertSame(
            $expected,
            $recordAuthorizationPassed,
            'The alert read scope and record authorization disagreed.',
        );
    }

    private function siteScopedUser(Site $site): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    private function makeAlert(array $overrides): ControlRoomAlert
    {
        return ControlRoomAlert::factory()->create(array_merge([
            'source' => 'manual',
            'alert_type' => 'Site scope precedence test',
            'severity' => 'high',
            'status' => ControlRoomAlert::STATUS_OPEN,
            'triggered_at' => now(),
        ], $overrides));
    }
}
