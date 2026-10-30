<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralCareHandoverRevisions;
use App\Domain\Referrals\Services\ReferralConversionService;
use App\Domain\Referrals\Services\ReferralHandoverPreview;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralHandoverController extends Controller
{
    public function carePreview(Request $request, string $reference, ReferralAccess $access, ReferralCareHandoverRevisions $handovers): JsonResponse
    {
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();

        return response()->json($handovers->preview($request->user(), $referral))->header('Cache-Control', 'no-store, private');
    }

    public function publishCare(Request $request, string $reference, ReferralAccess $access, ReferralCareHandoverRevisions $handovers): JsonResponse
    {
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];

        return response()->json($handovers->publish($request->user(), $referral->id, (int) $version, $request->all()))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $reference, ReferralAccess $access, ReferralConversionService $conversion): JsonResponse
    {
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();
        $version = $request->validate(['version' => 'required|integer|min:1'])['version'];

        return response()->json($conversion->convert($request->user(), $referral->id, (int) $version, $request->all()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function preview(Request $request, string $reference, ReferralAccess $access, ReferralHandoverPreview $preview): JsonResponse
    {
        $input = $request->validate(['target_id' => 'nullable|integer|min:1']);
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();

        return response()->json($preview->inspect($request->user(), $referral, isset($input['target_id']) ? (int) $input['target_id'] : null))
            ->header('Cache-Control', 'no-store, private');
    }
}
