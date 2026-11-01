<?php

namespace App\Domain\SecurityDevices\Hubs\Http\Controllers;

use App\Domain\SecurityDevices\Hubs\Models\SetupCode;
use App\Domain\SecurityDevices\Hubs\Services\SetupCodeRefused;
use App\Domain\SecurityDevices\Hubs\Services\SetupCodeService;
use App\Support\OrgBranding;
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
        // full = IT's install with the service. display: what the Welcome screen shows (windows
        // tab screen 3): the Site, who made the download, until when, and the brand.
        return response()->json(['state' => 'ok', ...$result, 'display' => $this->display($codes->find($code))], 200, $this->noStore());
    }

    /** @return array<string, string|null> */
    private function display(?SetupCode $record): array
    {
        $record?->loadMissing(['site', 'issuedBy']);
        $site = $record?->site;
        $address = $site === null ? null : collect([$site->address_line_1, $site->suburb, $site->city])
            ->filter(fn ($part) => is_string($part) && trim($part) !== '')->implode(', ');

        return [
            'brand_name' => OrgBranding::name(),
            'logo_url' => OrgBranding::logoUrl(),
            'site_name' => $site?->name,
            'site_address' => $address === '' ? null : $address,
            'made_by' => $record?->issuedBy?->name,
            'made_at' => $record?->created_at?->toIso8601String(),
            'expires_at' => $record?->expires_at?->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    private function noStore(): array
    {
        return ['Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache', 'X-Content-Type-Options' => 'nosniff'];
    }
}
