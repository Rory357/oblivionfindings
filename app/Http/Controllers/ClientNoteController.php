<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientNote;
use Illuminate\Http\Request;

class ClientNoteController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Can only add notes if you can view the client
        $this->authorize('view', $client);
        abort_unless($user->canDo('timeline.create'), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2'],
        ]);

        ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $user->id,
            'body' => $data['body'],
        ]);

        return back()->with('status', 'Note added.');
    }
}
