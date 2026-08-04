<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CarePlanSignOff extends Model
{
    use HasFactory, WritesLegacyOrganizationStorageContext;

    /** Who can be recorded as agreeing to a plan. */
    public const PARTY_ROLES = ['client', 'whanau', 'eor_guardian', 'key_worker', 'nasc', 'other'];

    /** How the agreement was reached / recorded. */
    public const METHODS = ['in_person', 'verbal', 'email', 'hui', 'portal'];

    protected $fillable = [
        'care_plan_id',
        'party_role',
        'party_name',
        'relationship',
        'agreed_on',
        'method',
        'acknowledgement',
        'recorded_by',
    ];

    protected $casts = [
        'agreed_on' => 'date',
    ];

    public function carePlan()
    {
        return $this->belongsTo(CarePlan::class);
    }

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
