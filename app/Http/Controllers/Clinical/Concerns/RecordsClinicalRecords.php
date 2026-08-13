<?php

namespace App\Http\Controllers\Clinical\Concerns;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Services\ClinicalAttachmentService;
use App\Enums\AlertSeverity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared validation + permission gating for recording clinical observations and
 * events. The canonical Domain services (App\Domain\Clinical\Services\*) persist
 * the records; this trait owns the request contract so that the module-level
 * register entry point (HealthClinicalDashboardController) and the client-profile
 * entry point (ClientClinicalController) can NEVER drift apart — they validate
 * through the exact same rules. See docs/health-clinical-redesign/PROGRESS.md §8.
 */
trait RecordsClinicalRecords
{
    /**
     * Gate + validate an observation recording request.
     *
     * Mirrors ClinicalObservationService::record()'s expected input. The per-type
     * `data` payload is validated structurally by the service (validateDataForType).
     *
     * @return array<string, mixed>
     */
    protected function validateObservationInput(Request $request, User $user): array
    {
        if (! $user->canDo('clinical.observations.record') && ! $user->canDo('clinical.observations.recordClinical')) {
            abort(403);
        }

        $validated = $request->validate([
            'observation_type' => ['required', Rule::in(array_column(ObservationType::cases(), 'value'))],
            'data' => ['present', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'recorded_at' => ['nullable', 'date'],
            // The canonical observation command resolves this opaque id through
            // its owning protocol and performs the authoritative client/type/
            // pending checks under row locks.
            'protocol_schedule_id' => ['nullable', 'integer'],
            // Flag-on-entry → pushes the record to the RN sign-off queue.
            'is_flagged' => ['nullable', 'boolean'],
            'flagged_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $type = ObservationType::from($validated['observation_type']);

        // Vitals & Pain are clinical observations — gate behind the clinical ability.
        if ($type->requiresClinicalPermission() && ! $user->canDo('clinical.observations.recordClinical')) {
            abort(403, 'Clinical observation permission required for '.$type->label());
        }

        return $validated;
    }

    /**
     * Gate + validate a clinical-event recording request.
     *
     * Mirrors ClinicalEventService::record()'s expected input. `witnesses` is
     * persisted by the service but was historically dropped by the validators
     * (handoff §7.2 / B3) — it is restored here.
     *
     * @return array<string, mixed>
     */
    protected function validateClinicalEventInput(Request $request, User $user): array
    {
        if (! $user->canDo('clinical.events.record')) {
            abort(403);
        }

        return $request->validate([
            'event_type' => ['required', Rule::in(array_column(ClinicalEventType::cases(), 'value'))],
            'severity' => ['required', Rule::in(AlertSeverity::ALL)],
            'occurred_at' => ['required', 'date'],
            'description' => ['required', 'string', 'max:5000'],
            'immediate_action_taken' => [
                Rule::requiredIf(fn () => ClinicalEventType::tryFrom((string) $request->input('event_type'))?->requiresImmediateAction() ?? false),
                'nullable',
                'string',
                'max:5000',
            ],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'witnesses' => ['nullable', 'array'],
            'witnesses.*' => ['string', 'max:255'],
            'requires_followup' => ['nullable', 'boolean'],
            'followup_notes' => ['nullable', 'string', 'max:2000'],
            // Evidence staged in the wizard (created with the record).
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx'],
        ]);
    }

    /**
     * Persist the `attachments[]` files a record wizard staged onto the freshly
     * created record (event / ABC entry). Returns the number saved.
     */
    protected function saveClinicalAttachments(Request $request, Model $attachable): int
    {
        $files = $request->file('attachments');

        if (! $files) {
            return 0;
        }

        return app(ClinicalAttachmentService::class)
            ->attachMany($attachable, is_array($files) ? $files : [$files], $request->user());
    }
}
