/**
 * The care tracker page's two tabs (drawings pg-sdr-8, 8b and 8c). Location: the map only when the
 * server says the location may be shown; otherwise the plain reason, who can fix it and what still
 * works. Console: the unit's own messages and the commands sent, each with its exact message and
 * the unit's reply. No coordinate reaches this page unless it may be shown.
 */
import LeafletMap from '@/components/leaflet-map';
import { NextStep, TrendChart } from '@/components/security-devices/device-blocks';
import { FactRows, KnowList, Log, Note, PeopleCards, QuietLine, Surface } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { seenTime } from '@/lib/security-devices/device-time';
import { Link } from '@inertiajs/react';
import { Info, ShieldAlert } from 'lucide-react';
import { logTime, MainSide } from './_device-page-parts';
import { FactLines, fill, nzDate } from './_sensor-tracker-parts';
import type { TrackerConsole, TrackerLocation, TrackerPrivacy } from './_sensor-tracker-types';

function shownWords(privacy: TrackerPrivacy): string {
    const person = privacy.person;
    if (person?.kind === 'client') {
        const whose = person.short ? `${person.short}’s` : 'the wearer’s';
        const until = person.consent?.until ? ` (until ${nzDate(person.consent.until)})` : '';
        return `Location shows because ${whose} location consent is active${until}.`;
    }
    if (person?.kind === 'staff') return 'Location shows because collection is on for this staff safety tracker.';
    return 'Location shows because this tracker is Site equipment, not linked to a person.';
}

function WhereMap({ location, privacy, now }: { location: TrackerLocation; privacy: TrackerPrivacy; now: Date }) {
    const title = privacy.person?.short ? `Where ${privacy.person.short} is` : 'Where it is';
    const position = location.position;
    if (!position) return <QuietLine title={title} text="No position from this unit yet." />;
    const when = position.at ? seenTime(position.at, now) : null;
    const sub = [position.address ?? 'Last position', when, position.accuracy_m !== null ? `accurate to about ${position.accuracy_m} m` : null].filter(Boolean).join(' · ');
    return (
        <Surface title={title} sub={sub}>
            <div className="px-[18px] pt-3 pb-4">
                <LeafletMap
                    center={{ lat: position.lat, lng: position.lng }}
                    zoom={16}
                    height={360}
                    fitMarkers={false}
                    observeResize
                    markers={[{ id: 'now', lat: position.lat, lng: position.lng, title: `${privacy.person?.short ?? 'Tracker'}${when ? ` · ${when}` : ''}`, type: 'default' }]}
                />
            </div>
        </Surface>
    );
}

function BatteryTrend({ battery }: { battery: TrackerLocation['battery'] }) {
    if (!battery) return <QuietLine title="Battery" text="Not available: no battery reports from it in the last 7 days." />;
    return <TrendChart title="Battery" sub="Last 7 days" start={battery.start} step={battery.step_seconds} points={battery.points} unit="%" peakWords="Highest" ticks={4} />;
}

function Linked({ linked }: { linked: TrackerLocation['linked'] }) {
    if (!linked) return null;
    return (
        <PeopleCards
            title="Linked to"
            cols={1}
            items={[
                {
                    name: linked.name,
                    sub: linked.sub ?? undefined,
                    flag: linked.flag ? <StatusBadge variant={linked.flag.tone}>{linked.flag.label}</StatusBadge> : undefined,
                    href: linked.href ?? undefined,
                },
            ]}
        />
    );
}

export function TrackerLocationTab({ location, privacy, now }: { location: TrackerLocation; privacy: TrackerPrivacy; now: Date }) {
    const consentGone = privacy.person?.kind === 'client' && privacy.person.consent?.active === false;
    const fix = location.fix;

    return (
        <MainSide
            main={
                <>
                    {fix ? (
                        <Note tone="warning" icon={ShieldAlert}>
                            <p>{fix.note}</p>
                            {!fix.title && fix.sub ? <p className="mt-1">{fix.sub}</p> : null}
                        </Note>
                    ) : (
                        <Note icon={Info}>{shownWords(privacy)}</Note>
                    )}
                    {privacy.shown ? <WhereMap location={location} privacy={privacy} now={now} /> : null}
                    {fix?.title ? (
                        <NextStep
                            tone="warning"
                            label="To show the location"
                            title={fix.title}
                            sub={fix.sub ?? undefined}
                            actions={
                                location.record_href ? (
                                    <Button asChild>
                                        <Link href={location.record_href}>Record consent</Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : null}
                    {consentGone ? (
                        <KnowList
                            title="What still works without consent"
                            items={[
                                { tone: 'success', text: 'Online and battery checks' },
                                { tone: 'success', text: <><b className="font-semibold">SOS button</b> still raises an alert in Control Room, without a location</> },
                                { tone: 'none', text: <><b className="font-semibold">Map, trail and Ask for position now</b> are off until consent is recorded and linked</> },
                            ]}
                        />
                    ) : null}
                </>
            }
            side={
                consentGone ? (
                    <>
                        {location.consent_history ? (
                            location.consent_history.length > 0 ? (
                                <Log
                                    title="Consent history"
                                    items={location.consent_history.map((entry) => ({
                                        key: entry.key,
                                        time: entry.at ? logTime(entry.at, now) : '—',
                                        tone: entry.tone,
                                        title: entry.title,
                                        sub: entry.sub ? fill({ text: entry.sub, at: entry.sub_at }, now) : undefined,
                                    }))}
                                />
                            ) : (
                                <QuietLine title="Consent history" text="No location consent linked to this tracker yet." />
                            )
                        ) : null}
                        <Linked linked={location.linked} />
                    </>
                ) : (
                    <>
                        <BatteryTrend battery={location.battery} />
                        <FactLines title="Check-ins" rows={location.checkins} now={now} />
                        <Linked linked={location.linked} />
                    </>
                )
            }
        />
    );
}

export function TrackerConsoleTab({
    console: data,
    now,
    onSend,
    onAskSettings,
}: {
    console: TrackerConsole;
    now: Date;
    onSend: (() => void) | null;
    onAskSettings: (() => void) | null;
}) {
    return (
        <MainSide
            main={
                <>
                    <Surface
                        title="Messages from the unit"
                        sub="Newest last · the unit sends UTC; times here are NZ time"
                        action={onSend ? { label: 'Send a command', onClick: onSend } : null}
                    >
                        <div className="mx-[18px] mt-3 mb-4 overflow-hidden rounded-xl bg-sidebar text-sidebar-foreground">
                            <p className="flex items-center gap-2 border-b border-sidebar-border px-3 py-2 font-mono text-[12px] text-sidebar-foreground/70">
                                <span aria-hidden="true" className="inline-flex gap-1">
                                    <i className="size-2 rounded-full bg-sidebar-foreground/30" />
                                    <i className="size-2 rounded-full bg-sidebar-foreground/30" />
                                    <i className="size-2 rounded-full bg-sidebar-foreground/30" />
                                </span>
                                {data.host}
                            </p>
                            {data.lines.length === 0 ? (
                                <p className="px-3 py-4 font-mono text-[12.5px] text-sidebar-foreground/70">No messages from the unit yet.</p>
                            ) : (
                                <ol aria-label="Messages from the unit" className="max-h-[420px] overflow-y-auto px-3 py-3 font-mono text-[12.5px] leading-relaxed scrollbar-pretty">
                                    {data.lines.map((line) => (
                                        <li key={line.id} className="grid grid-cols-[72px_minmax(0,1fr)] gap-x-2">
                                            <time className="text-sidebar-foreground/60">{line.at ? logTime(line.at, now) : '—'}</time>
                                            <span className="min-w-0 break-all">
                                                {line.out ? <b className="font-semibold text-sidebar-primary">obli&gt; </b> : <span aria-label="from the unit">← </span>}
                                                {line.text}
                                                {line.note ? <span className="block text-sidebar-foreground/60"># {line.note}</span> : null}
                                            </span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </div>
                        {data.location_hidden ? (
                            <p className="text-caption -mt-2 px-[18px] pb-4">Positions are hidden in these messages because the location can’t be shown.</p>
                        ) : null}
                    </Surface>
                    {data.commands.length === 0 ? (
                        <QuietLine title="Commands sent" text="No commands sent to this unit yet." />
                    ) : (
                        <Log
                            title="Commands sent"
                            sub="Newest first · each with the exact message and the unit’s reply"
                            limit={data.commands.length}
                            items={data.commands.map((command) => ({
                                key: command.id,
                                time: command.at ? logTime(command.at, now) : '—',
                                tone: command.tone,
                                title: command.title,
                                sub: (
                                    <>
                                        {command.sub}
                                        <code className="mt-1 block font-mono text-[12px] break-all text-muted-foreground">obli&gt; {command.message}</code>
                                        {command.reply ? <code className="block font-mono text-[12px] break-all text-muted-foreground">← {command.reply}</code> : null}
                                    </>
                                ),
                            }))}
                        />
                    )}
                </>
            }
            side={
                <>
                    {data.settings.rows.length > 0 ? (
                        <FactRows
                            title="Unit settings"
                            sub={data.settings.read_at ? `Read back from the unit ${seenTime(data.settings.read_at, now)}` : undefined}
                            action={onAskSettings ? { label: 'Ask the unit for its settings', onClick: onAskSettings } : null}
                            rows={data.settings.rows}
                        />
                    ) : (
                        <QuietLine
                            title="Unit settings"
                            text="Not read back from the unit yet."
                            action={onAskSettings ? { label: 'Ask the unit for its settings', onClick: onAskSettings } : null}
                        />
                    )}
                    <KnowList
                        title="About the console"
                        items={[
                            { tone: 'info', text: 'Sending a command needs the “Send a command to a care tracker” permission' },
                            { tone: 'info', text: 'Every command is kept here with who sent it and why' },
                            { tone: 'warning', text: <><b className="font-semibold">Restart the unit</b> asks you to confirm: it stops reporting for about 2 minutes</> },
                        ]}
                    />
                </>
            }
        />
    );
}
