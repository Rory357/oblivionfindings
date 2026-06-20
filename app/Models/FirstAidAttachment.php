<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * First Aid Register gold-standard upgrade — Step 2. Evidence attached to a first-aid
 * record (ACC45 form, injury photos, treatment notes). Mirrors EmergencyDrillAttachment.
 */
class FirstAidAttachment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'first_aid_record_id',
        'uploaded_by',
        'disk',
        'original_name',
        'path',
        'mime',
        'size',
        'kind',
        'notes',
        'alt_text',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function record(): BelongsTo
    {
        return $this->belongsTo(FirstAidRecord::class, 'first_aid_record_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
