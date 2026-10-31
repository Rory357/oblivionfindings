<?php

namespace App\Services\HealthSafety;

use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\User;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Attributed assessment/recovery facts. Workforce separately owns approval/effective restrictions. */
final class WorkplaceInjuryRecoveryService
{
    public function __construct(private readonly WorkplaceInjurySourceService $source, private readonly InjuryAccessService $access) {}

    public function recordCapacity(WorkplaceInjury $injury, User $actor, array $input): array
    {
        return $this->source->execute($injury, $actor, 'record_capacity', $input, function ($data) {
            $values = WorkplaceInjurySourceService::values($data, [
                'assessment_date' => ['required', 'string'], 'assessor_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
                'assessor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'assessor_type' => ['required', 'in:gp,nurse_practitioner,specialist,physiotherapist,occupational_therapist,employer'],
                'capacity_status' => ['required', 'in:fit_for_full_duties,fit_for_modified_duties,unfit_for_work,requires_review'],
                'assessment_summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'restrictions' => ['sometimes', 'nullable', 'string', 'max:5000'], 'recommendations' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'next_assessment_date' => ['sometimes', 'nullable', 'string'], 'hr_document_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
            $values['assessment_date'] = WorkplaceInjurySourceService::date($values['assessment_date'], 'assessment_date');
            if ($values['assessment_date'] > now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString()) {
                WorkplaceInjurySourceService::invalid('assessment_date', 'An attributed assessment fact cannot be dated in the future.');
            }
            if (! isset($values['assessor_user_id']) && ! filled($values['assessor_name'] ?? null)) {
                WorkplaceInjurySourceService::invalid('assessor_name', 'Record the actual attributed assessor; the injured worker is not a default assessor.');
            }
            if (array_key_exists('next_assessment_date', $values)) {
                $values['next_assessment_date'] = WorkplaceInjurySourceService::date($values['next_assessment_date'], 'next_assessment_date');
                if ($values['next_assessment_date'] !== null && $values['next_assessment_date'] <= $values['assessment_date']) {
                    WorkplaceInjurySourceService::invalid('next_assessment_date', 'The next assessment must follow this assessment.');
                }
            }
            return $values;
        }, function ($row, $current, $reads, $sites, $payload) {
            $assessor = isset($payload['assessor_user_id'])
                ? $this->access->currentStaff($payload['assessor_user_id'], (int) $row->site_id, $current, $reads, $sites) : null;
            if (isset($payload['hr_document_id'])) {
                $this->access->assertHrDocument($row, $payload['hr_document_id'], $current, $reads, $sites, 'recovery');
            }
            $worker = $reads->query(User::query())->whereKey($row->user_id)->firstOrFail();
            $assessment = new WorkCapacityAssessment;
            $assessment->forceFill([...array_diff_key($payload, array_flip(['hr_document_id'])),
                'workplace_injury_id' => $row->id, 'worker_id' => $row->user_id,
                // Required legacy storage carrier is preserved; only explicit typed fields establish attribution.
                'user_id' => $assessor?->id ?? $row->user_id, 'assessor_user_id' => $assessor?->id,
                'assessor_name' => $assessor?->name ?? $payload['assessor_name'],
                'worker_snapshot' => WorkplaceInjurySourceService::identity($worker),
                'assessor_snapshot' => $assessor ? WorkplaceInjurySourceService::identity($assessor)
                    : ['name' => $payload['assessor_name'], 'type' => $payload['assessor_type'], 'attribution' => 'reported_external'],
                'recorded_by' => $current->id, 'recorder_snapshot' => WorkplaceInjurySourceService::identity($current),
                'source_schema_version' => 1, 'source_revision' => (int) $row->source_revision + 1,
                'attribution_state' => 'recorded', 'created_by' => $current->id])->saveQuietly();
            return $assessment;
        }, sourceType: 'capacity_assessment', medical: true);
    }

    private function planValues(array $data, bool $create): array
    {
        $required = $create ? 'required' : 'sometimes';
        $values = WorkplaceInjurySourceService::values($data, ['worker_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'manager_id' => ['sometimes', 'nullable', 'integer', 'min:1'], 'plan_start_date' => [$required, 'string'],
            'plan_end_date' => ['sometimes', 'nullable', 'string'], 'goals' => [$required, 'array', 'min:1', 'max:30'],
            'goals.*' => ['string', 'max:500'], 'stages' => [$required, 'array', 'min:1', 'max:30'],
            'stages.*' => ['array:name,start_date,end_date,hours_per_week,duties_description'],
            'stages.*.name' => ['required', 'string', 'max:255'], 'stages.*.start_date' => ['required', 'string'],
            'stages.*.end_date' => ['nullable', 'string'], 'stages.*.hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'stages.*.duties_description' => ['nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'in:draft,active,in_progress,completed,cancelled'],
            'medical_clearance_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'medical_clearance_date' => ['sometimes', 'nullable', 'string'],
            'medical_clearance_provider' => ['sometimes', 'nullable', 'string', 'max:255'],
            'workplace_modifications' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'worker_agreement_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'worker_agreed' => ['sometimes', 'boolean'], 'worker_agreed_at' => ['sometimes', 'nullable', 'string'],
            'next_review_date' => ['sometimes', 'nullable', 'string'], 'review_notes' => ['sometimes', 'nullable', 'string', 'max:2000']]);
        foreach (['plan_start_date', 'plan_end_date', 'medical_clearance_date', 'worker_agreed_at', 'next_review_date'] as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = WorkplaceInjurySourceService::date($values[$field], $field);
            }
        }
        if (isset($values['stages'])) {
            foreach ($values['stages'] as &$stage) {
                $stage['start_date'] = WorkplaceInjurySourceService::date($stage['start_date'], 'stages');
                $stage['end_date'] = WorkplaceInjurySourceService::date($stage['end_date'] ?? null, 'stages');
                if ($stage['end_date'] !== null && $stage['end_date'] < $stage['start_date']) {
                    WorkplaceInjurySourceService::invalid('stages', 'A stage cannot end before it starts.');
                }
            }
            unset($stage);
        }
        foreach (['medical_clearance_date', 'worker_agreed_at'] as $field) {
            if (isset($values[$field]) && $values[$field] > now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString()) {
                WorkplaceInjurySourceService::invalid($field, 'An attributed fact cannot be dated in the future.');
            }
        }
        return $values;
    }


    private function assertPlanDates(array $values): void
    {
        $start = $values['plan_start_date'];
        $end = $values['plan_end_date'] ?? null;
        $previousEnd = null;
        foreach ($values['stages'] ?? [] as $stage) {
            $stageStart = WorkplaceInjurySourceService::date($stage['start_date'], 'stages');
            $stageEnd = WorkplaceInjurySourceService::date($stage['end_date'] ?? null, 'stages');
            if ($stageStart < $start || ($end !== null && ($stageStart > $end || ($stageEnd !== null && $stageEnd > $end)))
                || ($previousEnd !== null && $stageStart < $previousEnd)) {
                WorkplaceInjurySourceService::invalid('stages', 'Each ordered stage must fit the recorded plan interval.');
            }
            $previousEnd = $stageEnd ?? $stageStart;
        }
        if (($values['worker_agreed_at'] ?? null) !== null && ! ($values['worker_agreed'] ?? false)) {
            WorkplaceInjurySourceService::invalid('worker_agreed_at', 'A recorded agreement date requires an explicitly recorded agreement.');
        }
    }

    public function createPlan(WorkplaceInjury $injury, User $actor, array $input): array
    {
        return $this->source->execute($injury, $actor, 'create_rtw_plan', $input, fn ($data) => $this->planValues($data, true),
            function ($row, $current, $reads, $sites, $payload) {
                if (isset($payload['worker_id']) && $payload['worker_id'] !== (int) $row->user_id) {
                    abort(404);
                }
                $worker = $this->access->currentStaff((int) $row->user_id, (int) $row->site_id, $current, $reads, $sites);
                $manager = isset($payload['manager_id'])
                    ? $this->access->currentStaff($payload['manager_id'], (int) $row->site_id, $current, $reads, $sites, true) : null;
                if (isset($payload['plan_end_date']) && $payload['plan_end_date'] < $payload['plan_start_date']) {
                    WorkplaceInjurySourceService::invalid('plan_end_date', 'The plan cannot end before it starts.');
                }
                $this->assertPlanDates($payload);
                $plan = new ReturnToWorkPlan;
                $plan->forceFill([...$payload, 'workplace_injury_id' => $row->id, 'worker_id' => $row->user_id, 'manager_id' => $manager?->id,
                    'status' => $payload['status'] ?? 'active', 'source_schema_version' => 1, 'source_revision' => (int) $row->source_revision + 1,
                    'worker_snapshot' => WorkplaceInjurySourceService::identity($worker),
                    'recorder_snapshot' => WorkplaceInjurySourceService::identity($current), 'created_by' => $current->id])->saveQuietly();
                return $plan; // Retained operational status is not approved restriction/medical fitness.
            }, sourceType: 'rtw_plan', medical: true);
    }

    public function updatePlan(WorkplaceInjury $injury, ReturnToWorkPlan $plan, User $actor, array $input): array
    {
        return $this->source->execute($injury, $actor, 'update_rtw_plan', $input, fn ($data) => $this->planValues($data, false),
            function ($row, $current, $reads, $sites, $payload, $target) {
                if (isset($payload['worker_id']) && $payload['worker_id'] !== (int) $row->user_id) {
                    abort(404);
                }
                if (isset($payload['manager_id'])) {
                    $this->access->currentStaff($payload['manager_id'], (int) $row->site_id, $current, $reads, $sites, true);
                }
                $start = $payload['plan_start_date'] ?? $target->plan_start_date?->toDateString();
                $end = array_key_exists('plan_end_date', $payload) ? $payload['plan_end_date'] : $target->plan_end_date?->toDateString();
                if ($end !== null && $start !== null && $end < $start) {
                    WorkplaceInjurySourceService::invalid('plan_end_date', 'The plan cannot end before it starts.');
                }
                $merged = [...$target->attributesToArray(), ...$payload,
                    'plan_start_date' => $start, 'plan_end_date' => $end,
                    'worker_agreed_at' => array_key_exists('worker_agreed_at', $payload) ? $payload['worker_agreed_at'] : $target->worker_agreed_at?->toDateString()];
                $this->assertPlanDates($merged);
                $worker = $reads->query(User::query())->whereKey($row->user_id)->firstOrFail();
                $facts = [...$payload, 'source_schema_version' => 1,
                    'worker_snapshot' => $target->worker_snapshot ?? WorkplaceInjurySourceService::identity($worker)];
                $target->forceFill($facts);
                if ($target->isDirty(array_keys($facts))) {
                    $target->forceFill(['recorder_snapshot' => WorkplaceInjurySourceService::identity($current),
                        'updated_by' => $current->id])->saveQuietly();
                }
                return $target;
            }, sourceType: 'rtw_plan', sourceId: (int) $plan->id, medical: true);
    }

    public function addModifiedDuty(WorkplaceInjury $injury, ReturnToWorkPlan $plan, User $actor, array $input): array
    {
        return $this->source->execute($injury, $actor, 'add_modified_duty', $input, function ($data) use ($plan) {
            $values = WorkplaceInjurySourceService::values($data, ['start_date' => ['required', 'string'],
                'end_date' => ['sometimes', 'nullable', 'string'], 'modified_duties_description' => ['required', 'string', 'max:2000'],
                'hours_per_day' => ['required', 'numeric', 'min:0', 'max:24'], 'restrictions' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'accommodations' => ['sometimes', 'nullable', 'string', 'max:2000'], 'user_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);
            $values['start_date'] = WorkplaceInjurySourceService::date($values['start_date'], 'start_date');
            // The old nonnullable column is a storage carrier, not a claim of unrestricted duties.
            $values['restrictions'] = $values['restrictions'] ?? '';
            $values['end_date'] = WorkplaceInjurySourceService::date($values['end_date'] ?? null, 'end_date');
            if ($values['end_date'] !== null && $values['end_date'] < $values['start_date']) {
                WorkplaceInjurySourceService::invalid('end_date', 'The duty arrangement cannot end before it starts.');
            }
            // Canonical parent identity participates in the exact fingerprint, never a free submitted ID.
            $values['return_to_work_plan_id'] = (int) $plan->id;
            return $values;
        }, function ($row, $current, $reads, $sites, $payload) {
            $plan = ReturnToWorkPlan::whereKey($payload['return_to_work_plan_id'])->lockForUpdate()->firstOrFail();
            $this->access->assertChild($row, $plan, $current, $reads, $sites);
            if (isset($payload['user_id']) && $payload['user_id'] !== (int) $row->user_id) {
                abort(404);
            }
            $worker = $this->access->currentStaff((int) $row->user_id, (int) $row->site_id, $current, $reads, $sites);
            $duty = new ModifiedDuty;
            $duty->forceFill([...$payload, 'user_id' => $row->user_id, 'status' => 'active',
                'source_schema_version' => 1, 'source_revision' => (int) $row->source_revision + 1,
                'worker_snapshot' => WorkplaceInjurySourceService::identity($worker),
                'recorder_snapshot' => WorkplaceInjurySourceService::identity($current), 'created_by' => $current->id])->saveQuietly();
            return $duty; // A saved arrangement is not effective Workforce approval.
        }, sourceType: 'modified_duty', medical: true);
    }

    public function recordReview(WorkplaceInjury $injury, User $actor, array $input): array
    {
        return $this->source->execute($injury, $actor, 'review', $input, function ($data) {
            $values = WorkplaceInjurySourceService::values($data, ['reviewed_at' => ['required', 'string'],
                'next_review_date' => ['required', 'string'], 'notes' => ['required', 'string', 'max:5000']]);
            $values['reviewed_at'] = RestraintCommandValues::instant($values['reviewed_at'], 'reviewed_at');
            $values['next_review_date'] = WorkplaceInjurySourceService::date($values['next_review_date'], 'next_review_date');
            if ($values['next_review_date'] <= substr($values['reviewed_at'], 0, 10)) {
                WorkplaceInjurySourceService::invalid('next_review_date', 'The next review must follow the recorded review.');
            }
            return $values;
        }, function ($row, $current, $reads, $sites, $payload) {
            $row->forceFill(['next_review_date' => $payload['next_review_date']])->saveQuietly();
            return $row;
        }, medical: true);
    }

    /** Stage private bytes; cleanup only this command's unreferenced staging on failed/replayed acceptance. */
    public function uploadEvidence(WorkplaceInjury $injury, User $actor, UploadedFile $file, array $input): array
    {
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,gif,doc,docx']])->validate();
        $this->access->withCurrentViewer($actor, function ($current, $reads, $sites) use ($injury) {
            $this->access->assertMedicalSource($injury, $current, $reads, $sites, 'evidence');
            return true;
        });
        $digest = hash_file('sha256', $file->getRealPath());
        if (! is_string($digest)) {
            throw new LogicException('The staged injury evidence bytes could not be verified.');
        }
        $path = 'workplace_injury_attachments/'.$injury->id.'/'.Str::uuid().'/'.Str::uuid().'.'.$file->extension();
        if (! Storage::disk('private')->put($path, file_get_contents($file->getRealPath()))) {
            throw new LogicException('The private evidence could not be staged.');
        }
        // Register on the enclosing transaction as well: nested commit must not leave bytes after a later outer rollback.
        if (DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => Storage::disk('private')->delete($path));
        }
        try {
            $result = $this->source->execute($injury, $actor, 'upload_evidence', $input, function ($data) use ($file, $digest) {
                $values = WorkplaceInjurySourceService::values($data, ['kind' => ['sometimes', 'nullable', 'in:medical_cert,acc_form,rtw_clearance,photo,document'],
                    'notes' => ['sometimes', 'nullable', 'string', 'max:2000'], 'alt_text' => ['sometimes', 'nullable', 'string', 'max:255']]);
                return [...$values, 'file_evidence' => ['sha256' => $digest, 'size' => $file->getSize(),
                    'original_name' => $file->getClientOriginalName(), 'mime' => $file->getMimeType()]];
            }, function ($row, $current, $reads, $sites, $payload) use ($path) {
                if (! Storage::disk('private')->exists($path)
                    || ! hash_equals($payload['file_evidence']['sha256'], hash('sha256', Storage::disk('private')->get($path)))) {
                    throw new LogicException('The staged injury evidence changed before persistence.');
                }
                $attachment = new WorkplaceInjuryAttachment;
                $attachment->forceFill([...array_diff_key($payload, ['file_evidence' => true]), 'workplace_injury_id' => $row->id,
                    'uploaded_by' => $current->id, 'disk' => 'private', 'path' => $path,
                    'source_schema_version' => 1, 'source_revision' => (int) $row->source_revision + 1,
                    'original_name' => $payload['file_evidence']['original_name'], 'mime' => $payload['file_evidence']['mime'],
                    'size' => $payload['file_evidence']['size']])->saveQuietly();
                return $attachment;
            }, sourceType: 'attachment', medical: true);
            if ($result['replayed']) {
                Storage::disk('private')->delete($path);
            }
            return $result;
        } catch (Throwable $failure) {
            Storage::disk('private')->delete($path);
            throw $failure;
        }
    }

    /** Soft removal retains source-owned bytes/history; no independent approval/evidence is erased. */
    public function removeEvidence(WorkplaceInjury $injury, WorkplaceInjuryAttachment $attachment, User $actor, array $input): array
    {
        return $this->source->execute($injury, $actor, 'remove_evidence', $input, fn ($data) => WorkplaceInjurySourceService::values($data,
            ['reason' => ['required', 'string', 'max:2000']]), function ($row, $current, $reads, $sites, $payload, $target) {
                $target->deleteQuietly();
                return $target;
            }, sourceType: 'attachment', sourceId: (int) $attachment->id, medical: true);
    }
}

