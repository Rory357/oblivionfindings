<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralChecklists;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralChecklistController extends Controller
{
    public function update(Request $request, string $reference, ReferralAccess $access, ReferralChecklists $checklists): JsonResponse
    {
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $checklists->record($request->user(), $referral->id, (int) $data['version'], $request->all());

        return response()->json(['saved' => true])->header('Cache-Control', 'no-store, private');
    }
}
