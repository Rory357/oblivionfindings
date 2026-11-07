<?php

namespace App\Domain\Obli\Fixes\Http;

use App\Domain\Obli\Fixes\ObliFixList;
use App\Domain\Obli\Fixes\ObliFixRequests;
use App\Domain\Obli\Fixes\ObliFixSettings;
use App\Domain\Obli\Fixes\ObliScreenPictures;
use App\Domain\Obli\Fixes\Screens\ObliHelpTab;
use App\Domain\Obli\Fixes\Screens\SetupObli;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * S&D Phase I, IT's side: a help session's record (pop-up 96), opening its pictures with a reason
 * (pop-up 94), a one-off request (pop-up 95), turning a fix on for one computer (pop-up 93) or off,
 * and the two switches on Setup › Computers & Obli › Obli (15i). Each service checks the permission
 * and the computer's Site again; answers are no-store.
 */
final class ObliFixWebController extends Controller
{
    public function session(Request $request, string $uuid, ObliHelpTab $tab): JsonResponse
    {
        return $this->json($tab->record($this->viewer($request), $uuid));
    }

    public function openPictures(Request $request, string $uuid, ObliScreenPictures $pictures): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->json($pictures->open($this->viewer($request), $uuid, (string) $data['reason']));
    }

    public function picture(Request $request, int $picture, ObliScreenPictures $pictures): Response
    {
        $view = $request->query('view');
        abort_unless(is_string($view) && ctype_digit($view), 404);

        return $pictures->serve($this->viewer($request), $picture, (int) $view);
    }

    public function request(Request $request, int $id, ObliFixRequests $requests): JsonResponse
    {
        return $this->json($requests->panel($this->viewer($request), $id));
    }

    public function decide(Request $request, int $id, ObliFixRequests $requests): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,decline'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->json($requests->decide($this->viewer($request), $id, (string) $data['decision'], $data['note'] ?? null));
    }

    public function turnOn(Request $request, ObliFixList $fixes): JsonResponse
    {
        $data = $request->validate([
            'fix_key' => ['required', 'string', 'max:260'], 'device_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'], 'read' => ['required', 'accepted'],
        ]);
        $device = Device::query()->findOrFail((int) $data['device_id']);
        $fixes->turnOn($this->viewer($request), $device, (string) $data['fix_key'], trim((string) $data['reason']));

        return $this->json(['message' => 'Turned on for '.$device->name.'.']);
    }

    public function turnOff(Request $request, ObliFixList $fixes): JsonResponse
    {
        $data = $request->validate(['fix_key' => ['required', 'string', 'max:260'], 'device_id' => ['required', 'integer', 'min:1']]);
        $device = Device::query()->findOrFail((int) $data['device_id']);
        $fixes->turnOff($this->viewer($request), $device, (string) $data['fix_key']);

        return $this->json(['message' => 'Turned off for '.$device->name.'.']);
    }

    public function fixesSwitch(Request $request, ObliFixSettings $settings): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'expected_version' => ['required', 'integer', 'min:0']]);
        $after = $settings->save($this->viewer($request), (bool) $data['enabled'], (int) $data['expected_version']);

        return $this->json(['enabled' => $after['enabled'], 'version' => $after['version']]);
    }

    public function helperSwitch(Request $request, SetupObli $setup): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $setup->setHelper($this->viewer($request), (bool) $data['enabled']);

        return $this->json(['message' => $data['enabled'] ? 'Obli is on for staff computers.' : 'Obli is off for staff computers.']);
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function json(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Cache-Control' => 'no-store, private']);
    }
}
