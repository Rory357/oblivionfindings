/**
 * Overview › Reports (S&D rebuild drawing pg-sdr-18b): the monthly report for managers and the board.
 * Time offline by Site, alerts by kind, how fast safety alerts are picked up, upkeep and camera picture
 * time, other downloads, then the camera viewing log for people who may see it. Download PDF or CSV;
 * the monthly email is a scheduled email Oblivion sends itself (pop-up: Change who gets it).
 *
 * Every figure comes from the server; a figure with nothing behind it says "Not available". Door event
 * exports aren't listed: Oblivion can't export door events yet, so there is nothing to log.
 */
import { EntityTable, type MenuItem } from '@/components/lists';
import { PopupForm, PopupSection } from '@/components/popup';
import { ActionLink, KnowList, PersonChip, PointBars, QuietLine, Ring, Surface, Tally } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Download, ExternalLink, FileText, Mail, Users } from 'lucide-react';
import { Fragment, useEffect, useState, type ReactNode } from 'react';
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { toast } from 'sonner';
import { BlockHead } from '../devices/_parts';
import type { CameraViewRow, ReportPerson, ReportsData } from './_types';

const SLICES = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

const TICK = { fill: 'var(--muted-foreground)', fontSize: 12 };

/** 38 → "38 min", 120 → "2 h", 125 → "2 h 5 min". */
export function minutesWords(minutes: number): string {
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return rest > 0 && hours < 10 ? `${hours} h ${rest} min` : `${hours} h`;
}

/** 2.1 → "2 min 6 s", 0.5 → "30 s". */
export function waitWords(minutes: number): string {
    const seconds = Math.round(minutes * 60);
    if (seconds < 60) return `${seconds} s`;
    const whole = Math.floor(seconds / 60);
    const rest = seconds % 60;
    return whole >= 10 || rest === 0 ? `${whole} min` : `${whole} min ${rest} s`;
}

const hoursWords = (hours: number) => `${hours.toLocaleString('en-NZ', { maximumFractionDigits: 1 })} h`;

const nzDay = (iso: string) => new Date(iso).toLocaleDateString('en-CA', { timeZone: WORKER_TIMEZONE });

/** "Asked to watch live" (Oblivion records the request, not whether a picture came through), or "Clip saved, 9:31 – 9:33 am". */
export function viewWhat(row: Pick<CameraViewRow, 'kind' | 'from' | 'to' | 'at'>, now: Date): string {
    if (row.kind === 'live') return 'Asked to watch live';
    if (!row.from || !row.to) return 'Clip saved';
    const from = nzDay(row.from) === nzDay(row.at) ? formatTime(row.from) : seenTime(row.from, now);
    const to = nzDay(row.to) === nzDay(row.from) ? formatTime(row.to) : seenTime(row.to, now);
    return `Clip saved, ${from} – ${to}`;
}

function names(people: ReportPerson[]): ReactNode {
    return people.map((person, index) => (
        <Fragment key={person.id}>
            {index > 0 ? (index === people.length - 1 ? ' and ' : ', ') : null}
            <b className="font-semibold text-foreground">{person.name}</b>
        </Fragment>
    ));
}

/** A donut with its share in the middle and a legend with the numbers (as Access's "How people got in"). */
function ShareDonut({
    title,
    sub,
    center,
    centerSub,
    parts,
    note,
}: {
    title: string;
    sub?: ReactNode;
    center: string;
    centerSub: string;
    parts: { label: string; value: number; show: string; fill: string }[];
    note?: ReactNode;
}) {
    const total = parts.reduce((sum, part) => sum + part.value, 0);
    let at = 0;
    const gradient =
        total > 0
            ? parts
                  .map((part) => {
                      const from = at;
                      at += (part.value / total) * 100;
                      return `${part.fill} ${from}% ${at}%`;
                  })
                  .join(', ')
            : 'var(--muted) 0% 100%';
    return (
        <Surface title={title} sub={sub}>
            <div className="flex items-center gap-5 px-[18px] py-4">
                <div
                    role="img"
                    aria-label={`${title}: ${parts.map((part) => `${part.label} ${part.show}`).join(', ')}`}
                    className="relative grid size-[120px] shrink-0 place-items-center rounded-full"
                    style={{ background: `conic-gradient(${gradient})` }}
                >
                    <span className="absolute inset-[18px] rounded-full bg-card" />
                    <span className="relative grid justify-items-center">
                        <b className="text-xl font-bold tracking-tight tabular-nums">{center}</b>
                        <small className="text-caption">{centerSub}</small>
                    </span>
                </div>
                <dl className="grid min-w-0 flex-1 gap-2 text-sm">
                    {parts.map((part) => (
                        <div key={part.label} className="flex items-center gap-2">
                            <span aria-hidden="true" className="size-2.5 shrink-0 rounded-full" style={{ background: part.fill }} />
                            <dt className="min-w-0 truncate">{part.label}</dt>
                            <dd className="ml-auto font-semibold tabular-nums">{part.show}</dd>
                        </div>
                    ))}
                </dl>
            </div>
            {note ? <p className="text-subtle border-t border-border px-[18px] py-3">{note}</p> : null}
        </Surface>
    );
}

/** The drawing's "next" box: the month's report, its downloads, and the monthly email. */
function ReportNextBox({ reports, now, onChangeEmail }: { reports: ReportsData; now: Date; onChangeEmail: (() => void) | null }) {
    const { month, email, links } = reports;
    const place = reports.site_label === 'All your Sites' ? '' : `${reports.site_label} · `;
    const sub = month.current
        ? `${place}${month.range} · the full month is ready on ${month.ready_at ? seenTime(month.ready_at, now) : 'the 1st'} · figures below run to today`
        : `${place}${month.range} ${month.key.slice(0, 4)} · the full month`;
    const change = onChangeEmail ? (
        <>
            {' · '}
            <ActionLink action={{ label: email.on ? 'Change who gets it' : 'Turn it on', onClick: onChangeEmail }} className="ml-0" />
        </>
    ) : null;

    return (
        <Surface aria-label="This month’s report">
            <div className="flex flex-wrap items-center gap-4 px-5 py-4">
                <span aria-hidden="true" className="grid size-[46px] shrink-0 place-items-center rounded-full bg-accent text-primary ring-2 ring-primary/60 ring-offset-2 ring-offset-card">
                    <FileText className="size-5" />
                </span>
                <div className="grid min-w-0 flex-1 gap-0.5">
                    <span className="sg-kick">Monthly report · {month.label}</span>
                    <h2 className="text-section-title">{month.short} report for managers and the board</h2>
                    <p className="text-subtle">{sub}</p>
                </div>
                <div className="flex shrink-0 flex-wrap gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <a href={links.csv} download>
                            Download CSV
                        </a>
                    </Button>
                    <Button size="sm" asChild>
                        <a href={links.pdf} download>
                            Download PDF
                        </a>
                    </Button>
                </div>
            </div>
            <p className="flex items-start gap-2.5 border-t border-dashed border-border bg-muted/50 px-5 py-2.5 text-sm text-muted-foreground">
                <span className="pt-1">
                    <Ring tone={email.on && email.recipients.length > 0 ? 'info' : 'none'} />
                </span>
                <span className="min-w-0">
                    {email.on && email.recipients.length > 0 ? (
                        <>Oblivion emails it on the 1st of each month to {names(email.recipients)} (a scheduled email){change}</>
                    ) : email.on ? (
                        <>The monthly email is on, but nobody chosen can see reports now{change}</>
                    ) : (
                        <>The monthly email is off{change}</>
                    )}
                </span>
            </p>
        </Surface>
    );
}

function OfflineBySite({ offline, short }: { offline: ReportsData['offline']; short: string }) {
    if (!offline) {
        return <QuietLine title="Time offline by Site" text="Not available: no router or gateway is watched." />;
    }
    const worst = [...offline.sites].reverse().filter((site) => site.minutes > 0);
    const note =
        worst.length === 0 ? (
            `Every router and gateway stayed online in ${short}.`
        ) : (
            <>
                <b className="font-semibold text-foreground">
                    {worst[0].name} {minutesWords(worst[0].minutes)}
                </b>
                {worst[0].still_offline ? ', still offline now.' : '.'}
                {worst[1] ? ` ${worst[1].name}: ${minutesWords(worst[1].minutes)}.` : ''}
            </>
        );
    return (
        <PointBars
            title="Time offline by Site"
            sub={`Minutes in ${short} · routers and gateways`}
            values={offline.sites.map((site) => site.minutes)}
            labels={offline.sites.map((site) => site.name)}
            note={note}
        />
    );
}

function AlertsByKind({ alerts, month }: { alerts: ReportsData['alerts']; month: ReportsData['month'] }) {
    if (alerts.total === 0) {
        return <QuietLine title="Alerts by kind" text={`No device alerts in ${month.short}.`} />;
    }
    const top = alerts.kinds.slice(0, 4);
    const rest = alerts.kinds.slice(4).reduce((sum, kind) => sum + kind.count, 0);
    const parts = [...top, ...(rest > 0 ? [{ label: 'Everything else', count: rest }] : [])].map((kind, index) => ({
        label: kind.label,
        value: kind.count,
        show: kind.count.toLocaleString('en-NZ'),
        fill: SLICES[index % SLICES.length],
    }));
    return (
        <ShareDonut
            title="Alerts by kind"
            sub={`${alerts.total.toLocaleString('en-NZ')} in ${month.short}`}
            center={month.label.slice(0, 3)}
            centerSub={month.key.slice(0, 4)}
            parts={parts}
        />
    );
}

function SafetyPickUp({ safety, short }: { safety: ReportsData['safety']; short: string }) {
    if (!safety) {
        return <QuietLine title="Time to acknowledge safety alerts" text={`No safety alerts in the 13 weeks to the end of ${short}.`} />;
    }
    const { month } = safety;
    const values = safety.weeks.map((week) => week.minutes).filter((value): value is number => value !== null);
    return (
        <Surface title="Time to acknowledge safety alerts" sub="Average per week · panic buttons, alarms, leaks, forced doors, care plan sensors">
            <div
                className="h-44 w-full px-[18px] pt-3"
                role="img"
                aria-label={`Average time to pick up safety alerts each week: ${safety.weeks.map((week) => `${week.label} ${week.minutes === null ? 'none' : waitWords(week.minutes)}`).join(', ')}`}
            >
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={safety.weeks} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                        <CartesianGrid vertical={false} stroke="var(--border)" />
                        <XAxis dataKey="label" tick={TICK} tickLine={false} axisLine={false} interval="preserveStartEnd" minTickGap={24} />
                        <YAxis tick={TICK} tickLine={false} axisLine={false} width={44} unit=" min" domain={[0, Math.max(1, Math.ceil(Math.max(0, ...values)))]} />
                        <Tooltip
                            contentStyle={{ background: 'var(--popover)', border: '1px solid var(--border)', borderRadius: 8 }}
                            formatter={(value) => [typeof value === 'number' ? waitWords(value) : 'No safety alerts', 'Average']}
                        />
                        <Line type="linear" dataKey="minutes" name="Average" stroke="var(--chart-1)" strokeWidth={2} dot={{ r: 3 }} connectNulls={false} isAnimationActive={false} />
                    </LineChart>
                </ResponsiveContainer>
            </div>
            <p className="text-subtle mt-2 border-t border-border px-[18px] py-3">
                {month.count === 0 ? (
                    `No safety alerts in ${short}; the graph shows the weeks before.`
                ) : (
                    <>
                        <b className="font-semibold text-foreground">
                            {month.average_minutes !== null ? `${waitWords(month.average_minutes)} on average in ${short}` : `None picked up in ${short}`}
                        </b>
                        , {month.count} {month.count === 1 ? 'safety alert' : 'safety alerts'}
                        {month.not_picked > 0 ? `, ${month.not_picked} not picked up.` : ', all picked up.'}
                    </>
                )}
            </p>
        </Surface>
    );
}

function CameraTime({ cameras }: { cameras: ReportsData['cameras'] }) {
    if (!cameras || cameras.percent === null) {
        return <QuietLine title="Camera picture time" text="Not available: no camera is watched." />;
    }
    const note =
        cameras.worst.length === 0
            ? 'Every camera had a picture the whole time.'
            : `${cameras.worst.map((camera) => `${camera.name} ${hoursWords(camera.hours)}`).join(', ')} without a picture.`;
    return (
        <ShareDonut
            title="Camera picture time"
            sub={`${cameras.cameras} ${cameras.cameras === 1 ? 'camera' : 'cameras'} · privacy hours don’t count against it`}
            center={`${cameras.percent}%`}
            centerSub="with a picture"
            parts={[
                { label: 'With a picture', value: cameras.with_hours, show: hoursWords(cameras.with_hours), fill: 'var(--status-success)' },
                { label: 'No picture', value: cameras.without_hours, show: hoursWords(cameras.without_hours), fill: 'var(--status-critical)' },
            ]}
            note={note}
        />
    );
}

function OtherDownloads({ links }: { links: ReportsData['links'] }) {
    const items = [
        { title: 'Device list', sub: 'Every device you can see, its make, model and status', href: links.devices },
        { title: 'Device events', sub: 'Every device event in the last 90 days', href: links.events },
        { title: 'Upkeep log', sub: 'Services, repairs, batteries and software work recorded', href: links.upkeep },
    ];
    return (
        <Surface title="Other downloads">
            <ul className="sg-list mt-2">
                {items.map((item) => (
                    <li key={item.title} className="flex items-center">
                        <a href={item.href} download className="sg-li min-w-0 flex-1" aria-label={`Download ${item.title} (CSV)`}>
                            <span className="sg-li-t">
                                <b>{item.title}</b>
                                <small>{item.sub}</small>
                            </span>
                            <StatusBadge variant="neutral" size="sm">
                                CSV
                            </StatusBadge>
                        </a>
                    </li>
                ))}
            </ul>
        </Surface>
    );
}

function CameraViewingLog({ views, short, now }: { views: NonNullable<ReportsData['camera_views']>; short: string; now: Date }) {
    const what = views.live && views.clips ? 'Every live view and saved clip' : views.live ? 'Every live view' : 'Every saved clip';
    const who = views.live && views.clips ? 'people who may see camera pictures or recordings' : views.live ? 'people who may see camera pictures' : 'people who may see recordings';
    const actionsFor = (row: CameraViewRow): MenuItem[] => [{ label: 'Open camera', icon: ExternalLink, onClick: () => router.visit(`/security-devices/devices/${row.camera_id}`) }];
    return (
        <section className="flex flex-col gap-3" aria-label="Camera viewing log">
            <BlockHead
                title="Camera viewing log"
                sub={`${what} in ${short}, with the reason given · ${who} only`}
                right={
                    views.total > 0 ? (
                        <Button variant="outline" size="sm" asChild>
                            <a href={views.export} download>
                                <Download className="size-4" />
                                Export
                            </a>
                        </Button>
                    ) : null
                }
            />
            {views.rows.length === 0 ? (
                <QuietLine text={views.live && views.clips ? `Nobody watched a camera live or saved a clip in ${short}.` : views.live ? `Nobody watched a camera live in ${short}.` : `Nobody saved a clip in ${short}.`} />
            ) : (
                <div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
                    <EntityTable<CameraViewRow>
                        className="rounded-none border-0 shadow-none"
                        rows={views.rows}
                        rowKey={(row) => row.key}
                        identityLabel="When"
                        identityWidth="170px"
                        identity={(row) => ({ name: seenTime(row.at, now), linkLabel: `Actions for ${row.who} at ${seenTime(row.at, now)}` })}
                        columns={[
                            { key: 'who', label: 'Who', width: '1fr', cell: (row) => <PersonChip name={row.who} /> },
                            { key: 'camera', label: 'Camera', width: '1.4fr', cell: (row) => row.camera },
                            { key: 'what', label: 'What', width: '1.2fr', cell: (row) => viewWhat(row, now) },
                            { key: 'reason', label: 'Reason', width: '1.6fr', cell: (row) => (row.reason !== '' ? row.reason : <span className="text-muted-foreground">No reason given</span>) },
                        ]}
                        actionsFor={actionsFor}
                        rowHeight="content"
                        minWidth={900}
                    />
                    {views.total > views.rows.length ? (
                        <p className="text-caption border-t border-border px-[18px] py-2.5">
                            The newest {views.rows.length} of {views.total.toLocaleString('en-NZ')}. Export has them all.
                        </p>
                    ) : null}
                </div>
            )}
        </section>
    );
}

/** A switch with its On / Off word (the settings pattern), as a row in a pop-up. */
function SwitchRow({ id, label, hint, checked, onChange }: { id: string; label: string; hint?: string; checked: boolean; onChange: (on: boolean) => void }) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-[10px] border border-border bg-muted/40 px-3.5 py-2.5">
            <div className="min-w-0">
                <label htmlFor={id} className="text-sm font-semibold text-foreground">
                    {label}
                </label>
                {hint ? <p className="text-caption mt-0.5">{hint}</p> : null}
            </div>
            <span className="flex shrink-0 items-center gap-2">
                <span className="text-sm text-muted-foreground" aria-hidden="true">
                    {checked ? 'On' : 'Off'}
                </span>
                <Switch id={id} checked={checked} onCheckedChange={onChange} />
            </span>
        </div>
    );
}

/** Change who gets the monthly email (and turn it on or off). Saved straight away and audited. */
export function MonthlyEmailPopup({ open, email, onClose }: { open: boolean; email: ReportsData['email']; onClose: () => void }) {
    const [on, setOn] = useState(email.on);
    const [chosen, setChosen] = useState<number[]>(email.recipients.map((person) => person.id));
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!open) return;
        setOn(email.on);
        setChosen(email.recipients.map((person) => person.id));
        setError(null);
    }, [open, email.on, email.recipients]);

    const initial = email.recipients.map((person) => person.id).sort((a, b) => a - b).join(',');
    const dirty = on !== email.on || [...chosen].sort((a, b) => a - b).join(',') !== initial;

    const submit = async () => {
        if (on && chosen.length === 0) {
            setError('Choose at least one person to email.');
            return;
        }
        setSaving(true);
        setError(null);
        try {
            await axios.patch('/security-devices/monthly-report/email', { on, recipients: chosen });
            toast.success(on ? 'The monthly email is on.' : 'The monthly email is off.');
            onClose();
            router.reload({ only: ['reports'] });
        } catch (failure) {
            const response = axios.isAxiosError(failure) ? failure.response : undefined;
            const errors = (response?.data as { errors?: Record<string, string[]> } | undefined)?.errors;
            setError(errors ? (Object.values(errors)[0]?.[0] ?? 'It wasn’t saved. Try again.') : response?.status === 403 ? 'You can’t change the monthly email.' : 'It wasn’t saved. Try again.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="sm"
            icon={Mail}
            title="Monthly report email"
            description={`It goes on the 1st of each month at 7:00 am · next on ${seenTime(email.next_at)}`}
            callout={{ tone: 'info', content: 'Each person gets only their own Sites’ figures, with the PDF. Who watched cameras is never emailed.' }}
            error={error ?? undefined}
            submitLabel="Save"
            processing={saving}
            processingLabel="Saving…"
            onSubmit={submit}
            dirty={dirty}
            draftName="Monthly report email"
        >
            <SwitchRow id="monthly-email-on" label="Email the report every month" checked={on} onChange={setOn} />
            <PopupSection icon={Users} title="Who gets it" hint="People who can see reports">
                {email.choices.length === 0 ? (
                    <p className="text-subtle">Nobody with an email address can see reports yet.</p>
                ) : (
                    <div className="grid gap-2.5" role="group" aria-label="Who gets it">
                        {email.choices.map((person) => {
                            const id = `monthly-email-${person.id}`;
                            return (
                                <label key={person.id} htmlFor={id} className="flex cursor-pointer items-center gap-2.5">
                                    <Checkbox
                                        id={id}
                                        checked={chosen.includes(person.id)}
                                        onCheckedChange={(checked) => setChosen((current) => (checked === true ? [...current, person.id] : current.filter((value) => value !== person.id)))}
                                    />
                                    <span className="text-sm font-medium">{person.name}</span>
                                </label>
                            );
                        })}
                    </div>
                )}
            </PopupSection>
        </PopupForm>
    );
}

export function ReportsBody({ reports, now }: { reports: ReportsData; now: Date }) {
    const [emailOpen, setEmailOpen] = useState(false);
    const short = reports.month.short;

    return (
        <div className="flex flex-col gap-5">
            <ReportNextBox reports={reports} now={now} onChangeEmail={reports.email.can_change ? () => setEmailOpen(true) : null} />
            <div className="grid items-start gap-5 lg:grid-cols-3">
                <OfflineBySite offline={reports.offline} short={short} />
                <AlertsByKind alerts={reports.alerts} month={reports.month} />
                <SafetyPickUp safety={reports.safety} short={short} />
            </div>
            <div className="grid items-start gap-5 lg:grid-cols-3">
                {reports.upkeep ? (
                    <Tally
                        title={`Upkeep in ${short}`}
                        keepZeros
                        items={[
                            { label: 'Batteries changed', value: reports.upkeep.batteries },
                            { label: 'Software updates', value: reports.upkeep.software },
                            { label: 'Settings changes', value: reports.upkeep.settings },
                        ]}
                    />
                ) : (
                    <QuietLine title={`Upkeep in ${short}`} text="Not available: there are no devices yet." />
                )}
                <CameraTime cameras={reports.cameras} />
                <OtherDownloads links={reports.links} />
            </div>
            {reports.camera_views ? <CameraViewingLog views={reports.camera_views} short={short} now={now} /> : null}
            <KnowList
                title="How long it’s kept"
                items={[
                    {
                        tone: 'info',
                        text: (
                            <>
                                Names on door events: <b className="font-semibold">{reports.kept.door_names_days} days</b>
                            </>
                        ),
                    },
                    {
                        tone: 'info',
                        text: (
                            <>
                                Camera viewing log: as long as your audit policy says, <b className="font-semibold">{reports.kept.audit_years === 1 ? '1 year' : `${reports.kept.audit_years} years`}</b>
                            </>
                        ),
                    },
                    {
                        tone: 'info',
                        text: (
                            <>
                                Monthly reports: any of the last <b className="font-semibold">{reports.kept.months} months</b>
                            </>
                        ),
                    },
                ]}
            />
            {reports.email.can_change ? <MonthlyEmailPopup open={emailOpen} email={reports.email} onClose={() => setEmailOpen(false)} /> : null}
        </div>
    );
}
