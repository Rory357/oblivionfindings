<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Services\MonitoringReplayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class MonitoringDeadLetterController extends Controller
{
    public function replay(
        Request $request,
        MonitoringDeadLetter $deadLetter,
        MonitoringReplayService $replay,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $replay->replay($request->user(), $deadLetter, $validated['reason']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'reason' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Monitoring replay queued from the original signed evidence.');
    }

    public function discard(
        Request $request,
        MonitoringDeadLetter $deadLetter,
        MonitoringReplayService $replay,
    ): RedirectResponse {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $replay->discard($request->user(), $deadLetter, $validated['reason']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages([
                'reason' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', 'Monitoring evidence discarded from processing and retained for audit.');
    }
}
