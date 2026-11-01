/**
 * Access › Doors (drawing pg-sdr-19): each UniFi Access door as a card, problems first, with its
 * lock state, open or closed, who can open it and its entries per hour over the last 24 hours.
 * Cards or a list; each card's ⋮ menu, right-click and click work as on Devices.
 */
import { EntityKebab, EntityTable, type MenuItem } from '@/components/lists';
import { NextStep } from '@/components/security-devices/device-blocks';
import { Note, QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { cn } from '@/lib/utils';
import { Building2, DoorClosed, DoorOpen, Info, LayoutGrid, List, Lock, LockOpen, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { Spark } from '../devices/_device-card';
import { BlockHead, ViewSwitch } from '../devices/_parts';
import { lineText, StatusCell } from './_parts';
import type { DoorCard as DoorCardData, DoorsData } from './_types';

const VIEW_KEY = 'sd.access.doors.view';
const CARDS_PER_GROUP = 9;

const ICON: Record<DoorCardData['icon'], LucideIcon> = {
    lock: Lock,
    'lock-open': LockOpen,
    'door-open': DoorOpen,
    'door-closed': DoorClosed,
};

/** The icon tile takes the state's tint; normal doors stay quiet. */
const ICON_TONE: Record<string, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    neutral: 'bg-muted text-muted-foreground',
    success: 'bg-primary/10 text-primary',
    info: 'bg-primary/10 text-primary',
};

function readView(): 'cards' | 'list' {
    try {
        return window.localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'cards';
    } catch {
        return 'cards';
    }
}

export function DoorCard({ door, actions, onOpen, onContextMenu, now }: { door: DoorCardData; actions: MenuItem[]; onOpen: () => void; onContextMenu: (event: MouseEvent) => void; now: Date }) {
    const Icon = ICON[door.icon] ?? Lock;
    const graphNote = lineText(door.graph.note, now);
    return (
        <article
            aria-label={door.name}
            onClick={onOpen}
            onContextMenu={onContextMenu}
            className="flex min-w-0 cursor-pointer flex-col rounded-[14px] border border-border bg-card shadow-sm transition-shadow hover:shadow-md focus-within:ring-2 focus-within:ring-ring"
        >
            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="flex items-start gap-3">
                    <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-[10px]', ICON_TONE[door.badge.tone] ?? ICON_TONE.neutral)}>
                        <Icon aria-hidden="true" className="size-[18px]" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h3 className="truncate text-[14.5px] leading-tight font-[650]">
                            <a
                                href={door.href ?? '#'}
                                onClick={(event) => {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    onOpen();
                                }}
                                className="outline-none hover:underline focus-visible:underline"
                            >
                                {door.name}
                            </a>
                        </h3>
                        <p className="truncate text-xs text-muted-foreground" title={door.sub}>
                            {door.sub}
                        </p>
                    </div>
                    <EntityKebab actions={actions} label={`Actions for ${door.name}`} className="-mt-1 -mr-1" />
                </div>
                <div>
                    <StatusBadge variant={door.badge.tone} size="sm">
                        {door.badge.text}
                    </StatusBadge>
                    {door.note ? <p className="mt-1.5 text-[12.5px] text-muted-foreground">{lineText(door.note, now)}</p> : null}
                </div>
                <div className="flex min-h-[30px] flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span className={cn('leading-none font-bold tracking-tight', door.value === 'Not available' ? 'text-sm text-muted-foreground' : 'text-xl')}>
                        {door.value === 'Not available' ? 'Lock: Not available' : door.value}
                    </span>
                    <span className="text-xs text-muted-foreground">{door.value_sub}</span>
                </div>
                <div className="mt-auto">
                    <div className="mb-1.5 flex items-baseline justify-between gap-2 text-[11.5px]">
                        <span className="text-muted-foreground">{door.graph.label}</span>
                        {door.graph.spark ? <span className="font-semibold">{graphNote}</span> : null}
                    </div>
                    {door.graph.spark ? (
                        <Spark values={door.graph.spark} label={`${door.graph.label}: ${graphNote}`} />
                    ) : (
                        <p className="flex h-[38px] items-center text-xs text-muted-foreground">{graphNote}</p>
                    )}
                </div>
            </div>
            <footer className="flex items-center justify-between gap-2 border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                <span className="flex min-w-0 items-center gap-1.5">
                    <Building2 aria-hidden="true" className="size-3.5 shrink-0" />
                    <span className="truncate">{door.site}</span>
                </span>
                <span className="shrink-0">{door.seen.at ? `${door.seen.label} ${seenTime(door.seen.at, now)}` : 'Not seen yet'}</span>
            </footer>
        </article>
    );
}

export function DoorsView({
    data,
    actionsFor,
    onOpen,
    onContextMenu,
    onRollCall,
    canRollCall,
    filtered,
    now,
}: {
    data: DoorsData;
    actionsFor: (door: DoorCardData) => MenuItem[];
    onOpen: (door: DoorCardData) => void;
    onContextMenu: (event: MouseEvent, door: DoorCardData) => void;
    onRollCall: (siteId: number) => void;
    canRollCall: boolean;
    filtered: boolean;
    now: Date;
}) {
    const [view, setView] = useState<'cards' | 'list'>(readView);
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const switchView = (next: 'cards' | 'list') => {
        setView(next);
        try {
            window.localStorage.setItem(VIEW_KEY, next);
        } catch {
            // Not remembered; it still applies on this page.
        }
    };
    const shown = data.groups.filter((group) => group.count > 0);
    const all = shown.flatMap((group) => group.items);
    const viewSwitch = (
        <ViewSwitch<'cards' | 'list'>
            label="Show doors as"
            value={view}
            onChange={switchView}
            options={[
                { value: 'cards', label: 'Cards', icon: LayoutGrid },
                { value: 'list', label: 'List', icon: List },
            ]}
        />
    );

    let body: ReactNode;
    if (data.total === 0) {
        body = <QuietLine text="No doors in UniFi Access yet. Connect a Site’s UniFi Access in Setup › Doors & access, and its doors show here after the next read." />;
    } else if (all.length === 0) {
        body = <QuietLine text={filtered ? 'No doors match. Try another search, Site or state.' : 'No doors here.'} />;
    } else if (view === 'list') {
        body = (
            <EntityTable<DoorCardData>
                rows={all}
                rowKey={(door) => door.id}
                identityLabel="Door"
                identity={(door) => ({ icon: ICON[door.icon] ?? Lock, name: door.name, subline: door.sub, linkLabel: `Open ${door.name}` })}
                hrefFor={(door) => door.href}
                columns={[
                    { key: 'state', label: 'State', width: '1.4fr', cell: (door) => <StatusCell badge={door.badge} note={door.note} now={now} /> },
                    {
                        key: 'lock',
                        label: 'Lock',
                        width: '1.3fr',
                        cell: (door) => (
                            <span className="flex flex-col py-1">
                                <span className="font-semibold">{door.value}</span>
                                <span className="text-xs text-muted-foreground">{door.value_sub}</span>
                            </span>
                        ),
                    },
                    { key: 'entries', label: 'Entries, 24 h', width: '1fr', cell: (door) => lineText(door.graph.note, now) },
                    { key: 'site', label: 'Site', width: '1fr', cell: (door) => door.site },
                    { key: 'seen', label: 'Last seen', width: '120px', cell: (door) => <span className="whitespace-nowrap">{door.seen.at ? seenTime(door.seen.at, now) : '—'}</span> },
                ]}
                actionsFor={actionsFor}
                onOpen={onOpen}
                onRowContextMenu={onContextMenu}
                rowHeight="content"
                minWidth={900}
            />
        );
    } else {
        body = shown.map((group) => {
            const open = expanded[group.key] ?? false;
            const items = open ? group.items : group.items.slice(0, CARDS_PER_GROUP);
            return (
                <section key={group.key} aria-label={group.title} className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span aria-hidden="true" className={group.key === 'attention' ? 'size-2.5 rounded-full bg-status-warning' : 'size-2.5 rounded-full bg-status-success'} />
                        <h3 className="text-sm font-[650]">{group.title}</h3>
                        <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs font-semibold tabular-nums">{group.count}</span>
                        {group.sub ? <span className="text-subtle">{group.sub}</span> : null}
                        {group.items.length > CARDS_PER_GROUP ? (
                            <Button type="button" variant="link" className="ml-auto h-auto p-0" onClick={() => setExpanded((now2) => ({ ...now2, [group.key]: !open }))}>
                                {open ? 'Show fewer' : `Show all ${group.count}`}
                            </Button>
                        ) : null}
                    </div>
                    <div className="grid gap-5 [grid-template-columns:repeat(auto-fill,minmax(min(100%,280px),1fr))]">
                        {items.map((door) => (
                            <DoorCard key={door.id} door={door} actions={actionsFor(door)} onOpen={() => onOpen(door)} onContextMenu={(event) => onContextMenu(event, door)} now={now} />
                        ))}
                    </div>
                </section>
            );
        });
    }

    return (
        <div className="flex flex-col gap-5">
            <Note icon={Info}>
                <b className="font-semibold">Every door opens from the inside</b> by its exit button and handle. Clients fit both on every door before it joins, and the installer ticks each door off. Oblivion never locks anyone in: lockdown only stops cards opening doors from outside, and evacuation unlocks every door at that Site.
            </Note>
            <BlockHead title="Doors" sub="Problems first · each card shows entries per hour over the last 24 hours" right={data.total > 0 ? viewSwitch : undefined} />
            {body}
            {data.not_managed.count > 0 ? (
                <QuietLine
                    text={
                        <>
                            <b className="font-semibold">Not managed here:</b> {data.not_managed.names.join(', ')}
                            {data.not_managed.count > data.not_managed.names.length ? ` and ${data.not_managed.count - data.not_managed.names.length} more` : ''}{' '}
                            {data.not_managed.count === 1 ? 'isn’t' : 'aren’t'} in UniFi Access, so {data.not_managed.count === 1 ? 'it’s' : 'they’re'} view only.
                        </>
                    }
                    action={{ label: 'Open in Devices', href: '/security-devices/devices?tab=alarms_doors' }}
                />
            ) : null}
            {data.emergency.length > 0 ? (
                <div className="grid gap-5 md:grid-cols-2">
                    {data.emergency.map((site) => (
                        <NextStep
                            key={site.site_id}
                            tone={!site.known ? 'warning' : site.mode === 'normal' ? undefined : 'critical'}
                            label={`${site.site} · ${site.doors === 1 ? '1 door' : `${site.doors} doors`}`}
                            title={
                                !site.known
                                    ? 'Not known: UniFi Access isn’t answering'
                                    : site.mode === 'lockdown'
                                      ? 'Lockdown on'
                                      : site.mode === 'evacuation'
                                        ? 'Evacuation on'
                                        : 'Normal: no lockdown or evacuation'
                            }
                            sub={
                                site.mode === 'lockdown'
                                    ? 'Cards, PINs and phones don’t open the doors from outside. Every door still opens from the inside.'
                                    : site.mode === 'evacuation'
                                      ? 'Every door at this Site is unlocked.'
                                      : undefined
                            }
                            actions={
                                site.mode === 'evacuation' && canRollCall ? (
                                    <Button type="button" variant="outline" size="sm" onClick={() => onRollCall(site.site_id)}>
                                        Open the roll call
                                    </Button>
                                ) : undefined
                            }
                        />
                    ))}
                </div>
            ) : null}
        </div>
    );
}
