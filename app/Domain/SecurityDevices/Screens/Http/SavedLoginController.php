<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Credentials\Services\DeviceSavedLogin;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * S&D Phase G, pop-up 63 "Update the saved login": replace the login the hub uses to reach a device.
 * The new username and password go straight to CredentialReferenceManager; the reply only says
 * whether it's working, never what was saved.
 */
final class SavedLoginController extends Controller
{
    public function update(Request $request, Device $device, DeviceSavedLogin $logins): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:1', 'max:128', 'not_regex:/[\x00-\x1f\x7f]/'],
            'password' => ['required', 'string', 'min:1', 'max:512', 'not_regex:/[\x00-\x08\x0a-\x1f\x7f]/'],
        ]);

        $result = $logins->replace($user, $device, (string) $validated['username'], (string) $validated['password']);

        return response()->json($result, 200, ['Cache-Control' => 'no-store']);
    }
}
