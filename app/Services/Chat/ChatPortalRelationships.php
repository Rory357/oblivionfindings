<?php

namespace App\Services\Chat;

use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\Site;
use App\Models\User;
use App\Services\Clients\ClientFamilyCommunicationAccess;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Gate;

/**
 * A read-only view of who may contact a client through Chat and under which
 * effective policy. Relationships stay in the client portal administration
 * (portal users, next of kin, care-team assignments); this only reads them
 * and links there, so Chat never becomes a second relationship store.
 */
final class ChatPortalRelationships
{
    public const COVER_LIMIT = 30;

    public function __construct(
        private readonly ChatFamilyContacts $contacts,
        private readonly ClientFamilyCommunicationAccess $family,
        private readonly ChatSettings $settings,
        private readonly UserSiteAccessService $sites,
    ) {}

    public function authorize(?User $actor, ?Client $client = null): void
    {
        abort_unless($actor?->canDo('settings.access.manage'), 403);
        if ($client) {
            abort_unless(Gate::forUser($actor)->allows('view', $client), 403);
        }
    }

    public function search(User $actor, string $query): array
    {
        $this->authorize($actor);
        $query = trim($query);
        abort_if(mb_strlen($query) < 2, 422, 'Enter at least two letters of the person’s name.');
        $like = '%'.addcslashes($query, '%_\\').'%';

        return Client::query()->whereIn('site_id', $this->sites->accessibleSiteIds($actor))
            ->where(fn ($names) => $names->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('preferred_name', 'like', $like))
            ->with('site:id,name')->orderBy('first_name')->limit(20)->get()
            ->filter(fn (Client $client) => Gate::forUser($actor)->allows('view', $client))->take(10)
            ->map(fn (Client $client) => ['id' => $client->id, 'name' => $client->full_name, 'site' => $client->site?->name])->values()->all();
    }

    public function show(User $actor, Client $client): array
    {
        $this->authorize($actor, $client);
        $client->loadMissing(['site:id,name', 'portalUsers:id,name', 'nextOfKins']);
        $conversations = OpsConversation::where('conversation_type', 'family')->where('client_id', $client->id)->where('is_archived', false)
            ->with('participants:id,conversation_id,user_id,role')->get();
        $familyConversations = fn (int $userId) => $conversations->filter(fn ($conversation) => $conversation->participants
            ->contains(fn ($member) => (int) $member->user_id === $userId && $member->role === 'family'))->count();
        $kin = $client->nextOfKins->keyBy('user_id');

        $relatives = $client->portalUsers->map(function (User $person) use ($client, $kin, $familyConversations) {
            $approved = $person->canAccessClientPortal($client);
            $count = $familyConversations($person->id);

            return [
                'id' => $person->id, 'name' => $person->name,
                'relationship' => $kin->get($person->id)?->relationship ?? $person->pivot->relation,
                'status' => $approved ? 'approved' : 'not_approved',
                'access' => $approved
                    ? ($count ? 'Own conversations ('.$count.'); separate from other relatives unless invited' : 'No conversation yet; separate from other relatives unless invited')
                    : 'No Chat access until the portal relationship is approved again',
            ];
        })->sortBy('name')->values();

        $named = $this->contacts->namedPeople($client);
        $namedIds = $named->pluck('id')->all();
        $cover = $this->family->candidateStaffQuery($client)->whereNotIn('users.id', $namedIds)->orderBy('name')->limit(200)->get()
            ->filter(fn (User $person) => ! $person->canAccessClientPortal($client) && $this->family->canView($person, $client))->values();

        $siteId = $client->site_id ? (int) $client->site_id : null;
        $stored = $this->settings->read();
        $effective = $this->settings->read($siteId);
        $override = $siteId !== null && ! empty(array_intersect_key($stored['site_overrides'][(string) $siteId] ?? [], array_flip(ChatSettings::OVERRIDABLE)));

        return [
            'client' => ['id' => $client->id, 'name' => $client->full_name, 'site' => $client->site ? ['id' => $client->site->id, 'name' => $client->site->name] : null],
            'relatives' => $relatives->all(),
            'named_workers' => $named->sortBy('name')->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name,
                'access' => 'Assigned conversations for '.$client->first_name])->values()->all(),
            'cover' => $cover->take(self::COVER_LIMIT)->map(fn (User $person) => ['id' => $person->id, 'name' => $person->name,
                'access' => 'Disclosed cover conversations'])->values()->all(),
            'cover_total' => $cover->count(),
            'policy' => [
                'source' => $override ? 'site_override' : 'organisation',
                'scope' => $siteId !== null && Site::whereKey($siteId)->where('is_active', true)->where('archived', false)->exists() ? (string) $siteId : 'organisation',
                'family_enabled' => (bool) ($effective['enabled'] && $effective['family_enabled']),
                'family_calls_enabled' => (bool) ($effective['calls_enabled'] && $effective['family_calls_enabled']),
                'response_expectation' => (string) ($effective['response_expectation'] ?? ''),
            ],
            'links' => array_filter([
                'portal_users' => Gate::forUser($actor)->allows('update', $client) ? route('clients.portal_users.edit', $client) : null,
                'family_portal' => $actor->canDo('family_portal.viewAny') || $actor->canDo('clients.update') ? route('operations.family_portal.show', $client) : null,
            ]),
        ];
    }
}
