<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TrainingCourseController extends Controller
{
    public function index() { return inertia('training/courses/index'); }
    public function show($course) { return inertia('training/courses/show'); }
    public function store(Request $request) { return redirect()->back(); }
    public function update(Request $request, $course) { return redirect()->back(); }
    public function destroy($course) { return redirect()->back(); }
}
