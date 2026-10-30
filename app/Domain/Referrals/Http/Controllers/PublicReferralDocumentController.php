<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\PublicProviderWebsite;
use App\Domain\Referrals\Services\ReferralDocumentRequests;
use App\Domain\Referrals\Services\ReferralSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class PublicReferralDocumentController extends Controller
{
    public function show(Request $request, string $token, ReferralSettings $settings): Response
    {
        // The public shell deliberately neither resolves nor discloses a referral before code verification.
        $configuration = $settings->effective()?->configuration ?? ReferralSettings::defaults();
        $response = Inertia::render('public-referrals/document', [
            'provider' => app(PublicProviderWebsite::class)->fromConfiguration($configuration), 'token' => $token,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function inspect(Request $request, string $token, ReferralDocumentRequests $requests): JsonResponse
    {
        $data = $request->validate(['passcode' => 'required|string|regex:/\A[0-9]{10}\z/']);

        return $this->privateResponse($requests->inspect($token, $data['passcode']));
    }

    public function store(Request $request, string $token, ReferralDocumentRequests $requests): JsonResponse
    {
        $data = $request->validate(['passcode' => 'required|string|regex:/\A[0-9]{10}\z/', 'file' => 'required|file|max:10240']);

        return $this->privateResponse($requests->receive($token, $data['passcode'], $request->file('file')));
    }

    private function privateResponse(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
