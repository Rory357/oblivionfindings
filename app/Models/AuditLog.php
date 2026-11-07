<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'user_id',
        'client_id',
        'action',
        'auditable_type',
        'auditable_id',
        'meta',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    /** Keep medication evidence out of generic audit readers and retention. */
    public function scopeWithoutMedicationEvidence(Builder $query): Builder
    {
        return $query->where(function (Builder $nonMedication): void {
            $nonMedication->whereNull('auditable_type')
                ->orWhere(function (Builder $typed): void {
                    $typed->where('auditable_type', 'not like', '%Medication%')
                        ->where('auditable_type', 'not like', '%ControlledDrug%');
                });
        })->where(function (Builder $nonMedicationAction): void {
            $nonMedicationAction->whereNull('action')
                ->orWhere(function (Builder $action): void {
                    $action->where('action', 'not like', 'medication%')
                        ->where('action', 'not like', 'meds.%')
                        ->where('action', 'not like', 'emar.%')
                        ->where('action', 'not like', 'clientmedication%')
                        ->where('action', 'not like', 'clientcontrolleddrug%')
                        ->where('action', 'not like', 'controlled_drug%')
                        ->where('action', 'not like', 'cd.%')
                        ->where('action', 'not like', 'cd\_%');
                });
        });
    }

    /**
     * Safety review evidence (Stephan, answer 20, 1 Nov 2026: corrections and the activity log are kept at least 10
     * years). Rows about a safety review, its actions, investigations, close decisions and their history, or any
     * "healthSafety." action.
     */
    public const HEALTH_SAFETY_EVIDENCE_TYPES = [
        \App\Models\HsEvent::class,
        \App\Models\HsCorrectiveAction::class,
        \App\Models\HsCorrectiveActionOwnerChange::class,
        \App\Models\HsEventOwnerChange::class,
        \App\Models\HsInvestigation::class,
        \App\Models\HsInvestigationCorrection::class,
        \App\Models\HsRecommendationDisposition::class,
        \App\Models\HsClosureException::class,
        \App\Models\HsClosureExceptionDecision::class,
        // Readiness audit B6 (Stephan, 6 Nov 2026: "10 years"): the change history of the H&S records themselves —
        // incidents, restraints and behaviour support plans, safeguarding, hazards — plus first aid, workplace injuries
        // and chemical exposures, kept as long as the safety reviews made from them.
        \App\Models\ClientIncident::class,
        \App\Models\ClientIncidentNote::class,
        \App\Models\ClientIncidentAttachment::class,
        \App\Models\IncidentFollowup::class,
        \App\Models\IncidentOwnerChange::class,
        \App\Models\RestraintEvent::class,
        \App\Models\RestraintEventReview::class,
        \App\Models\RestraintEventAttachment::class,
        \App\Models\RestraintDebrief::class,
        \App\Models\RestraintFollowup::class,
        \App\Models\RestraintMonitoringObservation::class,
        \App\Models\BehaviourSupportPlan::class,
        \App\Models\BehaviourSupportPlanApproval::class,
        \App\Models\BehaviourSupportPlanReview::class,
        \App\Models\BehaviourSupportPlanVersion::class,
        \App\Models\SafeguardingConcern::class,
        \App\Models\SafeguardingActionPlan::class,
        \App\Models\SafeguardingAlert::class,
        \App\Models\SafeguardingAttachment::class,
        \App\Models\SafeguardingDeclassificationReview::class,
        \App\Models\SafeguardingExternalReport::class,
        \App\Models\SafeguardingInvestigation::class,
        \App\Models\SafeguardingRiskAssessment::class,
        \App\Models\SafeguardingTerminalTransition::class,
        \App\Models\SiteHazard::class,
        \App\Models\SiteHazardAction::class,
        \App\Models\FirstAidRecord::class,
        \App\Models\FirstAidFollowup::class,
        \App\Models\WorkplaceInjury::class,
        \App\Models\SubstanceExposureRecord::class,
        \App\Models\ChemicalExposureFollowup::class,
        \App\Models\ChemicalExposureReview::class,
    ];

    /** Action names that belong to those records, whatever the row's auditable type (B6, 6 Nov 2026). */
    public const HEALTH_SAFETY_EVIDENCE_ACTION_PREFIXES = ['healthSafety.', 'incident.', 'incidents.', 'restraint.', 'restraints.',
        'safeguarding.', 'hazard.', 'hazards.', 'first_aid.', 'injuries.', 'injury.', 'chemical.'];

    public const HEALTH_SAFETY_MIN_RETENTION_YEARS = 10;

    public function scopeHealthSafetyEvidence(Builder $query): Builder
    {
        return $query->where(function (Builder $hs): void {
            $hs->whereIn('auditable_type', self::healthSafetyEvidenceTypes());
            foreach (self::HEALTH_SAFETY_EVIDENCE_ACTION_PREFIXES as $prefix) {
                $hs->orWhere('action', 'like', self::likePrefix($prefix));
            }
        });
    }

    public function scopeWithoutHealthSafetyEvidence(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $type) => $type->whereNull('auditable_type')->orWhereNotIn('auditable_type', self::healthSafetyEvidenceTypes()))
            ->where(fn (Builder $action) => $action->whereNull('action')->orWhere(function (Builder $other): void {
                foreach (self::HEALTH_SAFETY_EVIDENCE_ACTION_PREFIXES as $prefix) {
                    $other->where('action', 'not like', self::likePrefix($prefix));
                }
            }));
    }

    /** "first_aid." must not match "firstXaid.": escape LIKE's own wildcards. */
    private static function likePrefix(string $prefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%';
    }

    /** Class names plus their morph aliases, so either stored form matches. @return list<string> */
    public static function healthSafetyEvidenceTypes(): array
    {
        $types = [];
        foreach (self::HEALTH_SAFETY_EVIDENCE_TYPES as $class) {
            $types[] = $class;
            if (class_exists($class)) {
                $types[] = (new $class)->getMorphClass();
            }
        }

        return array_values(array_unique($types));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
