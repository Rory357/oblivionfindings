<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A generated data-export package for a data subject request (Privacy Act 2020
 * IPP 6 access response). Backs the previously-orphaned
 * DataSubjectRequest::dataExports() relation (the table existed but the model
 * did not, so the relation fatally errored if ever resolved).
 */
class DataExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'data_subject_request_id',
        'export_type',
        'included_models',
        'export_format',
        'export_path',
        'file_size_bytes',
        'generated_at',
        'generated_by_user_id',
        'expires_at',
        'password_protected',
        'access_count',
        'last_accessed_at',
        'deleted',
    ];

    protected $casts = [
        'included_models' => 'array',
        'generated_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'password_protected' => 'boolean',
        'deleted' => 'boolean',
        'file_size_bytes' => 'integer',
        'access_count' => 'integer',
    ];

    public function dataSubjectRequest(): BelongsTo
    {
        return $this->belongsTo(DataSubjectRequest::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }
}
