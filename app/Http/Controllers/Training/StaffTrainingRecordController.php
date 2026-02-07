<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StaffTrainingRecordController extends Controller
{
    public function index() { return inertia('training/records/index'); }
    public function userTraining($user) { return inertia('training/records/user'); }
    public function show($record) { return inertia('training/records/show'); }
    public function matrix() { return inertia('training/matrix'); }
    public function enrol(Request $request, $user) { return redirect()->back(); }
    public function update(Request $request, $record) { return redirect()->back(); }
    public function markComplete(Request $request, $record) { return redirect()->back(); }
    public function renew(Request $request, $record) { return redirect()->back(); }
    public function exempt(Request $request, $record) { return redirect()->back(); }
}
