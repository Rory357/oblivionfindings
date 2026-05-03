<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\SsoGroupMapping;
use Inertia\Inertia;
use Inertia\Response;

class SsoConfigController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/sso-config', [
            'mappings' => SsoGroupMapping::with('role:id,name,label')->orderBy('provider')->get(),
            'roles' => Role::select('id', 'name', 'label')->orderBy('label')->get(),
            'stats' => [
                'total' => SsoGroupMapping::count(),
                'microsoft' => SsoGroupMapping::where('provider', 'microsoft')->count(),
                'google' => SsoGroupMapping::where('provider', 'google')->count(),
            ],
        ]);
    }
}
