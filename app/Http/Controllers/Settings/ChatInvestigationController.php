<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatInvestigations;
use App\Services\Chat\ChatPreservation;
use Illuminate\Http\Request;

/**
 * Restricted investigator workspace: case access to private staff chats and
 * preservation holds. Every action requires `chat.investigate`; settings
 * administration alone never reaches these routes.
 */
final class ChatInvestigationController extends Controller
{
    public function __construct(private readonly ChatInvestigations $investigations, private readonly ChatPreservation $preservation) {}

    public function index(Request $request)
    {
        $this->investigations->authorizeInvestigator($request->user());

        return inertia('settings/chat-investigations', [
            'grants' => $this->investigations->grants($request->user()),
            'holds' => $this->preservation->holds($request->user()),
            'events' => $this->investigations->events($request->user()),
            'durations' => ChatInvestigations::DURATIONS,
        ]);
    }

    public function overview(Request $request)
    {
        $user = $request->user();

        return $this->json([
            'grants' => $this->investigations->grants($user),
            'holds' => $this->preservation->holds($user),
            'events' => $this->investigations->events($user),
            'durations' => ChatInvestigations::DURATIONS,
        ]);
    }

    public function lookup(Request $request)
    {
        $this->investigations->authorizeInvestigator($request->user());
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return $this->json(['conversations' => $this->investigations->lookup($request->user(), $data['q'], $request->ip())]);
    }

    public function store(Request $request)
    {
        $this->investigations->authorizeInvestigator($request->user());
        $data = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:ops_conversations,id'],
            'case_reference' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'duration_hours' => ['required', 'integer', 'in:'.implode(',', ChatInvestigations::DURATIONS)],
        ], ['reason.min' => 'Describe the reason for access in at least 10 characters.']);
        $id = $this->investigations->grant($request->user(), $data, $request->ip());

        return $this->json(['grant_id' => $id, 'url' => route('settings.chat.investigations.show', $id)], 201);
    }

    public function show(Request $request, int $grant)
    {
        $page = $this->investigations->open($request->user(), $grant, null, $request->ip());

        return inertia('settings/chat-investigation', ['investigation' => $page])->toResponse($request)
            ->header('Cache-Control', 'private, no-store');
    }

    public function messages(Request $request, int $grant)
    {
        $data = $request->validate(['before' => ['required', 'integer', 'min:1']]);

        return $this->json($this->investigations->open($request->user(), $grant, (int) $data['before'], $request->ip()));
    }

    public function end(Request $request, int $grant)
    {
        $this->investigations->revoke($request->user(), $grant, $request->ip());

        return $this->json(['grants' => $this->investigations->grants($request->user())]);
    }

    public function placeHold(Request $request)
    {
        $this->investigations->authorizeInvestigator($request->user());
        $data = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:ops_conversations,id'],
            'case_reference' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'authority_reviewed' => ['required', 'accepted'],
        ], ['reason.min' => 'Describe the reason for the hold in at least 10 characters.', 'authority_reviewed.accepted' => 'Confirm the case authority has been reviewed.']);
        $this->preservation->place($request->user(), $data, $request->ip());

        return $this->json(['holds' => $this->preservation->holds($request->user())], 201);
    }

    public function releaseHold(Request $request, int $hold)
    {
        $this->investigations->authorizeInvestigator($request->user());
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']],
            ['reason.min' => 'Describe why the hold can end in at least 10 characters.']);
        $this->preservation->release($request->user(), $hold, $data['reason'], $request->ip());

        return $this->json(['holds' => $this->preservation->holds($request->user())]);
    }

    private function json(array $body, int $status = 200)
    {
        return response()->json($body, $status)->header('Cache-Control', 'private, no-store');
    }
}
