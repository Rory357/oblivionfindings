<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HrPublicHoliday extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $fillable = [
        'tenant_id',
        'name',
        'date',
        'region',
        'is_national',
        'year',
    ];

    protected $casts = [
        'date' => 'date',
        'is_national' => 'boolean',
        'year' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeForYear($query, int $year)
    {
        return $query->where('year', $year);
    }

    public function scopeNational($query)
    {
        return $query->where('is_national', true);
    }
}
