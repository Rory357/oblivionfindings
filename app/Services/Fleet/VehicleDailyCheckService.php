<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistTemplate;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Daily vehicle checks from the Daily checks page. Every check is its own
 * submitted record against the exact daily checklist version; checking again
 * adds a record and never changes an earlier one. Daily checks are recorded
 * observations: no approved rule evaluates them, so they are never Passed or
 * Failed, and they never block bookings or a maintenance release. An issue is
 * followed up by reporting it to Maintenance from the vehicle's checks.
 * Lock order: the vehicle, then its records, then the checklist.
 */
class VehicleDailyCheckService
{
    public const CONDITIONS = ['good', 'issue'];

    /** The checklist created the first time a daily check is recorded. */
    private const CHECKLIST = [
        'name' => 'Daily Vehicle Check',
        'items' => [
            ['label' => 'Visual Condition', 'type' => 'select', 'options' => ['good', 'issue']],
            ['label' => 'Notes', 'type' => 'text'],
        ],
    ];

    public function __construct(
        private readonly SecurityDevicesAccessService $vehicles,
        private readonly VehicleCheckLibraryService $library,
    ) {}

    /**
     * Record a daily check for a vehicle in the person's fleet scope. An
     * identical retry returns the check it already recorded.
     *
     * @param  array<string,mixed>  $input  condition (good|issue) and optional notes
     */
    public function record(User $actor, int $assetId, array $input, string $requestKey): FleetChecklistRun
    {
        Validator::make($input, [
            'condition' => ['required', 'string', 'in:'.implode(',', self::CONDITIONS)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'condition.required' => 'Choose Good or Issue.',
            'condition.in' => 'Choose Good or Issue.',
            'notes.max' => 'Keep the notes under 2,000 characters.',
        ])->validate();
        if (mb_strlen(trim($requestKey)) < 8 || mb_strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['request_key' => 'Reload the page, then record the check again.']);
        }
        $condition = (string) $input['condition'];
        $notes = trim((string) ($input['notes'] ?? '')) ?: null;
        $fingerprint = MaintenanceFingerprint::of([
            'actor' => (int) $actor->id, 'operation' => 'fleet.daily_check',
            'asset' => $assetId, 'condition' => $condition, 'notes' => $notes,
        ]);

        try {
            return DB::transaction(function () use ($actor, $assetId, $condition, $notes, $requestKey, $fingerprint): FleetChecklistRun {
                $current = User::query()->findOrFail($actor->id);
                $asset = $this->vehicles->accessibleVehiclesForFleet($current)->whereKey($assetId)
                    ->lockForUpdate()->first() ?? abort(404);
                $prior = FleetChecklistRun::query()->where('user_id', $current->id)
                    ->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    return $this->sameRequest($prior, (int) $asset->id, $fingerprint);
                }

                $template = $this->checklist();
                $version = $this->library->ensureCurrentVersion($template);
                $items = is_array($version->items) ? array_values($version->items) : [];
                $form = $this->form($this->library->questions($items));
                if ($form['condition'] === null) {
                    throw ValidationException::withMessages([
                        'condition' => 'The daily checklist has changed and can’t be recorded here. Ask a fleet manager to review it.',
                    ]);
                }
                $answers = [$form['condition'] => ['result' => $condition]];
                if ($notes !== null && $form['notes'] !== null) {
                    $answers[$form['notes']] = ['result' => $notes];
                }
                $now = now();
                // Whole seconds: these columns would otherwise round up past submitted_at.
                $observed = $now->copy()->startOfSecond();

                $run = new FleetChecklistRun;
                $run->forceFill([
                    'template_id' => $template->id,
                    'template_version_id' => $version->id,
                    'asset_id' => $asset->id,
                    'user_id' => $current->id,
                    'responses' => $answers + ['_metadata' => ['source' => 'daily_check']],
                    'passed' => $condition === 'good',
                    // Notes stay with the checklist's notes question when it has one.
                    'notes' => $form['notes'] === null ? $notes : null,
                    'completed_at' => $observed,
                    'presented_template_json' => [
                        'template_id' => $template->id,
                        'name' => $template->name,
                        'type' => $template->type,
                        'items' => $items,
                    ],
                    // No approved rule evaluates a daily check, so none is recorded.
                    'outcome' => $condition === 'good' ? FleetChecklistRun::OUTCOME_NO_ISSUE : FleetChecklistRun::OUTCOME_ISSUE,
                    'check_kind' => FleetChecklistRun::KIND_DAILY,
                    'observed_at' => $observed,
                    'submitted_at' => $now->format('Y-m-d H:i:s.u'),
                    'request_key' => $requestKey,
                    'request_fingerprint' => $fingerprint,
                ])->save();
                AuditLogger::logOrFail('fleet.daily_check.submit', $run, [
                    'asset_id' => $asset->id, 'template_version_id' => $version->id, 'outcome' => $run->outcome,
                ]);

                return $run;
            }, 3);
        } catch (QueryException $error) {
            // A concurrent identical submission won the (person, request key) slot.
            if ((int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            $prior = FleetChecklistRun::query()->where('user_id', $actor->id)->where('request_key', $requestKey)->first();
            if ($prior === null) {
                throw $error;
            }

            return $this->sameRequest($prior, $assetId, $fingerprint);
        }
    }

    /** The notes recorded with a daily check: its notes answer, or the run notes on earlier checks. */
    public function notes(FleetChecklistRun $run): ?string
    {
        $presented = is_array($run->presented_template_json) ? $run->presented_template_json : [];
        $question = $this->form($this->library->questions(is_array($presented['items'] ?? null) ? $presented['items'] : []))['notes'];
        $answer = $question !== null ? (($run->responses ?? [])[$question] ?? null) : null;
        $value = is_array($answer) ? ($answer['result'] ?? null) : $answer;

        return is_string($value) && trim($value) !== '' ? trim($value) : $run->notes;
    }

    /**
     * The questions the daily form answers: the Good / Issue choice and, when
     * the checklist has one, its notes question. The form can't record a
     * checklist with another required question.
     *
     * @param  list<array{id:string,label:string,kind:string,required:bool,options:list<array{value:string,label:string}>}>  $questions
     * @return array{condition:?string, notes:?string}
     */
    private function form(array $questions): array
    {
        $form = ['condition' => null, 'notes' => null];
        foreach ($questions as $question) {
            $values = array_column($question['options'], 'value');
            if ($form['condition'] === null && array_diff(self::CONDITIONS, $values) === []) {
                $form['condition'] = $question['id'];
            } elseif ($form['notes'] === null && $question['kind'] === 'text') {
                $form['notes'] = $question['id'];
            } elseif ($question['required']) {
                return ['condition' => null, 'notes' => null];
            }
        }

        return $form;
    }

    /** The daily checklist, locked by its key; created with its original questions on first use. */
    private function checklist(): FleetChecklistTemplate
    {
        $id = FleetChecklistTemplate::query()->where('type', FleetChecklistTemplate::TYPE_DAILY_CHECK)
            ->orderBy('id')->value('id');

        return $id !== null
            ? FleetChecklistTemplate::query()->whereKey($id)->lockForUpdate()->firstOrFail()
            : FleetChecklistTemplate::query()->create([
                'name' => self::CHECKLIST['name'], 'type' => FleetChecklistTemplate::TYPE_DAILY_CHECK,
                'items' => self::CHECKLIST['items'], 'is_active' => true,
            ]);
    }

    private function sameRequest(FleetChecklistRun $prior, int $assetId, string $fingerprint): FleetChecklistRun
    {
        if ((int) $prior->asset_id !== $assetId || ! hash_equals((string) $prior->request_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages([
                'request_key' => 'This check was already saved with different details. Reload the page, then record it again.',
            ]);
        }

        return $prior;
    }
}
