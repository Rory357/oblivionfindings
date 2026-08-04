<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCandidateDocument extends Model
{
    use WritesLegacyStorageContext;

    public const CATEGORIES = [
        'cv' => 'CV / Resume',
        'cover_letter' => 'Cover Letter',
        'qualification' => 'Qualification',
        'certification' => 'Certification',
        'police_vetting' => 'Police Vetting',
        'first_aid' => 'First Aid Certificate',
        'driver_licence' => 'Driver Licence',
        'reference_letter' => 'Reference Letter',
        'portfolio' => 'Portfolio',
        'other' => 'Other',
    ];

    protected $table = 'hr_candidate_documents';

    protected $fillable = [
        'tenant_id',
        'candidate_id',
        'application_id',
        'category',
        'title',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by',
        'notes',
        'expires_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'expires_at' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(HrCandidate::class, 'candidate_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(HrApplication::class, 'application_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category));
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size_bytes;
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1024).' KB';
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
