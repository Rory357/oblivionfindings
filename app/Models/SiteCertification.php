<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteCertification extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;

    protected $table = 'site_certifications';

    protected $fillable = [
        'site_id',
        'certification_type',
        'name',
        'issuing_body',
        'reference_number',
        'status',
        'issued_date',
        'expiry_date',
        'next_review_date',
        'notes',
        'document_path',
        'document_disk',
        'evidence_sha256',
        'supersedes_certification_id',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'reviewed_by',
        'reviewed_at',
        'created_by',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'expiry_date' => 'date',
        'next_review_date' => 'date',
        'reviewed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    // Relationships
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_certification_id')->withTrashed();
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    // Scopes
    public function scopeExpiring($query)
    {
        return $query->where('status', 'current')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now()->addDays(30))
            ->where('expiry_date', '>=', now());
    }

    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'expired')
                ->orWhere(function ($q2) {
                    $q2->whereNotNull('expiry_date')
                        ->where('expiry_date', '<', now());
                });
        });
    }

    public function scopeCurrent($query)
    {
        return $query->where('status', 'current');
    }
}
