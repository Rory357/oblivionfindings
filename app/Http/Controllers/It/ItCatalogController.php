<?php

namespace App\Http\Controllers\It;

use App\Domain\It\ItStaffDirectory;
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
        $includeInternal = $user->canDo('it.manage');
        $search = trim((string) $request->query('q', ''));

        $items = ItCatalogItem::query()
            ->published()
            ->when(! $includeInternal, fn ($query) => $query->where('internal_only', false))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('search_terms', 'like', $like));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (ItCatalogItem $item) => $item->discoveryPayload($includeInternal))
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
            ->published()
            ->when(! $user->canDo('it.manage'), fn ($query) => $query->where('internal_only', false))
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

        return redirect()->back()
            ->with('success', $result instanceof ItTicket
                ? "Request logged — {$result->reference}."
                : 'Provisioning request logged.')
            ->with('it_catalog_submission', $flash);
    }
}
