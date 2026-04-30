<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompetencyFrameworkController extends Controller
{
    public function index(): RedirectResponse { return redirect()->route('hr.competencies.index'); }
    public function show($framework): RedirectResponse { return redirect()->route('hr.competencies.index'); }
    public function create(): RedirectResponse { return redirect()->route('hr.competencies.index'); }
    public function store(Request $request) { return redirect()->back(); }
    public function edit($framework): RedirectResponse { return redirect()->route('hr.competencies.index'); }
    public function update(Request $request, $framework) { return redirect()->back(); }
}
