<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SiteChecklistRun extends Model
{
    use AuditableChanges, WritesLegacyStorageContext;
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'site_id',
        'template_id',
        'scheduled_date',
        'started_at',
        'completed_at',
        'completed_by_user_id',
        'assigned_to_user_id',
        'status',
        'completion_percentage',
        'items_passed',
        'items_failed',
        'overall_notes',
        'signature_name',
        'signature_signed_at',
        'signature_ip_address',
        'signature_user_agent',
        'completion_authority',
        'completion_authority_reason',
        'signature_payload_hash',
        'photos',
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'signature_signed_at' => 'datetime',
        'completion_percentage' => 'decimal:2',
        'items_passed' => 'integer',
        'items_failed' => 'integer',
        'photos' => 'array',
    ];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistAssignment::class, 'assignment_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SiteChecklistTemplate::class, 'template_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SiteChecklistResponse::class, 'run_id');
    }

    public function damages(): HasMany
    {
        return $this->hasMany(SiteDamage::class, 'checklist_run_id');
    }

    public function effectiveAssigneeUserId(): ?int
    {
        if ($this->assigned_to_user_id !== null) {
            return (int) $this->assigned_to_user_id;
        }

        $this->loadMissing('assignment');

        return $this->assignment?->assigned_to_user_id !== null
            ? (int) $this->assignment->assigned_to_user_id
            : null;
    }

    public function hasCanonicalExecutionProvenance(): bool
    {
        $this->loadMissing(['site', 'assignment']);

        return $this->site !== null
            && $this->assignment !== null
            && (int) $this->assignment->site_id === (int) $this->site_id
            && (int) $this->assignment->template_id === (int) $this->template_id;
    }

    public function isExecutableBy(User $user): bool
    {
        $assigneeId = $this->effectiveAssigneeUserId();

        return $assigneeId === null
            || $assigneeId === (int) $user->id
            || $user->canDo('checklists.schedule');
    }

    public function computedSignaturePayloadHash(): string
    {
        $this->loadMissing('responses');

        $payload = [
            'version' => 1,
            'run_id' => (int) $this->id,
            'assignment_id' => (int) $this->assignment_id,
            'site_id' => (int) $this->site_id,
            'template_id' => (int) $this->template_id,
            'assigned_to_user_id' => $this->assigned_to_user_id !== null
                ? (int) $this->assigned_to_user_id
                : null,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'status' => $this->status,
            'completed_by_user_id' => $this->completed_by_user_id !== null
                ? (int) $this->completed_by_user_id
                : null,
            'completed_at' => $this->completed_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'overall_notes' => $this->overall_notes,
            'signature_name' => $this->signature_name,
            'signature_signed_at' => $this->signature_signed_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'signature_ip_address' => $this->signature_ip_address,
            'signature_user_agent' => $this->signature_user_agent,
            'completion_authority' => $this->completion_authority,
            'completion_authority_reason' => $this->completion_authority_reason,
            'responses' => $this->responses
                ->sortBy('template_item_id')
                ->values()
                ->map(fn (SiteChecklistResponse $response): array => [
                    'template_item_id' => (int) $response->template_item_id,
                    'response_value' => $response->response_value,
                    'notes' => $response->notes,
                    'photo_path' => $response->photo_path,
                    'is_failed' => (bool) $response->is_failed,
                ])
                ->all(),
        ];

        return hash('sha256', json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function hasVerifiableSignatureProvenance(): bool
    {
        return $this->status === 'completed'
            && filled($this->signature_name)
            && $this->signature_signed_at !== null
            && filled($this->completion_authority)
            && is_string($this->signature_payload_hash)
            && strlen($this->signature_payload_hash) === 64
            && hash_equals($this->signature_payload_hash, $this->computedSignaturePayloadHash());
    }

    // Scopes
    public function scopeScheduled($query)
    {
        return $query->where('status', 'scheduled');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOverdue($query)
    {
        return $query->where('scheduled_date', '<', now()->toDateString())
            ->whereIn('status', ['scheduled', 'in_progress']);
    }

    public function scopeAwaitingCompletion($query)
    {
        return $query->whereIn('status', ['scheduled', 'in_progress', 'overdue']);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    // Helpers
    public function isOverdue(): bool
    {
        return $this->scheduled_date < now()->toDateString() &&
               in_array($this->status, ['scheduled', 'in_progress']);
    }

    public function calculateCompletion(): void
    {
        $total = $this->template->items()->count();
        $completed = $this->responses()->count();
        $failed = $this->responses()->where('is_failed', true)->count();

        $this->forceFill([
            'completion_percentage' => $total > 0 ? ($completed / $total) * 100 : 0,
            'items_passed' => $completed - $failed,
            'items_failed' => $failed,
        ])->saveQuietly();
    }
}
