<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrCustomFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'field_definition_id',
        'value',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(HrEmployeeProfile::class, 'employee_profile_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(HrCustomFieldDefinition::class, 'field_definition_id');
    }
}
