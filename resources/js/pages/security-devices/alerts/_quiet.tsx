/**
 * Alerts › Quiet times (S&D drawing pg-sdr-13c): every quiet time on the person's Sites, coming up first,
 * then the ones that ended in the last 30 days, how quiet time works and what it held back. Ending one
 * early is the same safe save Settings had (a cancel with a reason, kept until it's confirmed).
 */
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PopupField, PopupForm } from '@/components/popup';
import { useMaintenanceCommand } from '@/components/security-devices/use-maintenance-command';
import {
    KnowList,
    Log,
    Note,
    QuietLine,
    SideBySide,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { timedText } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import {
    BellOff,
    Building2,
    House,
    Network,
    ShieldCheck,
    SquareArrowOutUpRight,
} from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { toast } from 'sonner';
import { BlockHead } from '../devices/_parts';
import type { NoteLine, QuietTimeRow, QuietTimes } from './_types';

const ICONS = { site: House, building: Building2, network: Network } as const;

const nzDay = (date: Date) =>
    date.toLocaleDateString('en-NZ', { timeZone: WORKER_TIMEZONE });

const note = (line: NoteLine, now: Date) =>
    typeof line === 'string' ? line : timedText(line, now);

const bold = (text: string): ReactNode =>
    text
        .split(/\*\*(.+?)\*\*/g)
        .map((part, index) =>
            index % 2 === 1 ? <b key={index}>{part}</b> : part,
        );

/** "10:00 – 11:00 am", or "11:30 am – 12:30 pm" when the halves differ. */
function timeRange(start: Date, end: Date): string {
    const from = formatTime(start);
    const to = formatTime(end);
    const half = (text: string) => text.slice(-2);
    return half(from) === half(to)
        ? `${from.slice(0, -3)} – ${to}`
        : `${from} – ${to}`;
}

/** "Today, 10:00 – 11:00 am", "Sat 31 Oct, 6:00 – 7:00 am", "Every day: Today, 2:00 – 3:00 am · until Mon 30 Nov". */
export function quietWhen(when: QuietTimeRow['when'], now: Date): string {
    const start = new Date(when.starts_at);
    const end = new Date(when.ends_at);
    const day = (date: Date) =>
        nzDay(date) === nzDay(now) ? 'Today' : formatDate(date);
    const text =
        nzDay(start) === nzDay(end)
            ? `${day(start)}, ${timeRange(start, end)}`
            : `${day(start)}, ${formatTime(start)} – ${day(end)}, ${formatTime(end)}`;
    if (!when.repeats) return text;
    return `${when.repeats === 'daily' ? 'Every day' : 'Every week'}: ${text}${when.until ? ` · until ${formatDate(when.until)}` : ''}`;
}

export function QuietTimesTab({
    data,
    now,
    search,
    actorId,
    onAdd,
}: {
    data: QuietTimes;
    now: Date;
    /** The header search narrows the list. */
    search: string;
    actorId: number | undefined;
    onAdd: () => void;
}) {
    const menu = useEntityContextMenu<QuietTimeRow>();
    const [ending, setEnding] = useState<QuietTimeRow | null>(null);
    const words = search.trim().toLowerCase();
    const rows = useMemo(
        () =>
            words === ''
                ? data.rows
                : data.rows.filter((row) =>
                      `${row.paused.name} ${row.paused.sub} ${row.why} ${row.set_by ?? ''}`
                          .toLowerCase()
                          .includes(words),
                  ),
        [data.rows, words],
    );

    const open = (row: QuietTimeRow) => {
        if (row.paused.href) router.visit(row.paused.href);
    };
    const actionsFor = (row: QuietTimeRow): MenuItem[] => [
        ...(row.paused.href
            ? [
                  {
                      label: 'Open the device',
                      icon: SquareArrowOutUpRight,
                      onClick: () => open(row),
                  },
              ]
            : []),
        ...(row.can_end
            ? [
                  ...(row.paused.href ? [{ separator: true } as MenuItem] : []),
                  {
                      label: row.ends_now ? 'End it now' : 'Cancel it',
                      icon: BellOff,
                      danger: true,
                      onClick: () => setEnding(row),
                  },
              ]
            : []),
    ];

    return (
        <div className="flex flex-col gap-5">
            <Note icon={ShieldCheck}>
                <b>Safety alerts never pause.</b> Quiet time only pauses
                network gear, computers and building systems. Panic buttons,
                alarms, cameras, doors, care trackers and care rules always get
                through.
            </Note>
            <section
                className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm"
                aria-label="Quiet times"
            >
                <div className="border-b border-border px-4 py-3.5">
                    <BlockHead
                        title="Quiet times"
                        sub="Planned work on your Sites, on now and coming up first, then the last 30 days · ⋮ or right-click for its menu"
                        right={
                            data.can.add ? (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={onAdd}
                                >
                                    Add quiet time
                                </Button>
                            ) : null
                        }
                    />
                </div>
                {rows.length === 0 ? (
                    <p className="text-subtle px-4 py-6">
                        {words !== ''
                            ? 'No quiet times match. Try another search.'
                            : 'No quiet time planned, and none in the last 30 days.'}
                    </p>
                ) : (
                    <EntityTable<QuietTimeRow>
                        rows={rows}
                        rowKey={(row) => row.id}
                        identityLabel="What’s paused"
                        identity={(row) => ({
                            icon: ICONS[row.paused.icon],
                            name: row.paused.name,
                            subline: row.paused.sub || undefined,
                            linkLabel: row.paused.href
                                ? `Open ${row.paused.name}`
                                : undefined,
                        })}
                        columns={[
                            {
                                key: 'when',
                                label: 'When',
                                width: '1.4fr',
                                cell: (row) => (
                                    <span className="whitespace-nowrap">
                                        {quietWhen(row.when, now)}
                                    </span>
                                ),
                            },
                            {
                                key: 'why',
                                label: 'Why',
                                width: '1.6fr',
                                cell: (row) => row.why,
                            },
                            {
                                key: 'by',
                                label: 'Set by',
                                width: '0.9fr',
                                cell: (row) =>
                                    row.set_by ?? (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    ),
                            },
                            {
                                key: 'state',
                                label: 'State',
                                width: '1.2fr',
                                cell: (row) => (
                                    <span className="flex flex-col items-start gap-1">
                                        <StatusBadge variant={row.state.tone}>
                                            {row.state.label}
                                        </StatusBadge>
                                        {note(row.state.note, now) ? (
                                            <span className="text-caption">
                                                {note(row.state.note, now)}
                                            </span>
                                        ) : null}
                                    </span>
                                ),
                            },
                        ]}
                        actionsFor={actionsFor}
                        onOpen={open}
                        onRowContextMenu={(event, row) => menu.open(event, row)}
                        minWidth={1000}
                    />
                )}
            </section>
            <SideBySide cols="minmax(0, 1.3fr) minmax(0, 1fr)">
                <KnowList
                    title="How quiet time works"
                    items={data.know.map((item) => ({
                        tone: item.tone,
                        text: bold(item.text),
                    }))}
                />
                {data.held.length === 0 ? (
                    <QuietLine
                        title="What quiet time held back"
                        text="Nothing held back in the last 30 days: no paused device missed a check."
                    />
                ) : (
                    <Log
                        title="What quiet time held back"
                        sub="From the checks themselves"
                        items={data.held.map((item, index) => ({
                            key: index,
                            time: formatDate(item.at),
                            tone: item.tone,
                            title: item.title,
                            sub: note(item.sub, now),
                        }))}
                    />
                )}
            </SideBySide>
            {menu.ctx ? (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    icon={BellOff}
                    title={menu.ctx.record.paused.name}
                    items={actionsFor(menu.ctx.record)}
                    onClose={menu.close}
                />
            ) : null}
            <EndQuietTime
                row={ending}
                actorId={actorId}
                onClose={() => setEnding(null)}
            />
        </div>
    );
}

/** End a quiet time now, or cancel one that hasn't started: alerts for those devices go out again. */
function EndQuietTime({
    row,
    actorId,
    onClose,
}: {
    row: QuietTimeRow | null;
    actorId: number | undefined;
    onClose: () => void;
}) {
    const command = useMaintenanceCommand({
        actorId,
        active: row !== null,
        context: 'security-devices.alerts.quiet-times.end',
    });
    const [why, setWhy] = useState('');
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        if (row) {
            setWhy('');
            setError(null);
        }
    }, [row]);
    if (!row) return null;

    const finished = (message: string) => {
        toast.success(message);
        onClose();
        router.reload({ only: ['quiet_times', 'counts', 'meters'] });
    };
    const submit = async () => {
        if (command.blocked || command.busy) return;
        if (why.trim().length < 10) {
            setError('Say why in at least 10 characters, so others know.');
            return;
        }
        setError(null);
        const reply = await command.submit(
            'cancel',
            row.id,
            { version: row.version, reason: why.trim() },
            row.scope,
        );
        if (reply)
            finished(
                row.ends_now
                    ? 'Quiet time ended. Alerts for those devices go out again.'
                    : 'Quiet time cancelled.',
            );
    };
    const serverError =
        command.errors.reason ?? command.errors.version ?? command.errors.form;

    return (
        <PopupForm
            open
            onClose={onClose}
            size="sm"
            icon={BellOff}
            title={row.ends_now ? 'End quiet time now' : 'Cancel quiet time'}
            description={
                row.ends_now
                    ? 'Alerts for these devices go out again from their next check'
                    : 'It won’t start, and nothing is paused'
            }
            context={{
                title: row.paused.name,
                tag: row.paused.sub || undefined,
                note: row.why,
            }}
            error={error ?? serverError ?? undefined}
            callout={
                command.blocked && command.message
                    ? { tone: 'warning', content: command.message }
                    : undefined
            }
            dirty={why.trim() !== ''}
            draftName={`End quiet time for ${row.paused.name}`}
            processing={command.busy}
            processingLabel={row.ends_now ? 'Ending…' : 'Cancelling…'}
            submitDisabled={command.blocked}
            destructive
            submitLabel={row.ends_now ? 'End it now' : 'Cancel it'}
            onSubmit={() => void submit()}
            footerStart={
                command.blocked && command.pending ? (
                    <Button
                        type="button"
                        variant="outline"
                        disabled={command.busy}
                        onClick={() =>
                            void command.recover().then((reply) => {
                                if (reply) finished('That change was saved.');
                            })
                        }
                    >
                        Check saved request
                    </Button>
                ) : null
            }
            testId="sd-end-quiet-time"
        >
            <PopupField label="Why" required error={error ?? undefined}>
                <Textarea
                    value={why}
                    onChange={(event) => setWhy(event.target.value)}
                    rows={3}
                    maxLength={500}
                    placeholder="For example: the switch update finished early"
                />
            </PopupField>
        </PopupForm>
    );
}
