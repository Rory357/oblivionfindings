<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\SecurityDevices\Cctv\Services\CctvAuthority;
use App\Domain\SecurityDevices\Cctv\Services\CctvPublicError;
use App\Domain\SecurityDevices\Cctv\Services\CctvSources;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionSetup;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class CctvSourceController extends Controller
{
    public function show(Request $request, int $device, CctvSources $sources): mixed
    {
        try {
            $data = DB::transaction(function () use ($request, $device, $sources): array {
                $actorId = (int) $request->user()->id;
                $actor = app(CctvAuthority::class)->actor($actorId, true);
                $recorder = Device::query()->whereKey($device)->lock('for share nowait')->value('subcategory') === 'nvr';
                [$camera] = app(CctvAuthority::class)->device($actor, $device, $recorder);
                abort_if($recorder && $camera->provider !== 'hikvision', 404, 'unavailable');

                return $camera->provider === 'hikvision'
                    ? app(HikvisionSetup::class)->page($actorId, $device, $request->query())
                    : $sources->setup($actorId, $device, $request->query());
            });
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        } catch (\Throwable $exception) {
            return CctvPublicError::response($exception);
        }

        return $request->wantsJson() ? response()->json($data)->header('Cache-Control', 'no-store') : Inertia::render('security-devices/cctv/source', $data);
    }

    public function save(Request $request, int $device, CctvSources $sources): JsonResponse
    {
        return $this->json(fn () => $sources->save((int) $request->user()->id, $device, $request->all()));
    }

    public function enable(Request $request, int $device, CctvSources $sources): JsonResponse
    {
        return $this->json(fn () => $sources->action((int) $request->user()->id, $device, $request->all(), true));
    }

    public function disable(Request $request, int $device, CctvSources $sources): JsonResponse
    {
        return $this->json(fn () => $sources->action((int) $request->user()->id, $device, $request->all(), false));
    }

    private function json(callable $action): JsonResponse
    {
        try {
            return response()->json($action())->header('Cache-Control', 'no-store');
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        } catch (\Throwable $exception) {
            return CctvPublicError::response($exception);
        }
    }
}
