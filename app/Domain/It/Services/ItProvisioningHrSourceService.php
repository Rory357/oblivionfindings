<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrLifecycleAccessService;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\ItTicketEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Canonical HR changes stop or reschedule IT work within the same source transaction. */
final class ItProvisioningHrSourceService
{
    public function sync(HrOnboardingChecklist|HrOffboardingChecklist $source, ?User $actor): void
    {
        if (! Schema::hasTable('it_provisioning_workflows')) {
            return;
        }
        $sourceType = $source instanceof HrOffboardingChecklist ? 'hr_offboarding' : 'hr_onboarding';
        $query = ItProvisioningWorkflow::query()->where('source_type', $sourceType)->where('source_id', $source->id)
            ->where('employee_profile_id', $source->employee_profile_id);
        if (! $query->exists()) {
            return;
        }
        $this->requireTransaction();
        $actor = $actor ? User::query()->find($actor->id) : null;
        abort_unless($actor && $actor->canDo('hr.onboarding.manage')
            && app(HrCurrentStaffService::class)->isCurrent($actor), 403);
        $access = app(HrLifecycleAccessService::class);
        $source = $source instanceof HrOffboardingChecklist
            ? $access->visibleOffboardingChecklist($actor, $source)
            : $access->visibleOnboardingChecklist($actor, $source);
        foreach ($query->orderBy('id')->lockForUpdate()->get() as $workflow) {
            $cancel = in_array($source->status, ['cancelled', 'archived'], true);
            if ($cancel && $workflow->cancelled_at === null) {
                foreach ($this->openOriginals($workflow)->orderBy('id')->lockForUpdate()->get() as $task) {
                    $task->update(['status' => 'cancelled', 'approval_status' => $task->approval_required ? 'cancelled' : 'not_required']);
                    ItTicketEvent::record($task, 'cancelled', $actor->id, [
                        'reason' => 'The source HR checklist was '.$source->status.'.', 'source' => $sourceType,
                    ]);
                }
                $workflow->update(['status' => 'cancelled', 'cancelled_at' => now(),
                    'cancellation_reason' => 'Source HR checklist '.$source->status.'. Completed work remains recorded; corrective tasks were raised for it.']);
                $this->record($workflow, $actor, 'source_cancelled', ['source_status' => $source->status]);
                // Explicit rule: a withdrawn hire or a retained employee means every
                // completed grant/revoke needs reviewed corrective work. The reversal
                // tasks are approval- and evidence-gated, so nothing changes silently.
                $reversed = app(ItProvisioningReversalService::class)->reverse($workflow, $actor,
                    'The source HR checklist was '.$source->status.'.', allowExisting: true, source: 'hr_source_cancelled');
                if ($reversed !== []) {
                    $this->record($workflow, $actor, 'source_reversal_requested', ['source_status' => $source->status, 'original_request_ids' => $reversed]);
                }

                continue;
            }
            if ($cancel) {
                continue;
            }
            if ($workflow->cancelled_at !== null) {
                // Resume in the same HR change while no corrective work has started;
                // otherwise leave the explicit next step recorded for IT.
                $blocked = $workflow->requests()->whereNotNull('reversal_of_request_id')->where('status', '!=', 'cancelled')->exists();
                if (! $blocked) {
                    $reopened = [];
                    foreach ($workflow->requests()->where('status', 'cancelled')->whereNull('reversal_of_request_id')->orderBy('id')->lockForUpdate()->get() as $task) {
                        $task->update(['status' => 'pending', 'approval_status' => $task->approval_required ? 'cancelled' : 'not_required']);
                        ItTicketEvent::record($task, 'reopened', $actor->id, ['source' => $sourceType, 'reason' => 'The source HR checklist resumed.']);
                        $reopened[] = (int) $task->id;
                    }
                    $workflow->update(['cancelled_at' => null, 'cancellation_reason' => null]);
                    $this->record($workflow, $actor, 'source_resumed', ['source_status' => $source->status, 'reopened_request_ids' => $reopened]);
                    app(ItProvisioningRequestLifecycleService::class)->reconcileWorkflow($workflow);
                    $effective = $source instanceof HrOffboardingChecklist ? $source->due_date : $source->employeeProfile?->start_date;
                    $this->reschedule($workflow->refresh(), $actor, $effective);

                    continue;
                }
                $last = $workflow->events()->latest('id')->first();
                if ($last?->type !== 'source_resumed' || ($last->payload['source_status'] ?? null) !== $source->status) {
                    $this->record($workflow, $actor, 'source_resumed', ['source_status' => $source->status,
                        'next_action' => 'Corrective work is in progress for the cancelled IT work. Complete or cancel it, then explicitly start a new approved workflow if required.']);
                }

                continue;
            }
            $effective = $source instanceof HrOffboardingChecklist ? $source->due_date : $source->employeeProfile?->start_date;
            $this->reschedule($workflow, $actor, $effective);
        }
    }

    /** Called after the canonical profile update, while its profile and actor are locked. */
    public function syncProfileStartDate(HrEmployeeProfile $profile, User $actor): void
    {
        if (! Schema::hasTable('it_provisioning_workflows')) {
            return;
        }
        $query = ItProvisioningWorkflow::query()->where('source_type', 'hr_onboarding')
            ->where('employee_profile_id', $profile->id);
        if (! $query->exists()) {
            return;
        }
        $this->requireTransaction();
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->canDo('hr.employees.manage') && app(HrCurrentStaffService::class)->isCurrent($actor), 403);
        $profile = app(HrLifecycleAccessService::class)->onboardingProfiles($actor)->whereKey($profile->id)->firstOrFail();
        $query->whereNull('cancelled_at');
        foreach ($query->orderBy('id')->lockForUpdate()->get() as $workflow) {
            $source = HrOnboardingChecklist::query()->whereKey($workflow->source_id)
                ->where('employee_profile_id', $profile->id)->first();
            if (! $source || in_array($source->status, ['cancelled', 'archived'], true)) {
                continue;
            }
            $this->reschedule($workflow, $actor, $profile->start_date);
        }
    }

    /** A current IT verdict; no HR notes or names leave the source domain. */
    public function blocker(ItProvisioningRequest $task): ?string
    {
        if ($task->reversal_of_request_id !== null) {
            return null;
        }
        $workflow = $task->workflow;
        $source = null;
        $linked = false;
        if ($workflow && in_array($workflow->source_type, ['hr_onboarding', 'hr_offboarding'], true)) {
            $linked = true;
            $model = $workflow->source_type === 'hr_onboarding' ? HrOnboardingChecklist::class : HrOffboardingChecklist::class;
            $source = $model::query()->whereKey($workflow->source_id)->where('employee_profile_id', $task->employee_profile_id)->first();
        } elseif (! $workflow && ($task->onboarding_task_id || $task->offboarding_task_id)) {
            $linked = true;
            $source = $task->onboarding_task_id ? $task->onboardingTask?->checklist : $task->offboardingTask?->checklist;
            if ((int) $source?->employee_profile_id !== (int) $task->employee_profile_id) {
                $source = null;
            }
        }
        if (! $linked) {
            // Joiner/mover work for someone HR no longer employs must stop; leaver work
            // completes after the profile closes, so it is not blocked here.
            if ($workflow && in_array($workflow->lifecycle_type, ['joiner', 'mover'], true)
                && $task->employeeProfile && ! $task->employeeProfile->is_active) {
                return 'The employee is no longer current in HR. Review the recorded work and raise explicit corrective tasks where access was granted.';
            }

            return null;
        }
        if (! $source) {
            return 'The original HR checklist is unavailable. Reconcile the canonical HR source before continuing.';
        }
        if (in_array($source->status, ['cancelled', 'archived'], true)) {
            return 'The source HR checklist is cancelled or archived. Review its original work and any explicit corrective tasks.';
        }
        $effective = $source instanceof HrOffboardingChecklist ? $source->due_date : $source->employeeProfile?->start_date;

        return $effective ? null : 'The effective date is unavailable in HR. Update the canonical HR source before continuing.';
    }

    private function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('HR and provisioning changes must share a transaction.');
        }
        if (! app(ItProvisioningReadinessService::class)->storageReady()) {
            throw new DomainException('Complete provisioning history setup before changing this linked HR lifecycle.');
        }
    }

    private function openOriginals(ItProvisioningWorkflow $workflow)
    {
        return $workflow->requests()->whereNotIn('status', ['done', 'cancelled'])->whereNull('reversal_of_request_id');
    }

    private function reschedule(ItProvisioningWorkflow $workflow, User $actor, ?CarbonInterface $effective): void
    {
        if ($workflow->effective_at?->toDateString() === $effective?->toDateString()) {
            return;
        }
        $tasks = $this->openOriginals($workflow)->orderBy('id')->lockForUpdate()->get();
        if ($tasks->isEmpty()) {
            return;
        }
        $before = $workflow->effective_at;
        foreach ($tasks as $task) {
            $offset = $task->due_offset_days;
            if ($offset === null && $task->due_date && $before) {
                $offset = (int) $before->copy()->startOfDay()->diffInDays($task->due_date->copy()->startOfDay(), false);
            }
            $oldDate = $task->due_date?->toDateString();
            $task->update(['due_offset_days' => $offset,
                'due_date' => $offset !== null && $effective ? $effective->copy()->addDays($offset)->toDateString() : null,
                'approval_status' => $task->approval_required ? 'cancelled' : 'not_required']);
            ItTicketEvent::record($task, 'rescheduled', $actor->id, [
                'from' => $oldDate, 'to' => $task->due_date?->toDateString(),
                'reason' => 'The effective date changed in HR. Request a fresh approval where required.',
            ]);
        }
        $workflow->update(['original_effective_at' => $workflow->original_effective_at ?? $before, 'effective_at' => $effective]);
        $this->record($workflow, $actor, 'source_rescheduled', ['from' => $before?->toDateString(), 'to' => $effective?->toDateString()]);
    }

    private function record(ItProvisioningWorkflow $workflow, User $actor, string $event, array $data): void
    {
        $workflow->events()->create(['type' => $event, 'actor_user_id' => $actor->id, 'payload' => $data]);
        AuditLogger::logOrFail('it.provisioning.workflow.'.$event, $workflow, ['actor_id' => $actor->id, ...$data]);
    }
}
