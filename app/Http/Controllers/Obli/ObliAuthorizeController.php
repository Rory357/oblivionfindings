<?php

namespace App\Http\Controllers\Obli;

use App\Domain\Obli\ObliSignIn;
use App\Domain\Obli\ObliStaffAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class ObliAuthorizeController
{
    public function show(Request $request, ObliStaffAccess $access)
    {
        $input = ObliSignIn::authorizationInput($request->query());
        DB::transaction(fn () => $access->actor((int) $request->user()->id));

        return Inertia::render('obli/authorize', [
            'authorization' => $input,
            'account' => ['name' => $request->user()->name, 'email' => $request->user()->email],
            'server' => ObliSignIn::issuer(),
        ]);
    }

    public function store(Request $request, ObliSignIn $signIn)
    {
        $input = ObliSignIn::authorizationInput($request->all());
        $decision = $request->validate(['decision' => 'required|in:allow,decline'])['decision'];
        $callback = $decision === 'allow'
            ? $signIn->approve($request->user(), $input)
            : $input['redirect_uri'].'?'.http_build_query([
                'error' => 'access_denied', 'state' => $input['state'], 'iss' => ObliSignIn::issuer(),
            ], '', '&', PHP_QUERY_RFC3986);

        return Inertia::location($callback);
    }
}
