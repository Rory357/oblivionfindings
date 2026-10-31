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
        \App\Models\HsInvestigation::class,
        \App\Models\HsRecommendationDisposition::class,
        \App\Models\HsClosureException::class,
        \App\Models\HsClosureExceptionDecision::class,
    ];

    public const HEALTH_SAFETY_MIN_RETENTION_YEARS = 10;

    public function scopeHealthSafetyEvidence(Builder $query): Builder
    {
        return $query->where(fn (Builder $hs) => $hs
            ->whereIn('auditable_type', self::healthSafetyEvidenceTypes())
            ->orWhere('action', 'like', 'healthSafety.%'));
    }

    public function scopeWithoutHealthSafetyEvidence(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $type) => $type->whereNull('auditable_type')->orWhereNotIn('auditable_type', self::healthSafetyEvidenceTypes()))
            ->where(fn (Builder $action) => $action->whereNull('action')->orWhere('action', 'not like', 'healthSafety.%'));
    }

    /** Class names plus their morph aliases, so either stored form matches. @return list<string> */
    private static function healthSafetyEvidenceTypes(): array
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
