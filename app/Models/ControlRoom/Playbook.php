<?php

namespace App\Models\ControlRoom;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Playbook extends Model
{
    use HasFactory;

    protected static function newFactory(): \Database\Factories\PlaybookFactory
    {
        return \Database\Factories\PlaybookFactory::new();
    }

    protected $table = 'control_room_playbooks';

    protected $fillable = [
        'name',
        'code',
        'category',
        'description',
        'version',
        'is_active',
        'trigger_alert_types',
        'trigger_severities',
        'auto_attach',
        'sla_acknowledge_minutes',
        'sla_response_minutes',
        'sla_resolution_minutes',
        'required_evidence',
        'requires_approval',
        'approval_roles',
        'escalation_after_minutes',
        'escalation_targets',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'auto_attach' => 'boolean',
        'requires_approval' => 'boolean',
        'trigger_alert_types' => 'array',
        'trigger_severities' => 'array',
        'required_evidence' => 'array',
        'approval_roles' => 'array',
        'escalation_targets' => 'array',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(PlaybookStep::class, 'playbook_id')->orderBy('order');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PlaybookRun::class, 'playbook_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeAutoAttach($query)
    {
        return $query->where('is_active', true)->where('auto_attach', true);
    }

    public function scopeForAlertType($query, string $alertType)
    {
        return $query->whereJsonContains('trigger_alert_types', $alertType);
    }

    public function scopeForSeverity($query, string $severity)
    {
        return $query->whereJsonContains('trigger_severities', $severity);
    }

    public static function findForAlert(string $alertType, string $severity): ?self
    {
        return static::autoAttach()
            ->where(function ($q) use ($alertType) {
                $q->whereNull('trigger_alert_types')
                    ->orWhereJsonContains('trigger_alert_types', $alertType);
            })
            ->where(function ($q) use ($severity) {
                $q->whereNull('trigger_severities')
                    ->orWhereJsonContains('trigger_severities', $severity);
            })
            ->orderBy('id')
            ->first();
    }

    // Playbook categories
    public const CATEGORY_EMERGENCY = 'emergency';
    public const CATEGORY_SAFETY = 'safety';
    public const CATEGORY_COMPLIANCE = 'compliance';
    public const CATEGORY_MAINTENANCE = 'maintenance';
    public const CATEGORY_INVESTIGATION = 'investigation';

    public static function categories(): array
    {
        return [
            self::CATEGORY_EMERGENCY => 'Emergency',
            self::CATEGORY_SAFETY => 'Safety',
            self::CATEGORY_COMPLIANCE => 'Compliance',
            self::CATEGORY_MAINTENANCE => 'Maintenance',
            self::CATEGORY_INVESTIGATION => 'Investigation',
        ];
    }
}
