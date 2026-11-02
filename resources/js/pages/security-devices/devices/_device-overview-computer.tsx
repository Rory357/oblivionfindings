/**
 * A computer's overview, Health and Remote support tabs (drawings pg-sdr-9, 9c, 9b), from what
 * Obli sends today. What Obli can't read yet says so, in the drawing's own words.
 */
import {
    FactRows,
    Journey,
    Log,
    Note,
    PlaceCards,
    QuietLine,
    RingCard,
    Surface,
    Tally,
} from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import { agoWords } from '@/lib/security-devices/device-time';
import { Info } from 'lucide-react';
import type { ComputerHealth, DevicePageProps, RemoteSupport } from './_device-page-types';
import { logItems, MainSide, plural } from './_device-page-parts';
import { RecentLog } from './_device-overview-network';

function percent(value: number | null | undefined): string {
    return value === null || value === undefined ? 'Not available' : `${Math.round(value)}%`;
}

function readWords(at: string | null | undefined, now: Date): string {
    return at ? `Read ${agoWords(at, now)} by Obli` : 'Not read by Obli yet';
}

function NotSent({ title, restricted }: { title: string; restricted: boolean }) {
    return (
        <QuietLine
            title={title}
            text={restricted ? 'You can’t see this computer’s details.' : 'Not available: Obli hasn’t sent this computer’s details.'}
        />
    );
}

export function ComputerOverviewTab({ props, now }: { props: DevicePageProps; now: Date }) {
    const overview = props.overview;
    if (!overview) return null;
    const safety = overview.safety ?? [];
    const readable = safety.filter((check) => check.state !== 'none');
    const passing = readable.filter((check) => check.state === 'success');

    return (
        <MainSide
            main={
                <>
                    {overview.now ? (
                        <Tally
                            title="Right now"
                            sub={readWords(overview.now.read_at, now)}
                            keepZeros
                            items={[
                                { value: percent(overview.now.cpu), label: 'CPU busy' },
                                { value: percent(overview.now.memory), label: 'Memory in use' },
                                { value: percent(overview.now.disk), label: 'Disk used', tone: (overview.now.disk ?? 0) >= 90 ? 'warning' : undefined },
                            ]}
                        />
                    ) : (
                        <NotSent title="Right now" restricted={overview.restricted ?? false} />
                    )}
                    <RecentLog title="Recent" entries={overview.events} canSee={props.can.see_events} now={now} />
                </>
            }
            side={
                <>
                    <RingCard
                        value={passing.length}
                        of={Math.max(1, readable.length)}
                        label="Safety checks"
                        sub={overview.now ? readWords(overview.now.read_at, now) : 'Not read by Obli yet'}
                        checks={safety.map((check) => ({ label: check.label, state: check.state, note: check.note ?? undefined }))}
                    />
                    <FactRows title="About this PC" rows={overview.facts} />
                </>
            }
        />
    );
}

export function ComputerHealthTab({ health, now }: { health: ComputerHealth; now: Date }) {
    const services = health.services;
    const running = services.filter((service) => service.state === 'success');

    return (
        <MainSide
            main={
                <>
                    <PlaceCards
                        title="Safety"
                        sub={readWords(health.read_at, now)}
                        cols={2}
                        items={health.safety.map((card) => ({
                            name: card.name,
                            sub: card.sub,
                            flag: <StatusBadge variant={card.flag.tone}>{card.flag.label}</StatusBadge>,
                            facts: card.facts,
                        }))}
                    />
                    {health.available ? (
                        health.disks.length > 0 ? (
                            <Surface title="Disk space used" sub="Each drive at Obli’s last reading">
                                <ul className="grid gap-3 px-[18px] py-4">
                                    {health.disks.map((disk) => (
                                        <li key={disk.name} className="grid gap-1.5">
                                            <div className="flex items-baseline justify-between gap-3 text-sm">
                                                <b className="font-semibold">{disk.name}</b>
                                                <span className="text-subtle tabular-nums">
                                                    {disk.used} of {disk.total}
                                                    {disk.percent !== null ? ` · ${Math.round(disk.percent)}%` : ''}
                                                </span>
                                            </div>
                                            <div
                                                role="img"
                                                aria-label={`${disk.name}: ${disk.percent !== null ? Math.round(disk.percent) : 'unknown'}% used`}
                                                className="h-2 overflow-hidden rounded-full bg-muted"
                                            >
                                                <i
                                                    className={(disk.percent ?? 0) >= 90 ? 'block h-full rounded-full bg-status-warning' : 'block h-full rounded-full bg-primary'}
                                                    style={{ width: `${Math.max(2, Math.min(100, disk.percent ?? 0))}%` }}
                                                />
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </Surface>
                        ) : (
                            <QuietLine title="Disk space used" text="Not available: Obli didn’t send any drives." />
                        )
                    ) : (
                        <NotSent title="Disk space used" restricted={health.restricted} />
                    )}
                </>
            }
            side={
                <>
                    {services.length > 0 ? (
                        <RingCard
                            value={running.length}
                            of={services.length}
                            label="Services Obli watches"
                            sub={readWords(health.read_at, now)}
                            checks={services.map((service) => ({ label: service.label, state: service.state, note: service.note ?? undefined }))}
                        />
                    ) : (
                        <QuietLine title="Services Obli watches" text="Not available: no watched services in Obli’s last reading." />
                    )}
                    {health.facts.length > 0 ? <FactRows title="This PC" rows={health.facts} /> : null}
                    <FactRows
                        title="Warranty and programs"
                        rows={[
                            ['Warranty', health.warranty_until ? `Until ${formatDateOnly(health.warranty_until)}` : 'Not recorded'],
                            ['Programs', health.programs === null ? 'Not available' : `${plural(health.programs, 'program', 'programs')} installed`],
                        ]}
                    />
                </>
            }
        />
    );
}

export function RemoteSupportTab({ remote, now }: { remote: RemoteSupport; now: Date }) {
    const ready = remote.ready.filter((check) => check.state === 'success').length;

    return (
        <MainSide
            main={
                <>
                    <Journey
                        title="How remote support works"
                        sub="Nothing happens until the person at the PC clicks Allow"
                        steps={[
                            { label: 'You click Start remote support', meta: 'At the top of this page', state: 'todo' },
                            { label: 'They see a request on their screen', meta: 'Someone from IT wants to help', state: 'todo' },
                            { label: 'They click Allow', meta: 'Or Decline', state: 'todo' },
                            { label: remote.can_control ? 'You see and use their screen' : 'You see their screen', meta: 'They can stop it any time', state: 'todo' },
                        ]}
                    />
                    <Note icon={Info}>
                        <b className="font-semibold">Sessions are not recorded.</b> Oblivion keeps who connected, when and for how long.
                    </Note>
                    {remote.sessions.length > 0 ? (
                        <Log
                            title="Past sessions"
                            limit={20}
                            items={logItems(
                                remote.sessions.map((session) => ({
                                    key: session.key,
                                    at: session.at,
                                    tone: session.tone,
                                    title: `${session.who} · ${session.outcome}`,
                                    sub: [session.minutes !== null ? `${session.minutes} min` : null, session.why].filter(Boolean).join(' · ') || null,
                                })),
                                now,
                            )}
                        />
                    ) : (
                        <QuietLine title="Past sessions" text="No remote support on this PC yet." />
                    )}
                </>
            }
            side={
                <>
                    <RingCard value={ready} of={remote.ready.length} label="Ready for remote support" checks={remote.ready.map((check) => ({ label: check.label, state: check.state, note: check.note ?? undefined }))} />
                    <FactRows
                        title="How it connects"
                        rows={[
                            ['Through', 'Obli on the PC'],
                            ['Who can start it', 'Staff with IT remote support access'],
                            ['Allow', remote.asked_every_time ? 'Asked every time' : 'Set by IT for this PC'],
                            ['You can', remote.can_control ? 'See the screen and use the mouse and keyboard' : 'See the screen'],
                            ...(remote.max_minutes ? [['Longest session', `${remote.max_minutes} min`] as [string, string]] : []),
                        ]}
                    />
                </>
            }
        />
    );
}
