import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Field } from '@/components/wizard/primitives';
import { useState } from 'react';

/** Received-date filter: inclusive New Zealand calendar days, either end optional. */
export function ReceivedDatesDialog({
    from,
    to,
    onApply,
    onClose,
}: {
    from?: string;
    to?: string;
    onApply: (from?: string, to?: string) => void;
    onClose: () => void;
}) {
    const [start, setStart] = useState(from ?? '');
    const [end, setEnd] = useState(to ?? '');
    const invalid = !!start && !!end && end < start;
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>Received dates</DialogTitle>
                    <DialogDescription>
                        Show referrals received on or between these New Zealand
                        calendar days. Leave a date empty to keep that end open.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Received from">
                        <DatePicker
                            compact
                            id="workspace-received-from"
                            label="Received from"
                            value={start}
                            onChange={setStart}
                        />
                    </Field>
                    <Field label="Received through">
                        <DatePicker
                            compact
                            id="workspace-received-to"
                            label="Received through"
                            value={end}
                            onChange={setEnd}
                        />
                    </Field>
                </div>
                {invalid && (
                    <p role="alert" className="text-sm text-destructive">
                        Choose an end date on or after the start date.
                    </p>
                )}
                <DialogFooter>
                    {(from || to) && (
                        <Button
                            variant="ghost"
                            className="sm:mr-auto"
                            onClick={() => onApply(undefined, undefined)}
                        >
                            Clear dates
                        </Button>
                    )}
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        disabled={invalid || (!start && !end)}
                        onClick={() =>
                            onApply(start || undefined, end || undefined)
                        }
                    >
                        Apply dates
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
