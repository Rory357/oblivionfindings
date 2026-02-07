<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComplianceEvidence extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'compliance_evidence';

    protected $fillable = [
        'compliance_obligation_id',
        'evidence_type',
        'title',
        'description',
        'file_path',
        'external_reference',
        'url',
        'valid_from',
        'valid_until',
        'uploaded_by',
        'uploaded_at',
        'verified',
        'verified_by',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'uploaded_at' => 'datetime',
        'verified_at' => 'datetime',
        'verified' => 'boolean',
    ];

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(ComplianceObligation::class, 'compliance_obligation_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopeVerified($query)
    {
        return $query->where('verified', true);
    }

    public function scopePendingVerification($query)
    {
        return $query->where('verified', false);
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->whereBetween('valid_until', [now(), now()->addDays($days)]);
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->valid_until && $this->valid_until->diffInDays(now()) <= $days;
    }

    public function verify(int $userId, ?string $notes = null): void
    {
        $this->update([
            'verified' => true,
            'verified_by' => $userId,
            'verified_at' => now(),
            'verification_notes' => $notes,
        ]);

        // Update obligation status
        $this->obligation->update(['evidence_provided' => true]);
    }

    public function getFileUrl(): ?string
    {
        if (!$this->file_path) {
            return null;
        }
        return \Illuminate\Support\Facades\Storage::url($this->file_path);
    }
}
