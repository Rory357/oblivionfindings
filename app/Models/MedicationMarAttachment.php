<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class MedicationMarAttachment extends Model
{
    use HasFactory;
    use AuditableChanges;

    protected $fillable = [
        'client_medication_administration_id',
        'client_id',
        'attachable_type',
        'attachable_id',
        'file_name',
        'file_path',
        'mime_type',
        'file_size',
        'description',
        'uploaded_by',
    ];

    protected $casts = [
        'file_size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function ($attachment) {
            // Delete the physical file when the record is deleted
            if ($attachment->file_path && Storage::exists($attachment->file_path)) {
                Storage::delete($attachment->file_path);
            }
        });
    }

    public function administration(): BelongsTo
    {
        return $this->belongsTo(ClientMedicationAdministration::class, 'client_medication_administration_id');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Get file URL
     */
    public function getUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }

    /**
     * Get formatted file size
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes === null) return '—';
        
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        
        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }
        
        return round($bytes, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Check if file is an image
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    /**
     * Check if file is a PDF
     */
    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }
}
