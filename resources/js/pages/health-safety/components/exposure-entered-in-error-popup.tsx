/**
 * Entered in error (decided 6 Nov 2026): an exposure manager marks a private exposure record that was a mistake, with a
 * required reason. The record, its reviews, follow-ups, files and history are kept and never deleted; it leaves the
 * active lists and takes no further changes. It uses the exposure command protocol (one retained UUID, the reviewed
 * revision, an exact receipt), so an unconfirmed save can be checked or retried without making a second entry.
 */
import { PopupField, PopupForm } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { CHEMICAL_EXPOSURES } from '@/lib/health-safety/chemical-exposure-navigation';
import type { ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import { FileX2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useExposureCommand } from './use-exposure-command';

export function ExposureEnteredInErrorPopup({
    open,
    detail,
    actorId,
    allowed,
    onClose,
    onDone,
}: {
    open: boolean;
    detail: ExposureWorkspaceDetail;
    actorId: number;
    /** The page's own check: an intact, current record and the server's `can.mark_entered_in_error`. */
    allowed: boolean;
    onClose: () => void;
    onDone: () => void;
}) {
    const [reason, setReason] = useState('');
    const command = useExposureCommand(
        {
            actorId,
            command: 'mark_entered_in_error',
            rootId: detail.id,
            substanceId: null,
            sourceType: 'review',
            sourceId: null,
            expectedVersion: detail.command_context?.expected_version ?? null,
            method: 'post',
            path: `${CHEMICAL_EXPOSURES}/${detail.id}/entered-in-error`,
            allowed,
            canRecover: allowed,
        },
        reason.trim() !== '',
    );
    useEffect(() => {
        if (command.receipt) onDone();
    }, [command.receipt, onDone]);
    const record = detail.record;
    const problem =
        command.errors.entered_in_error ??
        command.errors.expected_version ??
        command.errors.idempotency_key ??
        command.message ??
        undefined;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={FileX2}
            title="Mark as entered in error?"
            description="The record is kept. It leaves the lists and can’t be changed."
            context={
                record
                    ? {
                          title: `${record.worker.name} · ${record.substance.name}`,
                          tag: record.site.name,
                          note: 'Private exposure record',
                      }
                    : undefined
            }
            error={problem}
            submitLabel="Mark as entered in error"
            processingLabel="Saving…"
            processing={command.busy}
            submitDisabled={command.locked || reason.trim() === ''}
            onSubmit={() => command.submit({ reason })}
            secondary={
                command.canCheck && !command.rejected ? (
                    <>
                        <Button type="button" variant="outline" onClick={() => void command.check()}>
                            Check if it saved
                        </Button>
                        <Button type="button" variant="outline" onClick={command.retry}>
                            Send the same again
                        </Button>
                    </>
                ) : undefined
            }
            dirty={reason.trim() !== '' || command.uncertain}
            draftName="this reason"
            testId="exposure-entered-in-error-popup"
        >
            <PopupField
                label="Why was it entered in error?"
                htmlFor="exposure-entered-in-error-reason"
                required
                hint="Needed. The record, its reviews, follow-ups, files and history are kept with your reason. Open follow-ups leave the task lists."
                error={command.errors.reason}
            >
                <Textarea
                    id="exposure-entered-in-error-reason"
                    rows={3}
                    maxLength={2000}
                    value={reason}
                    disabled={command.locked && !command.canEditRejected}
                    onChange={(event) => {
                        if (command.canEditRejected) command.editRejected();
                        setReason(event.target.value);
                    }}
                />
            </PopupField>
        </PopupForm>
    );
}
