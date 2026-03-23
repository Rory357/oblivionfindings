<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffQualificationRequirement extends Model
{
    protected $table = 'staff_qualification_requirements';

    protected $fillable = [
        'organization_id',
        'client_id',
        'service_context_id',
        'qualification_name',
        'qualification_type',
        'is_mandatory',
        'description',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
