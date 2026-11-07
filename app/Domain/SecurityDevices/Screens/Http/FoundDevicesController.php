<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\SecurityDevices\Screens\Devices\FoundDevicesPresenter;
use App\Domain\SecurityDevices\Screens\Devices\NetworkScans;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Scans and what they found (pg-sdr-14, pop-ups 3, 4 and 75). Add, Same device? and Ignore use
 * today's discovery candidate routes.
 */
final class FoundDevicesController extends Controller
{
    public function __construct(
        private readonly FoundDevicesPresenter $presenter,
        private readonly NetworkScans $scans,
        private readonly DiscoveryRunner $runner,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    public function show(Request $request, int $run): Response
    {
        $user = $this->viewer($request);
        $scan = $this->presenter->run($user, $run);

        $page = null;
        $input = $request->only(['tab', 'q', 'sure']);
        $read = function () use (&$page, $user, $scan, $input): array {
            return $page ??= $this->presenter->present($user, $scan, $input);
        };
        $props = [];
        foreach (['scan', 'tab', 'filters', 'counts', 'rows', 'can'] as $key) {
            $props[$key] = fn () => $read()[$key];
        }

        return Inertia::render('security-devices/devices/found', [
            ...$props,
            'matchDevices' => Inertia::optional(fn (): array => $this->matchDevices($user, (int) $scan->scope?->site_id)),
        ]);
    }

    public function progress(Request $request, int $run): JsonResponse
    {
        $user = $this->viewer($request);

        return response()
            ->json($this->presenter->progress($user, $this->presenter->run($user, $run)))
            ->header('Cache-Control', 'private, no-store');
    }

    /** Pop-up 3: start scanning a Site's network now. */
    public function start(Request $request): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'range' => ['required', 'string', 'max:64', 'regex:/^[0-9a-fA-F:.]+\/\d{1,3}$/'],
            'hub_id' => ['nullable', 'integer', 'min:1'],
            'look_for' => ['required', 'array', 'min:1', 'max:6'],
            'look_for.*' => ['string', Rule::in(NetworkScans::LOOK_FOR)],
        ], [
            'range.regex' => 'Write the range like 10.20.0.0/24.',
            'site_id.required' => 'Choose the Site to scan.',
        ]);

        $run = $this->scans->start(
            $user,
            (int) $validated['site_id'],
            trim($validated['range']),
            isset($validated['hub_id']) ? (int) $validated['hub_id'] : null,
            $validated['look_for'],
        );

        return response()->json([
            'run_id' => (int) $run->id,
            'href' => '/security-devices/scans/'.$run->id,
        ], 202);
    }

    /** Pop-up 4's Stop scan. */
    public function stop(Request $request, int $run): JsonResponse
    {
        $user = $this->manager($request);
        $scan = $this->presenter->run($user, $run);
        $stopped = $this->runner->cancel((int) $scan->id, 'stopped_by_user:'.$user->id);

        return response()->json(['status' => $stopped->status]);
    }

    /** Pop-up 75: add a network for a hub to check and scan. */
    public function addNetwork(Request $request): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'range' => ['required', 'string', 'max:64', 'regex:/^[0-9a-fA-F:.]+\/\d{1,3}$/'],
            'hub_id' => ['nullable', 'integer', 'min:1'],
            'schedule' => ['required', 'string', Rule::in(['daily', 'ask'])],
            'look_for' => ['required', 'array', 'min:1', 'max:6'],
            'look_for.*' => ['string', Rule::in(NetworkScans::LOOK_FOR)],
        ], [
            'range.regex' => 'Write the range like 10.30.0.0/24.',
        ]);

        $scope = $this->scans->add(
            $user,
            (int) $validated['site_id'],
            trim($validated['range']),
            isset($validated['hub_id']) ? (int) $validated['hub_id'] : null,
            $validated['look_for'],
            $validated['schedule'] === 'daily' ? '06:00' : null,
        );

        return response()->json([
            'scope_id' => (int) $scope->id,
            'message' => 'Added '.trim($validated['range']).'.',
        ], 201);
    }

    /** Pop-up 75's warning: is this range already used at another Site? */
    public function rangeCheck(Request $request): JsonResponse
    {
        $user = $this->manager($request);
        $validated = $request->validate([
            'site_id' => ['required', 'integer', 'min:1'],
            'range' => ['required', 'string', 'max:64'],
        ]);
        $this->access->assertCanViewSite($user, (int) $validated['site_id']);

        return response()->json([
            'sites' => $this->scans->sitesUsingRange($user, (int) $validated['site_id'], trim($validated['range'])),
        ]);
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            $user instanceof User
            && $user->canDo('securityDevices.devices.view')
            && ($user->canDo('securityDevices.integrations.view') || $user->canDo('securityDevices.integrations.manage')),
            403,
        );

        return $user;
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->canDo('securityDevices.integrations.manage'), 403);

        return $user;
    }

    /**
     * Same device? picks from the viewer's devices at the scanned Site.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    private function matchDevices(User $user, int $siteId): array
    {
        if ($siteId < 1) {
            return [];
        }

        return $this->access->visibleDevicesForSite($user, $siteId)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'manufacturer', 'model', 'ip_address'])
            ->map(fn ($device): array => [
                'value' => (string) $device->id,
                'label' => (string) $device->name,
                'description' => implode(' · ', array_filter([
                    trim(trim((string) $device->manufacturer).' '.trim((string) $device->model)) ?: null,
                    $device->ip_address,
                ])),
            ])
            ->all();
    }
}
