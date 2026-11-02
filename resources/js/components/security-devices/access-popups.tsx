/**
 * The Access pop-ups (S&D rebuild drawings sdr-81 Give someone access, sdr-82 Add a card at a
 * reader, sdr-83 Turn off a card, sdr-84 Add visitor pass, sdr-90 Match UniFi people to staff),
 * plus the People tab's other row actions (change doors, turn someone off or back on, undo a
 * match) and cancelling a visitor pass. All on the pop-up kit, at kit widths.
 *
 * Every change is a job the Site's hub runs in UniFi Access (AccessPeopleController). The pop-up
 * waits on it and says how it ended in plain words: done, refused (with the hub's reason), or
 * "Result not confirmed" with a way to check, never an invitation to do it twice.
 */
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { VehicleSearchSelect } from '@/components/fleet-assets/vehicle-workspace/search-select';
import {
    PopupCallout,
    PopupConfirm,
    PopupFacts,
    PopupField,
    PopupForm,
    PopupLens,
    PopupSection,
    PopupViewer,
    usePopupClose,
    DiscardDraftConfirm,
} from '@/components/popup';
import { failure, nextWholeHour, nzInstant } from '@/components/security-devices/alert-popups';
import { ChoiceTiles, type ChoiceTile } from '@/components/security-devices/device-popups';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane, type WizardStep } from '@/components/wizard/shell';
import { formatTime, toDatetimeLocal } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import type { AccessConsoleInfo, PersonRow, StaffOption, VisitorRow } from '@/pages/security-devices/access/_types';
import { router } from '@inertiajs/react';
import axios from 'axios';
import {
    Ban,
    CreditCard,
    DoorOpen,
    HardHat,
    IdCard,
    KeyRound,
    Link as LinkIcon,
    LoaderCircle,
    Nfc,
    Plus,
    Send,
    Ticket,
    Unlink,
    UserRound,
    UserRoundCheck,
    UserRoundX,
    type LucideIcon,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

const BASE = '/security-devices/access';
const JSON_HEADERS = { headers: { Accept: 'application/json' } };
/** How often a pop-up asks how the hub is getting on. */
export const POLL_MS = 1500;

/** What the page reloads after a change, so it shows straight away. */
export function refreshAccess(then?: () => void) {
    router.reload({ only: ['doors', 'people', 'visitors', 'schedules', 'activity', 'counts', 'meters', 'consoles'], onFinish: () => then?.() });
}

/* ------------------------------------------------------------------ */
/*  Waiting on the hub                                                 */
/* ------------------------------------------------------------------ */

export type AccessJobPhase =
    | { phase: 'idle' }
    | { phase: 'sending' }
    | { phase: 'waiting'; job: string; state: 'queued' | 'sent'; message: string; since: string }
    | { phase: 'done'; message: string; job: string }
    | { phase: 'failed'; message: string; fields?: Record<string, string> }
    | { phase: 'unconfirmed'; message?: string };

type Started = { job: string; message: string };

/**
 * Send one change to the hub and follow it to the end. `run` takes the request; the hook polls
 * GET /security-devices/access/jobs/{uuid} until it isn't queued or sent.
 */
export function useAccessJob(onDone?: (message: string) => void) {
    const [job, setJob] = useState<AccessJobPhase>({ phase: 'idle' });
    const alive = useRef(true);
    const timer = useRef<number | null>(null);
    const done = useRef(onDone);
    useEffect(() => {
        done.current = onDone;
    });

    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            if (timer.current) window.clearTimeout(timer.current);
        };
    }, []);

    const poll = useCallback((uuid: string, message: string, since: string, misses = 0) => {
        timer.current = window.setTimeout(async () => {
            try {
                const { data } = await axios.get<{ state: string; message: string | null }>(`${BASE}/jobs/${uuid}`, JSON_HEADERS);
                if (!alive.current) return;
                if (data.state === 'queued' || data.state === 'sent') {
                    setJob({ phase: 'waiting', job: uuid, state: data.state, message, since });
                    poll(uuid, message, since, 0);
                } else if (data.state === 'succeeded') {
                    const text = data.message ?? 'Done in UniFi Access.';
                    setJob({ phase: 'done', message: text, job: uuid });
                    done.current?.(text);
                } else if (data.state === 'uncertain') {
                    setJob({ phase: 'unconfirmed', message: data.message ?? undefined });
                } else {
                    setJob({ phase: 'failed', message: data.message ?? 'UniFi Access didn’t make the change.' });
                }
            } catch {
                if (!alive.current) return;
                if (misses >= 5) setJob({ phase: 'unconfirmed' });
                else poll(uuid, message, since, misses + 1);
            }
        }, POLL_MS);
    }, []);

    const run = useCallback(
        async (request: () => Promise<{ data: Started }>) => {
            if (timer.current) window.clearTimeout(timer.current);
            setJob({ phase: 'sending' });
            try {
                const { data } = await request();
                if (!alive.current) return;
                const since = new Date().toISOString();
                setJob({ phase: 'waiting', job: data.job, state: 'queued', message: data.message, since });
                poll(data.job, data.message, since);
            } catch (error) {
                if (!alive.current) return;
                const result = failure(error, 'It couldn’t be sent to the hub. Try again.');
                if (result.unconfirmed) setJob({ phase: 'unconfirmed' });
                else if (result.fields) {
                    const first = result.fields.access ?? Object.values(result.fields)[0] ?? 'Check the details and try again.';
                    setJob({ phase: 'failed', message: first, fields: result.fields });
                } else setJob({ phase: 'failed', message: result.error ?? 'It couldn’t be sent to the hub. Try again.' });
            }
        },
        [poll],
    );

    const reset = useCallback(() => {
        if (timer.current) window.clearTimeout(timer.current);
        setJob({ phase: 'idle' });
    }, []);

    return { job, run, reset, sending: job.phase === 'sending', waiting: job.phase === 'waiting' };
}

const UNCONFIRMED = 'Result not confirmed: the hub may still be making the change. Look again in a minute before trying again, so it isn’t done twice.';

/** How the change is going, in one callout. Announced as it changes. */
export function JobStatus({ job, site, sentWords }: { job: AccessJobPhase; site?: string; sentWords?: ReactNode }) {
    if (job.phase === 'idle') return null;
    const hub = site ? `${site}’s hub` : 'the Site’s hub';
    const body: { tone: 'info' | 'success' | 'critical' | 'warning'; content: ReactNode } =
        job.phase === 'sending'
            ? { tone: 'info', content: 'Sending it to the hub…' }
            : job.phase === 'waiting'
              ? job.state === 'queued'
                  ? { tone: 'info', content: `Waiting for ${hub} to pick this up. It checks in every few seconds.` }
                  : { tone: 'info', content: sentWords ?? job.message }
              : job.phase === 'done'
                ? { tone: 'success', content: job.message }
                : job.phase === 'failed'
                  ? { tone: 'critical', content: job.message }
                  : { tone: 'warning', content: job.message ?? UNCONFIRMED };
    return (
        <div role="status" aria-live="polite">
            <PopupCallout tone={body.tone}>{body.content}</PopupCallout>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Small shared bits                                                  */
/* ------------------------------------------------------------------ */

function firstName(name: string): string {
    return name.split(/\s+/)[0] || name;
}

function possessive(name: string): string {
    return name.endsWith('s') ? `${name}’` : `${name}’s`;
}

/** A tick with its label and a hint (the pop-up kit's "switch & tick"). */
function TickRow({ id, label, hint, checked, disabled, onChange }: { id: string; label: string; hint?: ReactNode; checked: boolean; disabled?: boolean; onChange: (checked: boolean) => void }) {
    return (
        <div className="flex items-start gap-2.5">
            <Checkbox id={id} checked={checked} disabled={disabled} onCheckedChange={(next) => onChange(next === true)} className="mt-0.5" />
            <div className="min-w-0 flex-1">
                <label htmlFor={id} className={cn('block text-sm font-medium', disabled && 'text-muted-foreground')}>
                    {label}
                </label>
                {hint ? <p className="text-caption">{hint}</p> : null}
            </div>
        </div>
    );
}

/** A wizard step's question: lens, title, one line. */
function StepQuestion({ icon, title, line }: { icon: LucideIcon; title: string; line: string }) {
    return (
        <div className="mb-5 flex items-start gap-3.5">
            <PopupLens icon={icon} />
            <div className="min-w-0 pt-0.5">
                <h2 className="text-section-title leading-snug">{title}</h2>
                <p className="mt-0.5 text-sm text-muted-foreground">{line}</p>
            </div>
        </div>
    );
}

/** Runs `reset` each time the pop-up opens (not when the page's props refresh while it's open). */
function useOnOpen(open: boolean, reset: () => void) {
    const was = useRef(false);
    const latest = useRef(reset);
    useEffect(() => {
        latest.current = reset;
    });
    useEffect(() => {
        if (open && !was.current) latest.current();
        was.current = open;
    }, [open]);
}

/** Staff at a Site, loaded when a pop-up that picks them opens. */
export function useStaffOptions(open: boolean, staff: Record<string, StaffOption[]> | undefined) {
    const asked = useRef(false);
    useEffect(() => {
        if (open && staff === undefined && !asked.current) {
            asked.current = true;
            router.reload({ only: ['staff'] });
        }
    }, [open, staff]);
    return staff;
}

/* ------------------------------------------------------------------ */
/*  sdr-81 Give someone access                                         */
/* ------------------------------------------------------------------ */

type Who = 'staff' | 'contractor' | 'visitor';
type CardChoice = 'tap' | 'none';

const WHO_TILES: ChoiceTile<Who>[] = [
    { value: 'staff', label: 'A staff member', description: 'From HR', icon: IdCard },
    { value: 'contractor', label: 'Someone else who works here', description: 'A contractor, by name', icon: HardHat },
    { value: 'visitor', label: 'Someone visiting', description: 'Use a visitor pass instead', icon: Ticket },
];

const CARD_TILES: ChoiceTile<CardChoice>[] = [
    { value: 'tap', label: 'Tap a new card at a reader', description: 'Hold it on the reader you choose, straight after', icon: Nfc },
    { value: 'none', label: 'No card for now', description: 'Add one later from People', icon: Ban },
];

const GIVE_STEPS: WizardStep[] = [
    { key: 'person', label: 'Person', blurb: 'Who needs to get in', icon: UserRound },
    { key: 'doors', label: 'Doors', blurb: 'Which door groups', icon: DoorOpen },
    { key: 'card', label: 'Card', blurb: 'How they get in', icon: CreditCard },
    { key: 'check', label: 'Check', blurb: 'And finish', icon: UserRoundCheck },
];

export type AddedPerson = { personId: number; name: string; consoleId: number; readerId: string | null };

export function GiveAccess({
    open,
    consoles,
    staff,
    initialSiteId,
    onClose,
    onVisitorPass,
    onAddCard,
    onSeePeople,
}: {
    open: boolean;
    consoles: AccessConsoleInfo[];
    staff: Record<string, StaffOption[]> | undefined;
    initialSiteId: number | null;
    onClose: () => void;
    onVisitorPass: () => void;
    onAddCard: (added: AddedPerson) => void;
    onSeePeople: () => void;
}) {
    const loaded = useStaffOptions(open, staff);
    const [step, setStep] = useState(0);
    const [who, setWho] = useState<Who>('staff');
    const [consoleId, setConsoleId] = useState<string>('');
    const [userId, setUserId] = useState('');
    const [first, setFirst] = useState('');
    const [last, setLast] = useState('');
    const [policies, setPolicies] = useState<string[]>([]);
    const [card, setCard] = useState<CardChoice>('tap');
    const [reader, setReader] = useState('');
    const [added, setAdded] = useState<AddedPerson | null>(null);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const { job, run, reset, sending, waiting } = useAccessJob();

    useOnOpen(open, () => {
        const start = consoles.find((item) => item.site_id === initialSiteId) ?? (consoles.length === 1 ? consoles[0] : undefined);
        setStep(0);
        setWho('staff');
        setConsoleId(start ? String(start.id) : '');
        setUserId('');
        setFirst('');
        setLast('');
        setPolicies([]);
        setCard('tap');
        setReader('');
        setAdded(null);
        setErrors({});
        reset();
    });

    const target = consoles.find((item) => String(item.id) === consoleId) ?? null;
    const siteStaff = target ? (loaded?.[String(target.site_id)] ?? null) : null;
    const person = siteStaff?.find((option) => String(option.id) === userId) ?? null;
    const readers = target ? target.doors.flatMap((door) => door.readers.map((item) => ({ value: item.id, label: `${item.name} · ${door.name}` }))) : [];
    const name = who === 'staff' ? (person?.name ?? '') : `${first.trim()} ${last.trim()}`.trim();
    const chosenGroups = target ? target.policies.filter((policy) => policies.includes(policy.id)) : [];
    const dirty = step > 0 || userId !== '' || first !== '' || last !== '';
    const guard = usePopupClose({ dirty: dirty && job.phase !== 'done', processing: sending, onClose });

    const check = (index: number): Record<string, string> => {
        const next: Record<string, string> = {};
        if (index === 0) {
            if (!target) next.console = 'Choose the Site.';
            if (who === 'staff' && !person) next.user_id = 'Choose who needs access.';
            if (who === 'staff' && person?.in_access) next.user_id = 'They’re already in UniFi Access here. Change their doors on the People tab.';
            if (who === 'contractor' && first.trim() === '') next.first_name = 'Write their first name.';
        }
        if (index === 1 && policies.length === 0) next.policy_ids = 'Choose at least one door group.';
        if (index === 2 && card === 'tap' && reader === '') next.reader = 'Choose the reader you’ll tap the card on.';
        return next;
    };

    const next = () => {
        if (step === 0 && who === 'visitor') {
            onVisitorPass();
            return;
        }
        const found = check(step);
        setErrors(found);
        if (Object.keys(found).length > 0) return;
        if (step < 3) {
            setStep(step + 1);
            return;
        }
        if (!target) return;
        void run(() =>
            axios.post<Started>(
                `${BASE}/consoles/${target.id}/people`,
                who === 'staff' ? { user_id: Number(userId), policy_ids: policies } : { first_name: first.trim(), last_name: last.trim() || null, policy_ids: policies },
                JSON_HEADERS,
            ),
        );
    };

    // Once UniFi has them, find who they are here so their card can be added straight away.
    const doneJob = job.phase === 'done' ? job.job : null;
    useEffect(() => {
        if (doneJob === null || !target) return;
        let live = true;
        const readerId = card === 'tap' ? reader : null;
        axios
            .get<{ person_id: number | null }>(`${BASE}/jobs/${doneJob}/person`, JSON_HEADERS)
            .then(({ data }) => live && setAdded({ personId: data.person_id ?? 0, name, consoleId: target.id, readerId }))
            .catch(() => live && setAdded({ personId: 0, name, consoleId: target.id, readerId }))
            .finally(() => refreshAccess());
        return () => {
            live = false;
        };
        // Once per finished change.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [doneJob]);

    const primaryLabel = step === 0 && who === 'visitor' ? 'Add visitor pass' : step < 3 ? 'Continue' : 'Give access';
    const success =
        job.phase === 'done' ? (
            <WizardSuccessPane
                title={`${name || 'They'} can get in at ${target?.site ?? 'the Site'}`}
                blurb={
                    <>
                        {chosenGroups.map((group) => group.name).join(', ')}: {chosenGroups.map((group) => group.doors).join(', ')}.{' '}
                        {card === 'tap' ? 'Next, tap their new card on the reader.' : 'They have no card yet; add one from People when they need it.'}
                    </>
                }
                actions={
                    <>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Close
                        </Button>
                        <Button type="button" variant="outline" onClick={onSeePeople}>
                            See them in People
                        </Button>
                        {card === 'tap' ? (
                            <Button type="button" disabled={!added || added.personId === 0} onClick={() => added && onAddCard(added)}>
                                <Nfc aria-hidden="true" />
                                {added && added.personId === 0 ? 'Add their card from People' : 'Add their card now'}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                onClick={() => {
                                    reset();
                                    setStep(0);
                                    setUserId('');
                                    setFirst('');
                                    setLast('');
                                    setAdded(null);
                                }}
                            >
                                <Plus aria-hidden="true" />
                                Give someone else access
                            </Button>
                        )}
                    </>
                }
            />
        ) : undefined;

    return (
        <>
            <WizardShell
                open={open && !guard.asking}
                onClose={guard.requestClose}
                title="Give someone access"
                description="Which doors, and how they get in"
                railIcon={KeyRound}
                railTitle="Give someone access"
                railSub="Which doors, and how they get in"
                steps={GIVE_STEPS}
                stepIndex={step}
                onStepClick={(index) => {
                    if (index < step && !waiting && !sending) setStep(index);
                }}
                maxWidth="min(94vw, 1100px)"
                railExtra={<p className="rounded-lg border border-border bg-card px-3 py-2.5 text-caption">Doors always open from the inside. This is only about getting in.</p>}
                success={success}
                footerStart={
                    step === 0 ? (
                        <span className="text-caption">Fields marked * are required</span>
                    ) : (
                        <Button type="button" variant="outline" onClick={() => setStep(step - 1)} disabled={sending || waiting}>
                            Back
                        </Button>
                    )
                }
                footerEnd={
                    <>
                        <Button type="button" variant="outline" onClick={guard.requestClose} disabled={sending}>
                            {waiting ? 'Close' : 'Cancel'}
                        </Button>
                        <Button type="button" onClick={next} disabled={sending || waiting || (step === 3 && job.phase === 'unconfirmed')}>
                            {sending || waiting ? <LoaderCircle aria-hidden="true" className="animate-spin motion-reduce:animate-none" /> : null}
                            {sending || waiting ? 'Waiting for the hub…' : primaryLabel}
                        </Button>
                    </>
                }
            >
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <>
                            <StepQuestion icon={UserRound} title="Who needs access?" line="Staff come from HR. Their employee number goes into UniFi so they match by themselves." />
                            <div className="grid gap-5">
                                <PopupSection icon={UserRound} title="Person">
                                    <PopupField label="Who is it for" required>
                                        <ChoiceTiles label="Who is it for" columns={3} options={WHO_TILES} value={who} onChange={setWho} />
                                    </PopupField>
                                    {who === 'visitor' ? (
                                        <PopupCallout tone="info">Visitors get a pass for set doors and times that stops by itself. Continue to add one.</PopupCallout>
                                    ) : (
                                        <>
                                            {consoles.length > 1 ? (
                                                <PopupField label="Site" required error={errors.console}>
                                                    <VehicleSearchSelect
                                                        label="Site"
                                                        value={consoleId}
                                                        options={consoles.map((item) => ({ value: String(item.id), label: item.site }))}
                                                        onChange={(value) => {
                                                            setConsoleId(value);
                                                            setUserId('');
                                                            setPolicies([]);
                                                            setReader('');
                                                        }}
                                                        invalid={!!errors.console}
                                                    />
                                                </PopupField>
                                            ) : null}
                                            {who === 'staff' ? (
                                                <>
                                                    <PopupField label="Staff member" required error={errors.user_id}>
                                                        {!target ? (
                                                            <p className="text-subtle">Choose the Site first.</p>
                                                        ) : siteStaff === null ? (
                                                            <p className="text-subtle" role="status">
                                                                Getting staff at {target.site}…
                                                            </p>
                                                        ) : siteStaff.length === 0 ? (
                                                            <p className="text-subtle">Nobody in HR works at {target.site}.</p>
                                                        ) : (
                                                            <VehicleSearchSelect
                                                                label="Staff member"
                                                                value={userId}
                                                                options={siteStaff.map((option) => ({
                                                                    value: String(option.id),
                                                                    label: option.name,
                                                                    description: option.in_access ? 'Already in UniFi Access here' : option.sub,
                                                                }))}
                                                                onChange={setUserId}
                                                                invalid={!!errors.user_id}
                                                            />
                                                        )}
                                                    </PopupField>
                                                    {person ? (
                                                        <PopupFacts rows={[['Employee number', person.employee_number ? `${person.employee_number} · from HR, goes into UniFi` : 'None in HR · they’ll need matching by hand']]} />
                                                    ) : null}
                                                </>
                                            ) : (
                                                <PopupSection columns={2}>
                                                    <PopupField label="First name" htmlFor="sd-give-first" required error={errors.first_name}>
                                                        <Input id="sd-give-first" value={first} maxLength={60} onChange={(event) => setFirst(event.target.value)} />
                                                    </PopupField>
                                                    <PopupField label="Last name" htmlFor="sd-give-last" error={errors.last_name}>
                                                        <Input id="sd-give-last" value={last} maxLength={60} onChange={(event) => setLast(event.target.value)} />
                                                    </PopupField>
                                                </PopupSection>
                                            )}
                                        </>
                                    )}
                                </PopupSection>
                            </div>
                        </>
                    ) : null}

                    {step === 1 && target ? (
                        <>
                            <StepQuestion icon={DoorOpen} title="Which doors?" line="Pick the door groups people like them already have. The times each group works are set in UniFi Access." />
                            <PopupSection icon={DoorOpen} title={`Door groups at ${target.site}`}>
                                {errors.policy_ids ? <p className="text-sm text-status-critical">{errors.policy_ids}</p> : null}
                                {target.policies.length === 0 ? (
                                    <PopupCallout tone="warning">{target.site} has no door groups in UniFi Access yet. Add one there first.</PopupCallout>
                                ) : (
                                    target.policies.map((policy) => (
                                        <TickRow
                                            key={policy.id}
                                            id={`sd-give-policy-${policy.id}`}
                                            label={policy.name}
                                            hint={`${policy.doors} · ${policy.people === 1 ? '1 person' : `${policy.people} people`} in it now`}
                                            checked={policies.includes(policy.id)}
                                            onChange={(on) => setPolicies((now) => (on ? [...now, policy.id] : now.filter((id) => id !== policy.id)))}
                                        />
                                    ))
                                )}
                            </PopupSection>
                        </>
                    ) : null}

                    {step === 2 && target ? (
                        <>
                            <StepQuestion icon={CreditCard} title="How will they get in?" line={`Tap a new card on a reader at ${target.site} and it’s theirs.`} />
                            <div className="grid gap-5">
                                <PopupSection icon={CreditCard} title="Gets in with">
                                    <PopupField label="Card" required>
                                        <ChoiceTiles label="Card" columns={2} options={CARD_TILES} value={card} onChange={setCard} />
                                    </PopupField>
                                    {card === 'tap' ? (
                                        <PopupField label="Reader" required hint="the next screen waits for the card" error={errors.reader}>
                                            {readers.length === 0 ? (
                                                <p className="text-subtle">No readers are listed at {target.site} yet.</p>
                                            ) : (
                                                <VehicleSearchSelect label="Reader" value={reader} options={readers} onChange={setReader} invalid={!!errors.reader} />
                                            )}
                                        </PopupField>
                                    ) : null}
                                    <PopupFacts rows={[['Touch Pass (phone)', 'Set up in UniFi if they need one · Touch Passes cost money, so Oblivion never buys them']]} />
                                </PopupSection>
                            </div>
                        </>
                    ) : null}

                    {step === 3 && target ? (
                        <>
                            <StepQuestion icon={UserRoundCheck} title="Check and give access" line="Edit any part before it goes to UniFi." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard icon={UserRound} title="Person" onEdit={waiting || sending ? undefined : () => setStep(0)}>
                                    <ReviewRow label="Name" value={name} />
                                    <ReviewRow label="From" value={who === 'staff' ? 'HR' : 'Typed here (not staff)'} />
                                    {who === 'staff' ? <ReviewRow label="Employee number" value={person?.employee_number ?? undefined} /> : null}
                                </ReviewCard>
                                <ReviewCard icon={DoorOpen} title="Doors" onEdit={waiting || sending ? undefined : () => setStep(1)}>
                                    {chosenGroups.map((group) => (
                                        <ReviewRow key={group.id} label={group.name} value={group.doors} />
                                    ))}
                                </ReviewCard>
                                <ReviewCard icon={CreditCard} title="Card" onEdit={waiting || sending ? undefined : () => setStep(2)}>
                                    <ReviewRow label="Card" value={card === 'tap' ? `Tap at ${readers.find((item) => item.value === reader)?.label ?? 'a reader'}` : 'No card for now'} />
                                </ReviewCard>
                                <ReviewCard icon={Send} title="Where it goes">
                                    <ReviewRow label="Sent to" value={`UniFi Access at ${target.site}, through its hub`} />
                                </ReviewCard>
                            </div>
                            {who === 'contractor' ? (
                                <PopupCallout tone="info" className="mt-4">
                                    Someone typed in here isn’t linked to HR, so their access doesn’t end by itself. Turn them off on the People tab when they finish.
                                </PopupCallout>
                            ) : null}
                            <div className="mt-4">
                                <JobStatus job={job} site={target.site} />
                            </div>
                        </>
                    ) : null}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftConfirm open={open && guard.asking} what="Give someone access" onKeepEditing={guard.keepEditing} onDiscard={guard.discard} />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  sdr-82 Add a card at a reader                                      */
/* ------------------------------------------------------------------ */

export type CardTarget = { person: Pick<PersonRow, 'id' | 'name' | 'site' | 'doors'>; console: AccessConsoleInfo | null; readerId?: string | null };

export function AddCard({ open, target, onClose }: { open: boolean; target: CardTarget | null; onClose: () => void }) {
    const [reader, setReader] = useState('');
    const { job, run, reset, sending, waiting } = useAccessJob(() => refreshAccess());
    const readers = useMemo(
        () => (target?.console ? target.console.doors.flatMap((door) => door.readers.map((item) => ({ value: item.id, label: `${item.name} · ${door.name}`, short: item.name }))) : []),
        [target],
    );
    const chosen = readers.find((item) => item.value === reader);
    const readerName = chosen?.label ?? 'the reader';
    const tapOn = chosen ? `${chosen.short}${/reader$/i.test(chosen.short) ? '' : ' reader'}` : 'reader';

    const start = useCallback(
        (readerId: string) => {
            if (!target) return;
            void run(() => axios.post<Started>(`${BASE}/people/${target.person.id}/cards`, { reader_id: readerId }, JSON_HEADERS));
        },
        [run, target],
    );

    useOnOpen(open, () => {
        reset();
        const preset = target?.readerId ?? (readers.length === 1 ? readers[0].value : '');
        setReader(preset ?? '');
        if (target?.readerId) start(target.readerId);
    });

    const choosing = job.phase === 'idle' || job.phase === 'failed';
    const site = target?.person.site ?? 'the Site';
    const name = target?.person.name ?? 'them';
    const callout =
        job.phase === 'waiting' && job.state === 'sent'
            ? {
                  tone: 'info' as const,
                  content: (
                      <>
                          <b className="font-semibold">Tap the new card on the {tapOn} now…</b> Hold it flat on the reader until this says it’s added.
                      </>
                  ),
              }
            : job.phase === 'done'
              ? {
                    tone: 'success' as const,
                    content: (
                        <>
                            <b className="font-semibold">{job.message}</b> It’s {possessive(firstName(name))} straight away and opens {target?.person.doors ?? 'their doors'}.
                        </>
                    ),
                }
              : undefined;
    const facts: [string, ReactNode][] = [['For', `${name} · ${site}`]];
    if (reader) facts.push(['Reader', readerName]);
    if (job.phase === 'waiting') facts.push(['Waiting', `Asked at ${formatTime(job.since)} · the reader listens for a minute once the hub reaches it`]);

    return (
        <PopupViewer
            open={open}
            onClose={onClose}
            size="md"
            icon={job.phase === 'done' ? UserRoundCheck : Nfc}
            title="Add a card at a reader"
            description={`For ${name}${reader ? ` · ${readerName}` : ''}`}
            callout={callout}
            closeLabel={job.phase === 'done' ? 'Done' : waiting ? 'Close' : 'Cancel'}
            facts={facts}
            action={
                choosing ? (
                    <Button type="button" onClick={() => start(reader)} disabled={!reader || sending}>
                        <Nfc aria-hidden="true" />
                        {job.phase === 'failed' ? 'Try again' : 'Start listening'}
                    </Button>
                ) : null
            }
            footerStart={
                !choosing && job.phase !== 'done' && readers.length > 1 ? (
                    <Button type="button" variant="ghost" onClick={() => reset()} disabled={sending}>
                        Choose another reader
                    </Button>
                ) : undefined
            }
            testId="sd-access-add-card"
        >
            {choosing ? (
                <PopupField label="Reader" required hint="the reader listens for a new card for a minute">
                    {readers.length === 0 ? (
                        <p className="text-subtle">No readers are listed at {site} yet.</p>
                    ) : (
                        <VehicleSearchSelect label="Reader" value={reader} options={readers} onChange={setReader} />
                    )}
                </PopupField>
            ) : null}
            {job.phase !== 'done' && !(job.phase === 'waiting' && job.state === 'sent') ? <JobStatus job={job} site={site} /> : null}
            {choosing ? <p className="text-caption">Not at {site}? Hand the card to someone who is, or choose another reader.</p> : null}
        </PopupViewer>
    );
}

/* ------------------------------------------------------------------ */
/*  sdr-83 Turn off a card                                             */
/* ------------------------------------------------------------------ */

const CARD_REASONS = ['Lost', 'Stolen', 'Broken'] as const;

export function TurnOffCard({
    open,
    person,
    card,
    canAddCard,
    onClose,
    onAddCardNext,
}: {
    open: boolean;
    person: PersonRow | null;
    card: { id: number; display: string } | null;
    canAddCard: boolean;
    onClose: () => void;
    onAddCardNext: (person: PersonRow) => void;
}) {
    const [reason, setReason] = useState('');
    const [another, setAnother] = useState(true);
    const { job, run, reset, sending, waiting } = useAccessJob((message) => {
        toast.success(message);
        refreshAccess();
        onClose();
        if (another && canAddCard && person) onAddCardNext(person);
    });

    useOnOpen(open, () => {
        setReason('');
        setAnother(true);
        reset();
    });

    if (!person || !card) return null;
    const resident = person.kind === 'resident';
    const thing = resident ? 'fob' : 'card';
    const label = card.display.startsWith('••') ? `${thing} ${card.display}` : `this ${thing}`;

    return (
        <PopupConfirm
            open={open}
            onClose={() => {
                if (waiting) toast.info('Still waiting for the hub. Look at People again in a minute.');
                onClose();
            }}
            onConfirm={() => void run(() => axios.delete<Started>(`${BASE}/cards/${card.id}`, { ...JSON_HEADERS, data: { reason } }))}
            variant="destructive"
            icon={CreditCard}
            title={`Turn off ${possessive(person.name)} ${thing}?`}
            description={
                <>
                    <b className="font-semibold text-foreground">{label.charAt(0).toUpperCase() + label.slice(1)}</b> stops opening every door straight away.{' '}
                    {resident
                        ? 'Their other ways in stay as they are.'
                        : `${firstName(person.name)} can still get in another way if they have one (another card, a PIN or their phone).`}{' '}
                    If it turns up, add it again at a reader.
                </>
            }
            confirmLabel={`Turn off ${label} now`}
            cancelLabel={waiting ? 'Close' : 'Cancel'}
            processing={sending}
            processingLabel="Sending…"
            confirmDisabled={reason === '' || waiting}
            error={job.phase === 'failed' ? job.message : undefined}
            testId="sd-access-turn-off-card"
        >
            <PopupField label="What happened" required>
                <ToggleGroup type="single" variant="outline" value={reason} onValueChange={(next) => next && setReason(next)} aria-label="What happened" className="justify-start bg-card">
                    {CARD_REASONS.map((item) => (
                        <ToggleGroupItem key={item} value={item} className="px-4">
                            {item}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
            </PopupField>
            {canAddCard ? (
                <TickRow id="sd-access-new-card" label={`Give ${resident ? 'them' : firstName(person.name)} a new ${thing} next`} hint="opens Add a card at a reader" checked={another} onChange={setAnother} />
            ) : null}
            <p className="text-caption">If anyone tries the old {thing}, it’s refused and shows in Activity.</p>
            {job.phase === 'waiting' || job.phase === 'sending' || job.phase === 'unconfirmed' ? <JobStatus job={job} site={person.site} /> : null}
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  Turn someone off, or back on                                       */
/* ------------------------------------------------------------------ */

export function PersonOnOff({ open, person, onClose }: { open: boolean; person: PersonRow | null; onClose: () => void }) {
    const [reason, setReason] = useState('');
    const { job, run, reset, sending, waiting } = useAccessJob((message) => {
        toast.success(message);
        refreshAccess();
        onClose();
    });
    useOnOpen(open, () => {
        setReason('');
        reset();
    });
    if (!person) return null;
    const turningOff = person.status !== 'deactivated';
    const resident = person.kind === 'resident';

    return (
        <PopupConfirm
            open={open}
            onClose={() => {
                if (waiting) toast.info('Still waiting for the hub. Look at People again in a minute.');
                onClose();
            }}
            onConfirm={() =>
                void run(() => axios.post<Started>(`${BASE}/people/${person.id}/status`, { status: turningOff ? 'deactivated' : 'active', reason: reason.trim() }, JSON_HEADERS))
            }
            variant={turningOff ? 'destructive' : 'plain'}
            icon={turningOff ? UserRoundX : UserRoundCheck}
            title={turningOff ? `Turn off ${person.name} in UniFi Access?` : `Turn ${person.name} back on?`}
            description={
                turningOff
                    ? resident
                        ? `Their fob stops opening the doors of their own home at ${person.site} straight away. Turn them back on here at any time.`
                        : `Every card, PIN and phone pass they have stops opening doors at ${person.site} straight away. Turn them back on here at any time.`
                    : `Their cards, PINs and phone pass open their doors at ${person.site} again.`
            }
            confirmLabel={turningOff ? `Turn off ${firstName(person.name)}` : 'Turn back on'}
            cancelLabel={waiting ? 'Close' : 'Cancel'}
            processing={sending}
            processingLabel="Sending…"
            confirmDisabled={reason.trim() === '' || waiting}
            error={job.phase === 'failed' ? job.message : undefined}
            testId="sd-access-person-on-off"
        >
            <PopupField label="Why" htmlFor="sd-access-on-off-reason" required hint="kept in the change history with your name">
                <Textarea id="sd-access-on-off-reason" value={reason} maxLength={191} rows={2} onChange={(event) => setReason(event.target.value)} />
            </PopupField>
            {job.phase === 'waiting' || job.phase === 'sending' || job.phase === 'unconfirmed' ? <JobStatus job={job} site={person.site} /> : null}
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  Change doors                                                       */
/* ------------------------------------------------------------------ */

export function ChangeDoors({ open, person, console: target, onClose }: { open: boolean; person: PersonRow | null; console: AccessConsoleInfo | null; onClose: () => void }) {
    const [policies, setPolicies] = useState<string[]>([]);
    const { job, run, reset, sending, waiting } = useAccessJob((message) => {
        toast.success(message);
        refreshAccess();
        onClose();
    });
    useOnOpen(open, () => {
        setPolicies(person?.policy_ids ?? []);
        reset();
    });
    if (!person) return null;
    const changed = [...policies].sort().join('|') !== [...person.policy_ids].sort().join('|');

    return (
        <PopupForm
            open={open}
            onClose={() => {
                if (waiting) toast.info('Still waiting for the hub. Look at People again in a minute.');
                onClose();
            }}
            size="sm"
            icon={DoorOpen}
            title="Change doors"
            description={`Which door groups ${person.name} is in`}
            context={{ icon: UserRound, title: person.name, tag: person.site, note: 'From the person you chose' }}
            error={job.phase === 'failed' ? job.message : undefined}
            submitLabel={waiting ? 'Waiting for the hub…' : 'Save doors'}
            processing={sending}
            processingLabel="Sending…"
            submitDisabled={!changed || waiting || job.phase === 'unconfirmed'}
            dirty={changed && !waiting}
            onSubmit={() => void run(() => axios.put<Started>(`${BASE}/people/${person.id}/doors`, { policy_ids: policies }, JSON_HEADERS))}
            testId="sd-access-change-doors"
        >
            {!target || target.policies.length === 0 ? (
                <PopupCallout tone="warning">There are no door groups at {person.site} in UniFi Access yet.</PopupCallout>
            ) : (
                target.policies.map((policy) => (
                    <TickRow
                        key={policy.id}
                        id={`sd-access-doors-${policy.id}`}
                        label={policy.name}
                        hint={policy.doors}
                        checked={policies.includes(policy.id)}
                        disabled={sending || waiting}
                        onChange={(on) => setPolicies((now) => (on ? [...now, policy.id] : now.filter((id) => id !== policy.id)))}
                    />
                ))
            )}
            {policies.length === 0 && person.policy_ids.length > 0 ? <PopupCallout tone="warning">With no door group, {firstName(person.name)} can’t open any door.</PopupCallout> : null}
            {job.phase === 'waiting' || job.phase === 'unconfirmed' ? <JobStatus job={job} site={person.site} /> : null}
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  sdr-84 Add visitor pass                                            */
/* ------------------------------------------------------------------ */

function addHours(local: string, hours: number): string {
    const at = nzInstant(local);
    return 'iso' in at ? toDatetimeLocal(new Date(Date.parse(at.iso) + hours * 3_600_000)) : '';
}

export function AddVisitorPass({ open, consoles, initialSiteId, onClose }: { open: boolean; consoles: AccessConsoleInfo[]; initialSiteId: number | null; onClose: () => void }) {
    const [name, setName] = useState('');
    const [consoleId, setConsoleId] = useState('');
    const [doors, setDoors] = useState<string[]>([]);
    const [from, setFrom] = useState('');
    const [until, setUntil] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const { job, run, reset, sending, waiting } = useAccessJob((message) => {
        toast.success(`${message} Give it a PIN or QR code in UniFi Access, then send it to ${name.trim() || 'the visitor'}.`, { duration: 10_000 });
        refreshAccess();
        onClose();
    });

    useOnOpen(open, () => {
        const start = consoles.find((item) => item.site_id === initialSiteId) ?? (consoles.length === 1 ? consoles[0] : undefined);
        const begin = nextWholeHour(0);
        setName('');
        setConsoleId(start ? String(start.id) : '');
        setDoors([]);
        setFrom(begin);
        setUntil(addHours(begin, 3));
        setErrors({});
        reset();
    });

    const target = consoles.find((item) => String(item.id) === consoleId) ?? null;
    const dirty = name.trim() !== '' || doors.length > 0;

    const submit = () => {
        const found: Record<string, string> = {};
        if (name.trim() === '') found.first_name = 'Write the visitor’s name.';
        if (!target) found.console = 'Choose the Site.';
        if (doors.length === 0) found.door_ids = 'Choose at least one door.';
        const start = nzInstant(from);
        const end = nzInstant(until);
        if ('error' in start) found.starts_at = start.error;
        if ('error' in end) found.ends_at = end.error;
        if ('iso' in start && 'iso' in end) {
            if (Date.parse(end.iso) <= Date.parse(start.iso)) found.ends_at = 'The pass must end after it starts.';
            else if (Date.parse(end.iso) <= Date.now()) found.ends_at = 'The pass must end in the future.';
            else if (Date.parse(end.iso) - Date.parse(start.iso) > 31 * 86_400_000) found.ends_at = 'A visitor pass lasts a month at most.';
        }
        setErrors(found);
        if (Object.keys(found).length > 0 || !target || !('iso' in start) || !('iso' in end)) return;
        const [firstPart, ...rest] = name.trim().split(/\s+/);
        void run(() =>
            axios.post<Started>(
                `${BASE}/consoles/${target.id}/visitors`,
                { first_name: firstPart, last_name: rest.join(' ') || null, starts_at: start.iso, ends_at: end.iso, door_ids: doors },
                JSON_HEADERS,
            ),
        );
    };
    const fieldErrors = { ...errors, ...(job.phase === 'failed' ? job.fields : {}) };

    return (
        <PopupForm
            open={open}
            onClose={() => {
                if (waiting) toast.info('Still waiting for the hub. Look at Visitors again in a minute.');
                onClose();
            }}
            size="md"
            icon={Ticket}
            title="Add visitor pass"
            description="For set doors and times, and it stops by itself"
            callout={{ tone: 'info', content: 'Visitor passes are for contractors and visits Oblivion arranges. Residents’ own guests are their business.' }}
            error={job.phase === 'failed' && !job.fields ? job.message : job.phase === 'failed' && job.fields?.access ? job.fields.access : undefined}
            submitLabel={waiting ? 'Waiting for the hub…' : 'Add visitor pass'}
            processing={sending}
            processingLabel="Sending…"
            submitDisabled={waiting || job.phase === 'unconfirmed'}
            dirty={dirty && !waiting}
            onSubmit={submit}
            testId="sd-access-visitor-pass"
        >
            <PopupSection columns={2}>
                <PopupField label="Visitor’s name" htmlFor="sd-visitor-name" required error={fieldErrors.first_name ?? fieldErrors.last_name}>
                    <Input id="sd-visitor-name" value={name} maxLength={120} onChange={(event) => setName(event.target.value)} />
                </PopupField>
                <PopupField label="Site" required error={fieldErrors.console}>
                    <VehicleSearchSelect
                        label="Site"
                        value={consoleId}
                        options={consoles.map((item) => ({ value: String(item.id), label: item.site }))}
                        onChange={(value) => {
                            setConsoleId(value);
                            setDoors([]);
                        }}
                    />
                </PopupField>
                <PopupField label="Doors" required wide error={fieldErrors.door_ids}>
                    {!target ? (
                        <p className="text-subtle">Choose the Site first.</p>
                    ) : target.doors.length === 0 ? (
                        <p className="text-subtle">No doors are listed at {target.site} yet.</p>
                    ) : (
                        <ToggleGroup type="multiple" variant="outline" value={doors} onValueChange={setDoors} aria-label="Doors" className="flex-wrap justify-start bg-card">
                            {target.doors.map((door) => (
                                <ToggleGroupItem key={door.id} value={door.id} className="px-3">
                                    {door.name}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    )}
                </PopupField>
                <DateTimeField id="sd-visitor-from" label="From" value={from} onChange={setFrom} error={fieldErrors.starts_at} clearable={false} />
                <DateTimeField id="sd-visitor-until" label="Until" value={until} onChange={setUntil} error={fieldErrors.ends_at} clearable={false} />
            </PopupSection>
            <p className="text-caption">The pass stops by itself at the end. UniFi Access makes it for these doors and times; give it a PIN or QR code in UniFi Access to send to the visitor.</p>
            {job.phase === 'waiting' || job.phase === 'unconfirmed' ? <JobStatus job={job} site={target?.site} /> : null}
        </PopupForm>
    );
}

/* ------------------------------------------------------------------ */
/*  Cancel a visitor pass                                              */
/* ------------------------------------------------------------------ */

export function CancelVisitorPass({ open, visitor, onClose }: { open: boolean; visitor: VisitorRow | null; onClose: () => void }) {
    const { job, run, reset, sending, waiting } = useAccessJob((message) => {
        toast.success(message === 'Done in UniFi Access.' ? 'Visitor pass cancelled.' : message);
        refreshAccess();
        onClose();
    });
    useEffect(() => {
        if (open) reset();
    }, [open, reset]);
    if (!visitor) return null;
    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={() => void run(() => axios.delete<Started>(`${BASE}/visitors/${visitor.id}`, JSON_HEADERS))}
            icon={Ticket}
            title={`Cancel ${possessive(visitor.name)} visitor pass?`}
            description={`It stops opening doors at ${visitor.site} straight away. It stays in the door log.`}
            confirmLabel="Cancel pass"
            cancelLabel={waiting ? 'Close' : 'Keep pass'}
            processing={sending}
            processingLabel="Sending…"
            confirmDisabled={waiting}
            error={job.phase === 'failed' ? job.message : undefined}
            testId="sd-access-cancel-visitor"
        >
            {job.phase === 'waiting' || job.phase === 'unconfirmed' ? <JobStatus job={job} site={visitor.site} /> : null}
        </PopupConfirm>
    );
}

/* ------------------------------------------------------------------ */
/*  sdr-90 Match UniFi people to staff                                 */
/* ------------------------------------------------------------------ */

export function MatchPeople({
    open,
    people,
    staff,
    onClose,
}: {
    open: boolean;
    people: PersonRow[];
    staff: Record<string, StaffOption[]> | undefined;
    onClose: () => void;
}) {
    const loaded = useStaffOptions(open, staff);
    const [choice, setChoice] = useState<Record<number, string>>({});
    const [errors, setErrors] = useState<Record<number, string>>({});
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();

    useOnOpen(open, () => {
        setChoice(Object.fromEntries(people.map((person) => [person.id, person.suggestion ? String(person.suggestion.user_id) : ''])));
        setErrors({});
        setError(undefined);
    });

    const chosen = people.filter((person) => (choice[person.id] ?? '') !== '');
    const dirty = people.some((person) => (choice[person.id] ?? '') !== (person.suggestion ? String(person.suggestion.user_id) : ''));

    const submit = async () => {
        setProcessing(true);
        setError(undefined);
        const failed: Record<number, string> = {};
        let matched = 0;
        let lost = false;
        for (const person of chosen) {
            try {
                await axios.put(`${BASE}/people/${person.id}/match`, { user_id: Number(choice[person.id]) }, JSON_HEADERS);
                matched += 1;
            } catch (caught) {
                const result = failure(caught, 'It couldn’t be matched. Try again.');
                if (result.unconfirmed) lost = true;
                failed[person.id] = result.fields?.user_id ?? result.error ?? (result.unconfirmed ? UNCONFIRMED : 'It couldn’t be matched. Try again.');
            }
        }
        setProcessing(false);
        setErrors(failed);
        if (matched > 0) {
            toast.success(matched === 1 ? 'Matched 1 person.' : `Matched ${matched} people.`);
            refreshAccess();
        }
        if (Object.keys(failed).length === 0) onClose();
        else if (lost) setError(UNCONFIRMED);
    };

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={LinkIcon}
            title="Match UniFi people to staff"
            description={people.length === 1 ? '1 person in UniFi isn’t linked to your staff yet' : `${people.length} people in UniFi aren’t linked to your staff yet`}
            error={error}
            submitLabel={chosen.length === 1 ? 'Match 1 person' : `Match ${chosen.length} people`}
            processing={processing}
            processingLabel="Matching…"
            submitDisabled={chosen.length === 0}
            dirty={dirty}
            onSubmit={() => void submit()}
            testId="sd-access-match"
        >
            {people.map((person) => {
                const options = loaded?.[String(person.site_id)] ?? null;
                return (
                    <PopupSection key={person.id} icon={UserRound} title={`“${person.name}” in UniFi`} hint={person.site}>
                        <PopupFacts
                            rows={[['In UniFi', [person.gets_in, person.doors !== 'None' ? person.doors : null, person.employee_number ? `employee number ${person.employee_number}` : 'no employee number'].filter(Boolean).join(' · ')]]}
                        />
                        <PopupField
                            label="Staff member"
                            error={errors[person.id]}
                            hint={person.suggestion && choice[person.id] === String(person.suggestion.user_id) ? 'suggested: the employee number matches HR' : 'leave empty to skip for now'}
                        >
                            {options === null ? (
                                <p className="text-subtle" role="status">
                                    Getting staff at {person.site}…
                                </p>
                            ) : (
                                <VehicleSearchSelect
                                    label="Staff member"
                                    value={choice[person.id] ?? ''}
                                    options={options.map((option) => ({ value: String(option.id), label: option.name, description: option.sub }))}
                                    onChange={(value) => setChoice((now) => ({ ...now, [person.id]: value }))}
                                    invalid={!!errors[person.id]}
                                />
                            )}
                        </PopupField>
                    </PopupSection>
                );
            })}
            <PopupCallout tone="info">Matching links them to HR in Oblivion: their access then ends by itself after their last day. Nothing changes in UniFi.</PopupCallout>
        </PopupForm>
    );
}

/** Undo a match made by mistake: they stay in UniFi, just no longer linked to staff. */
export function UndoMatch({ open, person, onClose }: { open: boolean; person: PersonRow | null; onClose: () => void }) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | undefined>();
    useEffect(() => {
        if (open) setError(undefined);
    }, [open]);
    if (!person) return null;
    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={async () => {
                setProcessing(true);
                try {
                    await axios.put(`${BASE}/people/${person.id}/match`, { user_id: null }, JSON_HEADERS);
                    toast.success('No longer matched.');
                    refreshAccess();
                    onClose();
                } catch (caught) {
                    const result = failure(caught, 'It couldn’t be changed. Try again.');
                    setError(result.unconfirmed ? UNCONFIRMED : (result.fields?.user_id ?? result.error));
                } finally {
                    setProcessing(false);
                }
            }}
            variant="warning"
            icon={Unlink}
            title={`Unmatch ${person.name}?`}
            description="They keep their access in UniFi, but it no longer ends by itself when they leave. Match them again from People."
            confirmLabel="Unmatch"
            processing={processing}
            processingLabel="Changing…"
            error={error}
            testId="sd-access-undo-match"
        />
    );
}
