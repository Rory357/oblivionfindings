<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StaffBackgroundCheckController extends Controller
{
    public function index(): RedirectResponse { return redirect()->route('hr.vetting.index'); }
    public function userChecks($user): RedirectResponse { return redirect()->route('hr.vetting.index'); }
    public function show($check): RedirectResponse { return redirect()->route('hr.vetting.show', ['check' => $check]); }
    public function create($user): RedirectResponse { return redirect()->route('hr.vetting.create'); }
    public function store(Request $request, $user) { return redirect()->back(); }
    public function edit($check): RedirectResponse { return redirect()->route('hr.vetting.edit', ['check' => $check]); }
    public function update(Request $request, $check) { return redirect()->back(); }
    public function verify(Request $request, $check) { return redirect()->back(); }
    public function assessRisk(Request $request, $check) { return redirect()->back(); }
}
