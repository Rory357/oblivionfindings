<?php

namespace App\Domain\CompanyHub\Models;

use Illuminate\Database\Eloquent\Model;

class HubEventDraftFile extends Model
{
    protected $guarded = [];

    protected $casts = ['retained' => 'boolean'];

    public function draft()
    {
        return $this->belongsTo(HubEventDraft::class, 'draft_id');
    }
}
