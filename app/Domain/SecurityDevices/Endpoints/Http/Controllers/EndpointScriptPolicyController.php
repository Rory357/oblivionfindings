<?php

namespace App\Domain\SecurityDevices\Endpoints\Http\Controllers;

use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptContract;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointScriptPolicy;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EndpointScriptPolicyController extends Controller
{
    public function show(Request $request, Device $device, EndpointScriptPolicy $policy): JsonResponse
    {
        return $this->reply($policy->show($request->user(), $device, $this->actor($request)));
    }

    public function update(Request $request, Device $device, EndpointScriptPolicy $policy): JsonResponse
    {
        abort_unless($request->isSecure() && (string) $request->server('QUERY_STRING', '') === '', 400);
        abort_unless(strtolower(trim(explode(';', (string) $request->header('Content-Type'))[0])) === 'application/json', 415);
        try {
            $input = get_object_vars(EndpointScriptContract::decode($request->getContent(), 8192));
        } catch (InvalidArgumentException) {
            abort(422, 'Invalid script policy request.');
        }

        return $this->reply($policy->save($request->user(), $device, $input));
    }

    public function recovery(Request $request, Device $device, string $request_key, EndpointScriptPolicy $policy): JsonResponse
    {
        return $this->reply($policy->recover($request->user(), $device, $this->actor($request), $request_key));
    }

    public function confirmIdentity(Request $request, Device $device, EndpointScriptPolicy $policy)
    {
        $policy->beginIdentity($request, $request->user(), $device, $this->actor($request));

        return redirect()->route('password.confirm')->header('Cache-Control', 'private, no-store');
    }

    private function actor(Request $request): int
    {
        abort_unless($request->isSecure() && $request->getContent() === '', 400);
        // One exact key: duplicates, arrays, unknown keys and alternate numeric forms are rejected.
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/\Aexpected_actor_id=([1-9][0-9]{0,15})\z/', $raw, $match) && (int) $match[1] <= 9007199254740991, 400);

        return (int) $match[1];
    }

    private function reply(array $dto): JsonResponse
    {
        return response()->json($dto)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
