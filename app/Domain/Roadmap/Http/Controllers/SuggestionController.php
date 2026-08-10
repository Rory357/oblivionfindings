<?php

namespace App\Domain\Roadmap\Http\Controllers;

use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Services\RoadmapChangeLogService;
use App\Domain\Roadmap\Services\RoadmapScoringService;
use App\Domain\Roadmap\Services\RoadmapSuggestionService;
use App\Domain\Roadmap\Http\Requests\StoreSuggestionRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SuggestionController extends Controller
{
    public function __construct(
        protected RoadmapSuggestionService $suggestionService,
        protected RoadmapScoringService $scoringService,
        protected RoadmapChangeLogService $changeLogService,
    ) {}

    public function index(Request $request): JsonResponse|RedirectResponse
    {
        if (! $this->shouldReturnJson($request)) {
            return redirect()->route('roadmap.dashboard');
        }

        $tenantId = $this->tenantId($request);

        $query = InitiativeSuggestion::query()
            ->forTenant($tenantId)
            ->with(['triageOwner:id,name,email']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('source')) {
            $query->where('source', $request->string('source')->value());
        }

        return response()->json([
            'items' => $query->orderByDesc('last_seen_at')->paginate(50),
        ]);
    }

    public function ingest(Request $request)
    {
        $tenantId = $this->tenantId($request);

        $result = $this->suggestionService->ingestAll($tenantId);

        return response()->json([
            'ingested' => $result,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function triage(Request $request, InitiativeSuggestion $suggestion)
    {
        $this->assertTenant($request, $suggestion->tenant_id);

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
            $updated->tenant_id,
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
        $this->assertTenant($request, $suggestion->tenant_id);

        $data = $request->validated();

        $initiative = $this->suggestionService->convertToInitiative($suggestion, $data, $request->user()?->id);
        $score = $this->scoringService->score($initiative, 'board_ceo', true);

        $this->changeLogService->log(
            $initiative->tenant_id,
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

    protected function tenantId(Request $request): ?int
    {
        if ($request->filled('tenant_id')) {
            return (int) $request->integer('tenant_id');
        }

        return $request->user()?->tenant_id ?? null;
    }

    protected function assertTenant(Request $request, ?int $resourceTenantId): void
    {
        $tenantId = $this->tenantId($request);

        if ($tenantId !== null && $resourceTenantId !== null && $tenantId !== $resourceTenantId) {
            abort(403, 'Tenant scope mismatch.');
        }
    }

    protected function shouldReturnJson(Request $request): bool
    {
        return $request->expectsJson()
            || $request->wantsJson()
            || $request->ajax();
    }
}
