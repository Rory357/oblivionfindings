<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicationDashboardAlert extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'client_id',
        'client_medication_id',
        'alert_type',
        'severity',
        'message',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'resolution_notes',
        'resolved_at',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public const ALERT_TYPES = [
        'overdue' => 'Overdue Medication',
        'prn_near_limit' => 'PRN Near Limit',
        'prn_over_limit' => 'PRN Over Limit',
        'controlled_discrepancy' => 'Controlled Drug Discrepancy',
        'expiring_soon' => 'Medication Expiring',
        'expired' => 'Medication Expired',
        'high_risk' => 'High Risk Medication',
        'stock_low' => 'Low Stock',
        'missed_dose' => 'Missed Dose',
        'late_dose' => 'Late Dose',
    ];

    public const SEVERITY_LEVELS = [
        'info' => ['label' => 'Info', 'color' => 'blue', 'icon' => 'info'],
        'warning' => ['label' => 'Warning', 'color' => 'yellow', 'icon' => 'alert-triangle'],
        'critical' => ['label' => 'Critical', 'color' => 'red', 'icon' => 'alert-circle'],
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(ClientMedication::class, 'client_medication_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Get alert type display name
     */
    public function getAlertTypeLabelAttribute(): string
    {
        return self::ALERT_TYPES[$this->alert_type] ?? $this->alert_type;
    }

    /**
     * Get severity display info
     */
    public function getSeverityInfoAttribute(): array
    {
        return self::SEVERITY_LEVELS[$this->severity] ?? [
            'label' => 'Unknown',
            'color' => 'gray',
            'icon' => 'help-circle',
        ];
    }

    /**
     * Acknowledge alert
     */
    public function acknowledge(int $userId): void
    {
        $this->acknowledged_by = $userId;
        $this->acknowledged_at = now();
        $this->save();
    }

    /**
     * Resolve alert
     */
    public function resolve(?string $notes = null): void
    {
        $this->resolution_notes = $notes;
        $this->resolved_at = now();
        $this->status = 'resolved';
        $this->save();
    }

    /**
     * Scope for active alerts
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope by severity
     */
    public function scopeSeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /**
     * Scope critical alerts
     */
    public function scopeCritical($query)
    {
        return $query->where('severity', 'critical')->where('status', 'active');
    }

    /**
     * Scope for client
     */
    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Create or update an alert
     */
    public static function createOrUpdateAlert(
        int $clientId,
        string $alertType,
        string $severity,
        string $message,
        ?int $medicationId = null
    ): self {
        // Check for existing active alert of same type
        $existing = self::where('client_id', $clientId)
            ->where('client_medication_id', $medicationId)
            ->where('alert_type', $alertType)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            $existing->severity = $severity;
            $existing->message = $message;
            $existing->save();
            return $existing;
        }

        return self::create([
            'client_id' => $clientId,
            'client_medication_id' => $medicationId,
            'alert_type' => $alertType,
            'severity' => $severity,
            'message' => $message,
            'status' => 'active',
        ]);
    }
}
