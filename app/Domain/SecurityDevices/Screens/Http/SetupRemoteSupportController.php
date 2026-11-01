<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Screens\Setup\SetupRemoteSupport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** S&D Phase G (pg-sdr-15k): turn remote support to staff computers on or off from Setup. */
final class SetupRemoteSupportController extends Controller
{
    public function update(Request $request, SetupRemoteSupport $remote): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && SetupRemoteSupport::canSee($user), 403);
        $data = $request->validate(['allowed' => ['required', 'boolean']]);
        $remote->setAllowed($user, (bool) $data['allowed']);

        return response()->json(['message' => $data['allowed'] ? 'Remote support is on.' : 'Remote support is off.']);
    }
}
