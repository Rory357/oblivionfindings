<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RosterTemplate extends Model
{
    use HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $fillable = [
        'name',
        'description',
        'template_type',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function templateShifts()
    {
        return $this->hasMany(RosterTemplateShift::class);
    }
}
