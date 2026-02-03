<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProcedureTemplate extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'name',
        'domain',
        'version',
        'trigger_event',
        'description',
        'steps_json',
        'required_roles',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'steps_json' => 'array',
        'required_roles' => 'array',
        'active' => 'boolean',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(ProcedureRun::class);
    }
}
