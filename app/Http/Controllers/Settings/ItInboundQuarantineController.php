<?php

namespace App\Http\Controllers\Settings;

use App\Domain\It\Services\ItInboundQuarantineReview;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ItInboundQuarantineController extends Controller
{
    public function index(Request $request, string $provider, ItInboundQuarantineReview $review): JsonResponse
    {
        $input = $request->validate(['connection_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'], 'before_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json($review->listing($request, $provider, $this->integers($input)));
    }

    public function retry(Request $request, string $provider, int $receipt, ItInboundQuarantineReview $review): JsonResponse
    {
        $input = $request->validate(['connection_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:1'], 'expected_review_version' => ['required', 'integer', 'min:0', 'max:4294967293']]);

        return response()->json($review->retry($request, $provider, $receipt, $this->integers($input)));
    }

    private function integers(array $input): array
    {
        return array_map(fn ($value) => $value === null ? null : (int) $value, $input);
    }
}
