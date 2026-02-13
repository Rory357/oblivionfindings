<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrDocument extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'employee_profile_id',
        'template_id',
        'title',
        'category',
        'storage_disk',
        'storage_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'is_restricted',
        'generated_from_template',
        'sent_to_employee',
        'sent_at',
        'signed_by_employee',
        'signed_at',
        'signed_document_path',
        'created_by',
        'uploaded_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'is_restricted' => 'boolean',
        'generated_from_template' => 'boolean',
        'sent_to_employee' => 'boolean',
        'sent_at' => 'datetime',
        'signed_by_employee' => 'boolean',
        'signed_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrDocumentTemplate::class, 'template_id');
    }
}
