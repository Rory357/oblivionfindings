<?php

namespace App\Domain\SecurityDevices\Screens\Http;

use App\Domain\Monitoring\Exceptions\SnapshotStoreUnavailable;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Screens\Devices\Pages\SettingsChangedPresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * S&D Phase G, pop-up 24 "Settings changed": a backup beside the one before it, and both as one
 * text file. Every check (the device's Site, the copy's Site, the snapshot permission, the stored
 * copy's integrity) is in the presenter and the snapshot service it reads through.
 */
final class SettingsBackupsController extends Controller
{
    private const array PRIVATE = ['Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff'];

    public function changes(Request $request, Device $device, ConfigurationSnapshot $snapshot, SettingsChangedPresenter $presenter): JsonResponse
    {
        try {
            return response()->json($presenter->present($this->viewer($request), $device, $snapshot), 200, self::PRIVATE);
        } catch (SnapshotStoreUnavailable) {
            return response()->json(['message' => 'A stored copy of its settings can’t be read right now. Try again later.'], 409, self::PRIVATE);
        }
    }

    public function download(Request $request, Device $device, ConfigurationSnapshot $snapshot, SettingsChangedPresenter $presenter): Response
    {
        try {
            $file = $presenter->download($this->viewer($request), $device, $snapshot);
        } catch (SnapshotStoreUnavailable) {
            abort(409, 'A stored copy of its settings can’t be read right now.');
        }

        return response($file['body'], 200, [
            ...self::PRIVATE,
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $file['name']),
        ]);
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
