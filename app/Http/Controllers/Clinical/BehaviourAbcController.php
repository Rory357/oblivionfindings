<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\BehaviourFunction;
use App\Domain\Clinical\Services\ClinicalAttachmentService;
use App\Domain\Clinical\Services\ClinicalSiteAccessService;
use App\Http\Controllers\Controller;
use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Services\Client\BehaviourAbcService;
use App\Services\Clients\ClientProfileSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Structured ABC (Antecedent–Behaviour–Consequence) records for the client
 * profile Behaviour / ABC tab. Reads require the client `view` policy plus
 * canonical behaviour access. Creation uses `clinical.events.record`, while
 * corrections and removal use `clinical.observations.correct`.
 */
class BehaviourAbcController extends Controller
{
    public function __construct(
        protected BehaviourAbcService $service,
        protected ClientProfileSectionAccess $sectionAccess,
        protected ClinicalSiteAccessService $siteAccess,
    ) {}

    /**
     * Paginated ABC log for the tab (JSON).
     */
    public function index(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $this->ensureCanView($request, $client);

        $entries = $this->siteAccess->applyClientRecordScope(BehaviourAbcEntry::query(), $request->user())
            ->forClient($client->id)
            ->recent()
            ->with('recorder:id,name')
            ->paginate(15)
            ->through(fn (BehaviourAbcEntry $entry) => $this->transform($entry));

        return response()->json($entries);
    }

    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);
        $this->ensureCanRecord($request);

        $validated = $this->validatePayload($request, $client, isUpdate: false);

        $entry = $this->service->record($client, $request->user(), $validated);

        if ($request->hasFile('attachments')) {
            app(ClinicalAttachmentService::class)
                ->attachMany($entry, $request->file('attachments'), $request->user());
        }

        if ($request->wantsJson()) {
            return response()->json($this->transform($entry->fresh('recorder'), detail: true), 201);
        }

        return back()->with('success', 'ABC entry saved.');
    }

    /**
     * Full detail for the manage / edit modal (JSON).
     */
    public function show(Request $request, Client $client, BehaviourAbcEntry $abc)
    {
        $this->authorize('view', $client);
        $this->ensureCanView($request, $client);
        abort_unless($abc->client_id === $client->id, 404);
        $this->assertCanAccessEntry($request, $abc);

        $abc->load(['recorder:id,name', 'carePlan:id,title', 'followupCompleter:id,name']);

        return response()->json($this->transform($abc, detail: true));
    }

    public function update(Request $request, Client $client, BehaviourAbcEntry $abc)
    {
        $this->authorize('view', $client);
        $this->ensureCanView($request, $client);
        abort_unless($abc->client_id === $client->id, 404);
        $this->assertCanAccessEntry($request, $abc);
        $this->ensureCanCorrect($request);

        $validated = $this->validatePayload($request, $client, isUpdate: true);

        $entry = $this->service->update($abc, $request->user(), $validated);

        if ($request->wantsJson()) {
            return response()->json($this->transform($entry->fresh('recorder'), detail: true));
        }

        return back()->with('success', 'ABC entry updated.');
    }

    public function destroy(Request $request, Client $client, BehaviourAbcEntry $abc)
    {
        $this->authorize('view', $client);
        $this->ensureCanView($request, $client);
        abort_unless($abc->client_id === $client->id, 404);
        $this->assertCanAccessEntry($request, $abc);
        $this->ensureCanCorrect($request);

        $abc->delete();

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('success', 'ABC entry removed.');
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function ensureCanRecord(Request $request): void
    {
        abort_unless((bool) $request->user()?->canDo('clinical.events.record'), 403);
    }

    private function ensureCanView(Request $request, Client $client): void
    {
        $user = $request->user();
        abort_unless(
            $user && $this->sectionAccess->canViewBehaviour($user, $client),
            403,
        );
    }

    private function ensureCanCorrect(Request $request): void
    {
        abort_unless(
            (bool) $request->user()?->canDo('clinical.observations.correct'),
            403,
        );
    }

    private function assertCanAccessEntry(Request $request, BehaviourAbcEntry $entry): void
    {
        abort_unless(
            $this->siteAccess->applyClientRecordScope(
                BehaviourAbcEntry::query()->whereKey($entry->getKey()),
                $request->user(),
            )->exists(),
            403,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, Client $client, bool $isUpdate): array
    {
        return $request->validate([
            'occurred_at' => [$isUpdate ? 'nullable' : 'required', 'date'],
            'setting' => ['nullable', 'string', 'max:255'],
            'others_present' => ['nullable', 'string', 'max:255'],
            'antecedent' => ['required', 'string', 'max:5000'],
            'behaviour' => ['required', 'string', 'max:5000'],
            'consequence' => ['required', 'string', 'max:5000'],
            'behaviour_tags' => ['nullable', 'array'],
            'behaviour_tags.*' => ['string', 'max:50'],
            'behaviour_function' => ['nullable', Rule::in(array_column(BehaviourFunction::cases(), 'value'))],
            'intensity' => ['required', Rule::in(BehaviourAbcEntry::INTENSITIES)],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'strategies_used' => ['nullable', 'string', 'max:5000'],
            'harm_occurred' => ['nullable', 'boolean'],
            'harm_notes' => ['nullable', 'string', 'max:2000'],
            'escalated' => ['nullable', 'boolean'],
            'requires_followup' => ['nullable', 'boolean'],
            'followup_notes' => ['nullable', 'string', 'max:2000'],
            'followup_completed' => ['nullable', 'boolean'],
            'linked_care_plan_id' => [
                'nullable',
                Rule::exists('care_plans', 'id')->where('client_id', $client->id),
            ],
            // Evidence staged in the Record ABC wizard (esp. when harm occurred).
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(BehaviourAbcEntry $entry, bool $detail = false): array
    {
        $base = [
            'id' => $entry->id,
            'occurred_at' => $entry->occurred_at?->toISOString(),
            'setting' => $entry->setting,
            'antecedent' => $entry->antecedent,
            'behaviour' => $entry->behaviour,
            'consequence' => $entry->consequence,
            'behaviour_tags' => $entry->behaviour_tags ?? [],
            'behaviour_function' => $entry->behaviour_function?->value,
            'behaviour_function_label' => $entry->behaviour_function?->label(),
            'intensity' => $entry->intensity,
            'duration_seconds' => $entry->duration_seconds,
            'harm_occurred' => $entry->harm_occurred,
            'escalated' => $entry->escalated,
            'requires_followup' => $entry->requires_followup,
            'followup_completed' => $entry->followup_completed_at !== null,
            'recorder' => $entry->recorder ? ['id' => $entry->recorder->id, 'name' => $entry->recorder->name] : null,
        ];

        if (! $detail) {
            return $base;
        }

        return array_merge($base, [
            // Worker-local value for the datetime-local edit input (avoids tz drift).
            'occurred_at_local' => $entry->occurred_at
                ?->setTimezone(config('app.worker_timezone', 'Pacific/Auckland'))
                ->format('Y-m-d\TH:i'),
            'others_present' => $entry->others_present,
            'strategies_used' => $entry->strategies_used,
            'harm_notes' => $entry->harm_notes,
            'followup_notes' => $entry->followup_notes,
            'followup_completed_at' => $entry->followup_completed_at?->toISOString(),
            'linked_care_plan_id' => $entry->linked_care_plan_id,
            'linked_care_plan' => $entry->relationLoaded('carePlan') && $entry->carePlan
                ? ['id' => $entry->carePlan->id, 'title' => $entry->carePlan->title]
                : null,
        ]);
    }
}
