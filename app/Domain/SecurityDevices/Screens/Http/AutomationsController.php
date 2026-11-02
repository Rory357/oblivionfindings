<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Automations\AutomationEngine;
use App\Domain\SecurityDevices\Automations\Automations;
use App\Domain\SecurityDevices\Screens\Alerts\AutomationsPresenter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Alerts › Rules & automations (pg-sdr-13b): add or change an automation (pop-up 40), test it
 * (pop-up 41), turn it off or on, and remove it. Every one needs securityDevices.automations.manage
 * (403 without); another Site's automation is a 404.
 */
final class AutomationsController extends Controller
{
    public function __construct(
        private readonly Automations $automations,
        private readonly AutomationEngine $engine,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $this->manager($request);
        $automation = $this->automations->add($user, $this->input($request, withSite: true));

        return response()->json([
            'id' => (int) $automation->id,
            'name' => (string) $automation->name,
            'on' => (bool) $automation->is_on,
            'message' => $automation->is_on ? 'Automation turned on.' : 'Automation saved. It’s off until you turn it on.',
        ], 201);
    }

    public function update(Request $request, int $automation): JsonResponse
    {
        $user = $this->manager($request);
        $record = $this->automations->find($user, $automation);
        $version = $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        $saved = $this->automations->change($user, $record, (int) $version, $this->input($request, withSite: false));

        return response()->json(['id' => (int) $saved->id, 'name' => (string) $saved->name, 'on' => (bool) $saved->is_on, 'message' => 'Automation saved.']);
    }

    public function turnOff(Request $request, int $automation): JsonResponse
    {
        $user = $this->manager($request);
        $record = $this->automations->find($user, $automation);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], ['reason.min' => 'Say why in at least 10 characters.', 'reason.required' => 'Say why in at least 10 characters.']);
        $this->automations->turn($user, $record, false, (int) $validated['version'], trim((string) $validated['reason']));

        return response()->json(['message' => 'Automation turned off.']);
    }

    public function turnOn(Request $request, int $automation): JsonResponse
    {
        $user = $this->manager($request);
        $record = $this->automations->find($user, $automation);
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $this->automations->turn($user, $record, true, (int) $validated['version'], null);

        return response()->json(['message' => 'Automation turned on.']);
    }

    public function destroy(Request $request, int $automation): JsonResponse
    {
        $user = $this->manager($request);
        $record = $this->automations->find($user, $automation);
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $reason = isset($validated['reason']) && trim((string) $validated['reason']) !== '' ? trim((string) $validated['reason']) : null;
        $this->automations->remove($user, $record, (int) $validated['version'], $reason);

        return response()->json(['message' => 'Automation removed. It stays in the history.']);
    }

    /** Pop-up 41: run once now with made-up trigger data; only the person testing is told. */
    public function test(Request $request, int $automation): JsonResponse
    {
        $user = $this->manager($request);
        $record = $this->automations->find($user, $automation);
        $run = $this->engine->test($record, $user);

        return response()->json(['run' => AutomationsPresenter::run($run)]);
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && AutomationsPresenter::canManage($user), 403);

        return $user;
    }

    /** @return array<string, mixed> */
    private function input(Request $request, bool $withSite): array
    {
        $payload = $request->only(['name', 'is_on', 'trigger', 'conditions', 'actions', ...($withSite ? ['site_id'] : [])]);
        $payload['is_on'] = $request->boolean('is_on', true);

        return $payload;
    }
}
