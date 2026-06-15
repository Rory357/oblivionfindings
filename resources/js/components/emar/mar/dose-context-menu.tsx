/* Right-click quick-action menu for a MAR time-grid dose cell.
 *
 * Reuses the shared `ShiftContextMenu` chrome (cursor-anchored, viewport-flip,
 * Esc/outside-close) and routes EVERY write through POST /meds/today/record →
 * EnhancedMarService — the single administration pipeline the wizard uses. So
 * witness rules, CD register entries, coded omission reasons and the audit
 * trail run identically whether the dose is recorded here or in the full
 * wizard. No second write path.
 *
 * "Mark given" is a genuine one-click record for *simple* meds (not controlled,
 * no witness, no required observation, today only). Anything that needs a
 * witness / balance / observation — or a coded reason (refused / withheld) —
 * opens the full RecordDoseWizard instead, so the required input is captured
 * and nothing is signed to the MAR without it.
 */
import { ShiftContextMenu, type ShiftCtxItem } from '@/components/rostering/shift-context-menu';
import type { DoseStatus, ScheduleRow } from '@/pages/meds/today/types';
import { router } from '@inertiajs/react';
import { Ban, Check, ClipboardCheck, Eye, FileText, Hand, History } from 'lucide-react';

/** What the MAR page tracks when a dose cell is right-clicked. `requiresObservation`
 *  comes from the rich `marData.scheduled` row (not on the flat ScheduleRow). */
export type DoseCtxTarget = {
    x: number;
    y: number;
    row: ScheduleRow;
    requiresObservation: boolean;
};

/** Status → CSS-var tag colours (design tokens only — no hex/oklch). */
const TAG_TONE: Record<DoseStatus, { bg: string; color: string }> = {
    given: { bg: 'var(--status-success-bg)', color: 'var(--status-success)' },
    refused: { bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' },
    withheld: { bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' },
    missed: { bg: 'var(--status-critical-bg)', color: 'var(--status-critical)' },
    overdue: { bg: 'var(--status-critical-bg)', color: 'var(--status-critical)' },
    due: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
    upcoming: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
};

const STATUS_LABEL: Record<DoseStatus, string> = {
    given: 'Given',
    refused: 'Refused',
    withheld: 'Withheld',
    missed: 'Missed',
    overdue: 'Overdue',
    due: 'Due',
    upcoming: 'Due',
};

function nowHm(): string {
    const d = new Date();
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function DoseContextMenu({
    target,
    date,
    isToday,
    canRecord,
    onRecordFull,
    onOutcome,
    onViewHistory,
    onClose,
}: {
    target: DoseCtxTarget | null;
    /** Board date (Y-m-d) — a quick "given" is anchored to it. */
    date: string;
    isToday: boolean;
    canRecord: boolean;
    /** Open the full RecordDoseWizard (defaults to the "given" outcome). */
    onRecordFull: (row: ScheduleRow) => void;
    /** Open the wizard with the outcome pre-selected (reason captured there). */
    onOutcome: (row: ScheduleRow, outcome: 'refused' | 'withheld') => void;
    /** Jump to the History tab (today's recorded administrations). */
    onViewHistory: () => void;
    onClose: () => void;
}) {
    if (!target) return null;

    const { row } = target;
    const recorded = row.recorded !== null;
    // A one-click "given" is only safe when nothing extra must be captured:
    // controlled drugs + witness meds need a countersignature, observation meds
    // need a reading, an overdue/missed dose needs a late reason, and a non-today
    // board makes "now" the wrong timestamp.
    const isLate = row.status === 'overdue' || row.status === 'missed';
    const needsWizardForGiven = row.is_controlled || row.requires_witness || target.requiresObservation || isLate || !isToday;

    const quickGiven = () => {
        // Same endpoint + pipeline as the wizard; a client_request_uuid makes a
        // double-click idempotent (HandlesOfflineSubmission de-dupes the replay).
        router.post(
            '/meds/today/record',
            {
                client_medication_id: row.medication_id,
                scheduled_for: row.scheduled_for,
                status: 'given',
                administered_at: `${date}T${nowHm()}:00`,
                client_request_uuid: crypto.randomUUID(),
            },
            {
                preserveScroll: true,
                // If the server still wants more (e.g. a verification gate or a
                // facility rule we didn't predict), fall back to the full wizard.
                onError: () => onRecordFull(row),
            },
        );
        onClose();
    };

    const viewHistory: ShiftCtxItem = {
        icon: <History className="h-3.5 w-3.5" />,
        label: "View today's records",
        sub: 'Open the History tab',
        onClick: onViewHistory,
    };

    let items: ShiftCtxItem[];

    if (recorded) {
        items = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'Recorded',
                sub: `${STATUS_LABEL[row.status]}${row.recorded?.time ? ` at ${row.recorded.time}` : ''}${row.recorded?.by ? ` · ${row.recorded.by}` : ''}`,
                tone: 'primary',
                onClick: onViewHistory,
            },
            { sep: true },
            viewHistory,
        ];
    } else if (canRecord) {
        items = [
            {
                icon: <Check className="h-3.5 w-3.5" />,
                label: 'Mark given',
                sub: !needsWizardForGiven
                    ? 'Records to the MAR now'
                    : row.is_controlled || row.requires_witness
                      ? 'Witness required → full check'
                      : target.requiresObservation
                        ? 'Observation required → full check'
                        : isLate
                          ? 'Overdue — record with a reason'
                          : 'Opens the recording flow',
                kbd: 'G',
                tone: 'primary',
                onClick: needsWizardForGiven ? () => onRecordFull(row) : quickGiven,
            },
            {
                icon: <Hand className="h-3.5 w-3.5" />,
                label: 'Refused',
                sub: 'Pick a reason → sign',
                onClick: () => onOutcome(row, 'refused'),
            },
            {
                icon: <Ban className="h-3.5 w-3.5" />,
                label: 'Withheld',
                sub: 'Needs a reason — audited',
                tone: 'critical',
                onClick: () => onOutcome(row, 'withheld'),
            },
            { sep: true },
            {
                icon: <ClipboardCheck className="h-3.5 w-3.5" />,
                label: 'Record in full',
                sub: 'Safety checks → sign',
                onClick: () => onRecordFull(row),
            },
            viewHistory,
        ];
    } else {
        items = [viewHistory];
    }

    const tone = TAG_TONE[row.status];

    return (
        <ShiftContextMenu
            ctx={{
                x: target.x,
                y: target.y,
                tag: STATUS_LABEL[row.status],
                tagBg: tone.bg,
                tagColor: tone.color,
                meta: `${row.medication_name}${row.dose ? ` · ${row.dose}` : ''}`,
                items,
            }}
            onClose={onClose}
        />
    );
}

export default DoseContextMenu;
