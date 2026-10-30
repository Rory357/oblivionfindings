<?php

namespace App\Services\Fleet;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\AssetDocument;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistRunAmendment;
use App\Models\FleetChecklistTemplate;
use App\Models\FleetVehicleCheckRequirement;
use App\Models\FleetWorkOrder;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Vehicle checks from the vehicle profile. Checks are recorded by the PKG-01
 * MaintenanceCheckService (approved rules alone decide Passed or Failed, and
 * a check never releases a restriction); this adds the exact checklist
 * version, required-evidence rules and supporting files kept as vehicle
 * documents. Reports go through MaintenanceReportService. Lock order: the
 * vehicle, then its records, then the checklist.
 */
class VehicleCheckService
{
    private const CHANGED = 'This checklist changed after you opened it. Review the latest version, then submit the check again. Your answers are kept.';

    public function __construct(
        private readonly MaintenanceAccessService $maintenance,
        private readonly MaintenanceCheckService $checks,
        private readonly MaintenanceReportService $reports,
        private readonly VehicleCheckLibraryService $library,
        private readonly VehicleDocumentService $documents,
        private readonly SecurityDevicesAccessService $vehicles,
        private readonly VehicleStaffDirectory $staff,
        private readonly MaintenanceTransitionService $transitions,
    ) {}

    /**
     * Record a check against the checklist version the person answered.
     * An identical retry returns the same check and resumes its files.
     *
     * @param  array<string,mixed>  $input
     * @param  list<UploadedFile>  $files
     * @return array{run: FleetChecklistRun, files: list<AssetDocument>}
     */
    public function submit(User $actor, int $assetId, array $input, array $files, string $requestKey): array
    {
        abort_unless($this->maintenance->canManage($actor), 403);
        $this->assertKey($requestKey);
        Validator::make($input, [
            'template_id' => ['required', 'integer', 'min:1'],
            'template_version_id' => ['nullable', 'integer', 'min:1'],
            'items_sha256' => ['required', 'string', 'size:64'],
            'observed_local' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/'],
            'observed_offset' => ['nullable', 'string', 'regex:/^[+-]\d{2}:\d{2}$/'],
            'answers' => ['nullable', 'array', 'max:100'],
            'answers.*' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rule_version_id' => ['nullable', 'integer', 'min:1'],
        ], [
            'template_id.required' => 'Choose the checklist.',
            'observed_local.required' => 'Choose a complete observation date and time.',
            'observed_local.regex' => 'Choose a complete observation date and time.',
        ])->validate();
        try {
            $observedAt = MaintenanceLocalTime::toUtc((string) $input['observed_local'], $input['observed_offset'] ?? null);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages([
                'observed_local' => $error->errors()['time'][0] ?? 'Choose an exact Auckland date and time.',
            ]);
        }
        if (CarbonImmutable::parse($observedAt, 'UTC')->greaterThan(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['observed_local' => 'The observation time can’t be in the future.']);
        }
        $hashes = $this->checkFiles($files);
        $answers = [];
        foreach ((array) ($input['answers'] ?? []) as $key => $value) {
            $value = is_string($value) ? trim($value) : $value;
            if ($value !== null && $value !== '') {
                $answers[(string) $key] = (string) $value;
            }
        }
        $data = [
            'asset_id' => $assetId,
            'template_id' => (int) $input['template_id'],
            'check_kind' => 'check',
            'answers' => $answers,
            'request_key' => $requestKey,
            'rule_version_id' => isset($input['rule_version_id']) ? (int) $input['rule_version_id'] : null,
            'observed_at' => $observedAt,
            'notes' => trim((string) ($input['notes'] ?? '')) ?: null,
            // Kept with the original responses; the exact version is also a column.
            'metadata' => [
                'source' => 'vehicle_profile',
                'template_version_id' => isset($input['template_version_id']) ? (int) $input['template_version_id'] : null,
                'evidence_sha256' => $hashes,
            ],
        ];

        $run = DB::transaction(function () use ($actor, $assetId, $input, $data, $answers, $hashes): FleetChecklistRun {
            $asset = $this->maintenance->asset($actor, $assetId, true);
            $prior = FleetChecklistRun::query()->where('user_id', $actor->id)
                ->where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($prior !== null) {
                // A retry: the check service confirms it is the same submission.
                return $this->checks->submit($actor, $data);
            }
            $template = FleetChecklistTemplate::query()->maintenanceChecklists()->whereKey($data['template_id'])
                ->where('is_active', true)->lockForUpdate()->first() ?? abort(404);
            $items = is_array($template->items) ? array_values($template->items) : [];
            abort_unless(hash_equals(MaintenanceFingerprint::of($items), (string) $input['items_sha256']), 409, self::CHANGED);
            $version = $this->library->ensureCurrentVersion($template);
            $expected = isset($input['template_version_id']) ? (int) $input['template_version_id'] : null;
            abort_if($expected !== null && $expected !== (int) $version->id, 409, self::CHANGED);
            $assignment = ['assignment' => $version->assignment, 'assignment_asset_id' => $version->assignment_asset_id];
            if (! $this->library->appliesTo($assignment, $asset)) {
                throw ValidationException::withMessages(['template_id' => 'This checklist isn’t assigned to this vehicle. Choose another checklist.']);
            }
            $errors = $this->answerErrors($this->library->questions($items), $answers);
            if ($version->evidence_required && $hashes === []) {
                $errors['files'] = 'Add at least one photo or document before submitting this check.';
            }
            if ($hashes !== [] && ! $this->documents->canManage($actor, $asset)) {
                $errors['files'] = 'Adding files to a check needs vehicle document access. Remove the files, or ask a fleet manager to add them.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $run = $this->checks->submit($actor, $data);
            DB::table('fleet_checklist_runs')->where('id', $run->id)->whereNull('template_version_id')
                ->update(['template_version_id' => $version->id]);

            return $run;
        }, 3);

        $stored = [];
        if ($files !== []) {
            // Supporting files stay with the check as private vehicle documents;
            // they never change its answers or outcome.
            $submitted = ($run->submitted_at !== null ? CarbonImmutable::instance($run->submitted_at) : CarbonImmutable::now())
                ->setTimezone('Pacific/Auckland');
            $stored = $this->documents->create($actor, (int) $run->asset_id, [
                'category' => 'Check evidence',
                'document_date' => $submitted->toDateString(),
                'reason' => 'Submitted with check CHK-'.$run->id.'.',
                'source_type' => 'checklist_run',
                'source_id' => $run->id,
            ], $files, 'check-'.$run->id.'-'.substr(hash('sha256', $requestKey), 0, 40))['files'];
        }

        return ['run' => $run->fresh() ?? $run, 'files' => $stored];
    }

    /** Keep an attributable correction beside a submitted check. */
    public function amend(User $actor, int $assetId, int $runId, string $note, string $requestKey): FleetChecklistRunAmendment
    {
        abort_unless($this->maintenance->canManage($actor), 403);
        $this->assertKey($requestKey);
        $note = trim($note);
        if ($note === '') {
            throw ValidationException::withMessages(['note' => 'Record the amendment and its reason.']);
        }
        if (mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'Keep the amendment under 2,000 characters.']);
        }
        $fingerprint = MaintenanceFingerprint::of([
            'actor' => (int) $actor->id, 'operation' => 'vehicle.check.amend',
            'asset' => $assetId, 'run' => $runId, 'note' => $note,
        ]);

        return DB::transaction(function () use ($actor, $assetId, $runId, $note, $requestKey, $fingerprint): FleetChecklistRunAmendment {
            $asset = $this->maintenance->asset($actor, $assetId, true);
            $prior = FleetChecklistRunAmendment::query()->where('recorded_by_user_id', $actor->id)
                ->where('request_key', $requestKey)->lockForUpdate()->first();
            if ($prior !== null) {
                abort_unless((int) $prior->asset_id === (int) $asset->id
                    && hash_equals((string) $prior->request_fingerprint, $fingerprint), 409,
                    'This request was already used for a different amendment. Reload and try again.');

                return $prior;
            }
            $current = User::query()->findOrFail($actor->id);
            abort_unless($this->maintenance->canManage($current), 403);
            $run = FleetChecklistRun::query()->whereKey($runId)->where('asset_id', $asset->id)
                ->whereNotNull('submitted_at')->first() ?? abort(404);
            $amendment = FleetChecklistRunAmendment::query()->create([
                'run_id' => $run->id, 'asset_id' => $asset->id, 'note' => $note,
                'recorded_by_user_id' => $current->id, 'recorded_at' => now(),
                'request_key' => $requestKey, 'request_fingerprint' => $fingerprint,
            ]);
            AuditLogger::logOrFail('fleet.maintenance.check.amend', $run, [
                'asset_id' => $asset->id, 'amendment_id' => $amendment->id, 'note' => $note,
            ]);

            return $amendment;
        }, 3);
    }

    /**
     * "No issue found — release for use" for a check that holds the vehicle.
     * The decision is Maintenance's own (MaintenanceTransitionService), which
     * rechecks authority, the approved Site and eligibility under the vehicle
     * lock; this only checks the request's shape.
     *
     * @param  array<string,mixed>  $input
     */
    public function assess(User $actor, int $assetId, int $runId, array $input, string $requestKey): object
    {
        abort_unless($this->maintenance->canManage($actor), 403);
        $this->assertKey($requestKey);
        Validator::make($input, [
            'decision' => ['required', 'string', 'in:'.MaintenanceRestrictionService::NO_ISSUE_RELEASE],
            'reason' => ['required', 'string', 'max:2000'],
            'confirmed' => ['accepted'],
        ], [
            'decision.required' => 'Choose the assessment decision.',
            'decision.in' => 'Choose the assessment decision.',
            'reason.required' => 'Record why the vehicle is safe to use.',
            'reason.max' => 'Keep the reason under 2,000 characters.',
            'confirmed.accepted' => 'Confirm that you assessed this check and found nothing that stops safe use.',
        ])->validate();

        return $this->transitions->assessCheck($actor, $assetId, $runId, (string) $input['reason'], true, $requestKey);
    }

    /**
     * Set the vehicle's check requirement: its checklist, owner and next due
     * date (the vehicle's inspection_due_at). Versioned; a retried save that
     * already landed reports success.
     *
     * @param  array<string,mixed>  $input
     */
    public function updateRequirement(User $actor, int $assetId, array $input): FleetVehicleCheckRequirement
    {
        abort_unless($actor->canDo('fleet.manage'), 403);
        Validator::make($input, [
            'template_id' => ['required', 'integer', 'min:1'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'owner_user_id' => ['required', 'integer', 'min:1'],
            'expected_version' => ['required', 'integer', 'min:0'],
        ], [
            'template_id.required' => 'Choose the checklist for this vehicle.',
            'due_on.required' => 'Choose the next check date.',
            'due_on.date_format' => 'Choose the next check date.',
            'owner_user_id.required' => 'Choose who is responsible for the check.',
        ])->validate();
        $due = (string) $input['due_on'];
        if ($due < CarbonImmutable::now('Pacific/Auckland')->toDateString()) {
            throw ValidationException::withMessages(['due_on' => 'Choose today or a later date.']);
        }
        $templateId = (int) $input['template_id'];
        $ownerId = (int) $input['owner_user_id'];
        $expected = (int) $input['expected_version'];

        return DB::transaction(function () use ($actor, $assetId, $due, $templateId, $ownerId, $expected): FleetVehicleCheckRequirement {
            $asset = $this->vehicles->fleetVehicle($actor, $assetId, true) ?? abort(404);
            $current = User::query()->findOrFail($actor->id);
            abort_unless($current->canDo('fleet.manage'), 403);
            $requirement = FleetVehicleCheckRequirement::query()->where('asset_id', $asset->id)->lockForUpdate()->first();
            $version = (int) ($requirement?->lock_version ?? 0);
            $currentDue = $asset->inspection_due_at?->toDateString();
            if ($requirement !== null && $version === $expected + 1 && (int) $requirement->template_id === $templateId
                && (int) $requirement->owner_user_id === $ownerId && $currentDue === $due) {
                return $requirement;
            }
            abort_unless($version === $expected, 409,
                'This vehicle’s check requirement changed while you were editing. Review the latest requirement before saving.');
            $template = FleetChecklistTemplate::query()->maintenanceChecklists()->whereKey($templateId)->where('is_active', true)->first();
            $definition = $template !== null
                ? $this->library->describe($template, $this->library->latestVersions([$templateId])[$templateId] ?? null)
                : null;
            if ($definition === null || $definition['questions'] === [] || ! $this->library->appliesTo($definition, $asset)) {
                throw ValidationException::withMessages(['template_id' => 'Choose a checklist available to this vehicle.']);
            }
            if (! $this->staff->isCandidate($asset, $ownerId)) {
                throw ValidationException::withMessages(['owner_user_id' => 'Choose a current staff member at this vehicle’s site.']);
            }

            $before = [
                'template_id' => $requirement?->template_id, 'owner_user_id' => $requirement?->owner_user_id,
                'due_on' => $currentDue, 'lock_version' => $version,
            ];
            $requirement ??= new FleetVehicleCheckRequirement(['asset_id' => $asset->id]);
            $requirement->forceFill([
                'asset_id' => $asset->id, 'template_id' => $templateId, 'owner_user_id' => $ownerId,
                'lock_version' => $version + 1, 'updated_by_user_id' => $current->id,
            ])->save();
            if ($currentDue !== $due || ! $asset->requires_inspection) {
                // The due date is part of the vehicle record, so its version moves on.
                $asset->forceFill([
                    'inspection_due_at' => $due, 'requires_inspection' => true,
                    'vehicle_profile_version' => (int) ($asset->vehicle_profile_version ?? 1) + 1,
                    'updated_by_user_id' => $current->id,
                ])->save();
            }
            AuditLogger::logOrFail('fleet.vehicle.check_requirement.update', $asset, [
                'asset_id' => $asset->id, 'before' => $before,
                'after' => ['template_id' => $templateId, 'owner_user_id' => $ownerId, 'due_on' => $due, 'lock_version' => $version + 1],
            ]);

            return $requirement->fresh() ?? $requirement;
        }, 3);
    }

    /**
     * Report a problem for Maintenance, optionally from a recorded check and
     * optionally linked to existing open work. The site's approved routing
     * decides who assesses it; reporting never releases the vehicle.
     *
     * @param  array<string,mixed>  $input
     * @return array{work_order: FleetWorkOrder, linked: bool}
     */
    public function report(User $actor, int $assetId, array $input, string $requestKey): array
    {
        abort_unless($this->maintenance->canReport($actor), 403);
        $this->assertKey($requestKey);
        Validator::make($input, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'source_run_id' => ['nullable', 'integer', 'min:1'],
            'existing_work_order_id' => ['nullable', 'integer', 'min:1'],
            'estimated_start_date' => ['nullable', 'date_format:Y-m-d', 'required_with:estimated_end_date'],
            'estimated_end_date' => ['nullable', 'date_format:Y-m-d', 'required_with:estimated_start_date', 'after_or_equal:estimated_start_date'],
        ], [
            'title.required' => 'Choose what needs attention.',
            'estimated_start_date.required_with' => 'Finish the selected date range, or choose Not known yet.',
            'estimated_end_date.required_with' => 'Finish the selected date range, or choose Not known yet.',
            'estimated_end_date.after_or_equal' => 'The final day must be on or after the first day.',
        ])->validate();
        // Resolve the vehicle in scope before looking at anything it owns.
        $asset = $this->maintenance->asset($actor, $assetId);
        $run = null;
        if (! empty($input['source_run_id'])) {
            $run = FleetChecklistRun::query()->whereKey((int) $input['source_run_id'])->where('asset_id', $asset->id)
                ->whereNotNull('submitted_at')->first();
            if ($run === null) {
                throw ValidationException::withMessages(['source_id' => 'Choose a check recorded for this vehicle.']);
            }
        }
        $existing = ! empty($input['existing_work_order_id']) ? (int) $input['existing_work_order_id'] : null;
        if ($existing !== null) {
            abort_unless($this->maintenance->canManage($actor), 403);
            $open = FleetWorkOrder::query()->whereKey($existing)->where('asset_id', $asset->id)
                ->whereNotIn('status', ['completed', 'cancelled'])->exists();
            if (! $open) {
                throw ValidationException::withMessages(['existing_work_order_id' => 'Choose open Maintenance work for this vehicle.']);
            }
        }

        $order = $this->reports->submit($actor, [
            'asset_id' => (int) $asset->id,
            'title' => trim((string) $input['title']),
            'description' => trim((string) ($input['description'] ?? '')) ?: null,
            // The Coordinator sets the working priority when assessing.
            'priority' => 'medium',
            'observed_at' => $run?->observed_at !== null
                ? CarbonImmutable::instance($run->observed_at)->utc()->format('Y-m-d H:i:s') : null,
            'estimated_start_date' => $input['estimated_start_date'] ?? null,
            'estimated_end_date' => $input['estimated_end_date'] ?? null,
            'source_type' => $run !== null ? 'fleet_checklist_run' : null,
            'source_id' => $run?->id,
            'existing_work_order_id' => $existing,
            'request_key' => $requestKey,
        ]);

        return ['work_order' => $order, 'linked' => $existing !== null];
    }

    /**
     * Validate files exactly as the vehicle document store will, before the
     * check exists, so a rejected file never leaves a check without its files.
     *
     * @param  list<mixed>  $files
     * @return list<string> SHA-256 of each file
     */
    private function checkFiles(array $files): array
    {
        if (count($files) > VehicleDocumentService::MAX_FILES) {
            throw ValidationException::withMessages(['files' => 'Attach up to '.VehicleDocumentService::MAX_FILES.' files.']);
        }
        $message = 'Choose PDF, PNG or JPEG files up to 10 MiB each.';
        $hashes = [];
        foreach (array_values($files) as $index => $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() < 1
                || $file->getSize() > VehicleDocumentService::MAX_BYTES) {
                throw ValidationException::withMessages(["files.{$index}" => $message]);
            }
            $path = (string) $file->getRealPath();
            $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '');
            $accepted = VehicleDocumentService::ACCEPTED[strtolower($file->getClientOriginalExtension())] ?? [];
            if (! in_array($mime, $accepted, true)
                || ($mime === 'application/pdf' && ! str_starts_with((string) file_get_contents($path, false, null, 0, 5), '%PDF-'))) {
                throw ValidationException::withMessages(["files.{$index}" => $message]);
            }
            if (str_starts_with($mime, 'image/') && @getimagesize($path) === false) {
                throw ValidationException::withMessages(["files.{$index}" => 'This image could not be read. Choose another file.']);
            }
            $hashes[] = (string) hash_file('sha256', $path);
        }

        return $hashes;
    }

    /**
     * @param  list<array{id:string,label:string,kind:string,required:bool,options:list<array{value:string,label:string}>}>  $questions
     * @param  array<string,string>  $answers
     * @return array<string,string>
     */
    private function answerErrors(array $questions, array $answers): array
    {
        $errors = [];
        $known = [];
        foreach ($questions as $question) {
            $known[$question['id']] = true;
            $value = $answers[$question['id']] ?? null;
            $key = 'answers.'.$question['id'];
            if ($value === null || $value === '') {
                if ($question['required']) {
                    $errors[$key] = $question['kind'] === 'condition'
                        ? 'Choose an answer, including Unable to assess when appropriate.'
                        : 'Answer this required question.';
                }

                continue;
            }
            if ($question['options'] !== [] && ! in_array($value, array_column($question['options'], 'value'), true)) {
                $errors[$key] = 'Choose one of the listed answers.';
            } elseif ($question['kind'] === 'number' && ! is_numeric($value)) {
                $errors[$key] = 'Enter a number.';
            }
        }
        foreach (array_keys($answers) as $answered) {
            if (! isset($known[(string) $answered])) {
                $errors['answers'] = 'An answer doesn’t match this checklist version. Reload the checklist and try again.';

                break;
            }
        }

        return $errors;
    }

    private function assertKey(string $requestKey): void
    {
        if (mb_strlen(trim($requestKey)) < 8 || mb_strlen($requestKey) > 100) {
            throw ValidationException::withMessages(['request_key' => 'A request key is required.']);
        }
    }
}
