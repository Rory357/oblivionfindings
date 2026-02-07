<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CompetencyFrameworkController extends Controller
{
    public function index() { return inertia('training/competencies/frameworks'); }
    public function show($framework) { return inertia('training/competencies/show'); }
    public function create() { return inertia('training/competencies/create'); }
    public function store(Request $request) { return redirect()->back(); }
    public function edit($framework) { return inertia('training/competencies/edit'); }
    public function update(Request $request, $framework) { return redirect()->back(); }
}
