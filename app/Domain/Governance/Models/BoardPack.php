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
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'governance_meeting_id',
        'revision_number',
        'supersedes_id',
        'build_status',
        'error_reference',
        'is_current',
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
        'supplementary_attachments',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'supersedes_id' => 'integer',
        'is_current' => 'boolean',
        'generated_at' => 'datetime',
        'distributed_at' => 'datetime',
        'document_manifest' => 'array',
        'distributed_to' => 'array',
        'download_tracking' => 'array',
        'read_tracking' => 'array',
        'supplementary_attachments' => 'array',
    ];

    protected array $auditExcludedAttributes = [
        'document_manifest',
        'file_path',
        'checksum',
        'distributed_to',
        'download_tracking',
        'read_tracking',
        'supplementary_attachments',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
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
        return ! is_null($this->distributed_at);
    }

    public function isPublished(): bool
    {
        return ($this->build_status ?? 'published') === 'published';
    }

    public function isBuilding(): bool
    {
        return $this->build_status === 'building';
    }

    public function isFailed(): bool
    {
        return $this->build_status === 'failed';
    }

    public function isCurrent(): bool
    {
        return (bool) ($this->is_current ?? true);
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopePublished($query)
    {
        return $query->where('build_status', 'published');
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

    /**
     * Record reading acknowledgement specifically for this pack revision.
     * Replay-safe and returns the durable receipt.
     */
    public function recordRead(int $boardMemberId, ?int $userId = null): array
    {
        $tracking = $this->read_tracking ?? [];

        foreach ($tracking as $entry) {
            if (($entry['board_member_id'] ?? null) === $boardMemberId) {
                return $entry;
            }
        }

        $receipt = [
            'receipt_id' => (string) \Illuminate\Support\Str::uuid(),
            'board_member_id' => $boardMemberId,
            'user_id' => $userId ?? auth()->id(),
            'revision_number' => (int) ($this->revision_number ?? 1),
            'read_at' => now()->toIso8601String(),
        ];

        $tracking[] = $receipt;
        $this->update(['read_tracking' => $tracking]);

        return $receipt;
    }

    public function hasMemberRead(int $boardMemberId): bool
    {
        return collect($this->read_tracking ?? [])
            ->contains(fn ($entry) => ($entry['board_member_id'] ?? null) === $boardMemberId);
    }

    public function getMemberReceipt(int $boardMemberId): ?array
    {
        return collect($this->read_tracking ?? [])
            ->first(fn ($entry) => ($entry['board_member_id'] ?? null) === $boardMemberId);
    }

    /**
     * Count actual documents and papers included in the pack.
     * Excludes root structural section keys (cover, agenda, dashboard, risk_report, finance_report).
     */
    public function actualDocumentCount(): int
    {
        $manifest = $this->document_manifest ?? [];
        $content = $manifest['content_sections'] ?? $manifest['content'] ?? [];

        $count = 0;

        // Count decision papers
        if (! empty($content['resolutions']['items'])) {
            $count += count($content['resolutions']['items']);
        }

        // Count supporting documents
        if (! empty($content['supporting_documents']['items'])) {
            $count += count($content['supporting_documents']['items']);
        }

        // Count committee reports
        if (! empty($content['committee_reports']['items'])) {
            $count += count($content['committee_reports']['items']);
        }

        // Count CEO report if included
        if (! empty($content['ceo_report'])) {
            $count += 1;
        }

        // Count supplementary attachments
        if (! empty($this->supplementary_attachments)) {
            $count += count($this->supplementary_attachments);
        }

        return $count;
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
        if (! $this->file_path) {
            return false;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($this->file_path)) {
            if (! file_exists(storage_path('app/' . $this->file_path))) {
                return false;
            }
            $currentChecksum = hash_file('sha256', storage_path('app/' . $this->file_path));
        } else {
            $currentChecksum = hash_file('sha256', $disk->path($this->file_path));
        }

        return hash_equals($this->checksum ?? '', $currentChecksum);
    }
}
