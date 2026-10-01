<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved Medication Settings change, or one default deliberately kept
 * (eMAR P11 Change history). Append-only: rows are never updated or deleted.
 */
class MedicationSettingChange extends Model
{
    public const ACTION_CHANGED = 'changed';

    public const ACTION_KEPT = 'kept';

    public const UPDATED_AT = null;

    protected $fillable = [
        'setting_group',
        'setting_key',
        'site_id',
        'action',
        'view',
        'section',
        'label',
        'before_value',
        'after_value',
        'before_text',
        'after_text',
        'loosens',
        'controlled',
        'note',
        'actor_id',
        'audit_event',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'actor_id' => 'integer',
        'before_value' => 'json',
        'after_value' => 'json',
        'loosens' => 'boolean',
        'controlled' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
