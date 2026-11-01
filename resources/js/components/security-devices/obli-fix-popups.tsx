/**
 * Obli fixes things (S&D Phase I): the help-session record (pop-up 96, and the 9d tab's main
 * column), opening its screen pictures with a reason (pop-up 94), a one-off request to IT
 * (pop-up 95) and turning a fix on for one computer (pop-up 93). Built on the pop-up kit at kit
 * widths; Cancel first and the main button named for what it does. Pictures are never in a
 * record: they load only after pop-up 94, for 30 minutes, and every view is logged.
 */
import { PopupCallout, PopupConfirm, PopupField, PopupForm, PopupSection, PopupViewer } from '@/components/popup';
import { Ring } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Command, CommandEmpty, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import {
    obliError,
    REQUEST_STATE,
    type ObliFixRequestPanel,
    type ObliFixRow,
    type ObliPictureView,
    type ObliSessionRecord,
} from '@/lib/security-devices/obli-fixes';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { Bot, Camera, Check, ChevronsUpDown, Cog, Laptop, Power, ShieldQuestion, X } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

const RING = { success: 'success', warning: 'warning', critical: 'critical', info: 'info', none: 'none' } as const;

function failureStatus(failure: unknown): number | undefined {
    return axios.isAxiosError(failure) ? failure.response?.status : undefined;
}

/* ------------------------------------------------------------------ */
/*  The conversation and the steps (9d main column, pop-up 96)         */
/* ------------------------------------------------------------------ */

function Bubble({ who, name, time, children }: { who: 'obli' | 'person'; name: string; time: string | null; children: ReactNode }) {
    return (
        <li className={cn('flex gap-2.5', who === 'person' && 'flex-row-reverse')}>
            {who === 'obli' ? (
                <span className="popup-lens popup-lens--sm mt-0.5 shrink-0" aria-hidden="true">
                    <Bot className="size-[15px]" />
                </span>
            ) : null}
            <div className={cn('max-w-[85%] rounded-[12px] px-3.5 py-2.5 text-sm text-foreground', who === 'obli' ? 'bg-muted' : 'bg-primary-fill/10')}>
                <p className="text-caption mb-1">
                    {name}
                    {time ? ` · ${time}` : ''}
                </p>
                {children}
            </div>
        </li>
    );
}

export function ObliConversation({ record, now }: { record: ObliSessionRecord; now: Date }) {
    return (
        <ol className="grid gap-3" aria-label="What was said">
            <Bubble who="person" name={record.who} time={seenTime(record.at, now)}>
                {record.problem ?? <span className="text-subtle">What was typed is no longer kept (chat retention).</span>}
            </Bubble>
            {record.kept && (record.found.length > 0 || record.plan.length > 0 || record.refusal) ? (
                <Bubble who="obli" name="Obli" time={record.planned_at ? seenTime(record.planned_at, now) : null}>
                    {record.found.length > 0 ? (
                        <ul className="mb-2 grid gap-1">
                            {record.found.map((item, index) => (
                                <li key={index} className="flex items-start gap-2">
                                    <Ring tone={item.tone === 'ok' ? 'success' : item.tone} />
                                    <span>{item.text}</span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                    {record.refusal ? <p>{record.refusal}</p> : null}
                    {record.plan.length > 0 ? (
                        <>
                            <p className="font-semibold">Here’s what I’ll do:</p>
                            <ol className="mt-1 list-decimal space-y-0.5 pl-5">
                                {record.plan.map((step, index) => (
                                    <li key={index}>
                                        {step.text}
                                        {step.note ? <span className="text-subtle"> · {step.note}</span> : null}
                                    </li>
                                ))}
                            </ol>
                            <p className="text-caption mt-2">
                                {record.approved_at ? `${record.who} pressed Fix it at ${seenTime(record.approved_at, now)}` : 'Not started: Fix it wasn’t pressed'}
                            </p>
                        </>
                    ) : null}
                </Bubble>
            ) : null}
            {record.outcome ? (
                <Bubble who="obli" name="Obli" time={record.outcome.at ? seenTime(record.outcome.at, now) : null}>
                    <p>{record.outcome.text}</p>
                </Bubble>
            ) : null}
        </ol>
    );
}

export function ObliSteps({ record, now }: { record: ObliSessionRecord; now: Date }) {
    if (record.steps.length === 0) return <p className="text-subtle">Obli didn’t touch the screen in this session.</p>;
    return (
        <ol className="grid gap-0 divide-y divide-border" aria-label="What Obli did">
            {record.steps.map((step) => (
                <li key={step.key} className="grid grid-cols-[auto_minmax(0,1fr)_auto_auto] items-start gap-3 py-2.5">
                    <span className="mt-0.5">
                        <Ring tone={RING[step.tone]} />
                    </span>
                    <div className="min-w-0">
                        <b className="text-sm font-semibold">{step.text}</b>
                        {step.note ? <p className="text-caption">{step.note}</p> : null}
                    </div>
                    <time className="text-caption tabular-nums">{seenTime(step.at, now)}</time>
                    {step.picture ? (
                        <span className="inline-flex items-center gap-1 text-caption" title="A screen picture was kept for this step">
                            <Camera aria-hidden="true" className="size-3.5" />
                            <span className="sr-only">Screen picture kept</span>
                        </span>
                    ) : (
                        <span />
                    )}
                </li>
            ))}
        </ol>
    );
}

export function pictureFoot(record: ObliSessionRecord): string {
    if (record.pictures.count === 0) return 'No screen pictures kept (they’re deleted after 7 days)';
    const pictures = record.pictures.count === 1 ? '1 screen picture' : `${record.pictures.count} screen pictures`;
    return `${pictures} · kept until ${record.pictures.deleted_on ? formatDate(record.pictures.deleted_on) : 'Not available'}, then deleted · only IT admins can open them, and each view is logged`;
}

/* ------------------------------------------------------------------ */
/*  Pop-up 94: open the pictures, with a reason                        */
/* ------------------------------------------------------------------ */

export function OpenPicturesPopup({
    open,
    record,
    now,
    onClose,
    onOpened,
}: {
    open: boolean;
    record: ObliSessionRecord | null;
    now: Date;
    onClose: () => void;
    onOpened: (view: ObliPictureView) => void;
}) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();
    useEffect(() => {
        if (open) {
            setReason('');
            setError(undefined);
        }
    }, [open]);
    if (!record) return null;
    const count = record.pictures.count === 1 ? '1 picture' : `${record.pictures.count} pictures`;

    const submit = async () => {
        setBusy(true);
        setError(undefined);
        try {
            const response = await axios.post<ObliPictureView>(`/security-devices/obli/sessions/${record.uuid}/pictures`, { reason: reason.trim() });
            onOpened(response.data);
        } catch (failure) {
            setError(obliError(failureStatus(failure)));
        } finally {
            setBusy(false);
        }
    };

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={() => void submit()}
            icon={Camera}
            title="Open Obli’s screen pictures?"
            description={
                <>
                    {count} from {record.who}’s help at {seenTime(record.at, now)} on the {record.device.name}.{' '}
                    <b className="font-semibold text-foreground">They may show client details.</b> Your name, the time and your reason are kept, and IT admins can see who opened
                    them.
                </>
            }
            context={{ title: 'Computer › Obli help', note: record.title, icon: Laptop }}
            confirmLabel="Open pictures"
            processing={busy}
            processingLabel="Opening…"
            confirmDisabled={reason.trim().length < 5}
            error={error}
            testId="sd-obli-pictures-popup"
        >
            <PopupField label="Why do you need to see them?" htmlFor="sd-obli-picture-reason" required hint="At least 5 characters">
                <Textarea id="sd-obli-picture-reason" value={reason} onChange={(event) => setReason(event.target.value)} placeholder="e.g. checking a fix that didn’t work" maxLength={500} rows={3} />
            </PopupField>
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  Pop-up 96: one help session                                        */
/* ------------------------------------------------------------------ */

export function ObliSessionPopup({
    open,
    uuid,
    initial,
    now,
    onClose,
}: {
    open: boolean;
    uuid: string | null;
    initial?: ObliSessionRecord | null;
    now: Date;
    onClose: () => void;
}) {
    const [record, setRecord] = useState<ObliSessionRecord | null>(initial ?? null);
    const [failed, setFailed] = useState<string | null>(null);
    const [asking, setAsking] = useState(false);
    const [view, setView] = useState<ObliPictureView | null>(null);
    const [reload, setReload] = useState(0);

    useEffect(() => {
        if (!open || !uuid) return;
        setView(null);
        setFailed(null);
        if (initial && initial.uuid === uuid && reload === 0) {
            setRecord(initial);
            return;
        }
        let gone = false;
        setRecord(null);
        axios
            .get<ObliSessionRecord>(`/security-devices/obli/sessions/${uuid}`)
            .then((response) => !gone && setRecord(response.data))
            .catch((failure) => !gone && setFailed(obliError(failureStatus(failure))));
        return () => {
            gone = true;
        };
    }, [open, uuid, initial, reload]);

    return (
        <>
            <PopupViewer
                open={open && !asking}
                onClose={onClose}
                size="lg"
                icon={Bot}
                title={record ? `Obli help · ${record.title}` : 'Obli help'}
                description={record ? `${record.who} · ${record.device.name} · ${seenTime(record.at, now)} · ${record.sub.split(' · ').slice(1).join(' · ')}` : undefined}
                testId="sd-obli-session-popup"
                action={
                    record ? (
                        <>
                            {record.ticket ? (
                                <Button type="button" variant="outline" onClick={() => router.visit(record.ticket!.href)}>
                                    Open as IT ticket
                                </Button>
                            ) : null}
                            {record.pictures.can_open && !view ? (
                                <Button type="button" onClick={() => setAsking(true)}>
                                    <Camera aria-hidden="true" className="size-4" />
                                    Open screen pictures
                                </Button>
                            ) : null}
                        </>
                    ) : undefined
                }
            >
                {failed ? (
                    <PopupCallout tone="critical">
                        <span>{failed} </span>
                        <Button type="button" variant="link" className="h-auto p-0" onClick={() => setReload((value) => value + 1)}>
                            Try again
                        </Button>
                    </PopupCallout>
                ) : !record ? (
                    <p className="text-subtle" role="status">
                        Loading the session…
                    </p>
                ) : (
                    <>
                        <div className="flex items-center gap-2">
                            <StatusBadge variant={record.badge.tone}>{record.badge.label}</StatusBadge>
                        </div>
                        <PopupSection title={record.title}>
                            <ObliConversation record={record} now={now} />
                        </PopupSection>
                        <PopupSection title="What Obli did" hint="Each step, with whether a screen picture was kept to check it">
                            <ObliSteps record={record} now={now} />
                            <p className="text-caption">{pictureFoot(record)}</p>
                        </PopupSection>
                        {view ? (
                            <PopupSection title="Screen pictures" hint={`Open for 30 minutes, until ${seenTime(view.expires_at, now)} · your view is logged`}>
                                <ul className="grid gap-3 sm:grid-cols-2">
                                    {view.pictures.map((picture) => (
                                        <li key={picture.id} className="grid gap-1">
                                            <img
                                                src={picture.url}
                                                alt={`Obli’s screen picture, step ${picture.turn}, ${seenTime(picture.taken_at, now)}`}
                                                width={picture.width}
                                                height={picture.height}
                                                loading="lazy"
                                                className="h-auto w-full rounded-[10px] border border-border"
                                            />
                                            <span className="text-caption">
                                                Turn {picture.turn} · {seenTime(picture.taken_at, now)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </PopupSection>
                        ) : null}
                        {record.views.length > 0 ? (
                            <PopupSection title="Who opened the pictures">
                                <ul className="grid gap-1 text-sm">
                                    {record.views.map((item, index) => (
                                        <li key={index}>
                                            <b className="font-semibold">{item.who}</b> · {seenTime(item.at, now)} · <span className="text-subtle">{item.reason}</span>
                                        </li>
                                    ))}
                                </ul>
                            </PopupSection>
                        ) : null}
                    </>
                )}
            </PopupViewer>
            <OpenPicturesPopup
                open={open && asking}
                record={record}
                now={now}
                onClose={() => setAsking(false)}
                onOpened={(opened) => {
                    setView(opened);
                    setAsking(false);
                }}
            />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Pop-up 95: Obli asks IT to approve a one-off fix                   */
/* ------------------------------------------------------------------ */

function Terminal({ host, lines }: { host: string; lines: string[] }) {
    return (
        <div className="grid gap-1.5">
            <p className="font-mono text-[12px] text-muted-foreground">{host}</p>
            <pre
                tabIndex={0}
                aria-label="The exact commands"
                className="scrollbar-pretty max-h-56 overflow-auto rounded-lg border border-border bg-muted p-3 font-mono text-[13px] leading-snug [overflow-wrap:anywhere] whitespace-pre-wrap"
            >
                {lines.length > 0 ? lines.join('\n') : 'No commands were given.'}
            </pre>
        </div>
    );
}

export function FixRequestPopup({ open, requestId, now, onClose }: { open: boolean; requestId: number | null; now: Date; onClose: () => void }) {
    const [panel, setPanel] = useState<ObliFixRequestPanel | null>(null);
    const [failed, setFailed] = useState<string | null>(null);
    const [busy, setBusy] = useState<'approve' | 'decline' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [result, setResult] = useState<{ state: string; route: string | null; governed_href: string | null } | null>(null);
    const [reload, setReload] = useState(0);

    useEffect(() => {
        if (!open || requestId === null) return;
        let gone = false;
        setPanel(null);
        setFailed(null);
        setError(null);
        setResult(null);
        axios
            .get<ObliFixRequestPanel>(`/security-devices/obli/requests/${requestId}`)
            .then((response) => !gone && setPanel(response.data))
            .catch((failure) => !gone && setFailed(obliError(failureStatus(failure))));
        return () => {
            gone = true;
        };
    }, [open, requestId, reload]);

    const decide = async (decision: 'approve' | 'decline') => {
        if (!panel) return;
        setBusy(decision);
        setError(null);
        try {
            const response = await axios.post(`/security-devices/obli/requests/${panel.id}/decide`, { decision });
            setResult(response.data);
        } catch (failure) {
            setError(obliError(failureStatus(failure)));
        } finally {
            setBusy(null);
        }
    };
    const state = result ? (result.state as ObliFixRequestPanel['state']) : panel?.state;
    const route = result?.route ?? panel?.route ?? null;
    const governed = result?.governed_href ?? panel?.governed_href ?? null;

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="md"
            icon={ShieldQuestion}
            title="Obli asks: approve a one-off fix"
            description="It isn’t on the fix list, so it needs IT this once"
            context={panel ? { title: panel.device.name, tag: panel.asker, note: `Asked ${seenTime(panel.asked_at, now)}`, icon: Laptop } : undefined}
            testId="sd-obli-request-popup"
            footerStart={
                panel?.can_decide && !result ? (
                    <Button type="button" variant="ghost" disabled={busy !== null} onClick={() => void decide('decline')}>
                        <X aria-hidden="true" className="size-4" />
                        {busy === 'decline' ? 'Declining…' : 'Decline'}
                    </Button>
                ) : undefined
            }
            action={
                panel?.can_decide && !result ? (
                    <Button type="button" disabled={busy !== null} onClick={() => void decide('approve')}>
                        <Check aria-hidden="true" className="size-4" />
                        {busy === 'approve' ? 'Approving…' : 'Approve once'}
                    </Button>
                ) : state === 'approved' && route === 'governed' && governed ? (
                    <Button type="button" onClick={() => router.visit(governed)}>
                        Open the fix form
                    </Button>
                ) : undefined
            }
        >
            {failed ? (
                <PopupCallout tone="critical">
                    <span>{failed} </span>
                    <Button type="button" variant="link" className="h-auto p-0" onClick={() => setReload((value) => value + 1)}>
                        Try again
                    </Button>
                </PopupCallout>
            ) : !panel ? (
                <p className="text-subtle" role="status">
                    Loading the request…
                </p>
            ) : (
                <>
                    {error ? <PopupCallout tone="critical">{error}</PopupCallout> : null}
                    {state === 'approved' && route === 'governed' ? (
                        <PopupCallout tone="success">
                            <b className="font-semibold">Approved once.</b> Run it from the computer’s fix form: your own permissions, the step-up check and {panel.asker}’s Allow still
                            apply. Obli tells {panel.asker} how it went.
                        </PopupCallout>
                    ) : state === 'approved' ? (
                        <PopupCallout tone="success">
                            <b className="font-semibold">Approved once.</b> There’s no Oblivion action for these commands, so nothing runs automatically: Obli tells {panel.asker} that IT
                            will do it by hand.
                        </PopupCallout>
                    ) : state === 'declined' ? (
                        <PopupCallout tone="info">Declined. Obli tells {panel.asker} and raises an IT ticket with what it found.</PopupCallout>
                    ) : state !== 'requested' || !panel.live ? (
                        <PopupCallout tone="info">This request is {REQUEST_STATE[state ?? 'expired'].label.toLowerCase()}; nothing more can be done from here.</PopupCallout>
                    ) : null}
                    <ol className="grid gap-3" aria-label="What was said">
                        <Bubble who="person" name={panel.asker} time={panel.problem_at ? seenTime(panel.problem_at, now) : null}>
                            {panel.problem ?? <span className="text-subtle">No longer kept.</span>}
                        </Bubble>
                        <Bubble who="obli" name="Obli" time={seenTime(panel.asked_at, now)}>
                            <p>{panel.what ?? 'Needs an administrator.'}</p>
                            <p className="text-caption mt-1">{panel.fix ? `On IT’s fix list: “${panel.fix.label}”` : 'Not on IT’s fix list'}</p>
                        </Bubble>
                    </ol>
                    <Terminal host={panel.host} lines={panel.commands} />
                    {panel.decided ? (
                        <p className="text-caption">
                            {REQUEST_STATE[panel.state].label} by {panel.decided.by} · {seenTime(panel.decided.at, now)}
                        </p>
                    ) : null}
                    {!panel.can_decide && panel.state === 'requested' && panel.live ? <p className="text-subtle">Only IT people who manage Obli’s fixes can approve this.</p> : null}
                </>
            )}
        </PopupViewer>
    );
}

/* ------------------------------------------------------------------ */
/*  Pop-up 93: turn a fix on, for one computer first                   */
/* ------------------------------------------------------------------ */

function ComputerPicker({
    value,
    computers,
    onChange,
    invalid,
}: {
    value: number | null;
    computers: { id: number; name: string; site: string | null }[];
    onChange: (id: number) => void;
    invalid?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const chosen = computers.find((computer) => computer.id === value) ?? null;
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    id="sd-obli-fix-computer"
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-invalid={invalid || undefined}
                    className="w-full justify-between font-normal"
                >
                    <span className={cn('truncate', !chosen && 'text-muted-foreground')}>{chosen ? `${chosen.name}${chosen.site ? ` · ${chosen.site}` : ''}` : 'Choose one computer to try it on'}</span>
                    <ChevronsUpDown aria-hidden="true" className="size-4 shrink-0 opacity-60" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
                <Command>
                    <CommandInput placeholder="Search computers" />
                    <CommandList>
                        <CommandEmpty>No computer with Obli matches.</CommandEmpty>
                        {computers.map((computer) => (
                            <CommandItem
                                key={computer.id}
                                value={`${computer.name} ${computer.site ?? ''} ${computer.id}`}
                                onSelect={() => {
                                    onChange(computer.id);
                                    setOpen(false);
                                }}
                            >
                                <Check aria-hidden="true" className={cn('size-4', computer.id === value ? 'opacity-100' : 'opacity-0')} />
                                <span className="truncate">{computer.name}</span>
                                {computer.site ? <span className="text-caption ml-auto">{computer.site}</span> : null}
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export function TurnOnFixPopup({
    open,
    fix,
    computers,
    onClose,
}: {
    open: boolean;
    fix: ObliFixRow | null;
    computers: { id: number; name: string; site: string | null }[];
    onClose: () => void;
}) {
    const [deviceId, setDeviceId] = useState<number | null>(null);
    const [reason, setReason] = useState('');
    const [read, setRead] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();
    useEffect(() => {
        if (open) {
            setDeviceId(null);
            setReason('');
            setRead(false);
            setError(undefined);
        }
    }, [open, fix?.key]);
    if (!fix) return null;
    const already = new Set(fix.where.map((place) => place.device_id));
    const choices = computers.filter((computer) => !already.has(computer.id));
    const ready = deviceId !== null && reason.trim().length >= 10 && read;

    const submit = async () => {
        if (!ready) return;
        setBusy(true);
        setError(undefined);
        try {
            await axios.post('/security-devices/setup/obli/fixes/on', { fix_key: fix.key, device_id: deviceId, reason: reason.trim(), read: true });
            onClose();
            router.reload({ only: ['fixes'] });
        } catch (failure) {
            const status = failureStatus(failure);
            setError(status === 403 ? 'You need permission to manage Obli’s fixes and this computer’s service actions.' : obliError(status));
        } finally {
            setBusy(false);
        }
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="lg"
            icon={fix.icon === 'power' ? Power : Cog}
            title={`Turn on “${fix.label}”`}
            description="Check what it does before Obli can use it"
            context={{ title: fix.label, tag: fix.runs_as, note: fix.sub, icon: fix.icon === 'power' ? Power : Cog }}
            callout={{
                tone: 'warning',
                content: (
                    <>
                        <b className="font-semibold">Turn it on for one computer first.</b> If that computer’s {fix.icon === 'power' ? 'restart rules are' : 'service actions are'} off,
                        this turns them on too (asking the person first), so IT can use them there as well.
                    </>
                ),
            }}
            error={error}
            submitLabel="Turn on for 1 computer"
            processing={busy}
            processingLabel="Turning on…"
            submitDisabled={!ready}
            onSubmit={() => void submit()}
            dirty={deviceId !== null || reason.trim() !== ''}
            draftName={`Turn on “${fix.label}”`}
            testId="sd-obli-turn-on-popup"
        >
            <Terminal host={`${fix.label} · runs as the Oblivion service`} lines={fix.script} />
            <PopupSection>
                <PopupField label="Where Obli may use it" htmlFor="sd-obli-fix-computer" required wide hint={choices.length === 0 ? 'It’s already on for every computer with Obli you can see.' : 'One computer first; turn it on for more once it has worked.'}>
                    <ComputerPicker value={deviceId} computers={choices} onChange={setDeviceId} />
                </PopupField>
                <PopupField label="Before it runs" wide>
                    <p className="text-sm">Obli shows its plan, says what this fix does, and waits for Fix it. IT then runs it once from the computer’s fix form.</p>
                </PopupField>
                <PopupField label="Why you’re turning it on" htmlFor="sd-obli-fix-reason" required wide hint="Kept with the change, at least 10 characters">
                    <Textarea id="sd-obli-fix-reason" value={reason} onChange={(event) => setReason(event.target.value)} maxLength={500} rows={2} placeholder="e.g. printers at Aurora House keep getting stuck" />
                </PopupField>
                <label htmlFor="sd-obli-fix-read" className="flex items-center gap-2 text-sm">
                    <Checkbox id="sd-obli-fix-read" checked={read} onCheckedChange={(next) => setRead(next === true)} />
                    I’ve read what it does
                </label>
            </PopupSection>
        </PopupForm>
    );
}
