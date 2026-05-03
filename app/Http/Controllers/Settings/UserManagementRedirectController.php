<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;

class UserManagementRedirectController extends Controller
{
    public function index()
    {
        return redirect()->route('system.users.index', [], 301);
    }

    public function show(User $target)
    {
        return redirect()->route('system.users.show', ['target' => $target], 301);
    }
}
