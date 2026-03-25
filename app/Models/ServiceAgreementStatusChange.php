<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceAgreementStatusChange extends Model
{
    protected $fillable = [
        'service_agreement_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
        'notes',
    ];

    public function agreement()
    {
        return $this->belongsTo(ServiceAgreement::class, 'service_agreement_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
