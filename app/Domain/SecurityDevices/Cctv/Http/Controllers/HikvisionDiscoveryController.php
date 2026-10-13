<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Services\CctvContract;
use App\Domain\SecurityDevices\Cctv\Services\CctvJsonBody;
use App\Domain\SecurityDevices\Cctv\Services\CctvPublicError;
use App\Domain\SecurityDevices\Cctv\Services\HikvisionDiscoveryRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HikvisionDiscoveryController extends Controller
{
    public function store(Request $request, int $device, HikvisionDiscoveryRequests $requests): JsonResponse
    {
        return $this->json(fn () => $requests->create((int) $request->user()->id, $device, CctvJsonBody::read($request, 8192)));
    }

    public function show(Request $request, string $uuid, HikvisionDiscoveryRequests $requests): JsonResponse
    {
        return $this->json(function () use ($request, $uuid, $requests) {
            CctvContract::exact($request->query(), []);

            return $requests->read((int) $request->user()->id, $uuid);
        });
    }

    public function recover(Request $request, string $key, HikvisionDiscoveryRequests $requests): JsonResponse
    {
        return $this->json(function () use ($request, $key, $requests) {
            CctvContract::exact($request->query(), ['expected_actor_id']);

            return $requests->recover((int) $request->user()->id, $key, $request->query('expected_actor_id'));
        });
    }

    public function work(Request $request, HikvisionDiscoveryRequests $requests): JsonResponse
    {
        return $this->json(fn () => $requests->work($this->collector($request), CctvJsonBody::read($request, 8192)));
    }

    public function results(Request $request, HikvisionDiscoveryRequests $requests): JsonResponse
    {
        return $this->json(fn () => $requests->results($this->collector($request), CctvJsonBody::read($request, 131072)));
    }

    private function collector(Request $request): MonitoringCollector
    {
        $collector = $request->attributes->get('monitoring_collector');
        abort_unless($collector instanceof MonitoringCollector, 401, 'unavailable');

        return $collector;
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
