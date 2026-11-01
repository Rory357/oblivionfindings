<?php

namespace App\Domain\SecurityDevices\Hubs\Http\Controllers;

use App\Domain\SecurityDevices\Hubs\Services\SetupCodeRefused;
use App\Domain\SecurityDevices\Hubs\Services\SetupCodeService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The installer's first call: it read "[oblivionfindings.com T6WD-3KQN]" from its own file
 * name (or the person typed the 8 characters) and swaps the code for its setup profile.
 * HTTPS only; throttled by the route; the hub still waits for an admin's approval.
 */
final class HubSetupRedeemController extends Controller
{
    public function __invoke(Request $request, SetupCodeService $codes): JsonResponse
    {
        abort_unless($request->isSecure() || app()->environment(['local', 'testing']), 400);
        $code = $request->input('code');
        $computerName = $request->input('computer_name');
        if (! is_string($code) || strlen($code) > 32 || ($computerName !== null && (! is_string($computerName) || strlen($computerName) > 63))) {
            return response()->json(['state' => 'unknown', 'message' => (new SetupCodeRefused('unknown'))->getMessage()], 422, $this->noStore());
        }
        try {
            $result = $codes->redeem($code, $computerName);
        } catch (SetupCodeRefused $refused) {
            return response()->json(['state' => $refused->reason, 'message' => $refused->getMessage()], $refused->reason === 'unknown' ? 422 : 410, $this->noStore());
        }

        // role: hub | computer. install_mode (computers): staff = Obli for this person, no admin;
        // full = IT's install with the service.
        return response()->json(['state' => 'ok', ...$result], 200, $this->noStore());
    }

    /** @return array<string, string> */
    private function noStore(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
    }
}
