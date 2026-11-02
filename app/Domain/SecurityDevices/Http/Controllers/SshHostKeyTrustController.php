<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\NetworkConfig\Services\SshHostKeyTrust;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * "Trust this key" for a device's SSH key (Phase A, item A6). The first trust needs device-management
 * rights; replacing a trusted key needs an IT admin. Both are audit-logged by SshHostKeyTrust.
 */
final class SshHostKeyTrustController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly SshHostKeyTrust $trust,
    ) {}

    public function __invoke(Request $request, Device $device): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $this->access->assertCanViewDevice($user, $device);

        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'max:51', 'regex:'.SshHostKeyTrust::FINGERPRINT_PATTERN],
        ]);

        $this->trust->trust($user, $device, $validated['fingerprint']);

        $message = 'SSH key trusted. Back up again to use it.';
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['trusted' => $validated['fingerprint'], 'message' => $message]);
        }

        return back()->with('success', $message);
    }
}
