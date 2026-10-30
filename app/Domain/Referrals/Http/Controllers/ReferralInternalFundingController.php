<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralEvent;
use App\Domain\Referrals\Models\ReferralProposal;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralInternalFunding;
use App\Domain\Referrals\Services\ReferralProposals;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralInternalFundingController extends Controller
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralInternalFunding $funding) {}

    private function record(Request $request, string $reference): CareReferral
    {
        $referral = $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
        $this->access->authorize($request->user(), $referral, 'funding.view');

        return $referral;
    }

    public function show(Request $request, string $reference, int $proposal): JsonResponse
    {
        $referral = $this->record($request, $reference);
        // Historical proposals remain inspectable through their immutable arrangement and decision history.
        $record = ReferralProposal::where('referral_id', $referral->id)->findOrFail($proposal);
        $arrangement = $this->funding->arrangement($record);
        $history = $arrangement ? ReferralEvent::with('actor:id,name')->where('referral_id', $referral->id)->where('kind', 'internal_funding.reviewed')->orderByDesc('id')->get()
            ->filter(fn ($event) => (int) ($event->payload['arrangement_id'] ?? 0) === (int) $arrangement->id)
            ->map(fn ($event) => ['id' => $event->id, 'actor_id' => $event->actor_id, 'actor_name' => $event->actor?->name ?? 'Former staff member', 'occurred_at' => $event->occurred_at->toISOString(), 'details' => $event->payload])->values() : [];

        return $this->json(['enabled' => $this->funding->enabled(), 'current' => app(ReferralProposals::class)->latest($referral)?->id === $record->id,
            'gap' => $this->funding->gap($referral, $record), 'arrangement' => $arrangement ? ['id' => $arrangement->id, 'authored_by' => $arrangement->authored_by,
                'authored_at' => $arrangement->authored_at->toISOString(), 'terms' => $arrangement->terms, 'availability' => $this->funding->availability($referral, $arrangement)] : null,
            'history' => $history]);
    }

    public function store(Request $request, string $reference, int $proposal): JsonResponse
    {
        $referral = $this->record($request, $reference);
        $version = (int) $request->validate(['version' => 'required|integer|min:1'])['version'];
        $arrangement = $this->funding->prepare($request->user(), $referral->id, $version, $proposal, $request->all());

        return $this->json(['saved' => true, 'arrangement_id' => $arrangement->id]);
    }

    public function review(Request $request, string $reference, int $arrangement): JsonResponse
    {
        $referral = $this->record($request, $reference);
        $version = (int) $request->validate(['version' => 'required|integer|min:1'])['version'];
        $this->funding->decide($request->user(), $referral->id, $version, $arrangement, $request->all());

        return $this->json(['saved' => true]);
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private');
    }
}
