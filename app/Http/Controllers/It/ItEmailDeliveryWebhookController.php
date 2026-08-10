<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItEmailDeliveryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\UpdateItEmailDeliveryStatusRequest;
use Illuminate\Http\JsonResponse;

class ItEmailDeliveryWebhookController extends Controller
{
    public function __invoke(
        UpdateItEmailDeliveryStatusRequest $request,
        ItEmailDeliveryService $deliveries,
    ): JsonResponse {
        $data = $request->validated();
        $delivery = $deliveries->recordProviderStatus(
            $data['notification_id'],
            $data['status'],
            $data['error'] ?? null,
            $data['provider_message_id'] ?? null,
            $data['occurred_at'] ?? null,
        );

        return response()->json([
            'status' => $delivery->status,
            'notification_id' => $delivery->notification_uuid,
        ]);
    }
}
