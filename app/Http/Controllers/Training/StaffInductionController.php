<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StaffInductionController extends Controller
{
    public function show($user) { return inertia('training/inductions/show'); }
    public function create(Request $request, $user) { return redirect()->back(); }
    public function update(Request $request, $induction) { return redirect()->back(); }
    public function complete(Request $request, $induction) { return redirect()->back(); }
}
