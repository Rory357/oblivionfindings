<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Http\Controllers\Concerns\ProvidesRoadmapInertiaProps;
use App\Domain\Roadmap\Http\Requests\StoreSuggestionRequest;
use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Services\RoadmapChangeLogService;
use App\Domain\Roadmap\Services\RoadmapScoringService;
use App\Domain\Roadmap\Services\RoadmapSuggestionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SuggestionController extends Controller
{
    use ProvidesRoadmapInertiaProps;

    public function __construct(
        protected RoadmapSuggestionService $suggestionService,
        protected RoadmapScoringService $scoringService,
        protected RoadmapChangeLogService $changeLogService,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $status = $request->filled('status')
            ? $request->string('status')->value()
            : ($this->shouldReturnJson($request) ? null : InitiativeSuggestion::STATUS_TRIAGE_PENDING);

        $query = InitiativeSuggestion::query()
            ->with(['triageOwner:id,name,email']);

        if ($status) {
            $query->where('status', $status);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->string('source')->value());
        }

        $items = $query
            ->orderByDesc('last_seen_at')
            ->paginate($this->paginationPerPage($request, 50, 100))
            ->withQueryString();

        if ($this->shouldReturnJson($request)) {
            return response()->json(['items' => $items]);
        }

        return Inertia::render('Roadmap/Suggestions/Index', [
            'items' => $items,
            'filters' => [
                'status' => $status,
                'source' => $request->input('source'),
            ],
            'managers' => $this->roadmapManagerOptions($request),
            'can' => $this->roadmapCan($request),
        ]);
    }

    public function ingest(Request $request)
    {
        $result = $this->suggestionService->ingestAll();

        return response()->json([
            'ingested' => $result,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function triage(Request $request, InitiativeSuggestion $suggestion)
    {
        $data = $request->validate([
            'status' => ['required', 'in:triage_pending,accepted,rejected,snoozed'],
            'snoozed_until' => ['nullable', 'date'],
            'triage_owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'triage_notes' => ['nullable', 'string'],
        ]);

        $notesProvided = array_key_exists('triage_notes', $data);
        $triageNotes = null;
        if ($notesProvided) {
            $triageNotes = is_string($data['triage_notes'] ?? null)
                ? trim((string) $data['triage_notes'])
                : null;

            if ($triageNotes === '') {
                $triageNotes = null;
            }
        }

        $updated = $this->suggestionService->triage(
            $suggestion,
            $data['status'],
            $data['triage_owner_id'] ?? $suggestion->triage_owner_id ?? $request->user()?->id,
            isset($data['snoozed_until']) ? new \DateTimeImmutable($data['snoozed_until']) : null,
            $triageNotes,
            $notesProvided,
        );

        $this->changeLogService->log(
            InitiativeSuggestion::class,
            $updated->id,
            'suggestion.triaged',
            [
                'status' => $updated->status,
                'triage_owner_id' => $updated->triage_owner_id,
                'triage_notes' => $updated->triage_notes,
            ],
            null,
            $request->user()?->id,
        );

        return response()->json(['item' => $updated]);
    }

    public function convert(StoreSuggestionRequest $request, InitiativeSuggestion $suggestion)
    {
        $data = $request->validated();

        $initiative = $this->suggestionService->convertToInitiative($suggestion, $data, $request->user()?->id);
        $score = $this->scoringService->score($initiative, 'board_ceo', true);

        $this->changeLogService->log(
            InitiativeSuggestion::class,
            $suggestion->id,
            'suggestion.converted',
            ['initiative_id' => $initiative->id, 'score' => $score['score']],
            null,
            $request->user()?->id,
        );

        return response()->json([
            'suggestion' => $suggestion->fresh(),
            'initiative' => $initiative->fresh(),
        ], 201);
    }

    protected function shouldReturnJson(Request $request): bool
    {
        return $request->expectsJson()
            || $request->wantsJson()
            || $request->ajax();
    }
}
