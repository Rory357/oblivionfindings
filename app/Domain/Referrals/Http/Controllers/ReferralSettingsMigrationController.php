<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralSettingsMigration;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralSettingsMigrationController extends Controller
{
    public function show(Request $request, string $reference, ReferralAccess $access, ReferralSettingsMigration $migration): JsonResponse
    {
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();

        return response()->json($migration->preview($request->user(), $referral))->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $reference, ReferralAccess $access, ReferralSettingsMigration $migration): JsonResponse
    {
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();
        $input = $request->validate(['version' => 'required|integer|min:1']);

        return response()->json($migration->apply($request->user(), $referral->id, (int) $input['version'], $request->all()))->header('Cache-Control', 'no-store, private');
    }
}
