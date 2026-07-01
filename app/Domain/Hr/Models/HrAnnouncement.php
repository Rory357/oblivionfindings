<?php

namespace App\Domain\Hr\Models;

use App\Models\Announcement;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrAnnouncement extends Model
{
    use HasFactory, AuditableChanges, SoftDeletes;

    protected static function newFactory()
    {
        return \Database\Factories\Hr\HrAnnouncementFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'title',
        'content',
        'priority',
        'status',
        'target_audience',
        'target_value',
        'published_at',
        'expires_at',
        'ack_deadline',
        'recurrence',
        'recurrence_ends_at',
        'recurrence_parent_id',
        'inbox_announcement_id',
        'is_pinned',
        'requires_acknowledgement',
        'created_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'ack_deadline' => 'datetime',
        'recurrence_ends_at' => 'datetime',
        'is_pinned' => 'boolean',
        'requires_acknowledgement' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(HrAnnouncementAcknowledgement::class, 'announcement_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(HrAnnouncementTarget::class, 'announcement_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(HrAnnouncementAttachment::class, 'announcement_id');
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(HrAnnouncementReminder::class, 'announcement_id');
    }

    public function inboxAnnouncement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class, 'inbox_announcement_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Live notices — explicitly published, in date window. Honours both the
     * legacy published_at semantics and the new status column.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->published()
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->whereIn('status', ['draft', 'scheduled']);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', 'archived');
    }
}
