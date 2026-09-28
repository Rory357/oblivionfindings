import { Alert, AlertDescription } from '@/components/ui/alert';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useEffect, useRef, useState } from 'react';
import { time, type History, type Person, type Workspace } from './model';
import { RecordPicker } from './record-picker';
import { checkResponse, csrfHeaders } from './request';

export function ReportDialog({
    workspace,
    person,
    source,
    initialJourney = '',
    onClose,
}: {
    workspace: Workspace;
    person: Person;
    source: string;
    initialJourney?: string;
    onClose: () => void;
}) {
    const [kind, setKind] = useState(initialJourney ? 'outing' : 'day'),
        [journey, setJourney] = useState(initialJourney),
        [format, setFormat] = useState('pdf'),
        [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false),
        [error, setError] = useState(''),
        [preview, setPreview] = useState<History | null>(null),
        [previewError, setPreviewError] = useState(''),
        [retry, setRetry] = useState(0);
    const pending = useRef<AbortController | null>(null);
    useEffect(
        () => () => {
            pending.current?.abort();
            pending.current = null;
        },
        [],
    );
    useEffect(() => {
        setPreview(null);
        setPreviewError('');
        if (kind === 'outing' && !journey) return;
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), 25000);
        const query = new URLSearchParams({
            person: person.id,
            source,
            date: workspace.filters.date,
        });
        if (kind === 'outing') query.set('journey', journey);
        let active = true;
        void (async () => {
            try {
                const response = await fetch(
                    `/operations/people-locations/report-preview?${query}`,
                    {
                        headers: csrfHeaders(),
                        cache: 'no-store',
                        signal: controller.signal,
                    },
                );
                await checkResponse(response);
                const data = (await response.json()) as History;
                if (active && !controller.signal.aborted) setPreview(data);
            } catch (e) {
                if (active)
                    setPreviewError(
                        controller.signal.aborted
                            ? 'Preview timed out. Retry to check the report window.'
                            : e instanceof Error
                              ? e.message
                              : 'Preview unavailable.',
                    );
            } finally {
                window.clearTimeout(timer);
            }
        })();
        return () => {
            active = false;
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [kind, journey, person.id, source, workspace.filters.date, retry]);
    const cancel = () => {
        pending.current?.abort();
        pending.current = null;
        onClose();
    };
    const generate = async () => {
        if (pending.current) return;
        const controller = new AbortController();
        pending.current = controller;
        const timer = window.setTimeout(() => controller.abort(), 60000);
        setBusy(true);
        setError('');
        try {
            const response = await fetch(
                '/operations/people-locations/export',
                {
                    method: 'POST',
                    cache: 'no-store',
                    signal: controller.signal,
                    headers: csrfHeaders(),
                    body: JSON.stringify({
                        person: person.id,
                        source,
                        date: workspace.filters.date,
                        kind,
                        journey: kind === 'outing' ? journey : null,
                        format,
                        reason: reason.trim(),
                    }),
                },
            );
            await checkResponse(
                response,
                { pdf: 'application/pdf', csv: 'text/csv', html: 'text/html' }[
                    format
                ],
            );
            const blob = await response.blob();
            if (
                controller.signal.aborted ||
                pending.current !== controller ||
                document.hidden
            )
                return;
            const href = URL.createObjectURL(blob),
                link = document.createElement('a');
            link.href = href;
            link.download = `person-${person.id}-${kind}-${workspace.filters.date}.${format}`;
            link.click();
            window.setTimeout(() => URL.revokeObjectURL(href), 1000);
            onClose();
        } catch (e) {
            if (pending.current === controller)
                setError(
                    controller.signal.aborted
                        ? 'Report generation timed out. Your reason is retained; retry or cancel.'
                        : e instanceof Error
                          ? e.message
                          : 'Report could not be generated.',
                );
        } finally {
            window.clearTimeout(timer);
            if (pending.current === controller) {
                pending.current = null;
                setBusy(false);
            }
        }
    };
    return (
        <Dialog open onOpenChange={(open) => !open && cancel()}>
            <DialogContent
                className="flex max-h-[90vh] flex-col gap-0 overflow-hidden p-0"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader className="border-b p-6">
                    <DialogTitle>Export location report</DialogTitle>
                    <DialogDescription>
                        {person.name} · {workspace.filters.date} ·
                        Pacific/Auckland
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 space-y-4 overflow-y-auto p-6">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label>Report</Label>
                            <Select
                                value={kind}
                                onValueChange={setKind}
                                disabled={busy}
                            >
                                <SelectTrigger aria-label="Report">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="day">
                                        Day report
                                    </SelectItem>
                                    <SelectItem
                                        value="outing"
                                        disabled={
                                            !workspace.history?.journeys?.length
                                        }
                                    >
                                        Passenger outing · selected day
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Format</Label>
                            <Select
                                value={format}
                                onValueChange={setFormat}
                                disabled={busy}
                            >
                                <SelectTrigger aria-label="Format">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="pdf">
                                        PDF · vehicle report style
                                    </SelectItem>
                                    <SelectItem value="csv">
                                        CSV · positions & device evidence
                                    </SelectItem>
                                    <SelectItem value="html">
                                        HTML · accessible report
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    {kind === 'outing' && (
                        <div className="space-y-2">
                            <Label>Passenger journey</Label>
                            <RecordPicker
                                disabled={busy}
                                label="Passenger journey"
                                value={journey}
                                onChange={setJourney}
                                options={(
                                    workspace.history?.journeys ?? []
                                ).map((j) => ({
                                    value: String(j.id),
                                    label: `${j.reference} · ${j.destination || j.purpose || 'Passenger journey'}`,
                                    description: `${time(j.departedAt)} · ${j.status}`,
                                }))}
                            />
                        </div>
                    )}
                    <section
                        className="space-y-2 rounded-lg border bg-muted/30 p-4"
                        aria-label="Report preview"
                        aria-live="polite"
                    >
                        <h3 className="font-semibold">Report preview</h3>
                        {previewError ? (
                            <>
                                <p role="alert" className="text-sm">
                                    {previewError}
                                </p>
                                <Button
                                    variant="outline"
                                    onClick={() => setRetry((v) => v + 1)}
                                >
                                    Retry preview
                                </Button>
                            </>
                        ) : preview ? (
                            <>
                                <p className="text-sm">
                                    {preview.scope} · {preview.source?.label}
                                </p>
                                <p className="text-sm">
                                    {time(preview.window?.from)} –{' '}
                                    {time(preview.window?.to)}
                                </p>
                                <p className="text-sm">
                                    {preview.positions?.length ?? 0} positions ·{' '}
                                    {preview.samples?.length ?? 0} device
                                    samples
                                </p>
                                {preview.truncated && (
                                    <Alert>
                                        <AlertDescription>
                                            Partial report: the latest 500
                                            records per stream are included.
                                            Earlier observations may be omitted.
                                            The PDF, HTML and CSV will carry
                                            this notice.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {preview.journey && (
                                    <p className="text-xs text-muted-foreground">
                                        Only this Auckland day's authorised
                                        observations inside the passenger window
                                        are included. An overnight journey may
                                        require another day's report.
                                    </p>
                                )}
                            </>
                        ) : (
                            <p className="text-sm">
                                {kind === 'outing' && !journey
                                    ? 'Choose a passenger journey to preview its window.'
                                    : 'Checking current authority and report window…'}
                            </p>
                        )}
                    </section>
                    <div className="space-y-2">
                        <Label htmlFor="export-reason">Reason for export</Label>
                        <Textarea
                            id="export-reason"
                            value={reason}
                            disabled={busy}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={500}
                            placeholder="Describe the authorised use of this report"
                        />
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Access is checked again before download. Missing
                        intervals stay unknown. Report generation is recorded in
                        the audit history.
                    </p>
                    {error && (
                        <Alert variant="destructive">
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}
                </div>
                <DialogFooter className="border-t p-4">
                    <Button variant="outline" onClick={cancel}>
                        {busy ? 'Cancel generation' : 'Cancel'}
                    </Button>
                    <Button
                        disabled={
                            busy ||
                            !preview ||
                            preview.needsSource ||
                            reason.trim().length < 5 ||
                            (kind === 'outing' && !journey)
                        }
                        onClick={() => void generate()}
                    >
                        {busy ? 'Generating…' : 'Generate report'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
