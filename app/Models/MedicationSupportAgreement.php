<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Model;

class MedicationSupportAgreement extends Model
{
    use AuditableChanges;

    protected $table = 'medication_support_agreements';

    protected $fillable = ['client_id', 'assessment_id', 'agreed_by_role', 'agreed_by_name', 'method', 'witness_id', 'recorded_by', 'attachment_path', 'attachment_name', 'ordering_responsibility', 'person_responsibilities', 'staff_responsibilities', 'storage_notes', 'supersedes_id'];

    protected $casts = [];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
