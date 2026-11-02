<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Care\ScaldWatch;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * S&D Phase F: link a sensor to a person's care (pop-up 65), unlink it, and turn a hot-water
 * probe's scald watch on or off. Every check lives in DevicePersonLinks and ScaldWatch.
 */
final class CareLinkController extends Controller
{
    public function __construct(
        private readonly DevicePersonLinks $links,
        private readonly ScaldWatch $scald,
    ) {}

    /** Pop-up 65's people, their consent state and care plans. */
    public function options(Request $request, Device $device): JsonResponse
    {
        return response()->json($this->links->options($this->user($request), $device))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'purpose' => ['required', 'string', 'in:'.implode(',', DevicePersonLink::PURPOSES)],
            'care_plan_id' => ['nullable', 'integer', 'min:1'],
            'rule' => ['nullable', 'array'],
            'rule.minutes' => ['nullable', 'integer', 'between:1,120'],
            'rule.from' => ['nullable', 'string', 'size:5'],
            'rule.to' => ['nullable', 'string', 'size:5'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $link = $this->links->link(
            $this->user($request),
            $device,
            (int) $validated['client_id'],
            (string) $validated['purpose'],
            isset($validated['care_plan_id']) ? (int) $validated['care_plan_id'] : null,
            $validated['rule'] ?? null,
            $validated['note'] ?? null,
        );

        return response()->json(['id' => (int) $link->id, 'message' => 'Linked. Alerts go to whoever is on shift.'], 201);
    }

    public function destroy(Request $request, Device $device): JsonResponse
    {
        $link = $this->links->currentFor($device);
        abort_unless($link instanceof DevicePersonLink, 404);
        $this->links->unlink($this->user($request), $link);

        return response()->json(['message' => 'Unlinked. This sensor no longer tells anyone about this person.']);
    }

    public function scaldWatch(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate(['on' => ['required', 'boolean']]);
        $this->scald->set($this->user($request), $device, (bool) $validated['on']);

        return response()->json(['on' => (bool) $validated['on']]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
