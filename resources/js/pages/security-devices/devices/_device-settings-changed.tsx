/**
 * Pop-up 24, "Settings changed" (S&D Phase G): a settings backup beside the one before it, with the
 * changed lines picked out. Passwords and keys were removed when the copy was stored, and stay that
 * way here. "Changed by" says only what a record shows; otherwise it says it isn't known.
 * Replaces the old 900 px "Compare configuration versions" on the device page.
 */
import { PopupFacts, PopupViewer } from '@/components/popup';
import { Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import axios from 'axios';
import { Download, FileDiff, HardDriveDownload, LoaderCircle } from 'lucide-react';
import { Fragment, useCallback, useEffect, useState } from 'react';

type Side = { n: number; text: string } | null;

export type SettingsChangeRow = {
    kind: 'same' | 'changed' | 'added' | 'removed' | 'gap';
    left: Side;
    right: Side;
    hidden: number;
};

export type SettingsChange = {
    device: { id: number; name: string; model: string | null };
    before: { id: number; at: string | null; how: string } | null;
    after: { id: number; at: string | null; how: string };
    /** null: the first copy, or too many changes to count. */
    lines: number | null;
    added: number | null;
    removed: number | null;
    too_many: boolean;
    rows: SettingsChangeRow[];
    truncated: boolean;
    changed_by: {
        known: boolean;
        changes: { who: string; what: string; at: string | null }[];
        /** null: the viewer can't see who used the terminal. */
        terminal: { who: string; at: string | null }[] | null;
    } | null;
    kept: string | null;
    schedule: string | null;
    download_href: string;
};

const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

export function settingsChangeLine(change: SettingsChange): string {
    const from = change.before?.at ? formatDate(change.before.at) : null;
    const to = change.after.at ? formatDate(change.after.at) : 'the latest copy';
    if (!change.before) return 'The first copy kept, so there’s nothing to compare it with yet';
    if (change.too_many) return `Too many lines changed to show side by side between ${from} and ${to}`;
    if (change.lines === 0) return `No lines changed between ${from} and ${to}`;
    return `${plural(change.lines ?? 0, 'line', 'lines')} changed between ${from} and ${to}`;
}

export function changedByText(change: SettingsChange, now: Date = new Date()): string {
    const by = change.changed_by;
    if (!by) return '—';
    if (by.changes.length > 0) {
        return by.changes.map((item) => `${item.who}: ${item.what}${item.at ? `, ${seenTime(item.at, now)}` : ''}`).join('; ');
    }
    if (by.terminal === null) return 'Not known';
    if (by.terminal.length === 0) return 'Not known · nobody used the Oblivion terminal on it between the two backups';
    const names = Array.from(new Set(by.terminal.map((item) => item.who)));
    const first = by.terminal[0]?.at;
    return `Not known · ${names.join(' and ')} used the Oblivion terminal on it${first ? ` (${seenTime(first, now)})` : ''}`;
}

function Cell({ side, tone }: { side: Side; tone: 'old' | 'new' | 'same' }) {
    return (
        <div
            className={cn(
                'grid min-w-0 grid-cols-[3rem_minmax(0,1fr)] gap-2 px-2.5 py-1',
                side && tone === 'old' && 'bg-status-critical-bg text-status-critical-foreground',
                side && tone === 'new' && 'bg-status-success-bg text-status-success-foreground',
            )}
        >
            <span className="text-right text-muted-foreground tabular-nums select-none">{side ? side.n : ''}</span>
            <span className="font-mono text-xs leading-5 break-all whitespace-pre-wrap">
                {side ? (
                    <>
                        {tone === 'old' ? <span className="sr-only">Before: </span> : null}
                        {tone === 'new' ? <span className="sr-only">After: </span> : null}
                        {side.text === '' ? ' ' : side.text}
                    </>
                ) : null}
            </span>
        </div>
    );
}

export function SettingsSideBySide({ change }: { change: SettingsChange }) {
    if (!change.before) return null;
    return (
        <div
            role="table"
            aria-label={`Settings before and after, ${settingsChangeLine(change)}`}
            className="max-h-[46vh] overflow-auto rounded-[12px] border border-border text-sm scrollbar-pretty"
        >
            <div role="row" className="sticky top-0 grid grid-cols-2 border-b border-border bg-muted text-xs font-semibold text-muted-foreground">
                <div role="columnheader" className="px-2.5 py-2">
                    Before{change.before.at ? ` · ${formatDate(change.before.at)}` : ''}
                </div>
                <div role="columnheader" className="border-l border-border px-2.5 py-2">
                    After{change.after.at ? ` · ${formatDate(change.after.at)}` : ''}
                </div>
            </div>
            {change.rows.map((row, index) =>
                row.kind === 'gap' ? (
                    <div role="row" key={`gap-${index}`} className="border-y border-dashed border-border bg-muted/40 px-2.5 py-1 text-center text-xs text-muted-foreground">
                        <span role="cell">{plural(row.hidden, 'line', 'lines')} the same</span>
                    </div>
                ) : (
                    <Fragment key={`row-${index}`}>
                        <div role="row" data-kind={row.kind} className="grid grid-cols-2">
                            <div role="cell" className="min-w-0">
                                <Cell side={row.left} tone={row.kind === 'same' ? 'same' : 'old'} />
                            </div>
                            <div role="cell" className="min-w-0 border-l border-border">
                                <Cell side={row.right} tone={row.kind === 'same' ? 'same' : 'new'} />
                            </div>
                        </div>
                    </Fragment>
                ),
            )}
            {change.truncated ? (
                <p className="px-2.5 py-2 text-center text-xs text-muted-foreground">More changes than fit here. Download both to see every line.</p>
            ) : null}
        </div>
    );
}

export function SettingsChangedPopup({
    open,
    deviceId,
    deviceName,
    snapshotId,
    onBackup,
    onClose,
    now = new Date(),
}: {
    open: boolean;
    deviceId: number;
    deviceName: string;
    snapshotId: number | null;
    /** "Back up now": opens the device's backup confirm. Null when a backup can't be asked for. */
    onBackup: (() => void) | null;
    onClose: () => void;
    now?: Date;
}) {
    const [change, setChange] = useState<SettingsChange | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    const retry = useCallback(() => setAttempt((value) => value + 1), []);

    useEffect(() => {
        if (!open || snapshotId === null) {
            setChange(null);
            setFailure(null);
            return;
        }
        let cancelled = false;
        setChange(null);
        setFailure(null);
        axios
            .get<SettingsChange>(`/security-devices/devices/${deviceId}/settings-backups/${snapshotId}/changes`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!cancelled) setChange(response.data);
            })
            .catch((error) => {
                if (cancelled) return;
                const status = axios.isAxiosError(error) ? error.response?.status : undefined;
                const message = axios.isAxiosError(error) ? (error.response?.data?.message as string | undefined) : undefined;
                setFailure(
                    status === 403
                        ? 'You can’t open stored copies of its settings.'
                        : status === 404
                          ? 'This copy isn’t here any more.'
                          : status === 409 && message
                            ? message
                            : 'The two copies couldn’t be loaded.',
                );
            });
        return () => {
            cancelled = true;
        };
    }, [open, deviceId, snapshotId, attempt]);

    const title = `Settings changed · ${change?.device.name ?? deviceName}`;
    const canRetry = failure !== null && !failure.startsWith('You can’t') && !failure.startsWith('This copy');

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="lg"
            icon={FileDiff}
            title={title}
            description={change ? settingsChangeLine(change) : failure ? undefined : 'Loading the two copies'}
            context={
                change
                    ? {
                          title: change.device.name,
                          tag: change.device.model ?? undefined,
                          note: change.schedule ? `Backed up ${change.schedule}` : 'Backed up when someone asks for it',
                          icon: HardDriveDownload,
                      }
                    : undefined
            }
            action={
                change ? (
                    <>
                        <Button asChild variant="outline">
                            <a href={change.download_href} download>
                                <Download className="size-4" aria-hidden="true" />
                                {change.before ? 'Download both' : 'Download'}
                            </a>
                        </Button>
                        {onBackup ? (
                            <Button type="button" onClick={onBackup}>
                                Back up now
                            </Button>
                        ) : null}
                    </>
                ) : null
            }
            testId="sd-settings-changed"
        >
            {failure ? (
                <Note tone="warning">
                    <span>{failure}</span>
                    {canRetry ? (
                        <>
                            {' '}
                            <Button type="button" variant="link" className="h-auto p-0" onClick={retry}>
                                Retry
                            </Button>
                        </>
                    ) : null}
                </Note>
            ) : !change ? (
                <p className="flex items-center gap-2 text-subtle" role="status">
                    <LoaderCircle className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                    Loading the two copies…
                </p>
            ) : change.too_many ? (
                <Note>Over 2,000 lines were added or removed, so they aren’t shown side by side. Download both to compare them.</Note>
            ) : change.before ? (
                <SettingsSideBySide change={change} />
            ) : (
                <Note>This is the first copy kept. The next backup will be compared with it.</Note>
            )}
            {change ? (
                // As drawn: the two days first, then who changed it and when it was backed up.
                <PopupFacts
                    rows={[
                        ...(change.before ? ([['Changed by', changedByText(change, now)]] as const) : []),
                        ['Backed up', `${change.after.at ? formatDateTime(change.after.at) : '—'}${change.kept ? ` · ${change.kept}` : ''}`],
                    ]}
                />
            ) : null}
        </PopupViewer>
    );
}
