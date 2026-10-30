<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\System\ServerReadinessService;
use Inertia\Inertia;
use Inertia\Response;

final class ServerReadinessController extends Controller
{
    public function index(ServerReadinessService $readiness): Response
    {
        return Inertia::render('settings/server-readiness', [
            'readiness' => $readiness->evaluate(),
        ]);
    }
}
