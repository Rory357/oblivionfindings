<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EvidenceLibrary extends Model
{
    use SoftDeletes;

    protected $table = 'evidence_library';

    protected $fillable = [
        'title', 'evidence_type', 'description', 'file_path',
        'file_size', 'mime_type', 'valid_from', 'valid_until',
        'tags', 'uploaded_by', 'uploaded_at',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_until' => 'date',
        'uploaded_at' => 'datetime',
        'tags' => 'array',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function obligations(): BelongsToMany
    {
        return $this->belongsToMany(
            ComplianceObligation::class,
            'evidence_obligation',
            'evidence_library_id',
            'compliance_obligation_id'
        )->withPivot('linked_by')->withTimestamps();
    }

    public function isValid(): bool
    {
        return !$this->valid_until || $this->valid_until->isFuture();
    }

    public function isExpiring(int $days = 30): bool
    {
        return $this->valid_until && $this->valid_until->diffInDays(now()) <= $days;
    }

    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('valid_until')->orWhere('valid_until', '>=', now());
        });
    }

    public function scopeExpiring($query, int $days = 30)
    {
        return $query->whereNotNull('valid_until')
            ->where('valid_until', '<=', now()->addDays($days));
    }
}
