<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\SecurityDevices\Cctv\Services\CctvJsonBody;
use App\Domain\SecurityDevices\Cctv\Services\CctvPublicError;
use App\Domain\SecurityDevices\Cctv\Services\CctvSessions;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class CctvLiveController extends Controller
{
    public function show(Request $request, int $device, CctvSessions $sessions): Response
    {
        return Inertia::render('security-devices/cctv/live', $sessions->page($request, $device));
    }

    public function start(Request $request, int $device, CctvSessions $sessions): JsonResponse
    {
        return $this->json(fn () => $sessions->start($request, $device, CctvJsonBody::read($request, 8192)));
    }

    public function status(Request $request, string $uuid, CctvSessions $sessions): JsonResponse
    {
        return $this->json(fn () => $sessions->control($request, $uuid, 'status', $request->query()));
    }

    public function offer(Request $request, string $uuid, CctvSessions $sessions): JsonResponse
    {
        return $this->json(fn () => $sessions->control($request, $uuid, 'offer', CctvJsonBody::read($request, 65536)));
    }

    public function renew(Request $request, string $uuid, CctvSessions $sessions): JsonResponse
    {
        return $this->json(fn () => $sessions->control($request, $uuid, 'renew', CctvJsonBody::read($request, 4096)));
    }

    public function stop(Request $request, string $uuid, CctvSessions $sessions): JsonResponse
    {
        return $this->json(fn () => $sessions->control($request, $uuid, 'stop', CctvJsonBody::read($request, 4096)));
    }

    private function json(callable $action): JsonResponse
    {
        try {
            return response()->json($action())->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'unavailable'], 404)->header('Cache-Control', 'no-store');
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        } catch (\Throwable $exception) {
            return CctvPublicError::response($exception);
        }
    }
}
