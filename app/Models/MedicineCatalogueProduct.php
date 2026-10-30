<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicineCatalogueProduct extends Model
{
    protected $guarded = [];

    protected $hidden = ['photo_path', 'photo_request_uuid'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(MedicineCatalogueSource::class, 'source_id');
    }
}
