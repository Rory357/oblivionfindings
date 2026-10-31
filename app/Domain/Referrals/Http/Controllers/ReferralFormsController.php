<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralForms;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralFormsController extends Controller
{
    public function update(Request $request, string $reference, ReferralAccess $access, ReferralForms $forms): JsonResponse
    {
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $forms->save($request->user(), $referral->id, (int) $data['version'], $request->all());

        return response()->json(['saved' => true])->header('Cache-Control', 'no-store, private');
    }
}
