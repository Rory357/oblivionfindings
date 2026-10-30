import { ConfirmDialog } from '@/components/confirm-dialog';
import { startOfWeek } from '@/components/rostering';
import { NoteCommandFeedback, noteRecoveryUrl } from './note-command-ui';
import { type ShiftNote, clientName, noteCalendarDate, ymd } from './shared';
import { useNoteCommand } from './use-note-command';

export function NoteActionDialog({
    note,
    action,
    actorId,
    allowed,
    timeZone,
    weekStart,
    onClose,
}: {
    note: ShiftNote;
    action: 'flag' | 'review';
    actorId: number;
    allowed: boolean;
    timeZone: string;
    weekStart: string;
    onClose: () => void;
}) {
    const command = useNoteCommand(
        `${actorId}:${action}:${note.id}:${note.shift?.id}:${note.client?.id}`,
    );
    const confirmed = command.outcome?.status === 'confirmed';
    const unknown = command.outcome?.status === 'unknown';
    const day = noteCalendarDate(
        note.shift?.starts_at ?? note.created_at,
        timeZone,
    );
    const week = Number.isFinite(day.getTime())
        ? ymd(startOfWeek(day))
        : weekStart;
    const targetFlag = action === 'flag' ? !note.is_flagged : note.is_flagged;
    const title = confirmed
        ? action === 'flag'
            ? targetFlag
                ? 'Note flagged'
                : 'Flag removed'
            : command.outcome?.status === 'confirmed' &&
                !command.outcome.receipt.changed
              ? 'Review already recorded'
              : 'Review recorded'
        : action === 'flag'
          ? note.is_flagged
              ? 'Remove this flag?'
              : 'Flag this note for review?'
          : 'Mark this note as reviewed?';
    const close = () => {
        if (!command.busy.current) onClose();
    };
    const submit = () => {
        if (confirmed || unknown || !allowed) {
            close();
            return;
        }
        if (
            !note.shift ||
            !note.client ||
            (action === 'flag' ? !note.can_flag : !note.can_review)
        )
            return;
        void command.submit(
            'patch',
            `/operations/shift-notes/${note.id}/${action}`,
            {},
            {
                action,
                actorId,
                noteId: note.id,
                shiftId: note.shift.id,
                clientId: note.client.id,
                values: {
                    type: note.type,
                    body: note.body,
                    is_flagged: targetFlag,
                    flagged_reason:
                        action === 'flag'
                            ? targetFlag
                                ? 'Flagged for review'
                                : null
                            : note.flagged_reason,
                    is_private: note.is_private,
                },
                priorReview: {
                    at: note.reviewed_at,
                    by: note.reviewer?.id ?? null,
                },
            },
        );
    };
    return (
        <ConfirmDialog
            open
            onClose={close}
            onConfirm={submit}
            title={!allowed && !confirmed ? 'Action unavailable' : title}
            description={
                <>
                    <p>
                        Note #{note.id} for {clientName(note.client)}.
                    </p>
                    {!allowed && !confirmed && (
                        <p className="mt-2">
                            The current record or permissions no longer allow
                            this action. Close and check the current notes.
                        </p>
                    )}
                    {!confirmed && (
                        <p className="mt-2">
                            {action === 'review'
                                ? 'This records your review of this note. It does not confirm that all care tasks are complete.'
                                : 'This changes the attention flag on this note. It does not send an incident report or emergency alert.'}
                        </p>
                    )}
                    <NoteCommandFeedback
                        outcome={command.outcome}
                        recoveryUrl={noteRecoveryUrl(
                            week,
                            note.client?.id ?? null,
                            note.user?.id,
                        )}
                        noteId={note.id}
                    />
                    {unknown && (
                        <p>
                            Check the current record before starting this action
                            again. Closing this message does not undo a saved
                            action.
                        </p>
                    )}
                </>
            }
            confirmText={
                confirmed || unknown || !allowed
                    ? 'Close message'
                    : action === 'review'
                      ? 'Mark reviewed'
                      : note.is_flagged
                        ? 'Remove flag'
                        : 'Flag note'
            }
            cancelText={confirmed || unknown ? 'Close' : 'Cancel'}
            variant="default"
            processing={command.pending}
            frontline
        />
    );
}
