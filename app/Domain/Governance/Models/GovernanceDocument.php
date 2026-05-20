<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\AuditableChanges;
class GovernanceDocument extends Model
{
    use SoftDeletes, AuditableChanges;

    protected $fillable = [
        'title', 'document_type', 'category', 'description', 'file_path',
        'file_size', 'mime_type', 'version_number', 'uploaded_by',
        'effective_from', 'expires_at', 'is_current', 'supersedes_id',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'expires_at' => 'date',
        'is_current' => 'boolean',
    ];

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
}
