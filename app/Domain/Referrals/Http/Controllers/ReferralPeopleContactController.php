<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralPeopleContacts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralPeopleContactController extends Controller
{
    public function store(Request $request, string $reference, ReferralAccess $access, ReferralPeopleContacts $contacts): JsonResponse
    {
        $referral = $access->query($request->user())->where('reference', $reference)->firstOrFail();
        $data = $request->validate(['version' => 'required|integer|min:1']);

        return response()->json($contacts->save($request->user(), $referral->id, (int) $data['version'], $request->all()))->header('Cache-Control', 'no-store, private');
    }
}
