<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\QueclinkUnitConsoleService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class QueclinkUnitConsoleController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly QueclinkUnitConsoleService $console,
    ) {}

    public function show(Request $request, Device $device): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && $user->canDo('securityDevices.devices.view')
            && $this->console->canViewConsole($user),
            403,
        );
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($this->console->supportsDevice($device), 404);

        return response()->json($this->console->present($user, $device));
    }

    public function sendCommand(Request $request, Device $device): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user
            && $user->canDo('securityDevices.devices.view')
            && $this->console->canViewConsole($user),
            403,
        );
        $this->access->assertCanViewDevice($user, $device);
        abort_unless($this->console->supportsDevice($device), 404);

        $validated = $request->validate([
            'command' => ['required', 'string', 'max:64'],
            'parameters' => ['nullable', 'array'],
            'reason' => ['nullable', 'string', 'max:500'],
            'confirmed' => ['nullable', 'boolean'],
        ]);

        $pending = $this->console->sendCommand(
            actor: $user,
            device: $device,
            commandKey: (string) $validated['command'],
            parameters: is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [],
            reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
            confirmed: (bool) ($validated['confirmed'] ?? false),
        );

        $message = "Queued {$pending->command_word} (#{$pending->serial_number}) for delivery to the tracker.";

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'pending_command_id' => (int) $pending->id,
                'command_word' => $pending->command_word,
                'serial_number' => $pending->serial_number,
                'status' => $pending->status,
                'message' => $message,
                'console' => $this->console->present($user, $device->fresh()),
            ]);
        }

        return back()->with('success', $message);
    }
}
