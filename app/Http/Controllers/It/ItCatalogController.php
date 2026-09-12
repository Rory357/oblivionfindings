<?php

namespace App\Http\Controllers\It;

use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItCatalogAccessService;
use App\Domain\It\Services\ItCatalogFieldOptionService;
use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\StoreCatalogRequest;
use App\Models\ItCatalogItem;
use App\Models\ItTicket;
use App\Models\User;
use App\Notifications\It\TicketCreatedNotification;
use Illuminate\Http\Request;

class ItCatalogController extends Controller
{
    public function __construct(
        private readonly ItCatalogSubmissionService $submissionService,
        private readonly ItEmailDeliveryService $emailDeliveries,
        private readonly ItCatalogFieldOptionService $fieldOptions,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $search = trim((string) $request->query('q', ''));

        $items = ItCatalogItem::query()
            ->published()
            ->with('publishedVersion')
            ->get()
            ->map(fn (ItCatalogItem $item) => $item->publishedContract())
            ->filter(fn (ItCatalogItem $item) => app(ItCatalogAccessService::class)->canDiscover($user, $item)
                && ($search === '' || str_contains(mb_strtolower($item->name.' '.$item->description.' '.implode(' ', $item->search_terms ?? [])), mb_strtolower($search))))
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->map(fn (ItCatalogItem $item) => app(ItCatalogAccessService::class)->discoveryPayload($user, $item))
            ->values();

        $types = $items->flatMap(fn (array $item) => collect($item['form_schema']['fields'] ?? [])->pluck('type'))
            ->filter(fn (mixed $type): bool => in_array($type, ItCatalogFieldOptionService::TYPES, true))
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'data' => $items,
            'field_options' => $this->fieldOptions->forTypes($user, $types),
        ]);
    }

    public function store(StoreCatalogRequest $request, int $catalogItem)
    {
        $user = $request->user();
        $item = ItCatalogItem::query()
            ->withTrashed()
            ->findOrFail($catalogItem);

        $outcome = $this->submissionService->submit($item, $user, $request->validated());
        $result = $outcome['result'];

        if ($outcome['created'] && $result instanceof ItTicket) {
            $this->emailDeliveries->send($user, new TicketCreatedNotification($result, 'receipt'));
            if ($result->priority === 'urgent') {
                $agents = ItStaffDirectory::agentsForTicket($result)
                    ->reject(fn (User $agent) => $agent->id === $user->id);
                $this->emailDeliveries->send($agents, new TicketCreatedNotification($result, 'urgent_alert'));
            }
        }

        $flash = [
            'submission_id' => $outcome['submission']->id,
            'result_type' => $outcome['submission']->result_type,
            'result_id' => $result->getKey(),
            'reference' => $result instanceof ItTicket ? $result->reference : null,
            'created' => $outcome['created'],
        ];

        return redirect()->to($result instanceof ItTicket ? '/it/tickets/'.$result->id : '/it/provisioning/'.$result->id)
            ->with('success', $result instanceof ItTicket
                ? "Request logged — {$result->reference}."
                : 'Provisioning request logged.')
            ->with('it_catalog_submission', $flash);
    }
}
