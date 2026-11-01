<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessJob;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Screens\Access\AccessPagePresenter;
use App\Domain\SecurityDevices\Screens\Access\AccessRollCall;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Access (S&D rebuild drawings pg-sdr-19, 19b–19d, 19f) and the fire roll call (pop-up 87). The
 * changes themselves (pop-ups 81–84, 90) go through AccessPeopleController.
 */
final class AccessPageController extends Controller
{
    public function index(Request $request, AccessPagePresenter $presenter): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && AccessPagePresenter::canOpen($user), 403);

        return Inertia::render('security-devices/access/index', [
            ...$presenter->present($user, $request->only(['tab', 'site', 'q', 'page', 'state', 'status', 'when', 'door', 'kind', 'days', 'person'])),
            // Give someone access (81) and Match (90) pick staff at the Site, loaded when one opens.
            'staff' => Inertia::optional(fn (): array => $presenter->staffOptions($user)),
            // S&D Phase H (pop-up 86): lockdown, evacuation and ending either, per Site, as governed commands.
            'emergency_actions' => ($request->query('tab') ?? 'doors') === 'doors'
                ? app(\App\Domain\SecurityDevices\Access\AccessEmergencyActions::class)->present(
                    $user,
                    is_numeric($confirmed = $request->session()->get('auth.password_confirmed_at')) ? CarbonImmutable::createFromTimestampUTC((int) $confirmed) : null,
                )
                : [],
        ]);
    }

    /** Pop-up 87: who may be at the Site now. Another Site is 404. */
    public function rollCall(Request $request, int $site, AccessRollCall $rollCall, SecurityDevicesAccessService $access): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && AccessPagePresenter::canOpen($user), 403);
        abort_unless(in_array($site, $access->accessibleSiteIds($user), true) && AccessConsole::query()->where('site_id', $site)->exists(), 404);

        return response()->json($rollCall->present(Site::query()->findOrFail($site), CarbonImmutable::now('UTC')));
    }

    /**
     * Pop-up 81's last step: once a "user_create" change is done, who the new person is here, so
     * their card can be added at a reader straight away. Another Site's change is 404.
     */
    public function addedPerson(Request $request, string $uuid, SecurityDevicesAccessService $access): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && AccessPagePresenter::canManage($user), 403);
        $job = AccessJob::query()->with('console:id,site_id')->where('uuid', strtolower($uuid))->where('kind', 'user_create')->firstOrFail();
        abort_unless($job->console !== null && in_array((int) $job->console->site_id, $access->accessibleSiteIds($user), true), 404);
        $external = $job->state === 'succeeded' ? ((array) ($job->result ?? []))['user_id'] ?? null : null;
        $person = is_string($external) ? AccessPerson::query()->where('console_id', $job->console_id)->where('external_id', $external)->first() : null;

        return response()->json(['person_id' => $person?->id !== null ? (int) $person->id : null]);
    }
}
