<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MedicationPrescriberOrder;
use App\Models\MedicationReview;
use App\Models\User;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\Reviews\MedicationReviewCadence;
use App\Services\Medication\Reviews\MedicationReviewReader;
use App\Services\Medication\Reviews\MedicationReviewWorkflow;
use App\Support\MedicationJourney;
use App\Support\WorkerClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

final class MedicationReviewController extends Controller
{
    public function __construct(
        private readonly MedicationReviewReader $reader,
        private readonly MedicationReviewWorkflow $workflow,
        private readonly MedicationReviewCadence $cadence,
        private readonly MedicationGovernanceScopeService $scope,
    ) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        $filters = $request->validate(['site_id' => ['nullable', 'integer'], 'client_id' => ['nullable', 'integer'],
            'review' => ['nullable', 'integer', 'min:1'], 'item' => ['nullable', 'integer', 'min:1'], 'view' => ['nullable', Rule::in(['due', 'booked', 'recorded', 'closed', 'changes'])],
            'kind' => ['nullable', Rule::in(['regular', 'triggered'])], 'search' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1']]);
        $siteId = $request->integer('site_id') ?: null;
        $clientId = $request->integer('client_id') ?: null;
        $query = $this->reader->query($actor, $siteId, $clientId);
        $meters = $this->reader->meters(clone $query, $actor);
        $view = $filters['view'] ?? 'due';
        $today = WorkerClock::today()->toDateString();
        match ($view) {
            'due' => $query->whereIn('status', ['scheduled', 'overdue', 'in_progress'])->whereDate('scheduled_date', '<=', WorkerClock::today()->addDays(30)->toDateString()),
            'booked' => $query->whereIn('status', ['scheduled', 'overdue', 'in_progress'])->whereDate('scheduled_date', '>', WorkerClock::today()->addDays(30)->toDateString()),
            'recorded' => $query->where('status', 'completed'),
            'closed' => $query->whereIn('status', ['cancelled', 'closed']),
            'changes' => $query->whereHas('items', function ($items) use ($actor): void {
                $items->whereIn('outcome', ['change', 'stop', 'swap', 'start', 'watch', 'pending_controlled']);
                if (! $actor->canDo('medications.controlled.view')) {
                    $items->where('controlled_snapshot', false)->where('classification_pending', false)->whereDoesntHave('medication', fn ($q) => $q->where('controlled_drug', true));
                }
            }),
        };
        if ($request->filled('kind')) {
            $regular = ['regular', 'routine', 'Routine'];
            $request->input('kind') === 'regular' ? $query->whereIn('review_type', $regular) : $query->whereNotIn('review_type', $regular);
        }
        if ($request->filled('search')) {
            $search = '%'.$request->input('search').'%';
            $query->where(fn ($q) => $q->whereHas('client', fn ($c) => $c->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search))
                ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', $search))->orWhere('reviewer_name', 'like', $search)
                ->orWhereHas('items', function ($i) use ($search, $actor): void {
                    $i->where('name_snapshot', 'like', $search);
                    if (! $actor->canDo('medications.controlled.view')) {
                        $i->where('controlled_snapshot', false)->where('classification_pending', false)->whereDoesntHave('medication', fn ($m) => $m->where('controlled_drug', true));
                    }
                }));
        }
        $reviews = $query->orderBy('scheduled_date')->orderBy('id')->paginate(25)->withQueryString();
        $reviews->through(fn ($review) => $this->reader->serialize($review, $actor));
        $selected = $request->filled('review') ? $this->reader->detail($actor, $request->integer('review')) : null;
        if ($selected !== null) {
            abort_if(($siteId !== null && (int) $selected->client->site_id !== $siteId) || ($clientId !== null && (int) $selected->client_id !== $clientId), 404);
        }
        $selectedItemId = $request->integer('item') ?: null;
        if ($selectedItemId !== null) {
            abort_unless($selected !== null, 404);
            $item = $selected->items->firstWhere('id', $selectedItemId);
            abort_unless($item !== null && (int) $item->client_id === (int) $selected->client_id, 404);
            abort_if($item->isRestricted() && ! $actor->canDo('medications.controlled.view'), 404);
        }
        $selectedClient = $clientId ?? $selected?->client_id;

        return Inertia::render('emar/reviews/index', [
            'reviews' => $reviews, 'meters' => $meters, 'filters' => [...$filters, 'view' => $view, 'site_id' => $siteId, 'client_id' => $clientId, 'return_to' => MedicationJourney::returnTo($request->query('return_to'))],
            'sites' => $this->scope->sitePicker($this->scope->readerSiteIds($actor, 'medications.view'))->map->only(['id', 'name'])->values(),
            'can' => ['manage' => $actor->canDo(MedicationReviewWorkflow::MANAGE), 'orders' => $actor->canDo('medications.orders.manage'),
                'controlled' => $actor->canDo('medications.controlled.view'), 'summary' => $selected ? $this->reader->canReadSource($selected, $actor) : $actor->canDo(MedicationReviewWorkflow::MANAGE)],
            'selected_item_id' => $selectedItemId,
            'selected' => $selected ? $this->reader->serialize($selected, $actor, true) : null,
            'person' => $selectedClient ? $this->reader->person($actor, $selectedClient) : null,
            'default_interval' => $this->cadence->organisation(), 'today' => $today,
            'as_at' => now(config('app.worker_timezone', 'Pacific/Auckland'))->toIso8601String(),
        ]);
    }

    public function store(Request $request)
    {
        return $this->saved($request, $this->workflow->book($request->user(), $this->input($request)), 'Review booked.');
    }

    public function update(Request $request, MedicationReview $review)
    {
        return $this->saved($request, $this->workflow->move($request->user(), $review, $this->input($request)), 'Review moved. The original date and reason are kept.');
    }

    public function appointment(Request $request, MedicationReview $review)
    {
        return $this->saved($request, $this->workflow->appointment($request->user(), $review, $this->input($request)), 'Appointment recorded.');
    }

    public function complete(Request $request, MedicationReview $review)
    {
        return $this->saved($request, $this->workflow->complete($request->user(), $review, $this->input($request), $request->file('source')), 'Outcome recorded. Recommendations leave the chart unchanged.');
    }

    public function destroy(Request $request, MedicationReview $review)
    {
        return $this->saved($request, $this->workflow->cancel($request->user(), $review, $this->input($request)), 'Triggered review cancelled.');
    }

    public function decision(Request $request, MedicationReview $review, int $item)
    {
        return $this->saved($request, $this->workflow->decide($request->user(), $review, $item, $this->input($request), $request->file('source')), 'Prescriber decision recorded. Agreed changes continue through Orders.');
    }

    public function outcome(Request $request, MedicationReview $review, int $item)
    {
        return $this->saved($request, $this->workflow->addOutcome($request->user(), $review, $item, $this->input($request)), 'Controlled-medicine outcome recorded.');
    }

    public function interval(Request $request, Client $client)
    {
        $this->workflow->interval($request->user(), $client, $this->input($request));

        return $this->saved($request, null, 'Review interval saved. Booked dates stay as they are.');
    }

    private function saved(Request $request, ?MedicationReview $review, string $message)
    {
        return $request->expectsJson()
            ? response()->json(['saved' => true, 'message' => $message, 'review_id' => $review?->id])
            : back()->with('success', $message);
    }

    /** Legacy action advancement must never claim implementation or prescriber agreement. */
    public function advance(Request $request, MedicationReview $review)
    {
        $this->reader->detail($request->user(), $review->id);
        abort(422, 'Record the prescriber’s decision on the recommendation, then enter and check the change through Orders.');
    }

    public function source(Request $request, MedicationReview $review, ?int $item = null)
    {
        $review = $this->reader->detail($request->user(), $review->id);
        abort_unless($this->reader->canReadSource($review, $request->user()), 404);
        $row = $item !== null ? $review->items()->where('client_id', $review->client_id)->whereKey($item)->firstOrFail() : $review;
        if ($item !== null) {
            abort_if($row->isControlled() && ! $request->user()->canDo('medications.controlled.view'), 404);
        }
        $prefix = $item !== null ? 'decision_source_' : 'source_';
        $path = $row->{$prefix.'path'};
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        $headers = ['Content-Type' => $row->{$prefix.'mime'} ?? 'application/octet-stream', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"];

        return $request->boolean('download')
            ? Storage::disk('local')->download($path, $row->{$prefix.'name'}, $headers)
            : Storage::disk('local')->response($path, $row->{$prefix.'name'}, $headers, 'inline');
    }

    public function pickers(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->canDo(MedicationReviewWorkflow::MANAGE), 403);
        $data = $request->validate(['kind' => ['required', Rule::in(['person', 'owner', 'clinician', 'prescriber'])], 'client_id' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);
        $clientId = $request->integer('client_id') ?: null;
        $ids = $this->reader->clientIds($actor, clientId: $clientId);
        $q = trim($data['q'] ?? '');
        if ($data['kind'] === 'person') {
            $options = Client::query()->whereIn('id', $ids)->whereNotIn('status', ['inactive', 'discharged', 'deceased'])
                ->when($q !== '', fn ($query) => $query->where(fn ($s) => $s->where('first_name', 'like', '%'.$q.'%')->orWhere('last_name', 'like', '%'.$q.'%')))
                ->orderBy('last_name')->limit(30)->get()->map(fn ($c) => ['value' => (string) $c->id, 'label' => trim($c->first_name.' '.$c->last_name), 'description' => $c->site?->name]);
        } elseif ($data['kind'] === 'owner') {
            abort_unless($clientId !== null, 422, 'Choose a person first.');
            $client = Client::query()->findOrFail($clientId);
            $options = $this->scope->staffPicker([(int) $client->site_id])->filter(fn ($row) => str_contains(mb_strtolower($row['name']), mb_strtolower($q)))
                ->filter(fn ($row) => User::query()->find($row['id'])?->canDo(MedicationReviewWorkflow::MANAGE))
                ->take(30)->map(fn ($row) => ['value' => (string) $row['id'], 'label' => $row['name']])->values();
        } else {
            // A bounded directory from this reader's permitted records; custom clinicians remain possible.
            $options = MedicationReview::query()->whereIn('client_id', $ids)->whereNotNull('reviewer_name')
                ->when($data['kind'] === 'prescriber', fn ($query) => $query->whereIn('reviewer_role', ['GP', 'Nurse practitioner', 'Specialist']))
                ->when($q !== '', fn ($query) => $query->where('reviewer_name', 'like', '%'.$q.'%'))
                ->orderBy('reviewer_name')->select(['reviewer_name', 'reviewer_role', 'clinician_practice'])->distinct()->limit(30)->get()
                ->map(fn ($r) => ['value' => $r->reviewer_name, 'label' => $r->reviewer_name, 'description' => implode(' · ', array_filter([$r->reviewer_role, $r->clinician_practice]))]);
            if ($data['kind'] === 'prescriber') {
                $orders = MedicationPrescriberOrder::query()->whereIn('client_id', $ids)->whereNotNull('prescriber_name');
                if (! $actor->canDo('medications.controlled.view')) {
                    $orders->where('controlled_drug_snapshot', false)->whereDoesntHave('medication', fn ($m) => $m->where('controlled_drug', true));
                }
                $options = $options->concat($orders->when($q !== '', fn ($query) => $query->where('prescriber_name', 'like', '%'.$q.'%'))
                    ->orderBy('prescriber_name')->select('prescriber_name')->distinct()->limit(30)->get()
                    ->map(fn ($o) => ['value' => $o->prescriber_name, 'label' => $o->prescriber_name]))->unique('value')->take(30)->values();
            }
        }

        return response()->json(['options' => $options]);
    }

    private function input(Request $request): array
    {
        $data = $request->except('source');
        foreach (['participants', 'items', 'new_medicine'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $decoded = json_decode($data[$key], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data[$key] = $decoded;
                }
            }
        }

        return $data;
    }
}
