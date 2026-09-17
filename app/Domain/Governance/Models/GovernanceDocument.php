<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Support\GovernanceLabels;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class GovernanceDocument extends Model
{
    use AuditableChanges, SoftDeletes;

    /**
     * Reference files the board keeps. Policies are not a document type:
     * they live in Policies, where members read and confirm them.
     */
    public const TYPES = [
        'constitution',
        'terms_of_reference',
        'procedure',
        'template',
        'report',
        'certificate',
        'other',
    ];

    protected $fillable = [
        'title', 'document_type', 'category', 'description', 'file_path',
        'original_name', 'file_size', 'mime_type', 'version_number', 'uploaded_by',
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

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function typeOptions(): array
    {
        return array_map(fn (string $type) => [
            'value' => $type,
            'label' => $type === 'other' ? 'Other' : GovernanceLabels::label('document_type', $type),
        ], self::TYPES);
    }

    public static function typeLabel(?string $type): string
    {
        return $type === 'other' ? 'Other' : GovernanceLabels::label('document_type', $type);
    }

    /** The name the file was uploaded with, or the stored name for older files. */
    public function displayName(): string
    {
        $original = trim((string) $this->original_name);

        return $original !== '' ? $original : basename((string) $this->file_path);
    }

    /** "PDF", "Word document", "Spreadsheet"… */
    public function formatLabel(): string
    {
        $mime = strtolower((string) $this->mime_type);
        $extension = strtolower(pathinfo($this->displayName(), PATHINFO_EXTENSION));

        return match (true) {
            $mime === 'application/pdf' || $extension === 'pdf' => 'PDF',
            str_contains($mime, 'wordprocessingml') || $mime === 'application/msword'
                || in_array($extension, ['doc', 'docx', 'odt', 'rtf'], true) => 'Word document',
            str_contains($mime, 'spreadsheetml') || $mime === 'application/vnd.ms-excel'
                || in_array($extension, ['xls', 'xlsx', 'ods'], true) => 'Spreadsheet',
            $mime === 'text/csv' || $extension === 'csv' => 'CSV file',
            str_contains($mime, 'presentationml') || $mime === 'application/vnd.ms-powerpoint'
                || in_array($extension, ['ppt', 'pptx', 'odp'], true) => 'Presentation',
            str_starts_with($mime, 'image/') => 'Image',
            str_starts_with($mime, 'text/') || $extension === 'txt' => 'Text file',
            $extension !== '' => strtoupper($extension).' file',
            default => 'File',
        };
    }
}
