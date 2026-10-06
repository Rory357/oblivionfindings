import { RecordPicker } from '@/components/people-locations/record-picker';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { reportRequest } from '@/pages/emar/reports/_types';
import { useState } from 'react';

export function HistoricalAuditExportDialog({
    url,
    purposes,
    onClose,
}: {
    url: string;
    purposes: Record<string, string>;
    onClose: () => void;
}) {
    const [purpose, setPurpose] = useState(''),
        [detail, setDetail] = useState(''),
        [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [done, setDone] = useState(false);
    async function download() {
        setBusy(true);
        setError('');
        try {
            const target = new URL(url, window.location.origin);
            target.searchParams.set('purpose', purpose);
            if (purpose === 'other')
                target.searchParams.set('purpose_detail', detail.trim());
            const response = await reportRequest(
                target.pathname + target.search,
            );
            const blob = await response.blob(),
                objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download =
                response.headers
                    .get('Content-Disposition')
                    ?.match(/filename="([^"]+)"/)?.[1] ??
                'medication-history.csv';
            document.body.append(anchor);
            anchor.click();
            anchor.remove();
            setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000);
            setDone(true);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'The file could not be made. Your purpose has been kept.',
            );
        } finally {
            setBusy(false);
        }
    }
    return (
        <Dialog open onOpenChange={(open) => !open && !busy && onClose()}>
            <DialogContent
                className="frontline-dialog flex max-h-[88vh] flex-col overflow-hidden p-0"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>Export historical evidence</DialogTitle>
                    <DialogDescription>
                        The selected record or change-log scope will be checked
                        again before download. Your purpose is recorded in the
                        audit trail.
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 overflow-y-auto px-5">
                    {done ? (
                        <p
                            role="status"
                            aria-live="polite"
                            className="text-subtle"
                        >
                            File ready. The download has started, and your
                            export purpose has been recorded.
                        </p>
                    ) : (
                        <div className="space-y-3">
                            <RecordPicker
                                label="Purpose"
                                value={purpose}
                                options={Object.entries(purposes).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                                onChange={setPurpose}
                            />
                            {purpose === 'other' && (
                                <div className="space-y-2">
                                    <Label htmlFor="history-export-purpose">
                                        Describe the purpose
                                    </Label>
                                    <Textarea
                                        id="history-export-purpose"
                                        value={detail}
                                        onChange={(event) =>
                                            setDetail(event.target.value)
                                        }
                                        maxLength={500}
                                    />
                                </div>
                            )}
                            {error && (
                                <p
                                    role="alert"
                                    className="text-status-critical"
                                >
                                    {error}
                                </p>
                            )}
                        </div>
                    )}
                </div>
                <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                    {done ? (
                        <Button
                            autoFocus
                            className="min-h-11"
                            onClick={onClose}
                        >
                            Done
                        </Button>
                    ) : (
                        <>
                            <Button
                                className="min-h-11"
                                variant="outline"
                                onClick={onClose}
                                disabled={busy}
                            >
                                Cancel
                            </Button>
                            <Button
                                className="min-h-11"
                                onClick={download}
                                disabled={
                                    busy ||
                                    !purpose ||
                                    (purpose === 'other' &&
                                        detail.trim().length < 3)
                                }
                            >
                                {busy ? 'Making file…' : 'Download CSV'}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
