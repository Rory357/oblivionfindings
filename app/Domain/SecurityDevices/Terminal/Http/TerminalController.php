<?php

namespace App\Domain\SecurityDevices\Terminal\Http;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Terminal\Models\TerminalSession;
use App\Domain\SecurityDevices\Terminal\TerminalSessions;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * S&D Phase G (D5): the browser side of a device's Terminal tab. Open, type a line, read what came
 * back, close. Only the person who opened a terminal can use it; all checks are in TerminalSessions.
 */
final class TerminalController extends Controller
{
    public function __construct(private readonly TerminalSessions $terminals) {}

    public function open(Request $request, Device $device): JsonResponse
    {
        $session = $this->terminals->open($this->user($request), $device);

        return response()->json(['session' => $session->uuid, 'state' => $session->state], 201)->header('Cache-Control', 'no-store');
    }

    public function input(Request $request, string $session): JsonResponse
    {
        $validated = $request->validate([
            'line' => ['nullable', 'string', 'max:'.TerminalSessions::MAX_LINE],
            'interrupt' => ['nullable', 'boolean'],
        ]);
        $this->terminals->input($this->user($request), $this->find($session), (string) ($validated['line'] ?? ''), (bool) ($validated['interrupt'] ?? false));

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    public function output(Request $request, string $session): JsonResponse
    {
        $after = max(0, (int) $request->query('after', '0'));

        return response()->json($this->terminals->output($this->user($request), $this->find($session), $after))->header('Cache-Control', 'no-store');
    }

    public function close(Request $request, string $session): JsonResponse
    {
        $this->terminals->close($this->user($request), $this->find($session));

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    private function find(string $uuid): TerminalSession
    {
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/i', $uuid) === 1, 404);

        return TerminalSession::query()->where('uuid', strtolower($uuid))->firstOrFail();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
