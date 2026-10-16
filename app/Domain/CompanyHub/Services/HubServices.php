<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\It\Services\ItCatalogAccessService;
use App\Domain\It\Services\ItCatalogFieldOptionService;
use App\Domain\It\Services\ItProvisioningTrackingService;
use App\Domain\It\Services\ItTicketDraftService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItCatalogItem;
use App\Models\ItTicket;
use App\Models\User;
use App\Services\Fleet\MaintenanceAccessService;

class HubServices
{
    /** Routes into existing staff workflows; never a second request system. */
    public function handoffs(User $user, string $search = ''): array
    {
        app(HubAccess::class)->assertEnter($user);
        $items = [
            ['id' => 'people', 'name' => 'Ask People & Culture', 'description' => 'Find an HR work contact for questions about working here, getting started or people processes.', 'category' => 'People & Culture', 'icon' => 'people', 'url' => null],
            ['id' => 'leave', 'name' => 'Plan and request leave', 'description' => 'Check your balance, request time away and follow your approval in My HR.', 'category' => 'People & Culture', 'icon' => 'leave', 'url' => '/hr/my/leave'],
            ['id' => 'policies', 'name' => 'Policies and guidance', 'description' => 'Read current policies and complete required acknowledgements in My HR.', 'category' => 'People & Culture', 'icon' => 'policies', 'url' => '/hr/my/policies'],
            ['id' => 'learning', 'name' => 'Explore learning', 'description' => 'View your assigned training and compliance requirements in My HR.', 'category' => 'Learning & development', 'icon' => 'learning', 'url' => '/hr/my/training'],
            ['id' => 'profile', 'name' => 'Update my details', 'description' => 'Review and update your own personal information in My HR.', 'category' => 'People & Culture', 'icon' => 'profile', 'url' => '/hr/my/profile'],
            ['id' => 'learning-resources', 'name' => 'Learning resources', 'description' => 'Find practical learning materials, useful guidance and shared resources in the Company Hub.', 'category' => 'Learning & development', 'icon' => 'learning', 'url' => '/company-hub?view=resources'],
        ];
        $maintenance = app(MaintenanceAccessService::class);
        if ($maintenance->canReport($user) && $maintenance->approvedSiteIds($user) !== []) {
            $items[] = ['id' => 'maintenance', 'name' => 'Report a maintenance issue', 'description' => 'Report an asset or equipment problem at one of your approved sites.', 'category' => 'Places & equipment', 'icon' => 'maintenance', 'url' => '/fleet-assets/maintenance/work-orders/create'];
        }

        return collect($items)->filter(fn ($item) => $search === '' || str_contains(mb_strtolower($item['name'].' '.$item['description'].' '.$item['category']), mb_strtolower(trim($search))))->values()->all();
    }

    public function search(User $user, string $search): array
    {
        return [
            ...collect($this->catalogue($user, $search))->map(fn ($item) => [...collect($item)->only(['id', 'name', 'description', 'category'])->all(), 'icon' => 'computer'])->all(),
            ...collect($this->handoffs($user, $search))->map(fn ($item) => [
                'id' => 'staff:'.$item['id'], 'name' => $item['name'], 'description' => $item['description'], 'category' => $item['category'],
                'icon' => $item['icon'],
                'url' => $item['url'] ?? '/company-hub?view=services&search='.rawurlencode($item['name']),
            ])->all(),
        ];
    }

    public function catalogue(User $user, string $search = ''): array
    {
        app(HubAccess::class)->assertEnter($user);
        $access = app(ItCatalogAccessService::class);
        $items = ItCatalogItem::query()->published()->with('publishedVersion')->get()
            ->map(fn ($item) => $item->publishedContract())
            ->filter(fn ($item) => $access->canDiscover($user, $item))
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->filter(fn ($item) => $search === '' || str_contains(mb_strtolower($item->name.' '.strip_tags($item->description ?? '').' '.str_replace('_', ' ', $item->category)), mb_strtolower($search)))
            ->map(fn ($item) => $access->discoveryPayload($user, $item))->values()->all();

        return $items;
    }

    public function forUser(User $user): array
    {
        $items = collect($this->catalogue($user));
        $types = $items->flatMap(fn ($item) => collect($item['form_schema']['fields'] ?? [])->pluck('type'))
            ->filter(fn ($type) => in_array($type, ItCatalogFieldOptionService::TYPES, true))->unique()->values()->all();
        $tickets = app(ItWorkAccessService::class)->applyViewScope(ItTicket::query()->where('requester_user_id', $user->id), $user)
            ->when(! $user->canDo('it.request') && ! $user->canDo('it.view'), fn ($query) => $query->whereRaw('1 = 0'))
            ->latest('updated_at')->limit(100)->get();

        return [
            'handoffs' => $this->handoffs($user),
            'peopleContacts' => app(HubDirectory::class)->peopleSupportContacts($user),
            'draftRecoveryEnabled' => app(ItTicketDraftService::class)->enabled(),
            'provisioning' => app(ItProvisioningTrackingService::class)->listing($user, request()),
            'actorId' => $user->id, 'items' => $items->all(),
            'fieldOptions' => app(ItCatalogFieldOptionService::class)->forTypes($user, $types),
            'requests' => $tickets->map(fn ($ticket) => [
                'id' => $ticket->id, 'title' => $ticket->title, 'reference' => $ticket->reference,
                'status' => $ticket->status, 'updated_at' => $ticket->updated_at->toIso8601String(),
                'url' => '/it/tickets/'.$ticket->id,
            ])->all(),
        ];
    }
}
