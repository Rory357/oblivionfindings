<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class TrainingRecordController extends Controller
{
    public function index() { return inertia('training/records/index'); }
    public function userRecords($user) { return inertia('training/records/user'); }
    public function show($record) { return inertia('training/records/show'); }
    public function store(Request $request, $user) { return redirect()->back(); }
    public function update(Request $request, $record) { return redirect()->back(); }
    public function complete(Request $request, $record) { return redirect()->back(); }
}
