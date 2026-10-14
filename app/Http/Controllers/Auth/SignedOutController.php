<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "You've signed out", in the staff or family look (see LogoutResponse). */
class SignedOutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('auth/signed-out', [
            'audience' => $request->query('for') === 'family' ? 'family' : 'staff',
        ]);
    }
}
