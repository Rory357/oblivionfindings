<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Cctv\Services\CctvCollectorBroker;
use App\Domain\SecurityDevices\Cctv\Services\CctvJsonBody;
use App\Domain\SecurityDevices\Cctv\Services\CctvPublicError;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollectorMediaController extends Controller
{
    public function work(Request $request, CctvCollectorBroker $broker): JsonResponse
    {
        return $this->handle($request, fn ($collector) => $broker->work($collector, CctvJsonBody::read($request, 8192)));
    }

    public function results(Request $request, CctvCollectorBroker $broker): JsonResponse
    {
        return $this->handle($request, fn ($collector) => $broker->results($collector, CctvJsonBody::read($request, 262144)));
    }

    private function handle(Request $request, callable $action): JsonResponse
    {
        $collector = $request->attributes->get('monitoring_collector');
        abort_unless($collector instanceof MonitoringCollector, 401, 'unavailable');
        try {
            return response()->json($action($collector))->header('Cache-Control', 'no-store');
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'unavailable'], 404)->header('Cache-Control', 'no-store');
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        } catch (\Throwable $exception) {
            return CctvPublicError::response($exception);
        }
    }
}
