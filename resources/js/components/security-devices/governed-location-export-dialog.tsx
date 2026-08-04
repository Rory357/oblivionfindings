import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Download, ShieldCheck } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    exportUrl: string;
    subjectLabel: string;
    dateFrom?: string | null;
    dateTo?: string | null;
    eventTypes?: string[];
    retentionDays?: number | null;
    onAccessEnded?: () => void;
};

function isoDate(date: Date): string {
    return date.toISOString().slice(0, 10);
}

function defaultFromDate(): string {
    const date = new Date();
    date.setDate(date.getDate() - 7);

    return isoDate(date);
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function filenameFromDisposition(disposition: string | null): string | null {
    const encoded = disposition?.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
    if (encoded) return decodeURIComponent(encoded);

    return disposition?.match(/filename="?([^";]+)"?/i)?.[1] ?? null;
}

export function GovernedLocationExportDialog({
    open,
    onOpenChange,
    exportUrl,
    subjectLabel,
    dateFrom,
    dateTo,
    eventTypes = [],
    retentionDays,
    onAccessEnded,
}: Props) {
    const today = useMemo(() => isoDate(new Date()), []);
    const [reason, setReason] = useState('');
    const [from, setFrom] = useState(dateFrom || defaultFromDate());
    const [to, setTo] = useState(dateTo || today);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setFrom(dateFrom || defaultFromDate());
        setTo(dateTo || today);
        setReason('');
        setError(null);
    }, [dateFrom, dateTo, open, today]);

    const exportHistory = async () => {
        if (reason.trim().length < 3) {
            setError('Record a short operational reason for this export.');
            return;
        }

        setProcessing(true);
        setError(null);

        try {
            const response = await fetch(exportUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'text/csv, application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    reason: reason.trim(),
                    date_from: from,
                    date_to: to,
                    event_types: eventTypes,
                }),
            });

            if (response.status === 403) {
                onAccessEnded?.();
                throw new Error(
                    'Location access is no longer active. Nothing was exported.',
                );
            }

            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                const validationMessage = payload?.errors
                    ? Object.values(payload.errors).flat().find(Boolean)
                    : null;
                throw new Error(
                    typeof validationMessage === 'string'
                        ? validationMessage
                        : payload?.message ||
                              'The governed export could not be created.',
                );
            }

            const blob = await response.blob();
            const objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = objectUrl;
            anchor.download =
                filenameFromDisposition(
                    response.headers.get('content-disposition'),
                ) ?? `client-location-${today}.csv`;
            document.body.append(anchor);
            anchor.click();
            anchor.remove();
            URL.revokeObjectURL(objectUrl);
            toast.success(
                'Location export created and recorded in audit history.',
            );
            onOpenChange(false);
        } catch (caught) {
            setError(
                caught instanceof Error
                    ? caught.message
                    : 'The governed export could not be created.',
            );
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <ShieldCheck className="h-5 w-5 text-primary" />
                        Export location history
                    </DialogTitle>
                    <DialogDescription>
                        Export authorised history for {subjectLabel}.
                        Permission, active consent, assignment, date scope and
                        purpose are checked again when you export.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="location-export-from">From</Label>
                            <Input
                                id="location-export-from"
                                type="date"
                                max={today}
                                value={from}
                                onChange={(event) =>
                                    setFrom(event.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="location-export-to">To</Label>
                            <Input
                                id="location-export-to"
                                type="date"
                                min={from}
                                max={today}
                                value={to}
                                onChange={(event) => setTo(event.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor="location-export-reason">
                            Operational reason
                        </Label>
                        <Textarea
                            id="location-export-reason"
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            maxLength={500}
                            placeholder="For example: review a reported safety event"
                            rows={3}
                        />
                        <p className="text-xs text-muted-foreground">
                            This reason is audited. Do not include passwords,
                            clinical detail or other unnecessary personal
                            information.
                            {retentionDays
                                ? ` History is limited to ${retentionDays} days.`
                                : ''}
                        </p>
                    </div>

                    {error ? (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    ) : null}
                </div>

                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={exportHistory}
                        disabled={processing || !from || !to}
                    >
                        <Download className="mr-2 h-4 w-4" />
                        {processing ? 'Checking and exporting…' : 'Export CSV'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
