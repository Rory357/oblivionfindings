<?php

namespace App\Domain\SecurityDevices\Cctv\Http\Controllers;

use App\Domain\SecurityDevices\Cctv\Services\CctvRecordingReferences;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class CctvRecordingReferenceController extends Controller
{
    public function index(Request $request, int $device, CctvRecordingReferences $references)
    {
        return $this->respond($request, fn () => $references->page($request, $device), 'security-devices/cctv/recording-references');
    }

    public function store(Request $request, int $device, CctvRecordingReferences $references)
    {
        return $this->respond($request, fn () => $references->create($request, $device, $request->all()));
    }

    public function show(Request $request, string $uuid, CctvRecordingReferences $references)
    {
        return $this->respond($request, fn () => $references->resolve($request, $uuid), 'security-devices/cctv/recording-reference');
    }

    public function recover(Request $request, string $key, CctvRecordingReferences $references)
    {
        return $this->respond($request, fn () => $references->recover($request, $key));
    }

    private function respond(Request $request, callable $action, ?string $page = null)
    {
        try {
            $data = $action();

            return $page && ! $request->expectsJson() ? Inertia::render($page, $data) : response()->json($data)->header('Cache-Control', 'no-store');
        } catch (\InvalidArgumentException) {
            return response()->json(['message' => 'invalid_request'], 400)->header('Cache-Control', 'no-store');
        }
    }
}
