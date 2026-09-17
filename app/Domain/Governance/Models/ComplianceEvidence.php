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
        'original_name',
        'mime_type',
        'file_size',
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
        if ($this->valid_from && $this->valid_from->isFuture()) {
            throw new \DomainException("Compliance evidence with future valid_from date cannot be verified.");
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            throw new \DomainException("Compliance evidence with expired valid_until date cannot be verified.");
        }

        if ($this->file_path && ! \Illuminate\Support\Facades\Storage::disk('local')->exists($this->file_path) && ! \Illuminate\Support\Facades\Storage::disk('public')->exists($this->file_path)) {
            throw new \DomainException("Compliance evidence file does not exist in storage and cannot be verified.");
        }

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

    /** Past its "valid until" date (NZ calendar date). */
    public function isExpired(?string $today = null): bool
    {
        return $this->valid_until !== null
            && $this->valid_until->toDateString() < ($today ?? ComplianceObligation::nzToday());
    }

    /** The disk holding the stored file, or null when it is missing. */
    public function storedDisk(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        foreach ([config('filesystems.default', 'local'), 'local', 'public'] as $disk) {
            if (is_string($disk) && \Illuminate\Support\Facades\Storage::disk($disk)->exists($this->file_path)) {
                return $disk;
            }
        }

        return null;
    }

    /** "Annual return receipt.pdf" — the uploaded name, never the storage code. */
    public function downloadName(): string
    {
        $original = trim((string) $this->original_name);
        if ($original !== '') {
            return $original;
        }

        $extension = pathinfo((string) $this->file_path, PATHINFO_EXTENSION);
        $base = \Illuminate\Support\Str::slug((string) $this->title) ?: 'evidence';

        return $extension !== '' ? "{$base}.{$extension}" : $base;
    }
}
