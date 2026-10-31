<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralServiceCommencement;
use App\Domain\Referrals\Services\ReferralStartPreview;
use App\Domain\Referrals\Services\ReferralStartReadiness;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralStartController extends Controller
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralStartPreview $preview) {}

    public function show(Request $request, string $reference): JsonResponse
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();

        return $this->json($this->preview->inspect($request->user(), $referral));
    }

    public function check(Request $request, string $reference, ReferralStartReadiness $readiness): JsonResponse
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        [$conversion, $person] = $this->preview->context($request->user(), $referral);
        $selection = $request->validate(['version' => 'required|integer|min:1', ...ReferralServiceCommencement::selectionRules()]);
        abort_unless((int) $selection['version'] === $referral->lock_version, 409, 'The referral changed. Reload before reviewing the start.');
        $result = $readiness->inspect($referral, $conversion, $person, $selection);
        foreach (['agreement_fingerprint', 'care_plan_digest'] as $key) {
            abort_unless(hash_equals($selection[$key], $result[$key]), 409, 'The agreement or care plan changed. Reload and review the current records.');
        }

        return $this->json($result);
    }

    public function update(Request $request, string $reference, string $operation, ReferralServiceCommencement $service): JsonResponse
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        $version = (int) $request->validate(['version' => 'required|integer|min:1'])['version'];
        $result = match ($operation) {
            'schedule' => $service->schedule($request->user(), $referral->id, $version, $request->all()),
            'commence' => $service->commence($request->user(), $referral->id, $version, $request->all()),
            'cancel' => $service->cancel($request->user(), $referral->id, $version, $request->all()),
            default => abort(404),
        };

        return $this->json($result);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
