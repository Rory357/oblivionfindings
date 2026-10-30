<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Chat\ChatSettings;
use App\Services\Chat\ChatUrgentContact;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use Illuminate\Http\Request;

final class ChatUrgentContactController extends Controller
{
    public function __invoke(Request $request, Client $client, ChatSettings $settings, ChatUrgentContact $contacts, ClientFamilyCommunicationAccess $access)
    {
        $actor = $request->user();
        abort_unless($actor->canAccessClientPortal($client) || $access->canView($actor, $client), 403);

        return response()->json(['actor_id' => (int) $actor->id, 'client_id' => (int) $client->id,
            'contact' => $contacts->publicContact($settings->read($client->site_id)['urgent_contact'])])
            ->header('Cache-Control', 'private, no-store');
    }
}
