<?php

namespace App\Domain\SecurityDevices\Screens\Http;

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
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);

        return Inertia::render('security-devices/sites/index', $this->sites->present($user, $request->only(['tab', 'q', 'region'])));
    }

    public function show(Request $request, int $site): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.devices.view'), 403);
        $model = $this->sitePage->site($user, $site);

        return Inertia::render('security-devices/sites/show', $this->sitePage->present($user, $model, $request->only(['tab'])));
    }
}
