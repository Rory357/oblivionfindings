<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDocumentFolder extends Model
{
    protected $fillable = [
        'site_id',
        'name',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
