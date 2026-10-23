<?php

namespace App\Domain\Referrals\Http\Controllers;

use App\Domain\Referrals\Services\PublicReferralIntake;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Contact us: the message becomes a general enquiry in referral intake (never sent by email). */
final class PublicContactController extends Controller
{
    public function store(Request $request, PublicReferralIntake $intake): Response
    {
        // An earlier cached form has no submission token: it is still told plainly that nothing was sent.
        if (! $request->has('submission_token')) {
            return app(PublicProviderController::class)->retiredContact($request);
        }
        abort_if(strlen($request->getContent()) > 16384, 413, 'Please shorten your message before sending it.');
        $receipt = $intake->submitContact($request->except('_token'));

        return response()->json($receipt, 201)->header('Cache-Control', 'no-store, private');
    }
}
