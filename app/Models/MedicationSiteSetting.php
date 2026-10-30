<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Medication Settings value chosen for one house (eMAR P11). A house with
 * no row follows the organisation value or the setting's default.
 */
class MedicationSiteSetting extends Model
{
    protected $fillable = ['site_id', 'key', 'value'];

    protected $casts = [
        'site_id' => 'integer',
        'value' => 'json',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
