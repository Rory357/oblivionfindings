<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffInductionController extends Controller
{
    public function show($user): RedirectResponse { return redirect()->route('hr.onboarding.index'); }
    public function create(Request $request, $user) { return redirect()->back(); }
    public function update(Request $request, $induction) { return redirect()->back(); }
    public function complete(Request $request, $induction) { return redirect()->back(); }
}
