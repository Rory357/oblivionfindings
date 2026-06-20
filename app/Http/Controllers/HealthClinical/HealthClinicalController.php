<?php

namespace App\Http\Controllers\HealthClinical;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Enums\ObservationType;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\HealthClinical\HealthSummaryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Per-client clinical health summary.
 *
 * The observation/event/protocol recording + register methods that once lived
 * here wrote to a dead parallel stack (App\Models\Clinical* + the phantom
 * `health_clinical_*` tables that never migrate). They have been retired; the
 * canonical write path is App\Domain\Clinical\Services\* via
 * App\Http\Controllers\Clinical\* (see docs/health-clinical-redesign/PROGRESS.md §1A).
 * Only the read-only client summary remains — HealthSummaryService already reads
 * the canonical Domain models.
 */
class HealthClinicalController extends Controller
{
    public function __construct(
        private readonly HealthSummaryService $summaryService,
    ) {}

    public function clientSummary(Request $request, Client $client): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless(
            $auth && (
                $auth->canDo('clinical.observations.viewAny')
                || $auth->canDo('clinical.observations.viewAssigned')
            ),
            403
        );

        if (! $auth->canDo('clinical.observations.viewAny')) {
            $this->authorize('view', $client);
        }

        $summary = $this->summaryService->forClient($client->id);

        return Inertia::render('health-clinical/ClientSummary', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'summary' => $summary,
            'observation_types' => collect(ObservationType::cases())
                ->mapWithKeys(fn (ObservationType $t) => [$t->value => $t->label()])
                ->all(),
            'event_types' => collect(ClinicalEventType::cases())
                ->mapWithKeys(fn (ClinicalEventType $t) => [$t->value => $t->label()])
                ->all(),
        ]);
    }
}
