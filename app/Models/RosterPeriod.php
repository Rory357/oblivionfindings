<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RosterPeriod extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_READY = 'ready_to_publish';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CHANGED_AFTER_PUBLISH = 'changed_after_publish';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'site_id',
        'week_start',
        'week_end',
        'version',
        'status',
        'shift_count',
        'validating_at',
        'ready_at',
        'published_at',
        'published_by',
        'locked_at',
        'archived_at',
        'archive_reason',
        'created_by',
        'notes',
        'snapshot',
        'validation_summary',
        'publish_meta',
        'last_validated_at',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'version' => 'integer',
        'shift_count' => 'integer',
        'validating_at' => 'datetime',
        'ready_at' => 'datetime',
        'published_at' => 'datetime',
        'locked_at' => 'datetime',
        'archived_at' => 'datetime',
        'snapshot' => 'array',
        'validation_summary' => 'array',
        'publish_meta' => 'array',
        'last_validated_at' => 'datetime',
    ];

    public function site()
    {
        return $this->belongsTo(Site::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function publisher()
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status !== self::STATUS_ARCHIVED;
    }

    public function isPublished(): bool
    {
        return in_array($this->status, [
            self::STATUS_PUBLISHED,
            self::STATUS_CHANGED_AFTER_PUBLISH,
        ], true);
    }
}
