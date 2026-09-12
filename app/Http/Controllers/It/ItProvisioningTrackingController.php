<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItProvisioningTrackingService;
use App\Http\Controllers\Controller;
use App\Models\ItProvisioningRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class ItProvisioningTrackingController extends Controller
{
    public function __invoke(Request $request, ItProvisioningRequest $provisioning, ItProvisioningTrackingService $tracking)
    {
        return Inertia::render('it/provisioning/show', [
            'request' => $tracking->detail($request->user(), $provisioning),
        ])->toResponse($request)->header('Cache-Control', 'no-store, private');
    }
}
