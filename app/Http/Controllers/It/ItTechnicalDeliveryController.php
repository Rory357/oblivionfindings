<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItTechnicalDeliveryRecoveryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItTechnicalDeliveryController extends Controller
{
    public function __invoke(Request $request, string $source, int $delivery, ItTechnicalDeliveryRecoveryService $recovery): JsonResponse
    {
        $data = $request->validate([
            'viewer_user_id' => ['required', 'integer', 'min:1'],
            ...($request->isMethod('post') ? ['version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']] : []),
        ]);
        $result = $request->isMethod('post')
            ? $recovery->retry($request->user(), (int) $data['viewer_user_id'], $source, $delivery, $data['version'])
            : $recovery->review($request->user(), (int) $data['viewer_user_id'], $source, $delivery);

        return response()->json(['data' => $result], 200, ['Cache-Control' => 'no-store, private']);
    }
}
