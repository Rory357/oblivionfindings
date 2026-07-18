<?php

namespace App\Http\Controllers\Api;

use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Http\Controllers\Controller;
use App\Http\Requests\It\Api\StoreItApiCommentRequest;
use App\Http\Requests\It\Api\StoreItApiWorkItemRequest;
use App\Http\Requests\It\Api\TransitionItApiWorkItemRequest;
use App\Http\Resources\ItApiWorkItemResource;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use DomainException;
use Illuminate\Http\Request;

class ItApiWorkItemController extends Controller
{
    public function __construct(private readonly ItApiWorkItemService $workItems) {}

    public function store(StoreItApiWorkItemRequest $request)
    {
        $ticket = $this->workItems->create($this->identity($request), $request->validated());

        return (new ItApiWorkItemResource($ticket))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $workItem): ItApiWorkItemResource
    {
        return new ItApiWorkItemResource($this->ticket($request, $workItem));
    }

    public function comment(StoreItApiCommentRequest $request, int $workItem)
    {
        $identity = $this->identity($request);
        $comment = $this->workItems->addPublicComment(
            $identity,
            $this->ticket($request, $workItem),
            (string) $request->validated('body'),
        );

        return response()->json(['data' => [
            'id' => $comment->id,
            'body' => $comment->body,
            'is_internal' => false,
            'created_at' => $comment->created_at?->toIso8601String(),
        ]], 201);
    }

    public function transition(TransitionItApiWorkItemRequest $request, int $workItem)
    {
        $identity = $this->identity($request);
        $data = $request->validated();
        try {
            $ticket = $this->workItems->transition(
                $identity,
                $this->ticket($request, $workItem),
                new ItTransitionInput(
                    tenantId: (int) $identity->tenant_id,
                    actor: $identity->actor,
                    to: ItWorkflowState::from((string) $data['to']),
                    reason: $data['reason'] ?? null,
                    waitingParty: $data['waiting_party'] ?? null,
                    nextAction: $data['next_action'] ?? null,
                    resolutionCode: $data['resolution_code'] ?? null,
                    resolutionSummary: $data['resolution_summary'] ?? null,
                    source: 'service_api',
                ),
            );
        } catch (DomainException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'transition_denied',
            ], 422);
        }

        return new ItApiWorkItemResource($ticket);
    }

    private function identity(Request $request): ItServiceIdentity
    {
        /** @var ItServiceIdentity $identity */
        $identity = $request->attributes->get('it_service_identity');

        return $identity;
    }

    private function ticket(Request $request, int $id): ItTicket
    {
        $identity = $this->identity($request);
        $siteIds = array_map('intval', $identity->allowed_site_ids ?? []);

        return ItTicket::query()
            ->forTenant((int) $identity->tenant_id)
            ->where('is_sensitive', false)
            ->whereIn('work_type', $identity->allowed_work_types ?? [])
            ->where(function ($query) use ($siteIds): void {
                $query->whereNull('site_id');
                if ($siteIds !== []) {
                    $query->orWhereIn('site_id', $siteIds);
                }
            })
            ->with(['site:id,name', 'service:id,name', 'asset:id,name,asset_tag', 'queue:id,name', 'team:id,name', 'owner:id,name', 'assignee:id,name'])
            ->findOrFail($id);
    }
}
