<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CompetencyAssessmentController extends Controller
{
    public function index() { return inertia('training/competencies/index'); }
    public function show($assessment) { return inertia('training/competencies/show'); }
    public function store(Request $request) { return redirect()->back(); }
    public function conduct(Request $request, $assessment) { return redirect()->back(); }
    public function update(Request $request, $assessment) { return redirect()->back(); }
}
