<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StaffCompetencyController extends Controller
{
    public function userCompetency($user) { return inertia('training/competencies/user'); }
    public function assess(Request $request, $user) { return redirect()->back(); }
    public function updateAssessment(Request $request, $assessment) { return redirect()->back(); }
}
