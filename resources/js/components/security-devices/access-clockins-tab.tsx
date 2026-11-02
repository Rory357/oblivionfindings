/**
 * Access › Clock-ins (S&D Phase H, decision D15; drawing pg-sdr-19e) and its side panel (pop-up
 * 89), for people who sort out clock-in gaps (attendance.doorClockIns.review). What each badge
 * suggested and what the person chose today, gaps and mismatches first, and how this week went.
 *
 * Built as its own component: the S&D session wires it into the Access page as the Clock-ins
 * tab, passing the presenter's `clockins` payload. A gap notice links to
 * `/security-devices/access?tab=clockins&clockin=ID`, which opens that row's panel.
 */
import { EntityContextMenu, EntityTable, useEntityContextMenu, type MenuItem } from '@/components/lists';
import { PopupCallout, PopupFooter, PopupSection } from '@/components/popup';
import { ChoiceTiles, type ChoiceTile } from '@/components/security-devices/device-popups';
import { KnowList, QuietLine, Ring, SideBySide, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { ErrorState } from '@/components/ui/error-state';
import { Label } from '@/components/ui/label';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { SkeletonCard } from '@/components/ui/skeleton-card';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatTime } from '@/lib/datetime';
import {
    badgeWords,
    firstName,
    formatDay,
    outcomeWords,
    panelSub,
    shiftWords,
    sitesWords,
    type AccessClockinsData,
    type ClockinPanelData,
    type ClockinRow,
} from '@/lib/security-devices/door-clockins';
import { BlockHead, ListPager } from '@/pages/security-devices/devices/_parts';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { CalendarPlus, CircleCheck, Clock, History, MessageCircle, Tablet, UserRound, X } from 'lucide-react';
import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

const PER_PAGE = 25;

const JSON_HEADERS = { headers: { Accept: 'application/json' } };

/** Fixed segment colours: tokens only, so the donut follows the theme. */
const SEGMENTS: { key: 'badge' | 'other_way' | 'by_manager' | 'none'; label: string; color: string }[] = [
    { key: 'badge', label: 'Confirmed from a badge', color: 'var(--status-success)' },
    { key: 'other_way', label: 'Clocked in another way', color: 'var(--chart-5)' },
    { key: 'by_manager', label: 'Missed, fixed by a manager', color: 'var(--status-warning)' },
    { key: 'none', label: 'Not clocked in', color: 'var(--status-critical)' },
];

function matches(row: ClockinRow, query: string): boolean {
    const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
    if (words.length === 0) return true;
    const haystack = [row.person.name, row.site.name, row.badge?.door ?? '', outcomeWords(row).badge].join(' ').toLowerCase();
    return words.every((word) => haystack.includes(word));
}

/** How the week went: confirmed from a badge, clocked in another way, missed. */
function WeekDonut({ week }: { week: NonNullable<AccessClockinsData['week']> }) {
    const total = week.starts;
    const share = total > 0 ? Math.round((week.badge / total) * 100) : null;
    let from = 0;
    const stops = SEGMENTS.filter((segment) => week[segment.key] > 0).map((segment) => {
        const to = from + (week[segment.key] / Math.max(1, total)) * 100;
        const stop = `${segment.color} ${from}% ${to}%`;
        from = to;
        return stop;
    });
    const shown = SEGMENTS.filter((segment) => segment.key !== 'none' || week.none > 0);
    const tenths = share === null ? null : Math.round(share / 10);

    return (
        <Surface title="This week" sub={`${formatDay(week.from)} – ${formatDay(week.to)} · ${total === 1 ? '1 rostered start' : `${total} rostered starts`}`}>
            {total === 0 ? (
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No rostered starts at these Sites yet this week.</span>
                </p>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-5 px-[18px] py-4">
                        <div
                            role="img"
                            aria-label={`${share}% of rostered starts clocked in from a badge`}
                            className="relative grid size-[120px] shrink-0 place-items-center rounded-full"
                            style={{ background: `conic-gradient(${stops.join(', ')})` }}
                        >
                            <span className="absolute inset-[18px] rounded-full bg-card" />
                            <span className="relative grid justify-items-center">
                                <b className="text-xl font-bold tracking-tight tabular-nums">{share}%</b>
                                <small className="text-caption">from a badge</small>
                            </span>
                        </div>
                        <dl className="grid min-w-0 flex-1 gap-2 text-sm">
                            {shown.map((segment) => (
                                <div key={segment.key} className="flex items-center gap-2">
                                    <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: segment.color }} />
                                    <dt>{segment.label}</dt>
                                    <dd className="ml-auto font-semibold tabular-nums">{week[segment.key]}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                    <p className="text-subtle border-t border-border px-[18px] py-3">
                        <b className="font-semibold text-foreground">{tenths === null ? '' : `${tenths} in 10 from a badge.`}</b>{' '}
                        {week.other_way > week.by_manager ? 'Most of the rest clocked in another way.' : week.by_manager > 0 ? 'Managers fixed the rest.' : ''}
                    </p>
                </>
            )}
        </Surface>
    );
}

/** The tab (19e). */
export function AccessClockinsTab({ data, query = '', onChanged }: { data: AccessClockinsData; query?: string; onChanged?: () => void }) {
    const [page, setPage] = useState(1);
    const [panel, setPanel] = useState<{ id: number; ask?: boolean } | null>(null);
    const menu = useEntityContextMenu<ClockinRow>();
    const rows = useMemo(() => data.rows.filter((row) => matches(row, query)), [data.rows, query]);
    const pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
    const shown = rows.slice((Math.min(page, pages) - 1) * PER_PAGE, Math.min(page, pages) * PER_PAGE);

    useEffect(() => setPage(1), [query]);

    // A gap notice links here with ?clockin=ID: open that row's panel.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const id = Number(params.get('clockin'));
        if (Number.isInteger(id) && id > 0) setPanel({ id });
    }, []);

    const changed = onChanged ?? (() => router.reload());
    const actions = (row: ClockinRow): MenuItem[] => {
        const first = firstName(row.person.name);
        return [
            { label: 'Open', icon: Clock, onClick: () => setPanel({ id: row.id }) },
            ...(row.waiting_for_you && row.person ? [{ label: `Ask ${first}`, icon: MessageCircle, onClick: () => setPanel({ id: row.id, ask: true }) }] : []),
        ];
    };

    if (data.state === 'off') {
        return (
            <QuietLine
                title="Clock-ins from doors"
                text="Clock-ins from doors are off. Nothing is suggested from door badges."
                action={data.can.setup ? { label: 'Turn on in Setup', href: '/security-devices/setup/access?tab=clockins' } : null}
            />
        );
    }

    const how = data.how;
    const knowSub =
        data.state === 'starting'
            ? `Starts ${formatDay(how.starts_on)} at ${sitesWords(how.places)}`
            : `On at ${sitesWords(how.places)}${how.since ? ` since ${formatDay(how.since)}` : ''}`;

    return (
        <div className="flex flex-col gap-5">
            <SideBySide cols="minmax(0, 1.6fr) minmax(0, 1fr)">
                <KnowList
                    title="How clock-ins from doors work"
                    sub={knowSub}
                    items={[
                        {
                            tone: 'success',
                            text: (
                                <>
                                    A badge at an entry door from{' '}
                                    <b className="font-semibold">
                                        {how.before} min before to {how.after} min after
                                    </b>{' '}
                                    a rostered start <b className="font-semibold">suggests</b> a clock-in
                                </>
                            ),
                        },
                        {
                            tone: 'success',
                            text: how.badge_time
                                ? 'The person confirms it in one tap on My Day, or clocks in now instead. Nothing is clocked in until they choose (D15)'
                                : 'The badge reminds the person to clock in now on My Day. Nothing is clocked in until they choose (D15)',
                        },
                        { tone: 'warning', text: 'Gaps and mismatches come to you here. You approve timesheets as usual' },
                        { tone: 'none', text: 'No clock-out from doors: people leave by the exit button, which has no badge' },
                        { tone: 'none', text: 'Faces and fingerprints are never used (D18), and residents’ fobs never count' },
                        { tone: 'info', text: 'Names on door events are kept 90 days. A confirmed clock-in is a time record, kept 6 years with the timesheet' },
                    ]}
                />
                {data.week ? <WeekDonut week={data.week} /> : <QuietLine title="This week" text="Not available" />}
            </SideBySide>

            <section className="flex flex-col gap-3">
                <BlockHead title="Today" sub="Waiting for you first · rostered starts so far, and badges from people not on the roster" />
                {rows.length === 0 ? (
                    <QuietLine
                        text={
                            query
                                ? 'Nobody matches. Try another search.'
                                : data.state === 'starting'
                                  ? `Nothing yet: it starts on ${formatDay(how.starts_on)}.`
                                  : 'No rostered starts yet today at these Sites.'
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                        <EntityTable<ClockinRow>
                            className="rounded-none border-0 shadow-none"
                            rows={shown}
                            rowKey={(row) => row.id}
                            identityLabel="Person"
                            identity={(row) => ({ icon: UserRound, name: row.person.name, subline: row.site.name, linkLabel: `Actions for ${row.person.name}` })}
                            columns={[
                                { key: 'badge', label: 'Badge', width: '1.2fr', cell: (row) => badgeWords(row.badge) },
                                { key: 'rostered', label: 'Rostered', width: '1fr', cell: (row) => <span className="whitespace-nowrap">{row.shift ? shiftWords(row.shift) : 'Not on the roster today'}</span> },
                                {
                                    key: 'what',
                                    label: 'What happened',
                                    width: '1.5fr',
                                    cell: (row) => {
                                        const words = outcomeWords(row);
                                        return (
                                            <span className="flex min-w-0 flex-col items-start gap-1 py-1">
                                                <StatusBadge variant={row.tone} size="sm">
                                                    {words.badge}
                                                </StatusBadge>
                                                {words.note ? <span className="max-w-full text-xs text-muted-foreground">{words.note}</span> : null}
                                            </span>
                                        );
                                    },
                                },
                                { key: 'next', label: 'Next', width: '0.8fr', cell: (row) => outcomeWords(row).next },
                            ]}
                            actionsFor={actions}
                            onOpen={(row) => setPanel({ id: row.id })}
                            onRowContextMenu={menu.open}
                            rowHeight="content"
                            minWidth={900}
                        />
                        <ListPager
                            meta={{
                                total: rows.length,
                                page: Math.min(page, pages),
                                last_page: pages,
                                from: (Math.min(page, pages) - 1) * PER_PAGE + 1,
                                to: Math.min(Math.min(page, pages) * PER_PAGE, rows.length),
                            }}
                            noun="clock-ins"
                            onPage={setPage}
                        />
                    </div>
                )}
            </section>

            {menu.ctx ? <EntityContextMenu x={menu.ctx.x} y={menu.ctx.y} icon={UserRound} title={menu.ctx.record.person.name} items={actions(menu.ctx.record)} onClose={menu.close} /> : null}

            <ClockinPanel
                open={panel !== null}
                clockinId={panel?.id ?? null}
                askFirst={panel?.ask ?? false}
                onClose={() => {
                    setPanel(null);
                    const params = new URLSearchParams(window.location.search);
                    if (params.has('clockin')) {
                        params.delete('clockin');
                        const rest = params.toString();
                        window.history.replaceState(window.history.state, '', `${window.location.pathname}${rest ? `?${rest}` : ''}`);
                    }
                }}
                onChanged={changed}
            />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pop-up 89: the side panel for one row                              */
/* ------------------------------------------------------------------ */

type Choice = 'keep' | 'ask' | 'not_work';

function PanelFacts({ rows }: { rows: ReadonlyArray<readonly [string, ReactNode]> }) {
    return (
        <dl className="grid grid-cols-[minmax(0,8.5rem)_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
            {rows.map(([label, value], index) => (
                <div key={`${label}-${index}`} className="contents">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="min-w-0 break-words text-foreground">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function suggestedWords(data: ClockinPanelData): string {
    if (!data.suggestion || !data.badge) return data.kind === 'not_rostered' ? 'Nothing: not on the roster' : 'Nothing suggested';
    const asked = `“Clock in at ${formatTime(data.badge.at)}?”`;
    const answer = data.suggestion.answer;
    if (answer === 'badge') return `${asked} · confirmed${data.suggestion.answered_at ? ` at ${formatTime(data.suggestion.answered_at)}` : ''}`;
    if (answer === 'now') return `${asked} · clocked in now instead`;
    if (answer === 'not_working') return `${asked} · said they’re not working`;
    return `${asked} · not answered`;
}

function rosterWords(data: ClockinPanelData): string {
    if (data.shift) return `${shiftWords(data.shift)} · ${data.site.name}`;
    return data.next_shift ? `Not today · next ${formatDateTime(data.next_shift)}` : 'Not today · no shift coming up';
}

function submitWords(choice: Choice | '', first: string): string {
    if (choice === 'keep') return 'Keep the clock-in';
    if (choice === 'not_work') return 'Mark as not work';
    return `Send to ${first}`;
}

export function ClockinPanel({
    open,
    clockinId,
    askFirst = false,
    onClose,
    onChanged,
}: {
    open: boolean;
    clockinId: number | null;
    askFirst?: boolean;
    onClose: () => void;
    onChanged: () => void;
}) {
    const [data, setData] = useState<ClockinPanelData | null>(null);
    const [failure, setFailure] = useState<'failed' | 'forbidden' | null>(null);
    const [reload, setReload] = useState(0);
    const [choice, setChoice] = useState<Choice | ''>('');
    const [note, setNote] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setData(null);
            setChoice('');
            setNote('');
            setError(null);
        }
    }, [open, clockinId]);

    useEffect(() => {
        if (!open || !clockinId) return;
        let cancelled = false;
        setFailure(null);
        axios
            .get<ClockinPanelData>(`/security-devices/access/clockins/${clockinId}/panel`, JSON_HEADERS)
            .then(({ data: next }) => {
                if (cancelled) return;
                setData(next);
                setChoice((current) => current || (askFirst && next.choices.includes('ask') ? 'ask' : next.choices.includes('ask') && next.waiting_for_you ? 'ask' : (next.choices[0] ?? '')));
            })
            .catch((problem) => {
                if (cancelled) return;
                const status = axios.isAxiosError(problem) ? problem.response?.status : undefined;
                setFailure(status === 403 || status === 404 ? 'forbidden' : 'failed');
            });
        return () => {
            cancelled = true;
        };
    }, [open, clockinId, reload, askFirst]);

    const first = data ? firstName(data.person.name) : '';
    const tiles: ChoiceTile<Choice>[] = data
        ? data.choices.map((key) =>
              key === 'keep'
                  ? { value: 'keep', label: 'Keep the clock-in', description: data.clock_in ? `${formatTime(data.clock_in.at)} · ${data.clock_in.how}` : undefined, icon: Tablet }
                  : key === 'not_work'
                    ? { value: 'not_work', label: 'Not work', description: 'e.g. dropping something off', icon: X }
                    : { value: 'ask', label: `Ask ${first}`, description: 'They get a message in Oblivion', icon: MessageCircle },
          )
        : [];

    const save = async () => {
        if (!data || !choice || saving) return;
        setSaving(true);
        setError(null);
        try {
            await axios.post(`/security-devices/access/clockins/${data.id}/review`, { choice, note: note.trim() || null }, JSON_HEADERS);
            toast.success(choice === 'ask' ? `Sent to ${first}` : choice === 'keep' ? 'Clock-in kept' : 'Marked as not work');
            onChanged();
            onClose();
        } catch (problem) {
            const response = axios.isAxiosError(problem) ? problem.response : undefined;
            const errors = (response?.data as { errors?: Record<string, string[]> } | undefined)?.errors;
            const firstError = errors ? Object.values(errors)[0]?.[0] : null;
            setError(firstError ?? (response?.status === 403 ? 'You can’t sort out this clock-in.' : response ? 'It wasn’t saved. Try again.' : 'Result not confirmed. Close and reopen the row to check before trying again.'));
        } finally {
            setSaving(false);
        }
    };

    let body: ReactNode;
    if (failure === 'forbidden') {
        body = <p className="text-subtle">You can’t see this clock-in.</p>;
    } else if (failure === 'failed') {
        body = <ErrorState title="Couldn’t load the clock-in" message="Check your connection, then try again." onRetry={() => setReload((n) => n + 1)} />;
    } else if (!data) {
        body = (
            <div role="status" aria-label="Loading the clock-in">
                <SkeletonCard rows={5} />
            </div>
        );
    } else {
        const facts: [string, ReactNode][] =
            data.kind === 'not_rostered'
                ? [
                      ['Badge', badgeWords(data.badge)],
                      ['Roster', rosterWords(data)],
                      ['Clock-in', data.clock_in ? `${formatTime(data.clock_in.at)} · ${data.clock_in.how}` : 'None'],
                  ]
                : [
                      ['Badge', badgeWords(data.badge)],
                      ['Suggested', suggestedWords(data)],
                      ['Clock-in', data.clock_in ? `${formatTime(data.clock_in.at)} · ${data.clock_in.how}` : 'None yet'],
                      ['Roster', rosterWords(data)],
                  ];
        body = (
            <div className="grid gap-4">
                <PanelFacts rows={facts} />
                <PopupSection icon={History} title={`${first}’s day`}>
                    {data.morning.length === 0 ? <p className="text-subtle">Nothing recorded yet.</p> : <PanelFacts rows={data.morning.map((item) => [formatTime(item.at), item.text] as const)} />}
                </PopupSection>
                {data.choices.length > 0 ? (
                    <PopupSection icon={CircleCheck} title={data.kind === 'not_rostered' ? 'What to do' : 'What to keep'}>
                        <div className="grid gap-1.5">
                            <Label className="leading-snug font-semibold">
                                {data.kind === 'not_rostered' ? 'This badge was' : 'Clock-in time'}
                                <span className="ml-0.5 text-status-critical" aria-hidden="true">
                                    *
                                </span>
                                <span className="sr-only"> (required)</span>
                            </Label>
                            <ChoiceTiles<Choice> label={data.kind === 'not_rostered' ? 'This badge was' : 'Clock-in time'} options={tiles} value={choice} onChange={setChoice} columns={tiles.length >= 3 ? 3 : 2} />
                        </div>
                        {data.kind === 'not_rostered' ? (
                            <p className="text-caption flex items-start gap-2">
                                <CalendarPlus aria-hidden="true" className="mt-px size-4 shrink-0" />
                                <span>
                                    A shift that isn’t on the roster? Add it on the{' '}
                                    <a className="font-medium text-primary underline-offset-2 hover:underline" href="/operations/rostering">
                                        roster
                                    </a>
                                    : the badge then suggests the clock-in to {first}.
                                </span>
                            </p>
                        ) : null}
                        {choice === 'keep' && data.can_note ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor="clockin-note" className="leading-snug font-semibold">
                                    Note <span className="text-caption font-normal">kept with the timesheet</span>
                                </Label>
                                <Textarea id="clockin-note" value={note} onChange={(event) => setNote(event.target.value)} placeholder="e.g. Handover started at 6:50 am" rows={3} />
                            </div>
                        ) : null}
                        {choice === 'ask' ? (
                            <div className="grid gap-1.5">
                                <Label htmlFor="clockin-message" className="leading-snug font-semibold">
                                    Message <span className="text-caption font-normal">optional · with the times</span>
                                </Label>
                                <Textarea id="clockin-message" value={note} onChange={(event) => setNote(event.target.value)} placeholder={`e.g. Did you start at ${data.badge ? formatTime(data.badge.at) : 'the badge time'}?`} rows={3} />
                            </div>
                        ) : null}
                        <PopupCallout tone="info">
                            {choice === 'ask'
                                ? `${first} gets a message in Oblivion with the badge and clock-in times. Nothing changes on the timesheet.`
                                : choice === 'keep'
                                  ? `Recorded with your name.${data.can_note ? ` A note goes on ${first}’s timesheet.` : ''}`
                                  : 'Recorded with your name. Nothing goes on a timesheet.'}
                        </PopupCallout>
                        {error ? <PopupCallout tone="critical">{error}</PopupCallout> : null}
                    </PopupSection>
                ) : data.review ? (
                    <PopupCallout tone="info">
                        {data.review.kind === 'kept' ? 'Clock-in kept' : data.review.kind === 'asked' ? `Asked ${first}` : 'Marked as not work'} by {data.review.by}
                        {data.review.at ? ` at ${formatTime(data.review.at)}` : ''}.{data.review.note ? ` “${data.review.note}”` : ''}
                    </PopupCallout>
                ) : null}
            </div>
        );
    }

    return (
        <Sheet open={open} onOpenChange={(next) => !next && onClose()}>
            <SheetContent side="right" closeLabel="Close panel" closeButtonClassName="text-band-foreground opacity-90" className="w-full gap-0 bg-card p-0 sm:max-w-[640px]" data-testid="door-clockin-89">
                <header className="eh-header shrink-0 text-band-foreground" style={{ borderRadius: 0 }}>
                    <div className="relative z-[1] px-5 pt-[18px] pr-14 pb-4">
                        <p className="text-xs font-semibold tracking-wide text-band-foreground/80 uppercase">Clock-in · {data?.site.name ?? '…'}</p>
                        <SheetTitle className="mt-1 text-section-title text-band-foreground">{data?.person.name ?? 'Clock-in'}</SheetTitle>
                        <SheetDescription className="mt-0.5 text-sm text-band-foreground/85">{data ? panelSub(data) : 'Getting the clock-in…'}</SheetDescription>
                        {data ? (
                            <div className="mt-2.5 flex flex-wrap gap-1.5">
                                <StatusBadge variant={data.waiting_for_you ? 'warning' : data.tone}>
                                    {data.waiting_for_you ? 'To check' : outcomeWords(data).badge}
                                </StatusBadge>
                                <StatusBadge variant="neutral">{data.shift ? `Rostered ${shiftWords(data.shift)}` : 'Not rostered today'}</StatusBadge>
                            </div>
                        ) : null}
                    </div>
                </header>
                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4 scrollbar-pretty">{body}</div>
                <PopupFooter>
                    <Button type="button" variant="outline" onClick={onClose} disabled={saving}>
                        Close
                    </Button>
                    {data && data.choices.length > 0 ? (
                        <Button type="button" onClick={save} disabled={!choice || saving}>
                            {saving ? 'Saving…' : submitWords(choice, first)}
                        </Button>
                    ) : null}
                </PopupFooter>
            </SheetContent>
        </Sheet>
    );
}

/**
 * For the S&D session's wiring of the Access header: the "Clocked in from a badge" block reads
 * `${meter.confirmed} of ${meter.starts}` with this caption; the tab's number is `data.count`.
 */
export function clockinsMeterCaption(data: Pick<AccessClockinsData, 'meter'>): string {
    return data.meter.starts === 0 ? 'No rostered starts yet today' : 'Rostered starts so far today · each person confirmed';
}
