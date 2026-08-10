<?php

namespace App\Domain\SecurityDevices\Management\Http\Controllers;

use App\Domain\SecurityDevices\Management\Enums\BreakGlassReviewOutcome;
use App\Domain\SecurityDevices\Management\Http\Requests\ReviewDeviceCommandBreakGlassRequest;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandBreakGlassService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

final class DeviceCommandBreakGlassReviewController extends Controller
{
    public function __invoke(
        ReviewDeviceCommandBreakGlassRequest $request,
        DeviceCommandRequest $command,
        DeviceCommandBreakGlassService $breakGlass,
    ): RedirectResponse {
        $validated = $request->validated();
        $breakGlass->review(
            $command,
            $request->user(),
            BreakGlassReviewOutcome::from($validated['outcome']),
            $validated['summary'],
        );

        return back()->with('success', 'The post-use break-glass review was recorded permanently.');
    }
}
