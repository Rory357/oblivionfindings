<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DashboardSnapshot extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'snapshot_data',
        'period_type',
        'period_start',
        'period_end',
        'checksum',
        'captured_at',
        'captured_by',
        'data_freshness',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'captured_at' => 'datetime',
        'snapshot_data' => 'array',
        'data_freshness' => 'array',
    ];

    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    public function boardPack(): HasOne
    {
        return $this->hasOne(BoardPack::class, 'dashboard_snapshot_id');
    }

    public static function generateChecksum(array $data): string
    {
        return hash('sha256', json_encode($data));
    }

    public function verifyIntegrity(): bool
    {
        $expectedChecksum = self::generateChecksum($this->snapshot_data);
        return hash_equals($this->checksum, $expectedChecksum);
    }

    public function getWidgetData(string $widget): ?array
    {
        return $this->snapshot_data['widgets'][$widget] ?? null;
    }

    public function scopeForPeriod($query, string $type, string $start, string $end)
    {
        return $query->where('period_type', $type)
            ->where('period_start', $start)
            ->where('period_end', $end);
    }

    public function scopeLatest($query)
    {
        return $query->orderByDesc('captured_at');
    }
}
