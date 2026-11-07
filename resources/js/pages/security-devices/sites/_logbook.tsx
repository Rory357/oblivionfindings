/**
 * Site › Logbook (S&D drawing pg-sdr-12i): what happened at the Site in plain sentences, newest
 * first, a day at a time: since the night ended, the night itself, and the day before. One "What"
 * dropdown narrows it; "Go to" picks another day with the approved date picker.
 */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { PopupForm } from '@/components/popup';
import { KnowList, Log, QuietLine, SideBySide, SideColumn, Surface, type SgAction } from '@/components/signature/blocks';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Download } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import type { LogbookWhat, SiteLogbook, StreamLine } from './_types';

export const LOGBOOK_WHAT: { value: LogbookWhat; label: string }[] = [
    { value: 'everything', label: 'Everything' },
    { value: 'alerts', label: 'Alerts' },
    { value: 'alarm', label: 'Alarm' },
    { value: 'modes', label: 'Modes' },
    { value: 'devices', label: 'Devices' },
    { value: 'people', label: 'Who did what' },
];

/** The line's own action, or "Open" for what it's about. */
export function lineAction(line: StreamLine): SgAction | null {
    if (line.action) return { label: line.action.label, href: line.action.href, ariaLabel: `${line.action.label}: ${line.title}` };
    return line.href ? { label: 'Open', href: line.href, ariaLabel: `Open: ${line.title}` } : null;
}

export function LogbookTab({ logbook, base, siteName }: { logbook: SiteLogbook; base: string; siteName: string }) {
    const [exportOpen, setExportOpen] = useState(false);
    const go = (changes: { what?: LogbookWhat; day?: string }) => {
        const what = changes.what ?? logbook.what;
        // Today is the default, so it isn't put in the address; a cleared date goes back to today.
        const day = changes.day !== undefined ? changes.day : logbook.today ? '' : logbook.day;
        router.get(base, { tab: 'logbook', ...(what !== 'everything' ? { what } : {}), ...(day ? { day } : {}) }, { preserveScroll: true, preserveState: true });
    };
    const nothing = logbook.sections.every((section) => section.items.length === 0);

    return (
        <SideBySide cols="minmax(0, 1fr) 360px">
            <div className="flex flex-col gap-5">
                {nothing ? (
                    <QuietLine
                        title="Logbook"
                        text={logbook.what === 'everything' ? `Nothing was recorded at ${siteName} on these days.` : 'Nothing of that kind was recorded on these days. Try Everything.'}
                    />
                ) : (
                    logbook.sections
                        .filter((section) => section.items.length > 0)
                        .map((section, index) => (
                            <Log
                                key={section.key}
                                title={section.title}
                                sub={index === 0 ? `Newest first · everything that happened at ${siteName}, with who did it` : undefined}
                                limit={section.items.length}
                                items={section.items.map((line) => ({
                                    key: line.key,
                                    time: formatTime(line.at),
                                    tone: line.tone,
                                    title: line.title,
                                    sub: line.sub ?? undefined,
                                    action: lineAction(line),
                                }))}
                            />
                        ))
                )}
            </div>
            <SideColumn>
                <Surface title="Show">
                    <div className="grid gap-4 px-[18px] pt-2 pb-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="logbook-what">What</Label>
                            <Select value={logbook.what} onValueChange={(value) => go({ what: value as LogbookWhat })}>
                                <SelectTrigger id="logbook-what" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {LOGBOOK_WHAT.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="logbook-day">Go to</Label>
                            <DatePicker id="logbook-day" label="Go to" value={logbook.today ? '' : logbook.day} allowClear onChange={(day) => go({ day })} />
                        </div>
                    </div>
                </Surface>
                <KnowList
                    title="What the logbook keeps"
                    items={[
                        { tone: 'info', text: 'The alarm, house modes, alerts and how they ended, scans, settings changes and planned quiet times, each with who did it' },
                        {
                            tone: 'info',
                            text: (
                                <>
                                    <b>Sensors linked to someone’s care</b> show here only when their care rule acts, never minute by minute. Someone you can’t look up is “a person”
                                </>
                            ),
                        },
                        {
                            tone: 'info',
                            text: (
                                <>
                                    <b>Camera viewing</b> is in Overview › Reports, with each reason
                                </>
                            ),
                        },
                    ]}
                />
                <QuietLine
                    title="Export the logbook"
                    text="A day or a date range as PDF or CSV. Export asks for a reason, kept with your name."
                    action={{ label: 'Export…', onClick: () => setExportOpen(true) }}
                />
            </SideColumn>
            <ExportLogbookPopup open={exportOpen} logbook={logbook} base={base} siteName={siteName} onClose={() => setExportOpen(false)} />
        </SideBySide>
    );
}

type ExportSpan = 'day' | 'range';
type ExportFormat = 'pdf' | 'csv';

function ExportLogbookPopup({
    open,
    logbook,
    base,
    siteName,
    onClose,
}: {
    open: boolean;
    logbook: SiteLogbook;
    base: string;
    siteName: string;
    onClose: () => void;
}) {
    const [span, setSpan] = useState<ExportSpan>('day');
    const [from, setFrom] = useState(logbook.day);
    const [to, setTo] = useState(logbook.day);
    const [what, setWhat] = useState<LogbookWhat>(logbook.what);
    const [format, setFormat] = useState<ExportFormat>('pdf');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setSpan('day');
        setFrom(logbook.day);
        setTo(logbook.day);
        setWhat(logbook.what);
        setFormat('pdf');
        setReason('');
        setError(null);
    }, [open, logbook.day, logbook.what]);

    const dirty = reason.trim() !== '';

    const submit = async () => {
        if (saving) return;
        const trimmed = reason.trim();
        if (trimmed.length < 3) {
            setError('Give a short reason for exporting the logbook.');
            return;
        }
        if (!from) {
            setError('Pick a date to export.');
            return;
        }
        const endDay = span === 'range' ? to : from;
        if (!endDay || endDay < from) {
            setError('The end date must be on or after the start date.');
            return;
        }

        setSaving(true);
        setError(null);
        try {
            const response = await axios.post(
                `${base}/logbook/export`,
                { format, from, to: endDay, what, reason: trimmed },
                { responseType: 'blob' },
            );
            const disposition = typeof response.headers?.['content-disposition'] === 'string' ? response.headers['content-disposition'] : '';
            const match = disposition.match(/filename="([^"]+)"/i);
            const filename = match?.[1] ?? `security-devices-logbook-${from}${endDay !== from ? `-to-${endDay}` : ''}.${format}`;
            if (typeof URL.createObjectURL === 'function') {
                const url = URL.createObjectURL(response.data);
                const anchor = document.createElement('a');
                anchor.href = url;
                anchor.download = filename;
                anchor.click();
                window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            }
            toast.success('Logbook exported.');
            onClose();
        } catch (failure) {
            const response = axios.isAxiosError(failure) ? failure.response : undefined;
            let message = response?.status === 403 ? 'You can’t export this logbook.' : 'The export couldn’t be prepared. Try again.';
            if (response?.data instanceof Blob) {
                try {
                    const parsed = JSON.parse(await response.data.text()) as { message?: string; errors?: Record<string, string[]> };
                    const first = parsed.errors ? Object.values(parsed.errors)[0]?.[0] : undefined;
                    if (first) message = first;
                    else if (typeof parsed.message === 'string' && parsed.message !== '') message = parsed.message;
                } catch {
                    // Keep safe fallback message.
                }
            }
            setError(message);
        } finally {
            setSaving(false);
        }
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={Download}
            title="Export the logbook"
            description={`${siteName} · kept with your name and reason`}
            error={error ?? undefined}
            submitLabel={format === 'pdf' ? 'Export PDF' : 'Export CSV'}
            processingLabel="Exporting…"
            processing={saving}
            onSubmit={submit}
            dirty={dirty}
            draftName="Export the logbook"
        >
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-1.5">
                        <Label htmlFor="logbook-export-span">Dates</Label>
                        <Select
                            value={span}
                            onValueChange={(value) => {
                                const next = value as ExportSpan;
                                setSpan(next);
                                if (next === 'day') setTo(from);
                            }}
                        >
                            <SelectTrigger id="logbook-export-span" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="day">One day</SelectItem>
                                <SelectItem value="range">Date range</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-1.5">
                        <Label htmlFor="logbook-export-format">Format</Label>
                        <Select value={format} onValueChange={(value) => setFormat(value as ExportFormat)}>
                            <SelectTrigger id="logbook-export-format" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="pdf">PDF</SelectItem>
                                <SelectItem value="csv">CSV</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
                {span === 'day' ? (
                    <div className="grid gap-1.5">
                        <Label htmlFor="logbook-export-day">Day</Label>
                        <DatePicker
                            id="logbook-export-day"
                            label="Day"
                            value={from}
                            onChange={(day) => {
                                setFrom(day);
                                setTo(day);
                            }}
                        />
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="logbook-export-from">From</Label>
                            <DatePicker id="logbook-export-from" label="From" value={from} onChange={setFrom} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="logbook-export-to">To</Label>
                            <DatePicker id="logbook-export-to" label="To" value={to} onChange={setTo} />
                        </div>
                    </div>
                )}
                <div className="grid gap-1.5">
                    <Label htmlFor="logbook-export-what">What</Label>
                    <Select value={what} onValueChange={(value) => setWhat(value as LogbookWhat)}>
                        <SelectTrigger id="logbook-export-what" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {LOGBOOK_WHAT.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="logbook-export-reason">Reason</Label>
                    <Textarea
                        id="logbook-export-reason"
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="Why you need this export (kept with your name)"
                        maxLength={500}
                        rows={3}
                    />
                </div>
            </div>
        </PopupForm>
    );
}
