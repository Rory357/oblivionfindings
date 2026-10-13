<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\CctvJsonBody;
use App\Domain\SecurityDevices\Cctv\Services\CctvPublicError;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionSetup;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionSources;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HikvisionSourceController extends Controller
{
    public function show(Request $request, int $device, HikvisionSources $sources): JsonResponse
    {
        return $this->json(function () use ($request, $device, $sources): array {
            CctvContract::exact($request->query(), []);

            return $sources->setup((int) $request->user()->id, $device);
        });
    }

    public function setup(Request $request, int $device, HikvisionSetup $setup): JsonResponse
    {
        return $this->json(fn () => $setup->page((int) $request->user()->id, $device, $request->query()));
    }

    public function store(Request $request, int $device, HikvisionSources $sources): JsonResponse
    {
        return $this->json(fn () => $sources->save((int) $request->user()->id, $device, CctvJsonBody::read($request, 8192)));
    }

    public function enable(Request $request, int $device, HikvisionSources $sources): JsonResponse
    {
        return $this->json(fn () => $sources->action((int) $request->user()->id, $device, CctvJsonBody::read($request, 8192), true));
    }

    public function disable(Request $request, int $device, HikvisionSources $sources): JsonResponse
    {
        return $this->json(fn () => $sources->action((int) $request->user()->id, $device, CctvJsonBody::read($request, 8192), false));
    }

    public function review(Request $request, int $device, string $uuid, HikvisionSources $sources): JsonResponse
    {
        return $this->json(function () use ($request, $device, $uuid, $sources): array {
            CctvContract::exact($request->query(), []);

            return $sources->review((int) $request->user()->id, $device, $uuid);
        });
    }

    public function recover(Request $request, int $device, string $key, HikvisionSources $sources): JsonResponse
    {
        return $this->json(function () use ($request, $device, $key, $sources): array {
            CctvContract::exact($request->query(), ['expected_actor_id']);

            return $sources->recover((int) $request->user()->id, $device, $key, $request->query('expected_actor_id'));
        });
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
