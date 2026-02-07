<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoardPack extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'governance_meeting_id',
        'dashboard_snapshot_id',
        'document_manifest',
        'generated_at',
        'generated_by',
        'file_path',
        'file_size',
        'checksum',
        'watermark_text',
        'distributed_at',
        'distributed_to',
        'download_tracking',
        'read_tracking',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'distributed_at' => 'datetime',
        'document_manifest' => 'array',
        'distributed_to' => 'array',
        'download_tracking' => 'array',
        'read_tracking' => 'array',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(DashboardSnapshot::class, 'dashboard_snapshot_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function isDistributed(): bool
    {
        return !is_null($this->distributed_at);
    }

    public function markAsDistributed(array $boardMemberIds): void
    {
        $this->update([
            'distributed_at' => now(),
            'distributed_to' => $boardMemberIds,
        ]);
    }

    public function recordDownload(int $boardMemberId): void
    {
        $tracking = $this->download_tracking ?? [];
        $tracking[] = [
            'board_member_id' => $boardMemberId,
            'downloaded_at' => now()->toIso8601String(),
            'ip_address' => request()->ip(),
        ];
        $this->update(['download_tracking' => $tracking]);
    }

    public function recordRead(int $boardMemberId): void
    {
        $tracking = $this->read_tracking ?? [];
        if (!in_array($boardMemberId, array_column($tracking, 'board_member_id'))) {
            $tracking[] = [
                'board_member_id' => $boardMemberId,
                'read_at' => now()->toIso8601String(),
            ];
            $this->update(['read_tracking' => $tracking]);
        }
    }

    public function readCount(): int
    {
        return count($this->read_tracking ?? []);
    }

    public function downloadCount(): int
    {
        return count($this->download_tracking ?? []);
    }

    public function verifyIntegrity(): bool
    {
        if (!$this->file_path || !file_exists(storage_path('app/' . $this->file_path))) {
            return false;
        }
        $currentChecksum = hash_file('sha256', storage_path('app/' . $this->file_path));
        return hash_equals($this->checksum, $currentChecksum);
    }
}
