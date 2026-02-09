<?php

namespace App\Observers;

use App\Models\SiteHazard;
use App\Notifications\Sites\HazardAssignedNotification;
use App\Services\AuditLogger;

class SiteHazardObserver
{
    public function creating(SiteHazard $hazard): void
    {
        // Generate reference number if not set
        if (empty($hazard->reference_number)) {
            $hazard->reference_number = $this->generateReferenceNumber();
        }

        // Calculate risk rating
        if ($hazard->severity && $hazard->likelihood) {
            $calculator = new \App\Services\Sites\SiteHazardRiskCalculator();
            $hazard->risk_rating = $calculator->calculate($hazard->severity, $hazard->likelihood);
        }

        // Set due date based on risk rating
        if (empty($hazard->due_date) && $hazard->risk_rating) {
            $calculator = new \App\Services\Sites\SiteHazardRiskCalculator();
            $hazard->due_date = now()->addDays($calculator->suggestedDueDays($hazard->risk_rating));
        }
    }

    public function created(SiteHazard $hazard): void
    {
        // Auto-assign H&S officer if high/extreme risk
        if (in_array($hazard->risk_rating, ['high', 'extreme'])) {
            $this->autoAssignHealthSafetyOfficer($hazard);
        }

        AuditLogger::log('hazard.created', $hazard);
    }

    public function updating(SiteHazard $hazard): void
    {
        // Recalculate risk if severity/likelihood changed
        if ($hazard->isDirty(['severity', 'likelihood'])) {
            $calculator = new \App\Services\Sites\SiteHazardRiskCalculator();
            $hazard->risk_rating = $calculator->calculate($hazard->severity, $hazard->likelihood);
        }
    }

    public function updated(SiteHazard $hazard): void
    {
        // Log status changes
        if ($hazard->isDirty('status')) {
            AuditLogger::log('hazard.status_changed', $hazard, [
                'from' => $hazard->getOriginal('status'),
                'to' => $hazard->status,
            ]);

            // Update timestamps
            $hazard->status_changed_at = now();
            $hazard->status_changed_by_user_id = auth()->id();

            // If closing, set closed info
            if (in_array($hazard->status, ['mitigated', 'closed'])) {
                $hazard->closed_at = now();
                $hazard->closed_by_user_id = auth()->id();
            }
        }

        // Notify on assignment
        if ($hazard->isDirty('assigned_to_user_id') && $hazard->assigned_to_user_id) {
            $hazard->assignedTo->notify(new HazardAssignedNotification($hazard));
            $hazard->assigned_at = now();
        }

        // Log risk changes
        if ($hazard->isDirty('risk_rating')) {
            AuditLogger::log('hazard.risk_changed', $hazard, [
                'from' => $hazard->getOriginal('risk_rating'),
                'to' => $hazard->risk_rating,
            ]);
        }
    }

    private function autoAssignHealthSafetyOfficer(SiteHazard $hazard): void
    {
        $hsOfficer = \App\Models\User::role('health_safety_officer')->first();

        if ($hsOfficer) {
            $hazard->assigned_to_user_id = $hsOfficer->id;
            $hazard->assigned_at = now();
        }
    }

    private function generateReferenceNumber(): string
    {
        $year = now()->year;
        $count = SiteHazard::whereYear('created_at', $year)->count() + 1;
        return sprintf('HAZ-%d-%04d', $year, $count);
    }
}
