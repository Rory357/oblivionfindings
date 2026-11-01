<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\SecurityDevices\Screens\Devices\BulkActionOptions;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The Devices bulk bar (S&D rebuild C6): which governed actions the ticked devices support. Running
 * one goes through today's command-batch routes (step-up, approval, change reference, typed count
 * and every check there stay as they are).
 */
final class BulkActionsController extends Controller
{
    public function options(Request $request, BulkActionOptions $options): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && BulkActionOptions::allowed($user), 403);
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:2', 'max:'.BulkActionOptions::MAX_DEVICES],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], [
            'ids.min' => 'Tick at least two devices.',
            'ids.max' => 'Tick up to '.BulkActionOptions::MAX_DEVICES.' devices at a time.',
        ]);

        return response()->json(
            $options->present($user, array_values(array_map('intval', $validated['ids']))),
        )->header('Cache-Control', 'private, no-store');
    }
}
