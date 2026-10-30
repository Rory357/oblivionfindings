<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralFundingCase;
use App\Domain\Referrals\Models\ReferralFundingDecision;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralFunding;
use App\Domain\Referrals\Services\ReferralFundingDecisionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralFundingController extends Controller
{
    public function __construct(private readonly ReferralAccess $access) {}

    public function store(Request $request, string $reference, ReferralFunding $funding): JsonResponse
    {
        $referral = $this->record($request, $reference);
        $case = $funding->open($request->user(), $referral->id, $this->version($request), $request->all());

        return $this->saved(['case_id' => $case->id]);
    }

    public function update(Request $request, string $reference, int $case, string $operation, ReferralFunding $funding, ReferralFundingDecisionService $decisions): JsonResponse
    {
        $referral = $this->record($request, $reference);
        $version = $this->version($request);
        match ($operation) {
            'reassign' => $funding->reassign($request->user(), $referral->id, $version, $case, $request->all()),
            'prepare' => $funding->prepare($request->user(), $referral->id, $version, $case, $request->all()),
            'submission' => $funding->submission($request->user(), $referral->id, $version, $case, $request->all()),
            'progress' => $funding->progress($request->user(), $referral->id, $version, $case, $request->all()),
            'decision' => $decisions->record($request->user(), $referral->id, $version, $case, $request->all()),
            'verification' => $decisions->verify($request->user(), $referral->id, $version, $case, $request->all()),
            default => abort(404),
        };

        return $this->saved();
    }

    private function record(Request $request, string $reference): CareReferral
    {
        $record = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        abort_unless($this->access->can($request->user(), 'funding.view'), 403);

        return $record;
    }

    public function history(Request $request, string $reference, int $case): JsonResponse
    {
        $referral = $this->record($request, $reference);
        ReferralFundingCase::query()->where('referral_id', $referral->id)->findOrFail($case);
        $request->validate(['page' => 'nullable|integer|min:1']);
        $decisions = ReferralFundingDecision::query()->where('funding_case_id', $case)->orderByDesc('version')->paginate(10)->withQueryString()
            ->through(fn ($decision) => ['id' => $decision->id, 'version' => $decision->version, 'outcome' => $decision->outcome, 'terms' => $decision->decision,
                'recorded_by' => $decision->recorded_by, 'recorded_at' => $decision->recorded_at->toISOString()]);

        return response()->json(['decisions' => $decisions])->header('Cache-Control', 'no-store, private');
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['version' => 'required|integer|min:1'])['version'];
    }

    private function saved(array $data = []): JsonResponse
    {
        return response()->json(['saved' => true, ...$data])->header('Cache-Control', 'no-store, private');
    }
}
