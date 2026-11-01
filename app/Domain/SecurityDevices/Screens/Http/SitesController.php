<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Screens\Devices\DeviceFormOptions;
use App\Domain\SecurityDevices\Screens\Sites\SitePagePresenter;
use App\Domain\SecurityDevices\Screens\Sites\SitesIndexPresenter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/** Sites and one Site (S&D rebuild drawings pg-sdr-11 and pg-sdr-12). */
final class SitesController extends Controller
{
    public function __construct(
        private readonly SitesIndexPresenter $sites,
        private readonly SitePagePresenter $sitePage,
        private readonly DeviceFormOptions $formOptions,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);

        return Inertia::render('security-devices/sites/index', [
            ...$this->sites->present($user, $request->only(['tab', 'q', 'region'])),
            // Add a device and Scan a network load their choices only when one opens.
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }

    public function show(Request $request, int $site): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        $model = $this->sitePage->site($user, $site);

        return Inertia::render('security-devices/sites/show', [
            ...$this->sitePage->present($user, $model, $request->only(['tab'])),
            'deviceForm' => Inertia::optional(fn (): array => $this->formOptions->present($user)),
        ]);
    }
}
