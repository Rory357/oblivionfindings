<?php

namespace Tests\Feature\SecurityDevices\Concerns;

use App\Domain\SecurityDevices\Config\CategoryPageConfig;
use App\Domain\SecurityDevices\Config\WorkspaceConfig;
use App\Domain\SecurityDevices\Presenters\HealthcareWorkspacePresenter;
use App\Domain\SecurityDevices\Presenters\IntegrationsWorkspacePresenter;
use App\Domain\SecurityDevices\Presenters\SecurityWorkspacePresenter;
use App\Domain\SecurityDevices\Presenters\SettingsAuditPresenter;
use App\Domain\SecurityDevices\Presenters\TrackingWorkspacePresenter;
use App\Domain\SecurityDevices\Presenters\WorkspacePresenter;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert;

/**
 * S&D rebuild C6: the Tracking, Healthcare and Integrations hub pages are gone (they redirect into
 * the six-entry menu), but their presenters still feed live screens: the Control Room map, the
 * client profile's Healthcare devices and Setup › Connected services. Their privacy and scoping
 * rules keep their tests: these build the same props the old page built, straight from the
 * presenters, and hand each assertion the same AssertableInertia it had before.
 */
trait ReadsRetiredWorkspaceProps
{
    protected function retiredWorkspace(User $viewer, string $slug, string $query = ''): object
    {
        parse_str($query, $input);
        Assert::assertTrue($viewer->canDo('securityDevices.devices.view'), 'The old workspace needed devices.view.');

        $workspaceConfig = WorkspaceConfig::get($slug);
        $config = CategoryPageConfig::get($slug);
        Assert::assertNotNull($workspaceConfig);
        Assert::assertNotNull($config);
        $activeTab = WorkspaceConfig::activeTab($workspaceConfig, is_string($input['tab'] ?? null) ? $input['tab'] : '');
        $scope = app(SecurityDevicesAccessService::class)->visibleDevices($viewer)->byDomain($config['domain']);

        return $this->retiredPage([
            'workspace' => app(WorkspacePresenter::class)->present(clone $scope, $workspaceConfig, $activeTab, $viewer),
            'trackingWorkspace' => $slug === 'tracking'
                ? app(TrackingWorkspacePresenter::class)->present($viewer, clone $scope, $activeTab, Arr::only($input, ['attention']))
                : null,
            'healthcareWorkspace' => $slug === 'healthcare'
                ? app(HealthcareWorkspacePresenter::class)->present($viewer, clone $scope, $activeTab, Arr::only($input, ['flow']))
                : null,
            // Only for the local door schedules and cards (kept: no new screen reads them yet).
            'securityWorkspace' => $slug === 'security'
                ? app(SecurityWorkspacePresenter::class)->present($viewer, clone $scope, $activeTab, Arr::only($input, ['status']))
                : null,
        ]);
    }

    /** The old Integrations hub's props; IntegrationsWorkspacePresenter now feeds Setup › Connected services. */
    protected function retiredIntegrationsHub(User $viewer): object
    {
        Assert::assertTrue($viewer->canDo('securityDevices.integrations.view'), 'The old hub needed integrations.view.');

        return $this->retiredPage(app(IntegrationsWorkspacePresenter::class)->present($viewer));
    }

    /**
     * The old Settings & audit page's props (it now redirects to Setup). Its record audit list is
     * where these tests check that ProtectedAuditLogger files each change under the right Site.
     */
    protected function retiredSettingsAudit(User $viewer): object
    {
        Assert::assertTrue(
            $viewer->canDo('securityDevices.groups.manage') || $viewer->canDo('securityDevices.reports.view')
                || $viewer->canDo('securityDevices.commands.admin') || $viewer->canDo('securityDevices.monitoring.manage'),
            'The old Settings & audit page needed one of its four keys.',
        );

        return $this->retiredPage(app(SettingsAuditPresenter::class)->present($viewer));
    }

    /** @param  array<string, mixed>  $props */
    private function retiredPage(array $props): object
    {
        // Round-trip through JSON, as the page did, so assertions see what a browser would.
        $props = json_decode(json_encode($props, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return new class($props)
        {
            /** @param  array<string, mixed>  $props */
            public function __construct(private readonly array $props) {}

            public function assertOk(): static
            {
                return $this;
            }

            public function assertInertia(callable $callback): static
            {
                $callback(AssertableInertia::fromArray($this->props));

                return $this;
            }

            /** @return array<string, mixed> */
            public function props(): array
            {
                return $this->props;
            }
        };
    }
}
