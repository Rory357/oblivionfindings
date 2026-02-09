<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteHoSetting extends Model
{
    use HasFactory;

    protected $table = 'site_ho_settings';

    protected $fillable = [
        'site_id',
        'tenant_id',
        'visitor_sign_in_process',
        'after_hours_procedures',
        'it_network_details',
    ];

    protected $casts = [
        'it_network_details' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
