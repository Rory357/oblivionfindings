<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class StaffBackgroundCheckController extends Controller
{
    public function index() { return inertia('training/background-checks/index'); }
    public function userChecks($user) { return inertia('training/background-checks/user'); }
    public function show($check) { return inertia('training/background-checks/show'); }
    public function create($user) { return inertia('training/background-checks/create'); }
    public function store(Request $request, $user) { return redirect()->back(); }
    public function edit($check) { return inertia('training/background-checks/edit'); }
    public function update(Request $request, $check) { return redirect()->back(); }
    public function verify(Request $request, $check) { return redirect()->back(); }
    public function assessRisk(Request $request, $check) { return redirect()->back(); }
}
