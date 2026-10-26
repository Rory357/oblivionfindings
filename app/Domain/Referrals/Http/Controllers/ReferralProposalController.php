<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Finance\Models\FinTaxRate;
use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Models\ReferralProposalReview;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralProposalReadiness;
use App\Domain\Referrals\Services\ReferralProposals;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralProposalController extends Controller
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralProposals $proposals) {}

    private function record(Request $request, string $reference): CareReferral
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        abort_unless($this->access->can($request->user(), 'funding.view'), 403);

        return $referral;
    }

    public function index(Request $request, string $reference): JsonResponse
    {
        $referral = $this->record($request, $reference);
        $request->validate(['page' => 'nullable|integer|min:1']);
        $latest = $this->proposals->latest($referral);
        $history = ReferralProposal::query()->where('referral_id', $referral->id)->orderByDesc('version')->paginate(10)->withQueryString()
            ->through(fn ($proposal) => ['id' => $proposal->id, 'version' => $proposal->version, 'authored_by' => $proposal->authored_by, 'authored_at' => $proposal->authored_at->toISOString(), 'terms' => $proposal->proposal]);

        return $this->json(['proposals' => $history, 'latest_id' => $latest?->id,
            'current' => $latest ? ['id' => $latest->id, 'version' => $latest->version, 'authored_by' => $latest->authored_by, 'authored_at' => $latest->authored_at->toISOString(), 'terms' => $latest->proposal] : null,
            'readiness' => $latest ? app(ReferralProposalReadiness::class)->inspect($referral, $latest) : null,
            'tax_rates' => FinTaxRate::query()->where('is_active', true)->whereIn('type', ['gst', 'zero_rated', 'exempt'])->orderBy('name')->get(['id', 'name', 'code', 'rate', 'type'])]);
    }

    public function reviews(Request $request, string $reference, int $proposal): JsonResponse
    {
        $referral = $this->record($request, $reference);
        ReferralProposal::query()->where('referral_id', $referral->id)->findOrFail($proposal);
        $request->validate(['page' => 'nullable|integer|min:1']);
        $reviews = ReferralProposalReview::query()->where('proposal_id', $proposal)->orderByDesc('id')->paginate(15)->withQueryString()->through(fn ($review) => [
            'id' => $review->id, 'kind' => $review->kind, 'result' => $review->result, 'funding_case_id' => $review->funding_case_id,
            'details' => $review->details, 'recorded_by' => $review->recorded_by, 'recorded_at' => $review->recorded_at->toISOString(),
        ]);

        return $this->json(['reviews' => $reviews]);
    }

    public function store(Request $request, string $reference): JsonResponse
    {
        $referral = $this->record($request, $reference);
        $proposal = $this->proposals->save($request->user(), $referral->id, $this->version($request), $request->all());

        return $this->json(['saved' => true, 'proposal_id' => $proposal->id, 'proposal_version' => $proposal->version]);
    }

    public function review(Request $request, string $reference, int $proposal, string $operation): JsonResponse
    {
        $referral = $this->record($request, $reference);
        match ($operation) {
            'acceptance' => $this->proposals->accept($request->user(), $referral->id, $this->version($request), $proposal, $request->all()),
            'approval' => $this->proposals->approve($request->user(), $referral->id, $this->version($request), $proposal, $request->all()),
            default => abort(404),
        };

        return $this->json(['saved' => true]);
    }

    private function version(Request $request): int
    {
        return (int) $request->validate(['version' => 'required|integer|min:1'])['version'];
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
