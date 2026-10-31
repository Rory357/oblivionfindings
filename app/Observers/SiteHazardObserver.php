<?php

namespace App\Observers;

use App\Models\HsEvent;
use App\Models\SiteHazard;
use App\Models\User;
use App\Notifications\Sites\HazardAssignedNotification;
use App\Services\AuditLogger;
use App\Services\ControlRoom\ComprehensiveAlertBridgeService;
use App\Services\HealthSafety\HsEventService;
use App\Services\References\ReferenceNumberGenerator;
use App\Services\Sites\SiteHazardRiskCalculator;
use App\Services\SiteTypeAccessService;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SiteHazardObserver
{
    public function __construct(
        private readonly ComprehensiveAlertBridgeService $bridge,
        private readonly HsEventService $hsEventService,
    ) {}

    public function creating(SiteHazard $hazard): void
    {
        // Generate reference number if not set
        if (empty($hazard->reference_number)) {
            $hazard->reference_number = $this->generateReferenceNumber();
        }

        // Calculate risk rating
        if ($hazard->severity && $hazard->likelihood) {
            $calculator = new SiteHazardRiskCalculator;
            $hazard->risk_rating = $calculator->calculate($hazard->severity, $hazard->likelihood);
        }

        // Set due date based on risk rating
        if (empty($hazard->due_date) && $hazard->risk_rating) {
            $calculator = new SiteHazardRiskCalculator;
            $hazard->due_date = now(config('app.worker_timezone', 'Pacific/Auckland'))->addDays($calculator->suggestedDueDays($hazard->risk_rating))->toDateString();
        }
        if (in_array($hazard->risk_rating, ['high', 'extreme'])) {
            $this->autoAssignHealthSafetyOfficer($hazard);
        }
        if ($hazard->assigned_to_user_id && ! $hazard->assigned_at) {
            $hazard->assigned_at = now();
        }
    }

    public function created(SiteHazard $hazard): void
    {
        AuditLogger::logOrFail('hazard.created', $hazard);
        if ($hazard->assigned_to_user_id) {
            $this->recordAssignment($hazard, 'initial');
        }

        // Capture the saved values before another save changes this model.
        // External notifications and bridge calls remain after commit.
        $snapshot = clone $hazard;
        DB::afterCommit(function () use ($snapshot): void {
            $this->recordHsEvent($snapshot);
            if ($snapshot->assigned_to_user_id) {
                $this->notifyAssignee($snapshot);
            }
            if (in_array($snapshot->risk_rating, ['high', 'extreme'])) {
                $this->dispatchBridge($snapshot);
            }
        });
    }

    public function updating(SiteHazard $hazard): void
    {
        if ($hazard->isDirty(['severity', 'likelihood'])) {
            $calculator = new SiteHazardRiskCalculator;
            $hazard->risk_rating = $calculator->calculate($hazard->severity, $hazard->likelihood);
        }
        if ($hazard->isDirty('status')) {
            $hazard->status_changed_at = now();
            $hazard->status_changed_by_user_id = auth()->id();
            if ($hazard->status === 'closed') {
                $hazard->closed_at = now();
                $hazard->closed_by_user_id = auth()->id();
            }
        }
        if ($hazard->isDirty('assigned_to_user_id')) {
            $hazard->assigned_at = $hazard->assigned_to_user_id ? now() : null;
        }
    }

    public function updated(SiteHazard $hazard): void
    {
        if ($hazard->wasChanged('status')) {
            AuditLogger::logOrFail('hazard.status_changed', $hazard, [
                'from' => $hazard->getOriginal('status'), 'to' => $hazard->status,
                'control_hierarchy' => $hazard->control_hierarchy ?? [],
                'residual_severity' => $hazard->residual_severity,
                'residual_likelihood' => $hazard->residual_likelihood,
                'residual_risk_rating' => $hazard->residual_risk_rating,
                'control_review_date' => $hazard->control_review_date?->toDateString(),
                'resolution_summary' => $hazard->resolution_summary,
            ]);
        }
        $assignmentChanged = $hazard->wasChanged('assigned_to_user_id');
        if ($assignmentChanged) {
            $this->recordAssignment($hazard, 'manual');
        }
        if ($hazard->wasChanged('due_date')) {
            AuditLogger::logOrFail('hazard.due_date_changed', $hazard, [
                'from' => $hazard->getOriginal('due_date')?->toDateString(),
                'to' => $hazard->due_date?->toDateString(),
            ]);
        }
        $riskChanged = $hazard->wasChanged('risk_rating');
        $riskEscalated = $riskChanged && in_array($hazard->risk_rating, ['high', 'extreme'])
            && ! in_array($hazard->getOriginal('risk_rating'), ['high', 'extreme']);
        if ($riskChanged) {
            AuditLogger::logOrFail('hazard.risk_changed', $hazard, [
                'from' => $hazard->getOriginal('risk_rating'), 'to' => $hazard->risk_rating,
            ]);
        }
        $snapshot = clone $hazard;
        DB::afterCommit(function () use ($snapshot, $assignmentChanged, $riskChanged, $riskEscalated): void {
            if ($assignmentChanged && $snapshot->assigned_to_user_id) {
                $this->notifyAssignee($snapshot);
            }
            if ($riskChanged) {
                $this->syncHsEventSeverity($snapshot);
            }
            if ($riskEscalated) {
                $this->dispatchBridge($snapshot, escalation: true);
            }
        });
    }

    private function recordAssignment(SiteHazard $hazard, string $source): void
    {
        AuditLogger::logOrFail('hazard.assigned', $hazard, [
            'previous_assignee_id' => $source === 'initial' ? null : $hazard->getOriginal('assigned_to_user_id'),
            'assignee_id' => $hazard->assigned_to_user_id,
            'assignee_name' => User::find($hazard->assigned_to_user_id)?->name,
            'previous_due_date' => $source === 'initial' ? null : $hazard->getOriginal('due_date')?->toDateString(),
            'due_date' => $hazard->due_date?->toDateString(),
            'previous_assigned_at' => $source === 'initial' ? null : $hazard->getOriginal('assigned_at')?->toISOString(),
            'assignment_source' => $source,
        ]);
    }

    private function notifyAssignee(SiteHazard $hazard): void
    {
        try {
            User::find($hazard->assigned_to_user_id)?->notify(new HazardAssignedNotification($hazard));
        } catch (Throwable $error) {
            Log::warning('Hazard assignment notification failed after commit', ['hazard_id' => $hazard->id, 'error_class' => $error::class]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HsEvent wiring */
    /* ------------------------------------------------------------------ */

    private function recordHsEvent(SiteHazard $hazard): void
    {
        try {
            $severity = $hazard->risk_rating ?? 'low';

            $this->hsEventService->recordEvent([
                'source' => $hazard,
                'event_category' => HsEvent::CATEGORY_HAZARD,
                'severity' => $severity,
                'occurred_at' => $hazard->created_at,
                'reported_at' => $hazard->created_at,
                'site_id' => $hazard->site_id,
                'staff_id' => $hazard->reported_by_user_id,
                'created_by' => $hazard->reported_by_user_id,
            ]);
        } catch (Throwable $e) {
            Log::error('SiteHazardObserver: HsEvent creation failed', [
                'site_hazard_id' => $hazard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncHsEventSeverity(SiteHazard $hazard): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($hazard), $hazard->getKey(), HsEvent::CATEGORY_HAZARD);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->syncSeverity($hsEvent, $hazard->risk_rating ?? 'low');
            }
        } catch (Throwable $e) {
            Log::error('SiteHazardObserver: HsEvent severity sync failed', [
                'site_hazard_id' => $hazard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Bridge dispatch */
    /* ------------------------------------------------------------------ */

    private function dispatchBridge(SiteHazard $hazard, bool $escalation = false): void
    {
        try {
            $severity = $hazard->risk_rating === 'extreme' ? 'critical' : 'high';

            $alert = $this->bridge->bridgeOperationalAlert('hazard_identified', $severity, [
                'site_hazard_id' => $hazard->id,
                'reference_number' => $hazard->reference_number,
                'site_id' => $hazard->site_id,
                'hazard_type' => $hazard->hazard_type,
                'risk_rating' => $hazard->risk_rating,
                'description' => $hazard->description,
                'reported_by_user_id' => $hazard->reported_by_user_id,
                'severity_escalation' => $escalation,
            ]);

            if ($alert) {
                $this->linkAlertToHsEvent($hazard, $alert->id);
            }
        } catch (Throwable $e) {
            Log::error('SiteHazardObserver: bridge dispatch failed', [
                'site_hazard_id' => $hazard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function linkAlertToHsEvent(SiteHazard $hazard, int $alertId): void
    {
        try {
            $key = HsEvent::buildIdempotencyKey(get_class($hazard), $hazard->getKey(), HsEvent::CATEGORY_HAZARD);
            $hsEvent = HsEvent::where('idempotency_key', $key)->first();

            if ($hsEvent) {
                $this->hsEventService->linkControlRoomAlert($hsEvent, $alertId);
            }
        } catch (Throwable $e) {
            Log::warning('SiteHazardObserver: failed to link alert to HsEvent', [
                'site_hazard_id' => $hazard->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Existing helpers (unchanged) */
    /* ------------------------------------------------------------------ */

    private function autoAssignHealthSafetyOfficer(SiteHazard $hazard): void
    {
        if ($hazard->assigned_to_user_id) {
            return;
        }
        $siteAccess = app(UserSiteAccessService::class);
        $candidates = User::query()->staff()->whereNotNull('approved_at')
            ->whereHas('roles', fn ($q) => $q->where('name', 'health_safety_officer'))
            ->orderBy('id')->get();
        $site = $hazard->site;
        foreach ($candidates as $candidate) {
            if ($site && $candidate->canDo('hazards.view')
                && $siteAccess->applyStaffScope(User::query()->whereKey($candidate->id), $candidate, ['sites.viewAll'])->exists()
                && in_array($site->type, app(SiteTypeAccessService::class)->allowedTypes($candidate), true)
                && in_array((int) $site->id, $siteAccess->accessibleSiteIds($candidate, ['sites.viewAll']), true)) {
                $hazard->assigned_to_user_id = $candidate->id;
                $hazard->assigned_at = now();

                return;
            }
        }
    }

    private function generateReferenceNumber(): string
    {
        return app(ReferenceNumberGenerator::class)->next('HAZ');
    }
}
