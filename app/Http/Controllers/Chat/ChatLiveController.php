<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\OpsConversation;
use App\Models\User;
use App\Services\Chat\ChatAccess;
use App\Services\Chat\ChatPresence;
use App\Services\Chat\ChatRealtime;
use App\Services\Chat\OblivionLive;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/** Oblivion Live endpoints: connection tickets, typing and the gateway's presence reports. */
final class ChatLiveController extends Controller
{
    public function __construct(private readonly ChatRealtime $realtime, private readonly OblivionLive $live) {}

    /** POST /chat/api/live/ticket (spec section 3). */
    public function ticket(Request $request, ChatPresence $presence)
    {
        abort_unless($this->realtime->usesLive(), 404, 'Live updates are not switched on.');
        abort_unless($this->realtime->eligible($request->user()), 403);
        // Each renewal also keeps the person counted as online.
        $presence->touch((int) $request->user()->id);

        return response()->json($this->live->ticket($request->user(), $request->hasSession() ? $request->session()->getId() : null))
            ->header('Cache-Control', 'private, no-store');
    }

    /** POST /chat/api/conversations/{conversation}/typing (spec section 6). Nothing is stored. */
    public function typing(Request $request, OpsConversation $conversation, ChatAccess $access)
    {
        abort_unless($this->realtime->usesLive(), 404, 'Live updates are not switched on.');
        $data = $request->validate(['state' => ['required', Rule::in(['start', 'stop'])]]);
        $typist = $request->user();
        $access->authorize($typist, $conversation, true);
        // At most once every 3 seconds per person, conversation and state.
        $key = 'chat-typing:'.$typist->id.':'.$conversation->id.':'.$data['state'];
        if (! RateLimiter::attempt($key, 1, fn () => true, 3)) {
            return response()->noContent(429);
        }
        $readers = array_values(array_diff($this->realtime->recipients($conversation), [(int) $typist->id]));
        if ($readers === []) {
            return response()->noContent();
        }
        $visibility = app(OpsMessageVisibilityService::class);
        $typistIsStaff = $visibility->isCurrentStaff($typist);
        $staffReaders = User::whereIn('id', $readers)->get()->filter(fn (User $reader) => $visibility->isCurrentStaff($reader))
            ->map(fn (User $reader) => (int) $reader->id)->values()->all();
        $familyReaders = array_values(array_diff($readers, $staffReaders));
        $event = ['conversation_id' => $conversation->id, 'state' => $data['state']];
        $events = [];
        if ($staffReaders !== []) {
            $events[] = ['to' => $staffReaders, 'type' => 'typing', 'data' => $event + ['who' => $typist->name]];
        }
        if ($familyReaders !== []) {
            // Family never see a staff name here (decision D8).
            $events[] = ['to' => $familyReaders, 'type' => 'typing', 'data' => $event + ['who' => $typistIsStaff ? 'Care team' : $typist->name]];
        }
        $this->live->publishMany($events);

        return response()->noContent();
    }

    /** POST /chat/api/live/presence, from the gateway only (spec section 5). */
    public function presence(Request $request, ChatPresence $presence)
    {
        abort_unless($this->realtime->usesLive(), 404);
        abort_unless($this->live->verifyGatewayRequest($request), 401);
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'state' => ['required', Rule::in(['online', 'offline'])],
            'nonce' => ['nullable', 'string', 'max:64'],
        ]);
        if (User::whereKey($data['user_id'])->exists()) {
            $presence->report((int) $data['user_id'], $data['state']);
        }

        return response()->noContent();
    }
}
