<?php

namespace App\Domain\Monitoring\Models;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CollectorEnrollment extends Model
{
    protected $table = 'monitoring_collector_enrollments';

    protected $fillable = [
        'site_id',
        'issued_by_user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'consumed_collector_id',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'consumed_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function consumedCollector(): BelongsTo
    {
        return $this->belongsTo(MonitoringCollector::class, 'consumed_collector_id');
    }
}
