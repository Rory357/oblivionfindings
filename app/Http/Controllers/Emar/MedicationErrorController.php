<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\MedicationError;
use App\Models\MedicationErrorEntry;
use App\Models\MedicationMarAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MarLinkService;
use App\Services\Medication\MedicationErrorCommands;
use App\Services\Medication\MedicationErrorReadScope;
use App\Services\Medication\MedicationErrorSummary;
use App\Services\Medication\MedicationErrorWorkflow;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\Reporting\RecordsReportingSettings;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class MedicationErrorController extends Controller
{
    public function __construct(
        private readonly MedicationScopeDecisionService $medicationScope,
        private readonly MedicationGovernanceScopeService $governanceScope,
        private readonly MarLinkService $marLinks,
    ) {}

    private function serializeAttachment(
        MedicationMarAttachment $attachment,
        Request $request,
        bool $controlledMedication,
    ): array {
        $actor = $request->user();

        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
            'file_size' => $attachment->file_size,
            'formatted_size' => $attachment->formatted_size,
            'description' => $attachment->description,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'uploaded_by' => $attachment->uploadedBy?->name,
            'download_url' => route('api.medications.supporting_attachments.download', [
                'client' => $attachment->client_id,
                'attachment' => $attachment->id,
            ]),
            'can_delete' => $actor->canDo('medications.administer.correct')
                && (! $controlledMedication
                    || $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_CAPABILITY)),
        ];
    }

    private function serializeError(
        MedicationError $error,
        Request $request,
        bool $controlledMedication,
    ): array {
        $investigator = $request->user()->canDo('medications.errors.manage') || $request->user()->canDo('medications.audit.view');
        $incident = $error->incident;
        if (
            $incident !== null
            && (
                (int) $incident->client_id !== (int) $error->client_id
                || (int) $incident->site_id !== (int) $error->client?->site_id
            )
        ) {
            $incident = null;
        }

        $attachments = $error->attachments
            ->filter(fn (MedicationMarAttachment $attachment): bool => (int) $attachment->client_id === (int) $error->client_id
                && $attachment->attachable_type === $error->getMorphClass()
                && (int) $attachment->attachable_id === (int) $error->getKey())
            ->map(fn (MedicationMarAttachment $attachment) => $this->serializeAttachment(
                $attachment,
                $request,
                $controlledMedication,
            ))
            ->values()
            ->all();

        return [
            'stage' => $error->stage(),
            'sac' => ['enabled' => app(RecordsReportingSettings::class)->enabled(), 'proposed' => app(RecordsReportingSettings::class)->preselection($error->reached_client, $error->harm_level), 'confirmed' => $error->confirmed_sac, 'confirmed_at' => $error->sac_confirmed_at?->toIso8601String()],
            'summary' => MedicationErrorSummary::for($error),
            'occurred_at' => ($error->occurred_at ?? $error->reported_at)?->toIso8601String(),
            'triage_due_at' => $error->triage_due_at?->toIso8601String(),
            'investigation_due_at' => $error->investigation_due_at?->toIso8601String(),
            'owner' => $error->owner?->only(['id', 'name']),
            'entries' => $error->entries->filter(fn ($entry) => $investigator || in_array($entry->kind, ['reported', 'account', 'closed', 'reopened', 'triaged'], true))->map(fn ($e) => ['id' => $e->id, 'kind' => $e->kind, 'text' => ($investigator || in_array($e->kind, ['reported', 'account'], true)) ? $e->text : null, 'data' => ($investigator || in_array($e->kind, ['reported', 'account'], true)) ? collect($e->data)->except(['report_token', 'fingerprint'])->all() : [], 'by' => $e->actor?->name, 'at' => $e->created_at->toIso8601String()])->values(),
            'actions' => $error->actions->filter(fn () => $request->user()->canDo('medications.errors.manage') || $request->user()->canDo('medications.audit.view'))->map(fn ($a) => ['id' => $a->id, 'description' => $a->description, 'owner' => $a->owner?->only(['id', 'name']), 'due_at' => $a->due_at->toIso8601String(), 'completed_at' => $a->completed_at?->toIso8601String(), 'completion_note' => $a->completion_note])->values(),
            'close_blockers' => $request->user()->canDo('medications.errors.manage') ? app(MedicationErrorWorkflow::class)->closeBlockers($error, $request->user()) : [],
            'can_close' => $request->user()->canDo('medications.errors.manage') && (int) $error->reported_by !== (int) $request->user()->id,
            'can_reopen' => $request->user()->canDo('medications.errors.manage') && (int) $error->reported_by !== (int) $request->user()->id,
            'id' => $error->id,
            'ref' => $error->reference_number ?? 'ERR-'.str_pad((string) $error->id, 4, '0', STR_PAD_LEFT),
            'error_type' => $error->error_type,
            'severity' => $error->severity,
            'reached_client' => $error->reached_client,
            'harm_level' => $error->harm_level,
            'open_disclosure' => $error->open_disclosure,
            'description' => $error->description,
            'immediate_action' => $error->immediate_action,
            'contributing_factors' => $error->contributing_factors,
            'review_notes' => ($request->user()->canDo('medications.errors.manage') || $request->user()->canDo('medications.audit.view')) ? $error->review_notes : null,
            'outcome' => ($request->user()->canDo('medications.errors.manage') || $request->user()->canDo('medications.audit.view')) ? $error->outcome : null,
            'preventive_actions' => ($request->user()->canDo('medications.errors.manage') || $request->user()->canDo('medications.audit.view')) ? $error->preventive_actions : null,
            'close_note' => ($request->user()->canDo('medications.errors.manage') || $request->user()->canDo('medications.audit.view')) ? $error->close_note : null,
            'status' => $error->status,
            'reported_at' => $error->reported_at?->toIso8601String(),
            'reviewed_at' => $error->reviewed_at?->toIso8601String(),
            'closed_at' => $error->closed_at?->toIso8601String(),
            'client_id' => $error->client_id,
            'client' => $error->client ? [
                'id' => $error->client->id,
                'first_name' => $error->client->first_name,
                'last_name' => $error->client->last_name,
            ] : null,
            'site_id' => $error->client?->site_id,
            'site_name' => $error->client?->site?->name,
            'medication' => $error->medication ? [
                'id' => $error->medication->id,
                'name' => $error->medication->name,
            ] : null,
            'incident' => $incident ? [
                'id' => $incident->id,
                'status' => $incident->status,
                'ready_to_close' => $error->stage() === 'closed' && $incident->status !== 'closed',
                'can_close' => Gate::forUser($request->user())->allows('close', $incident),
                'ref' => $incident->reference_number ?? 'INC-'.str_pad((string) $incident->id, 4, '0', STR_PAD_LEFT),
            ] : null,
            'mar_url' => $this->marLinks->urlFor($request->user(), $error->client_id),
            'reported_by_user' => $error->reportedBy ? [
                'id' => $error->reportedBy->id,
                'name' => $error->reportedBy->name,
            ] : null,
            'reviewed_by_user' => $error->reviewedBy ? [
                'id' => $error->reviewedBy->id,
                'name' => $error->reviewedBy->name,
            ] : null,
            'attachments' => $attachments,
        ];
    }

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.view'), 403);
        $siteFilter = $request->integer('site_id') ?: null;
        $sites = $this->governanceScope->readerSiteIds($actor, 'medications.view', requestedSiteId: $siteFilter);
        $readerSites = $siteFilter ? [$siteFilter] : $sites;
        $clients = $this->marLinks->openableClientIds($actor, Client::query()->whereIn('site_id', $readerSites)->pluck('id'));
        $all = $actor->canDo('medications.errors.manage') || $actor->canDo('medications.audit.view');
        $base = app(MedicationErrorReadScope::class)->apply(MedicationError::query(), $actor, $readerSites);
        $tab = $request->string('tab', $all ? 'triage' : 'mine')->toString();
        $query = clone $base;
        $status = ['triage' => 'reported', 'investigating' => 'investigating', 'actions' => 'resolved', 'closed' => 'closed'][$tab] ?? null;
        if ($status) {
            $query->where('status', $status);
        }
        if ($tab === 'incidents') {
            $query->whereHas('incident', fn ($q) => $q->where('status', '!=', 'closed'));
        }
        if ($tab === 'mine') {
            $query->where(fn ($q) => $q->where('reported_by', $actor->id)->orWhereHas('entries', fn ($entries) => $entries->where('kind', 'account')->where('actor_id', $actor->id)));
        }
        if ($request->filled('q')) {
            $term = '%'.addcslashes(mb_substr($request->string('q')->toString(), 0, 100), '%_\\').'%';
            $query->where(fn ($q) => $q->where('reference_number', 'like', $term)->orWhereHas('client', fn ($c) => $c->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)));
        }
        if (in_array($request->input('reach'), ['no', 'yes', 'unknown'], true)) {
            $query->where('reached_client', $request->input('reach'));
        }
        $relations = ['client.site', 'medication' => fn ($q) => $q->withTrashed(), 'incident', 'reportedBy:id,name', 'reviewedBy:id,name', 'owner:id,name', 'entries.actor:id,name', 'actions.owner:id,name', 'attachments.uploadedBy:id,name'];
        if ($tab === 'triage') {
            $query->orderBy('triage_due_at')->orderBy('reported_at')->orderBy('id');
        } elseif ($tab === 'investigating') {
            $query->orderBy('investigation_due_at')->orderBy('id');
        } elseif ($tab === 'closed') {
            $query->orderByDesc('closed_at')->orderByDesc('id');
        } else {
            $query->orderByDesc('reported_at')->orderByDesc('id');
        }
        $page = $query->with($relations)->paginate(25)->withQueryString();
        $errors = $page->getCollection()->map(fn ($e) => $this->serializeError($e, $request, (bool) $e->medication?->controlled_drug));
        $detail = null;
        if ($request->integer('error') > 0) {
            $model = (clone $base)->with($relations)->find($request->integer('error'));
            abort_unless($model, 404);
            $detail = $this->serializeError($model, $request, (bool) $model->medication?->controlled_drug);
        }
        $now = now('Pacific/Auckland');
        $occurred = 'COALESCE(occurred_at, reported_at)';
        $recent = (clone $base)->whereRaw($occurred.' >= ? AND '.$occurred.' <= ?', [$now->copy()->startOfDay()->subDays(89)->utc(), now()]);
        $trend = collect(range(7, 0))->map(function ($week) use ($base, $now, $occurred) {
            $start = $now->copy()->startOfWeek()->subWeeks($week);
            $q = (clone $base)->whereRaw($occurred.' >= ? AND '.$occurred.' < ? AND '.$occurred.' <= ?', [$start->copy()->utc(), $start->copy()->addWeek()->utc(), now()]);

            return ['week' => $start->format('d M'), 'count' => (clone $q)->count(), 'near_miss' => (clone $q)->where('reached_client', 'no')->count()];
        });

        return Inertia::render('emar/MedicationErrors', [
            'errors' => $errors, 'detail' => $detail,
            'pagination' => ['links' => $page->linkCollection(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(), 'last_page' => $page->lastPage()],
            'filters' => ['tab' => $tab, 'q' => $request->input('q', ''), 'reach' => $request->input('reach', 'all'), 'site_id' => $siteFilter],
            'stats' => [
                'triage' => (clone $base)->where('status', 'reported')->count(), 'investigating' => (clone $base)->where('status', 'investigating')->count(),
                'actions' => (clone $base)->where('status', 'resolved')->count(), 'closed' => (clone $base)->where('status', 'closed')->count(),
                'total_open' => (clone $base)->where('status', '!=', 'closed')->count(), 'recent' => (clone $recent)->count(),
                'reached' => (clone $recent)->where('reached_client', 'yes')->count(), 'near_miss' => (clone $recent)->where('reached_client', 'no')->count(),
                'unknown_reach' => (clone $recent)->where(fn ($q) => $q->where('reached_client', 'unknown')->orWhereNull('reached_client'))->count(), 'trend' => $trend,
            ],
            'clients' => $this->governanceScope->clientPicker($readerSites)->whereIn('id', $clients)->values(), 'staff' => $this->governanceScope->staffPicker($readerSites)->filter(fn ($staff) => User::query()->find($staff['id'])?->canDo('medications.errors.manage'))->values(),
            'sites' => $this->governanceScope->sitePicker($sites)->map(fn ($s) => $s->only(['id', 'name']))->values(),
            'triage_rule' => app(MedicationErrorWorkflow::class)->triageRule(),
            'can' => ['record' => $actor->canDo('medications.administer.record'), 'manage' => $actor->canDo('medications.errors.manage'), 'all' => $all, 'controlled' => $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)],
        ]);
    }

    public function export(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.view') && ($actor->canDo('medications.errors.manage') || $actor->canDo('medications.audit.view')), 403);
        $data = $request->validate(['purpose' => 'required|string|min:3|max:500']);
        $site = $request->integer('site_id') ?: null;
        $sites = $this->governanceScope->readerSiteIds($actor, 'medications.view', requestedSiteId: $site);
        $query = app(MedicationErrorReadScope::class)->apply(MedicationError::query(), $actor, $site ? [$site] : $sites);
        AuditLogger::logOrFail('medications.errors.export', null, ['actor_id' => (int) $actor->id, 'purpose' => $data['purpose'], 'site_ids' => $sites, 'format' => 'neutral_csv']);

        return response()->streamDownload(function () use ($query) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Error', 'Person', 'House', 'Occurred (NZ)', 'Reach', 'Harm', 'Stage', 'Owner', 'Summary'], ',', '"', '');
            $query->with(['client.site', 'owner'])->orderBy('id')->chunkById(200, function ($errors) use ($stream) {
                foreach ($errors as $error) {
                    $row = [$error->reference_number, trim($error->client->first_name.' '.$error->client->last_name), $error->client->site?->name,
                        ($error->occurred_at ?? $error->reported_at)?->tz('Pacific/Auckland')->format('Y-m-d H:i'),
                        $error->reached_client, $error->harm_level, $error->stage(), $error->owner?->name, MedicationErrorSummary::for($error)];
                    $row = array_map(fn ($value) => preg_match('/^[\x00-\x20]*[=+@-]/u', (string) $value) ? "'".$value : $value, $row);
                    fputcsv($stream, $row, ',', '"', '');
                }
            });
            fclose($stream);
        }, 'medication-errors-neutral.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function medicines(Request $request, Client $client)
    {
        $actor = $this->recordingActor($request);
        $client = app(MedicationRecordAccess::class)->client($actor, $client->id);
        $q = $client->medications()->where('status', 'active');
        if (! $actor->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY)) {
            $q->where('controlled_drug', false);
        }

        return response()->json(['medicines' => $q->orderBy('name')->get(['id', 'name'])->toArray()]);
    }

    public function store(Request $request)
    {
        $actor = $this->recordingActor($request);
        $data = $request->validate([
            'client_id' => 'required|integer|min:1', 'client_medication_id' => 'nullable|integer|min:1',
            'error_type' => 'required|in:wrong_medication,wrong_client,wrong_dose,wrong_time,wrong_route,omission,unauthorised,documentation,other',
            'reached_client' => 'required|in:no,yes,unknown', 'harm_level' => ['required_unless:reached_client,no', 'nullable', Rule::in(MedicationErrorWorkflow::HARMS)],
            'occurred_at' => 'required|date_format:Y-m-d\\TH:i', 'description' => 'required|string|max:5000',
            'immediate_action' => 'nullable|string|max:5000', 'contributing_factors' => 'nullable|string|max:5000', 'report_token' => 'required|uuid',
            'create_incident' => 'nullable|boolean', 'duplicate_id' => 'nullable|integer|min:1', 'separate_reason' => 'nullable|string|max:1000',
        ]);

        return $this->withAssignedClient($actor, (int) $data['client_id'], function (MedicationScopeDecision $scope) use ($actor, $data, $request) {
            $before = $this->entryCursor($scope->client->id);
            $response = app(MedicationErrorCommands::class)->report($request, $scope->client, $actor, $data);
            $this->recordEvents($scope->client, $actor, $before);

            return $response;
        });
    }

    public function update(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'note');
    }

    public function review(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'triage');
    }

    public function resolve(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'resolve');
    }

    public function close(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'close');
    }

    public function reopen(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'reopen');
    }

    public function note(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'note');
    }

    public function action(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'action');
    }

    public function disclosure(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'disclosure');
    }

    public function linkIncident(Request $request, MedicationError $error)
    {
        return $this->manage($request, $error, 'incident');
    }

    public function completeAction(Request $request, MedicationError $error, int $action)
    {
        return $this->manage($request, $error, 'complete', $action);
    }

    public function account(Request $request, MedicationError $error)
    {
        $actor = $this->recordingActor($request);

        return $this->withCanonicalError($actor, $error, fn ($locked, $client) => app(MedicationErrorCommands::class)->run('account', $request, $locked, $client, $actor), 'medications.administer.record');
    }

    private function manage(Request $request, MedicationError $error, string $command, ?int $action = null)
    {
        $actor = $this->correctionActor($request);

        return $this->withCanonicalError($actor, $error, fn ($locked, $client) => app(MedicationErrorCommands::class)->run($command, $request, $locked, $client, $actor, $action));
    }

    private function recordingActor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.administer.record'), 403);

        return $user;
    }

    private function correctionActor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->canDo('medications.errors.manage'), 403);

        return $user;
    }

    private function withCanonicalError(User $user, MedicationError $submittedError, Closure $callback, string $capability = 'medications.errors.manage'): mixed
    {
        $snapshot = MedicationError::query()
            ->whereKey($submittedError->getKey())
            ->first(['id', 'client_id', 'client_medication_id']);
        abort_unless($snapshot !== null, 404);

        $clientId = (int) $snapshot->client_id;
        abort_unless($clientId > 0, 404);

        return $this->governanceScope->forClient(
            $user,
            $clientId,
            $capability,
            function (Client $client) use ($user, $snapshot, $callback) {
                if ($snapshot->client_medication_id !== null) {
                    $medication = ClientMedication::withTrashed()
                        ->whereKey($snapshot->client_medication_id)
                        ->where('client_id', $client->id)
                        ->lockForUpdate()
                        ->first(['id', 'controlled_drug']);
                    abort_unless($medication !== null, 404);
                    abort_if(
                        (bool) $medication->controlled_drug
                        && ! $user->canDo(MedicationGovernanceScopeService::CONTROLLED_VIEW_CAPABILITY),
                        404,
                    );
                }

                $error = MedicationError::query()
                    ->whereKey($snapshot->getKey())
                    ->where('client_id', $client->id)
                    ->lockForUpdate()
                    ->first();
                abort_unless($error !== null, 404);
                abort_unless(
                    ($error->client_medication_id === null && $snapshot->client_medication_id === null)
                    || (int) $error->client_medication_id === (int) $snapshot->client_medication_id,
                    404,
                );
                $this->assertErrorOwnership($error, $client);
                app(MedicationRecordAccess::class)->assertReadable($user, $client);

                $before = $this->entryCursor($client->id);
                $response = $callback($error, $client);
                $this->recordEvents($client, $user, $before);

                return $response;
            },
        );
    }

    private function withAssignedClient(User $user, int $clientId, Closure $callback): mixed
    {
        $scopeEntered = false;

        try {
            return $this->medicationScope->forClient(
                $user,
                $clientId,
                now(),
                function (MedicationScopeDecision $scope) use ($callback, &$scopeEntered) {
                    $scopeEntered = true;

                    return $callback($scope);
                },
            );
        } catch (HttpExceptionInterface $exception) {
            if (! $scopeEntered && $exception->getStatusCode() === 403) {
                abort(404, 'The requested medication action is not available.');
            }

            throw $exception;
        }
    }

    private function assertErrorOwnership(MedicationError $error, Client $client): void
    {
        abort_unless((int) $error->client_id === (int) $client->id, 404);

        if ($error->client_incident_id !== null) {
            $incidentMatches = ClientIncident::query()
                ->whereKey($error->client_incident_id)
                ->where('client_id', $client->id)
                ->where('site_id', $client->site_id)
                ->exists();
            abort_unless($incidentMatches, 404);
        }
    }

    private function entryCursor(int $clientId): int
    {
        return (int) DB::table('medication_error_entries')->whereIn('medication_error_id', MedicationError::query()->where('client_id', $clientId)->select('id'))->max('id');
    }

    private function recordEvents(Client $client, User $actor, int $after): void
    {
        $entries = MedicationErrorEntry::query()->where('id', '>', $after)
            ->whereIn('medication_error_id', MedicationError::query()->where('client_id', $client->id)->select('id'))->orderBy('id')->get();
        $items = [];
        foreach ($entries as $entry) {
            $error = MedicationError::query()->findOrFail($entry->medication_error_id);
            $controlled = $error->client_medication_id !== null && ClientMedication::withTrashed()->whereKey($error->client_medication_id)->where('controlled_drug', true)->exists();
            $items[] = new MedicationEventData(
                siteId: (int) $client->site_id, kind: 'error.'.$entry->kind, subjectType: 'medication_error', subjectId: (string) $error->id,
                actorId: (int) $actor->id, occurredAt: CarbonImmutable::instance($entry->created_at),
                summary: 'Medication error '.$error->reference_number.' — '.str_replace('_', ' ', $entry->kind).'.',
                facts: ['entry_id' => (int) $entry->id, 'stage' => $error->stage()], clientId: (int) $client->id, controlled: $controlled,
            );
        }
        if ($items !== []) {
            app(MedicationEventRecorder::class)->appendMany($items);
        }
    }
}
