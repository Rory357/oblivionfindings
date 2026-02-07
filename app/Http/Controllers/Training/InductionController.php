<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InductionController extends Controller
{
    public function index() { return inertia('training/inductions/index'); }
    public function show($induction) { return inertia('training/inductions/show'); }
    public function store(Request $request) { return redirect()->back(); }
    public function complete(Request $request, $induction) { return redirect()->back(); }
}
