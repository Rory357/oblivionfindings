/**
 * The door's Overview (drawing pg-sdr-6), Events (6b) and Access (6c) tabs.
 *
 * Privacy (D19, D14): the server never sends a resident's name. Their entries read "Resident fob",
 * their fobs are a count, and anyone Oblivion can't place shows no name. Names show for 90 days.
 */
import { Choices, DutyClock, FactRows, KnowList, Log, PersonChip, PointBars, QuietLine, Surface, type LogItem, type SgTone } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { agoWords, seenTime } from '@/lib/security-devices/device-time';
import { House, Lock, LockOpen, Users } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { logTime, MainSide } from './_device-page-parts';
import { commandLineItems } from './_safety-kit-parts';
import type { DoorEvent, DoorPageProps } from './_safety-kit-types';

function eventTitle(row: DoorEvent): string {
    return row.kind === 'unlocked' && row.who ? `${row.title} by ${row.who}` : row.title;
}

function eventLog(rows: DoorEvent[], now: Date): LogItem[] {
    return rows.map((row) => ({
        key: row.key,
        time: row.at ? logTime(row.at, now) : '—',
        tone: row.tone,
        title: eventTitle(row),
        sub: row.how ?? undefined,
    }));
}

const BADGE_KINDS: Partial<Record<DoorEvent['kind'], 'critical' | 'warning'>> = {
    refused: 'critical',
    forced: 'critical',
    held: 'warning',
};

/* ------------------------------------------------------------------ */
/*  Overview                                                           */
/* ------------------------------------------------------------------ */

export function DoorOverview({
    props,
    now,
    onTab,
    onUnlock,
    onKeepUnlocked,
    onLockAgain,
    onCheck,
}: {
    props: DoorPageProps;
    now: Date;
    onTab: (tab: DoorPageProps['tab']) => void;
    onUnlock: () => void;
    onKeepUnlocked?: () => void;
    onLockAgain?: () => void;
    onCheck: () => void;
}) {
    const overview = props.overview;
    if (!overview) return null;
    const { lock, control, can } = props;
    const seconds = control.seconds;

    const lines: { state: SgTone; text: ReactNode }[] = [];
    if (control.unlock && !control.unlock.available && control.unlock.reason) {
        lines.push({ state: 'warning', text: control.unlock.reason });
    }
    if (control.unlock) {
        lines.push({
            state: 'none',
            text: `Unlocking from Oblivion asks for a reason and is recorded with your name${control.unlock.approval ? '; a second person approves it first' : ''}.`,
        });
    }
    lines.push(...commandLineItems({ commands: control.commands, now, deviceId: props.device.id, onCheck }));

    const keep = can.keep_unlocked && onKeepUnlocked;
    const again = can.lock_again && onLockAgain && lock.title.startsWith('Unlocked');
    const actions =
        (can.unlock && seconds) || keep || again ? (
            <>
                {can.unlock && seconds ? (
                    <Button type="button" size="sm" onClick={onUnlock}>
                        Unlock for {seconds} seconds
                    </Button>
                ) : null}
                {keep ? (
                    <Button type="button" size="sm" variant="outline" onClick={onKeepUnlocked}>
                        Keep unlocked
                    </Button>
                ) : null}
                {again ? (
                    <Button type="button" size="sm" variant="outline" onClick={onLockAgain}>
                        Lock again now
                    </Button>
                ) : null}
            </>
        ) : undefined;

    return (
        <MainSide
            main={
                <>
                    <DutyClock
                        tone={lock.tone ?? undefined}
                        big={lock.title.startsWith('Unlocked') ? <LockOpen aria-hidden="true" className="size-6" /> : <Lock aria-hidden="true" className="size-6" />}
                        kicker={lock.checked_at ? `Door · checked ${agoWords(lock.checked_at, now)}` : 'Door'}
                        title={lock.title}
                        sub={lock.relocks ? 'UniFi Access locks it again by itself after each entry.' : undefined}
                        lines={lines}
                        actions={actions}
                    />
                    {can.see_events ? (
                        overview.today.length > 0 ? (
                            <Log
                                title="Who came in"
                                sub="Today · newest first"
                                action={{ label: 'All events', onClick: () => onTab('events') }}
                                items={eventLog(overview.today, now)}
                            />
                        ) : (
                            <QuietLine title="Who came in" text="Nobody has come in today." action={{ label: 'All events', onClick: () => onTab('events') }} />
                        )
                    ) : null}
                </>
            }
            side={
                <>
                    <FactRows
                        title="Who can open it"
                        sub="Locked all the time; these open it"
                        action={{ label: 'Access tab', onClick: () => onTab('access') }}
                        rows={overview.who}
                    />
                    {overview.week ? (
                        <PointBars
                            title="Comings and goings"
                            sub="Last 7 days"
                            labels={overview.week.labels}
                            values={overview.week.values}
                            note={busiest(overview.week)}
                        />
                    ) : null}
                    <FactRows title="About this door" rows={overview.facts} />
                </>
            }
        />
    );
}

function busiest(week: { labels: string[]; values: number[] }): string {
    const max = Math.max(...week.values);
    if (max === 0) return 'Nobody came in through this door in the last 7 days.';
    const index = week.values.indexOf(max);
    return `Busiest on ${week.labels[index]}: ${max} ${max === 1 ? 'entry' : 'entries'}.`;
}

/* ------------------------------------------------------------------ */
/*  Events                                                             */
/* ------------------------------------------------------------------ */

function WaysDonut({ ways }: { ways: { key: string; label: string; tone: string; value: number }[] }) {
    const total = ways.reduce((sum, way) => sum + way.value, 0);
    const colour = (tone: string) => (tone === 'critical' ? 'var(--status-critical)' : `var(--${tone})`);
    let start = 0;
    const stops = ways
        .map((way) => {
            const end = start + (way.value / Math.max(1, total)) * 100;
            const stop = `${colour(way.tone)} ${start}% ${end}%`;
            start = end;
            return stop;
        })
        .join(', ');

    return (
        <Surface title="How people came in" sub="Last 7 days">
            {total === 0 ? (
                <p className="sg-quiet">Nobody came in through this door in the last 7 days.</p>
            ) : (
                <div className="flex items-center gap-5 px-[18px] py-4">
                    <div
                        role="img"
                        aria-label={`How people came in, last 7 days: ${ways.map((way) => `${way.label} ${way.value}`).join(', ')}`}
                        className="relative grid size-[120px] shrink-0 place-items-center rounded-full"
                        style={{ background: `conic-gradient(${stops})` } as CSSProperties}
                    >
                        <span className="absolute inset-[18px] rounded-full bg-card" />
                        <span className="relative grid justify-items-center">
                            <b className="text-xl font-bold tracking-tight tabular-nums">{total.toLocaleString('en-NZ')}</b>
                            <small className="text-caption">in 7 days</small>
                        </span>
                    </div>
                    <dl className="grid min-w-0 flex-1 gap-2 text-sm">
                        {ways.map((way) => (
                            <div key={way.key} className="flex items-center gap-2">
                                <i aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: colour(way.tone) }} />
                                <dt>{way.label}</dt>
                                <dd className="ml-auto font-semibold tabular-nums">{way.value}</dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}
        </Surface>
    );
}

export function DoorEvents({
    props,
    now,
    onRange,
    onPage,
}: {
    props: DoorPageProps;
    now: Date;
    onRange: (range: 'today' | '7d' | '90d') => void;
    onPage: (page: number) => void;
}) {
    const events = props.events;
    if (!events) return null;

    return (
        <MainSide
            main={
                <Surface title="Events" sub="Every entry and unlock · names show for 90 days">
                    <div className="flex justify-end px-[18px] pt-2">
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            aria-label="Events: time range"
                            value={events.range}
                            onValueChange={(next) => {
                                if (next) onRange(next as 'today' | '7d' | '90d');
                            }}
                        >
                            {events.ranges.map((range) => (
                                <ToggleGroupItem key={range.key} value={range.key} className="px-2.5 text-xs">
                                    {range.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </div>
                    {events.rows.length === 0 ? (
                        <p className="sg-quiet">Nothing happened at this door in this time.</p>
                    ) : (
                        <div className="mt-2 overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="whitespace-nowrap">When</TableHead>
                                        <TableHead>What happened</TableHead>
                                        <TableHead className="whitespace-nowrap">Who</TableHead>
                                        <TableHead>How</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {events.rows.map((row) => {
                                        const badge = BADGE_KINDS[row.kind];
                                        return (
                                            <TableRow key={row.key}>
                                                <TableCell className="whitespace-nowrap">{row.at ? seenTime(row.at, now) : '—'}</TableCell>
                                                <TableCell>{badge ? <StatusBadge variant={badge}>{row.title}</StatusBadge> : row.kind === 'came_in' ? 'Came in' : row.title}</TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {row.who_kind === 'staff' && row.who ? <PersonChip name={row.who} /> : row.who_kind === 'resident' ? 'Resident fob' : '—'}
                                                </TableCell>
                                                <TableCell>{row.how ?? '—'}</TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-[18px] py-3">
                        <p className="text-subtle">
                            Showing {events.rows.length.toLocaleString('en-NZ')} of {events.total.toLocaleString('en-NZ')}
                        </p>
                        {events.last_page > 1 ? (
                            <LaravelPagination
                                lastPage={events.last_page}
                                ariaLabel="Door events pages"
                                navigation={{
                                    currentPage: events.page,
                                    previousPage: events.page > 1 ? events.page - 1 : null,
                                    nextPage: events.page < events.last_page ? events.page + 1 : null,
                                    onPageChange: onPage,
                                }}
                            />
                        ) : null}
                    </div>
                </Surface>
            }
            side={
                <>
                    <WaysDonut ways={events.ways} />
                    <KnowList
                        title="What’s kept"
                        items={[
                            { tone: 'info', text: <>Every entry, unlock and refused card. <b>Names show for 90 days</b>, then only the time and how</> },
                            { tone: 'info', text: 'A resident’s fob shows as “Resident fob”, never their name' },
                            { tone: 'info', text: 'Unlocking from Oblivion needs a reason' },
                        ]}
                    />
                </>
            }
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Access                                                             */
/* ------------------------------------------------------------------ */

export function DoorAccess({ props }: { props: DoorPageProps }) {
    const access = props.access;
    if (!access) return null;

    return (
        <MainSide
            main={
                <>
                    <Choices
                        title="Lock rule"
                        sub="The rule in use"
                        cols={1}
                        options={[
                            {
                                title: 'Normal',
                                on: true,
                                text: 'Locked. Cards, fobs, PINs and phones open it.',
                                can: [
                                    { label: 'Staff and residents get in', state: 'success' },
                                    { label: 'Never unlocks by itself (D12)', state: 'none' },
                                ],
                            },
                        ]}
                    />
                    {access.groups.length === 0 ? (
                        <QuietLine title="Who can open it" text="No cards or fobs are recorded for this door in Oblivion yet." />
                    ) : (
                        <Surface title="Who can open it" sub="By group · residents’ names aren’t listed (D19)">
                            <div className="mt-3 overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Group</TableHead>
                                            <TableHead>Who</TableHead>
                                            <TableHead>When</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {access.groups.map((group) => {
                                            const Icon = group.kind === 'resident' ? House : Users;
                                            return (
                                                <TableRow key={group.key}>
                                                    <TableCell>
                                                        <span className="flex items-center gap-2.5">
                                                            <Icon aria-hidden="true" className="size-4 shrink-0 text-muted-foreground" />
                                                            <b className="text-sm font-semibold">{group.group}</b>
                                                        </span>
                                                    </TableCell>
                                                    <TableCell>{group.who}</TableCell>
                                                    <TableCell>{group.when}</TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        </Surface>
                    )}
                </>
            }
            side={
                <>
                    <KnowList
                        title="Alerts for this door"
                        items={[{ tone: 'critical', text: <><b>Forced open</b> opens an alert for the staff on shift straight away</> }]}
                    />
                    <FactRows
                        title="Schedule"
                        rows={[
                            ['Unlocks by itself', 'Never (D12)'],
                            ['Card schedules', access.schedules.length > 0 ? access.schedules.join(', ') : 'None recorded'],
                        ]}
                    />
                    <KnowList
                        title="Safety"
                        items={[{ tone: 'success', text: 'Only a person can unlock it from Oblivion, with a reason; automations never unlock' }]}
                    />
                </>
            }
        />
    );
}
