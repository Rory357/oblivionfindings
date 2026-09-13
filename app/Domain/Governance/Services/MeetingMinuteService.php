<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingMinute;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class MeetingMinuteService
{
    /**
     * Store initial draft minutes for a meeting.
     */
    public function storeMinutes(GovernanceMeeting $meeting, ?array $contentBlocks, User $user): MeetingMinute
    {
        return DB::transaction(function () use ($meeting, $contentBlocks, $user) {
            $lockedMeeting = GovernanceMeeting::where('id', $meeting->id)->lockForUpdate()->firstOrFail();

            // Reconcile existing minutes: if already exists, return existing
            $existing = MeetingMinute::where('governance_meeting_id', $lockedMeeting->id)->first();
            if ($existing) {
                return $existing;
            }

            if (empty($contentBlocks)) {
                $contentBlocks = $lockedMeeting->generateMinutesSkeleton();
            }

            $contentHash = hash('sha256', json_encode($contentBlocks));

            $initialHistory = [
                [
                    'version' => 1,
                    'status' => 'draft',
                    'content_blocks' => $contentBlocks,
                    'content_hash' => $contentHash,
                    'created_by_user_id' => $user->id,
                    'created_by_name' => $user->name,
                    'created_at' => now()->toIso8601String(),
                    'note' => 'Initial draft created',
                ],
            ];

            $minutes = MeetingMinute::create([
                'governance_meeting_id' => $lockedMeeting->id,
                'content_blocks' => $contentBlocks,
                'version_number' => 1,
                'status' => 'draft',
                'version_history' => $initialHistory,
                'drafted_by' => $user->id,
                'drafted_at' => now(),
            ]);

            $lockedMeeting->update(['status' => 'minutes_draft']);

            return $minutes;
        });
    }

    /**
     * Update draft minutes with optimistic locking and full version retention.
     * Denies updating approved/signed minutes in place.
     */
    public function updateMinutes(GovernanceMeeting $meeting, array $contentBlocks, User $user, ?int $expectedVersion = null): MeetingMinute
    {
        return DB::transaction(function () use ($meeting, $contentBlocks, $user, $expectedVersion) {
            $lockedMeeting = GovernanceMeeting::where('id', $meeting->id)->lockForUpdate()->firstOrFail();
            $minutes = MeetingMinute::where('governance_meeting_id', $lockedMeeting->id)->lockForUpdate()->firstOrFail();

            // 1. Guard against in-place edits to frozen minutes
            if (! $minutes->canEdit()) {
                throw new DomainException(
                    "Minutes in status '{$minutes->status}' cannot be edited in place. Approved and signed minutes are immutable. Create a correction draft to propose revisions."
                );
            }

            // 2. Concurrency guard (optimistic locking)
            if ($expectedVersion !== null && (int) $expectedVersion !== (int) $minutes->version_number) {
                throw new DomainException(
                    "Stale edit conflict: you submitted version {$expectedVersion}, but the current version is {$minutes->version_number}. Please refresh to review the latest changes."
                );
            }

            // 3. Preserve current version snapshot in history before mutating
            $history = $minutes->version_history ?? [];
            $history[] = [
                'version' => $minutes->version_number,
                'status' => $minutes->status,
                'content_blocks' => $minutes->content_blocks,
                'content_hash' => hash('sha256', json_encode($minutes->content_blocks ?? [])),
                'updated_by_user_id' => $user->id,
                'updated_by_name' => $user->name,
                'archived_at' => now()->toIso8601String(),
                'note' => 'Prior version superseded by update',
            ];

            $minutes->content_blocks = $contentBlocks;
            $minutes->version_number = $minutes->version_number + 1;
            $minutes->version_history = $history;
            $minutes->save();

            return $minutes;
        });
    }

    /**
     * Transition draft minutes to reviewed status.
     */
    public function submitForReview(GovernanceMeeting $meeting, User $user): MeetingMinute
    {
        return DB::transaction(function () use ($meeting, $user) {
            $lockedMeeting = GovernanceMeeting::where('id', $meeting->id)->lockForUpdate()->firstOrFail();
            $minutes = MeetingMinute::where('governance_meeting_id', $lockedMeeting->id)->lockForUpdate()->firstOrFail();

            if (! in_array($minutes->status, ['draft', 'reviewed'])) {
                throw new DomainException("Minutes in status '{$minutes->status}' cannot be submitted for review.");
            }

            $boardMember = $user->boardMember ?? BoardMember::where('user_id', $user->id)->first();
            $actorId = $boardMember?->id ?? $user->id;

            $history = $minutes->version_history ?? [];
            $history[] = [
                'event' => 'submitted_for_review',
                'version' => $minutes->version_number,
                'status' => 'reviewed',
                'user_id' => $user->id,
                'user_name' => $user->name,
                'timestamp' => now()->toIso8601String(),
            ];

            $minutes->status = 'reviewed';
            $minutes->version_history = $history;
            $minutes->save();

            $lockedMeeting->update(['status' => 'minutes_review']);

            return $minutes;
        });
    }

    public function approveMinutes(
        GovernanceMeeting $meeting,
        User $user,
        ?string $notes = null,
        ?int $expectedVersion = null,
        ?string $expectedHash = null
    ): MeetingMinute {
        return DB::transaction(function () use ($meeting, $user, $notes, $expectedVersion, $expectedHash) {
            $lockedMeeting = GovernanceMeeting::where('id', $meeting->id)->lockForUpdate()->firstOrFail();
            $minutes = MeetingMinute::where('governance_meeting_id', $lockedMeeting->id)->lockForUpdate()->firstOrFail();

            if ($minutes->isApproved()) {
                // Idempotent return if already approved
                return $minutes;
            }

            if (! in_array($minutes->status, ['draft', 'reviewed'])) {
                throw new DomainException("Minutes in status '{$minutes->status}' cannot be approved.");
            }

            // Must have reviewable content
            if (! $minutes->hasReviewableContent()) {
                throw new DomainException('Cannot approve empty minutes. At least one agenda or minute section must contain written content.');
            }

            // Concurrency guard: verify reviewer saw the current version
            if ($expectedVersion !== null && (int) $minutes->version_number !== (int) $expectedVersion) {
                throw new DomainException("Concurrency conflict: expected minute version {$expectedVersion}, but current version is {$minutes->version_number}.");
            }

            $currentHash = hash('sha256', json_encode($minutes->content_blocks ?? []));
            if ($expectedHash !== null && $currentHash !== $expectedHash) {
                throw new DomainException("Concurrency conflict: minute content hash does not match expected hash.");
            }

            $boardMember = $user->boardMember ?? BoardMember::where('user_id', $user->id)->first();
            $boardMemberId = $boardMember?->id;

            $history = $minutes->version_history ?? [];
            $history[] = [
                'event' => 'approved',
                'version' => $minutes->version_number,
                'status' => 'approved',
                'content_hash' => $currentHash,
                'approver_user_id' => $user->id,
                'approver_user_name' => $user->name,
                'approver_board_member_id' => $boardMemberId,
                'timestamp' => now()->toIso8601String(),
                'notes' => $notes,
            ];

            $minutes->status = 'approved';
            $minutes->reviewed_by = $boardMemberId;
            $minutes->reviewed_at = now();
            if ($notes !== null) {
                $minutes->review_notes = $notes;
            }
            $minutes->version_history = $history;
            $minutes->save();

            $lockedMeeting->update([
                'status' => 'minutes_approved',
                'minutes_approved_at' => now(),
                'minutes_approved_by' => $boardMemberId,
            ]);

            return $minutes;
        });
    }

    /**
     * Sign approved minutes. Replay safe and preserves signer attribution.
     */
    public function signMinutes(
        GovernanceMeeting $meeting,
        User $user,
        ?int $expectedVersion = null,
        ?string $expectedHash = null
    ): MeetingMinute {
        return DB::transaction(function () use ($meeting, $user, $expectedVersion, $expectedHash) {
            $lockedMeeting = GovernanceMeeting::where('id', $meeting->id)->lockForUpdate()->firstOrFail();
            $minutes = MeetingMinute::where('governance_meeting_id', $lockedMeeting->id)->lockForUpdate()->firstOrFail();

            // Replay safe: if already signed, return existing minute without re-dating
            if ($minutes->isSigned()) {
                return $minutes;
            }

            if (! $minutes->isApproved()) {
                throw new DomainException("Minutes must be approved before they can be signed. Current status is '{$minutes->status}'.");
            }

            // Concurrency guard: verify signer signed the exact approved version
            if ($expectedVersion !== null && (int) $minutes->version_number !== (int) $expectedVersion) {
                throw new DomainException("Concurrency conflict: expected minute version {$expectedVersion}, but current version is {$minutes->version_number}.");
            }

            $currentHash = hash('sha256', json_encode($minutes->content_blocks ?? []));
            if ($expectedHash !== null && $currentHash !== $expectedHash) {
                throw new DomainException("Concurrency conflict: minute content hash does not match expected hash.");
            }

            $boardMember = $user->boardMember ?? BoardMember::where('user_id', $user->id)->first();
            $boardMemberId = $boardMember?->id;

            $history = $minutes->version_history ?? [];
            $history[] = [
                'event' => 'signed',
                'version' => $minutes->version_number,
                'status' => 'signed',
                'content_hash' => hash('sha256', json_encode($minutes->content_blocks ?? [])),
                'signer_user_id' => $user->id,
                'signer_user_name' => $user->name,
                'signer_board_member_id' => $boardMemberId,
                'timestamp' => now()->toIso8601String(),
                'attestation' => 'Minutes confirmed as an accurate, true, and complete record of proceedings by the authorized signatory.',
            ];

            $minutes->status = 'signed';
            $minutes->signed_by = $boardMemberId ?? $user->id;
            $minutes->signed_at = now();
            $minutes->version_history = $history;
            $minutes->save();

            $lockedMeeting->update([
                'status' => 'minutes_signed',
                'minutes_signed_at' => now(),
                'minutes_signed_by' => $boardMemberId,
            ]);

            return $minutes;
        });
    }

    /**
     * Archive signed minutes.
     */
    public function archiveMinutes(GovernanceMeeting $meeting, User $user): MeetingMinute
    {
        return DB::transaction(function () use ($meeting, $user) {
            $lockedMeeting = GovernanceMeeting::where('id', $meeting->id)->lockForUpdate()->firstOrFail();
            $minutes = MeetingMinute::where('governance_meeting_id', $lockedMeeting->id)->lockForUpdate()->firstOrFail();

            if (! $minutes->isSigned()) {
                throw new DomainException("Only signed minutes can be archived. Current status is '{$minutes->status}'.");
            }

            $history = $minutes->version_history ?? [];
            $history[] = [
                'event' => 'archived',
                'version' => $minutes->version_number,
                'status' => 'archived',
                'archived_by_user_id' => $user->id,
                'archived_by_user_name' => $user->name,
                'timestamp' => now()->toIso8601String(),
            ];

            $minutes->status = 'archived';
            $minutes->archived_at = now();
            $minutes->version_history = $history;
            $minutes->save();

            $lockedMeeting->update(['status' => 'archived']);

            return $minutes;
        });
    }

    /**
     * Create a correction draft from an approved, signed, or archived minute.
     * Preserves the full previous version in version_history.
     */
    public function createCorrection(GovernanceMeeting $meeting, User $user, string $reason): MeetingMinute
    {
        return DB::transaction(function () use ($meeting, $user, $reason) {
            $lockedMeeting = GovernanceMeeting::where('id', $meeting->id)->lockForUpdate()->firstOrFail();
            $minutes = MeetingMinute::where('governance_meeting_id', $lockedMeeting->id)->lockForUpdate()->firstOrFail();

            if (! in_array($minutes->status, ['approved', 'signed', 'archived'])) {
                throw new DomainException("Only approved, signed, or archived minutes can have a correction draft created. Current status is '{$minutes->status}'.");
            }

            // Capture complete immutable snapshot of previous version
            $history = $minutes->version_history ?? [];
            $history[] = [
                'event' => 'superseded_for_correction',
                'version' => $minutes->version_number,
                'status' => $minutes->status,
                'content_blocks' => $minutes->content_blocks,
                'content_hash' => hash('sha256', json_encode($minutes->content_blocks ?? [])),
                'drafted_by' => $minutes->drafted_by,
                'drafted_at' => $minutes->drafted_at?->toIso8601String(),
                'reviewed_by' => $minutes->reviewed_by,
                'reviewed_at' => $minutes->reviewed_at?->toIso8601String(),
                'signed_by' => $minutes->signed_by,
                'signed_at' => $minutes->signed_at?->toIso8601String(),
                'archived_at' => $minutes->archived_at?->toIso8601String(),
                'superseded_at' => now()->toIso8601String(),
                'reason_for_correction' => $reason,
                'correction_initiated_by_user_id' => $user->id,
                'correction_initiated_by_name' => $user->name,
            ];

            // Increment version and reset to draft for editing
            $newVersion = $minutes->version_number + 1;
            $minutes->version_number = $newVersion;
            $minutes->status = 'draft';
            $minutes->drafted_by = $user->id;
            $minutes->drafted_at = now();
            $minutes->reviewed_by = null;
            $minutes->reviewed_at = null;
            $minutes->signed_by = null;
            $minutes->signed_at = null;
            $minutes->archived_at = null;
            $minutes->version_history = $history;
            $minutes->save();

            $lockedMeeting->update([
                'status' => 'minutes_draft',
                'minutes_approved_at' => null,
                'minutes_approved_by' => null,
                'minutes_signed_at' => null,
                'minutes_signed_by' => null,
            ]);

            return $minutes;
        });
    }
}
