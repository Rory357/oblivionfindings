<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\ProcedureReviewRequest;
use App\Models\SafeWorkProcedure;
use App\Models\User;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\HealthSafety\SafeWorkProcedureService;
use App\Support\HealthSafety\SafeWorkProcedureReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProcedureReviewRequestController extends Controller
{
    public function __construct(private readonly SafeWorkProcedureAccessService $access, private readonly SafeWorkProcedureService $service) {}

    public function options(Request $request, HsEvent $event): JsonResponse
    {
        $data = $request->validate(['procedure_id' => ['nullable', 'integer', 'min:1'], 'request_id' => ['nullable', 'integer', 'min:1']]);
        $result = $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($event, $data, $request) {
            $source = $this->access->assertSource(['hs_event_id' => $event->id], $current, $reads, $sites);
            abort_unless($current->canDo('procedures.view'), 403);
            $create = $current->canDo('hazards.manage');
            $origin = $this->sourceOrigin($request, $source);
            $published = $this->access->publishedForAudience($current, (int) $source->site_id);
            $procedures = $create ? $published->map(fn ($record) => ['id' => $record->id, 'title' => $record->publishedValue('title'), 'reference_number' => $record->publishedValue('reference_number'), 'published_version_id' => $record->published_version_id, 'version' => $record->publishedVersion->version_number, 'provenance' => $record->publishedVersion->provenance]) : collect();
            $selected = null;
            $reassign = false;
            if (isset($data['request_id'])) {
                $row = $reads->query(ProcedureReviewRequest::query())->where('hs_event_id', $source->id)->findOrFail($data['request_id']);
                abort_unless(isset($data['procedure_id']) && (int) $row->safe_work_procedure_id === (int) $data['procedure_id'], 403);
                $selected = $reads->query(SafeWorkProcedure::query())->findOrFail($row->safe_work_procedure_id);
                $reassign = $this->access->reviewRequestCan($selected, $row, $current)['reassign'];
                abort_unless($reassign, 403);
            } elseif (isset($data['procedure_id'])) {
                $selected = $published->firstWhere('id', (int) $data['procedure_id']);
                abort_unless($selected, 403);
            }
            $executors = collect();
            if (($create || $reassign) && $selected) {
                $executors = collect($this->access->pickerOptions($current, $selected)['owners'])->filter(function ($candidate) use ($source) {
                    try {
                        $candidate = $candidate instanceof User ? $candidate : User::findOrFail($candidate['id']);

                        return $this->access->withCurrentViewer($candidate, function ($owner, $ownerReads, $ownerSites) use ($source) {
                            $this->access->assertSource(['hs_event_id' => $source->id], $owner, $ownerReads, $ownerSites);

                            return true;
                        });
                    } catch (HttpException) {
                        return false;
                    }
                })->map(fn ($owner) => ['id' => $owner instanceof User ? $owner->id : $owner['id'], 'name' => $owner instanceof User ? $owner->name : $owner['name']])->values();
            }
            $requests = $reads->query(ProcedureReviewRequest::query()->where('hs_event_id', $source->id))->with(['procedure.publishedVersion', 'requestedVersion', 'resultVersion', 'assignedTo'])->orderBy('id')->get()->filter(function ($row) use ($current, $reads, $sites) {
                try {
                    $this->access->assertSource($row, $current, $reads, $sites);

                    return $row->procedure && $row->requestedVersion && (int) $row->requestedVersion->safe_work_procedure_id === (int) $row->procedure->id && $this->access->canViewVersion($row->procedure, $row->requestedVersion, $current) && ($row->result_version_id === null || ($row->resultVersion && (int) $row->resultVersion->safe_work_procedure_id === (int) $row->procedure->id && $this->access->canViewVersion($row->procedure, $row->resultVersion, $current)));
                } catch (HttpException) {
                    return false;
                }
            })->map(fn ($row) => ['id' => $row->id, 'status' => $row->status, 'requested_at' => $row->requested_at?->toISOString(), 'procedure' => ['id' => $row->procedure->id, 'title' => $row->requestedVersion->content_snapshot['title'] ?? '', 'href' => SafeWorkProcedureReturn::href($row->procedure->id, $origin, 'connections', ($row->procedure->status === 'archived' || (int) $row->procedure->published_version_id !== (int) $row->requested_version_id) ? (int) $row->requested_version_id : null).'&request='.$row->id], 'executor' => $row->assignedTo ? ['id' => $row->assignedTo->id, 'name' => $row->assignedTo->name] : null, 'result_version' => $row->resultVersion ? ['id' => $row->resultVersion->id, 'version' => $row->resultVersion->version_number, 'href' => SafeWorkProcedureReturn::href($row->procedure->id, $origin, 'connections', $row->resultVersion->id).'&request='.$row->id] : null, 'outcome' => $row->outcome])->values();

            return ['can' => ['create_request' => $create], 'procedures' => $procedures->values(), 'executors' => $executors, 'actions' => $create ? $reads->query(HsCorrectiveAction::query()->where('hs_event_id', $source->id))->orderBy('id')->get(['id', 'reference_number', 'title', 'status'])->filter(function ($action) use ($source, $current, $reads, $sites) {
                try {
                    $this->access->assertSource(['hs_event_id' => $source->id, 'hs_corrective_action_id' => $action->id], $current, $reads, $sites);

                    return true;
                } catch (HttpException) {
                    return false;
                }
            })->values() : [], 'requests' => $requests];
        });

        return response()->json($result);
    }

    public function store(Request $request, HsEvent $event): RedirectResponse
    {
        $data = $request->validate(['procedure_id' => ['required', 'integer', 'min:1'], 'requested_version_id' => ['required', 'integer', 'min:1'], 'assigned_to_user_id' => ['required', 'integer', 'min:1'], 'hs_corrective_action_id' => ['nullable', 'integer', 'min:1'], 'note' => ['required', 'string', 'max:5000'], 'idempotency_key' => ['required', 'uuid']]);
        $record = SafeWorkProcedure::findOrFail($data['procedure_id']);
        $review = $this->service->requestReview($record, $request->user(), [...$data, 'hs_event_id' => $event->id]);

        return back()->with(['success' => 'Procedure review requested.', 'created_procedure_review_request_id' => $review->id, 'created_procedure_review_request_href' => SafeWorkProcedureReturn::href($record->id, $this->sourceOrigin($request, $event), 'connections').'&request='.$review->id]);
    }

    public function command(Request $request, SafeWorkProcedure $procedure, ProcedureReviewRequest $reviewRequest, string $command): RedirectResponse
    {
        $this->service->reviewRequestCommand($procedure, $reviewRequest, $request->user(), $command, $request->all());

        return back()->with('success', 'Procedure review request updated.');
    }

    private function sourceOrigin(Request $request, HsEvent $event): string
    {
        $path = '/health-safety/events/'.$event->id;
        $origin = SafeWorkProcedureReturn::sanitize($request->input('return_to'));
        if ($origin && parse_url($origin, PHP_URL_PATH) === $path) {
            return SafeWorkProcedureReturn::sanitize($origin.(str_contains($origin, '?') ? '&' : '?').'section=actions') ?? $path.'?section=actions';
        }
        $leaf = SafeWorkProcedureReturn::leaf($request->input('return_to'));

        return $path.'?'.http_build_query(array_filter(['section' => 'actions', 'return_to' => $leaf]), '', '&', PHP_QUERY_RFC3986);
    }
}
