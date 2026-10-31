<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralServiceAgreements;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The draft service agreement made from the approved and accepted support proposal. */
final class ReferralServiceAgreementController extends Controller
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralServiceAgreements $agreements) {}

    public function show(Request $request, string $reference): JsonResponse
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        abort_unless($this->access->can($request->user(), 'funding.view'), 403);

        return $this->json($this->agreements->inspect($request->user(), $referral));
    }

    public function store(Request $request, string $reference): JsonResponse
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        abort_unless($this->access->can($request->user(), 'funding.view'), 403);
        $version = (int) $request->validate(['version' => 'required|integer|min:1'])['version'];

        return $this->json($this->agreements->create($request->user(), $referral->id, $version, $request->all()));
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
