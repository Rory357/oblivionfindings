<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Models\CareReferral;
use App\Domain\Referrals\Models\ReferralDocumentRequest;
use App\Domain\Referrals\Services\ReferralAccess;
use App\Domain\Referrals\Services\ReferralDocumentRequests;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReferralDocumentRequestController extends Controller
{
    public function __construct(private readonly ReferralAccess $access, private readonly ReferralDocumentRequests $requests) {}

    private function referral(Request $request, string $reference): CareReferral
    {
        return $this->access->query($request->user())->where('reference', $reference)->firstOrFail();
    }

    public function index(Request $request, string $reference): JsonResponse
    {
        $referral = $this->referral($request, $reference);
        $data = $request->validate(['audience' => 'required|in:assessment,funding', 'page' => 'nullable|integer|min:1']);
        abort_unless($this->access->audience($request->user(), $data['audience']), 403);
        $rows = ReferralDocumentRequest::query()->where('referral_id', $referral->id)->where('audience', $data['audience'])
            ->orderByDesc('id')->paginate(10)->through(fn ($row) => $this->requests->summary($row, $referral));

        return response()->json(['requests' => $rows, 'configuration' => $this->requests->configuration($referral),
            'can_issue' => $this->requests->canIssue($request->user(), $referral, $data['audience'])])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1']);
        $issued = $this->requests->issue($request->user(), $this->referral($request, $reference), $data['version'], $request->all());

        return response()->json(['saved' => true, 'request' => $issued], 201)->header('Cache-Control', 'no-store, private');
    }

    public function revoke(Request $request, string $reference, int $documentRequest): JsonResponse
    {
        $data = $request->validate(['version' => 'required|integer|min:1', 'reason' => 'required|string|min:5|max:2000']);
        $this->requests->revoke($request->user(), $this->referral($request, $reference), $data['version'], $documentRequest, $data['reason']);

        return response()->json(['saved' => true])->header('Cache-Control', 'no-store, private');
    }
}
