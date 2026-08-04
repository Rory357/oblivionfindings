<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Database\Factories\Hr\HrDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrDocument extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrDocumentFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'template_id',
        'title',
        'category',
        'folder',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'is_restricted',
        'generated_from_template',
        'version',
        'sent_to_employee',
        'sent_at',
        'signed_by_employee',
        'signed_at',
        'signed_document_path',
        'expires_at',
        'expiry_reminder_sent',
        'created_by',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'version' => 'integer',
        'is_restricted' => 'boolean',
        'generated_from_template' => 'boolean',
        'sent_to_employee' => 'boolean',
        'sent_at' => 'datetime',
        'signed_by_employee' => 'boolean',
        'signed_at' => 'datetime',
        'expires_at' => 'date',
        'expiry_reminder_sent' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrDocumentTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(HrDocumentSignature::class, 'document_id');
    }
}
