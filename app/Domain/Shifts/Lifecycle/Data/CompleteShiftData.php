<?php

namespace App\Domain\Shifts\Lifecycle\Data;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use Carbon\CarbonInterface;

class CompleteShiftData
{
    /**
     * @param  array<string, mixed>  $timelineMeta
     */
    public function __construct(
        public readonly ?CarbonInterface $actualStartsAt = null,
        public readonly ?CarbonInterface $actualEndsAt = null,
        public readonly ?string $finalNoteSubject = null,
        public readonly ?string $finalNoteBody = null,
        public readonly bool $allowIncompleteTasks = false,
        public readonly ?string $incompleteTasksReason = null,
        public readonly ?string $handoverWaiverReason = null,
        public readonly ShiftLifecycleSource $source = ShiftLifecycleSource::Manual,
        public readonly bool $createSummaryNote = true,
        public readonly bool $syncDraftTimesheet = true,
        public readonly bool $autoWaiveHandover = false,
        public readonly array $timelineMeta = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromManualRequest(array $data): self
    {
        return new self(
            finalNoteSubject: $data['final_note_subject'] ?? null,
            finalNoteBody: $data['final_note_body'] ?? null,
            allowIncompleteTasks: (bool) ($data['allow_incomplete_tasks'] ?? false),
            incompleteTasksReason: $data['incomplete_tasks_reason'] ?? null,
            handoverWaiverReason: $data['handover_waiver_reason'] ?? null,
            source: ShiftLifecycleSource::Manual,
        );
    }

    /**
     * @param  array<string, mixed>  $timelineMeta
     */
    public static function fromClockOutSession(
        HrAttendanceSession $session,
        bool $forced = false,
        ?string $overrideReason = null,
        array $timelineMeta = [],
    ): self {
        $reason = trim((string) $overrideReason);

        return new self(
            actualStartsAt: $session->clock_in_at,
            actualEndsAt: $session->clock_out_at,
            allowIncompleteTasks: $forced,
            incompleteTasksReason: $forced && $reason !== '' ? $reason : null,
            handoverWaiverReason: $forced && $reason !== '' ? $reason : 'clock_out_auto_complete',
            source: ShiftLifecycleSource::ClockOut,
            createSummaryNote: false,
            syncDraftTimesheet: false,
            autoWaiveHandover: $forced,
            timelineMeta: $timelineMeta,
        );
    }
}
