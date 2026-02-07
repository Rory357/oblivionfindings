<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AuditEvidencePack extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'audit_evidence_packs';

    protected $fillable = [
        'pack_name',
        'audit_type',
        'audit_date_range_start',
        'audit_date_range_end',
        'generated_at',
        'generated_by',
        'file_path',
        'file_size',
        'checksum',
        'contents_manifest',
        'included_data_types',
        'retention_until',
        'deleted_after_retention',
        'legal_hold',
        'legal_hold_reason',
    ];

    protected $casts = [
        'audit_date_range_start' => 'date',
        'audit_date_range_end' => 'date',
        'generated_at' => 'datetime',
        'retention_until' => 'date',
        'contents_manifest' => 'array',
        'included_data_types' => 'array',
        'deleted_after_retention' => 'boolean',
        'legal_hold' => 'boolean',
    ];

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('audit_type', $type);
    }

    public function scopeExpiring($query)
    {
        return $query->where('retention_until', '<=', now()->addMonth())
            ->where('deleted_after_retention', false);
    }

    public function scopeOnLegalHold($query)
    {
        return $query->where('legal_hold', true);
    }

    public function isOnLegalHold(): bool
    {
        return $this->legal_hold;
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->retention_until && 
               $this->retention_until->diffInDays(now()) <= $days &&
               !$this->deleted_after_retention;
    }

    public function applyLegalHold(string $reason): void
    {
        $this->update([
            'legal_hold' => true,
            'legal_hold_reason' => $reason,
        ]);
    }

    public function releaseLegalHold(): void
    {
        $this->update([
            'legal_hold' => false,
            'legal_hold_reason' => null,
        ]);
    }

    public function verifyIntegrity(): bool
    {
        if (!$this->file_path || !\Storage::exists($this->file_path)) {
            return false;
        }
        $currentChecksum = hash_file('sha256', \Storage::path($this->file_path));
        return hash_equals($this->checksum, $currentChecksum);
    }

    public function getDownloadUrl(): ?string
    {
        if (!$this->file_path || !\Storage::exists($this->file_path)) {
            return null;
        }
        return \Storage::temporaryUrl($this->file_path, now()->addHour());
    }

    public function canDelete(): bool
    {
        return !$this->legal_hold && 
               $this->retention_until && 
               $this->retention_until->isPast();
    }

    public function getTypeLabel(): string
    {
        return match($this->audit_type) {
            'nga_paerewa' => 'Ngā Paerewa Audit',
            'charities' => 'Charities Services Audit',
            'funding' => 'Funding Provider Audit',
            'iso' => 'ISO Certification Audit',
            'internal' => 'Internal Audit',
            default => $this->audit_type,
        };
    }
}
