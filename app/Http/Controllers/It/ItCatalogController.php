<?php

namespace App\Http\Controllers\It;

use App\Domain\It\Services\ItCatalogAccessService;
use App\Domain\It\Services\ItCatalogFieldOptionService;
use App\Domain\It\Services\ItCatalogSubmissionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\RecoverCatalogSubmissionRequest;
use App\Http\Requests\It\StoreCatalogRequest;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\ItCatalogItem;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ItCatalogController extends Controller
{
    public function __construct(
        private readonly ItCatalogSubmissionService $submissionService,
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
        if ($outcome['cancelled'] ?? false) {
            if ($request->expectsJson()) {
                return $this->commandResponse($outcome, $user, (string) $request->validated('idempotency_key'));
            }
            throw ValidationException::withMessages(['idempotency_key' => 'This request was cancelled. Start a new request when you are ready.']);
        }
        $result = $outcome['result'];

        if ($result instanceof ItTicket) {
            try {
                DispatchItTicketNotifications::dispatchAfterResponse((int) $result->id);
            } catch (\Throwable) {
                // The canonical outbox has committed. The scheduled drain can
                // recover a lost dispatch without changing the saved outcome.
            }
        }

        if ($request->expectsJson()) {
            return $this->commandResponse($outcome, $user, (string) $request->validated('idempotency_key'));
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

    public function recover(RecoverCatalogSubmissionRequest $request, int $catalogItem): JsonResponse
    {
        $key = (string) $request->validated('idempotency_key');
        $outcome = $this->submissionService->recover($catalogItem, $request->user(), $key,
            (int) $request->validated('actor_user_id'));
        if ($outcome !== null) {
            return $this->commandResponse($outcome, $request->user(), $key);
        }

        return response()->json(['status' => 'not_found', 'data' => [
            'viewer_user_id' => (int) $request->user()->id,
            'catalog_item_id' => $catalogItem, 'request_uuid' => $key,
            'retry_same_command' => true,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function cancel(RecoverCatalogSubmissionRequest $request, int $catalogItem): JsonResponse
    {
        $key = (string) $request->validated('idempotency_key');

        return $this->commandResponse($this->submissionService->cancel($catalogItem, $request->user(), $key,
            (int) $request->validated('actor_user_id')), $request->user(), $key);
    }

    /** Only confirmed canonical identity leaves the command boundary. */
    private function commandResponse(array $outcome, User $actor, string $key): JsonResponse
    {
        if ($outcome['cancelled'] ?? false) {
            return response()->json(['status' => 'cancelled', 'data' => [
                'viewer_user_id' => (int) $actor->id, 'catalog_item_id' => $outcome['catalog_item_id'],
                'request_uuid' => $key, 'cancelled' => true,
            ]])->header('Cache-Control', 'private, no-store');
        }
        $result = $outcome['result'];
        $submission = $outcome['submission'];
        $isTicket = $result instanceof ItTicket;

        return response()->json(['status' => 'committed', 'data' => [
            'viewer_user_id' => (int) $actor->id,
            'catalog_item_id' => (int) $submission->catalog_item_id,
            'submission_id' => (int) $submission->id,
            'schema_version' => (int) $submission->schema_version,
            'request_uuid' => $key,
            'result_type' => $isTicket ? 'ticket' : 'provisioning',
            'id' => (int) $result->getKey(),
            'reference' => $isTicket ? $result->reference : null,
            'url' => ($isTicket ? '/it/tickets/' : '/it/provisioning/').$result->getKey(),
            'replayed' => ! $outcome['created'],
        ]], $outcome['created'] ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
