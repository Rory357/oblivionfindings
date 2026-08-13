<?php

namespace App\Domain\Clinical\Models;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientMealRestrictionDiscrepancy extends Model
{
    protected $fillable = [
        'site_id',
        'client_id',
        'restriction_id',
        'reported_by',
        'report_replay_key',
        'status',
        'details',
        'reported_at',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function restriction(): BelongsTo
    {
        return $this->belongsTo(ClientMealRestriction::class, 'restriction_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
