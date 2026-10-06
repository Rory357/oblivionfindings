/* Medication competency exemptions (eMAR P11 v5 `dialogs-elig.tsx`
 * ExemptionWizard and EndExemption). Granting is house-scoped: one person,
 * their own house, a reason and an end date within the organisation's longest
 * exemption (Settings › Staff & PINs › Exemption limit); never for yourself.
 * The server re-checks all of it and audits each grant and early end.
 *
 * Staff eligibility › Exemptions (P11 chunk 6) hosts both dialogs.
 *
 * v5's "What this does" also said an exempt person can't witness and that the
 * restricted and area rules still apply. Neither is true in the app today
 * (witnessing doesn't check competency; those rules read an assessment), so
 * only what the exemption does is listed. */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { SettingsModal } from '@/components/settings/settings-modal';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, StepHead } from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { formatDateOnly, toDateInput, WORKER_TIMEZONE } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    FileText,
    ShieldCheck,
    User,
} from 'lucide-react';
import { useState } from 'react';
import { RecordPicker } from '../settings/_ui';

/** Someone at the approver's houses, and whether they can be exempted. */
export type ExemptionPerson = {
    id: number;
    name: string;
    role: string | null;
    /** Their own house (HR profile): an exemption covers that house. */
    house_id: number | null;
    house: string | null;
    /** "No current assessment", "Assessment ended", … */
    status: string;
    /** False when they have a current assessment or are already exempt. */
    ok: boolean;
    why?: string;
};

export type ActiveExemption = {
    id: number;
    person: string;
    house: string;
    until: string;
};

const firstName = (name: string) => name.split(' ')[0] || name;

/** "2026-10-02" plus whole days, as a date string. */
export const addDays = (day: string, days: number) => {
    const [y, m, d] = day.split('-').map(Number);
    if (!y || !m || !d) return day;
    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
};

const STEPS = [
    { key: 'who', label: 'Person & reason', blurb: 'Who and why', icon: User },
    { key: 'dates', label: 'Dates', blurb: 'From and until', icon: FileText },
    {
        key: 'review',
        label: 'Review & grant',
        blurb: 'What it allows',
        icon: Check,
    },
];

type Draft = { who: string; reason: string; from: string; until: string };

/* ── Grant an exemption: WizardShell — Person & reason · Dates · Review & grant ── */
export function ExemptionWizard({
    people,
    longestDays,
    limitReviewed,
    approver,
    who,
    onClose,
}: {
    people: ExemptionPerson[];
    /** Settings › Staff & PINs › Exemption limit. */
    longestDays: number;
    /** Has someone saved or kept the limit, or is it still the default? */
    limitReviewed: boolean;
    approver: string;
    /** Open with this person chosen, when they can be exempted. */
    who?: number;
    onClose: () => void;
}) {
    const today = toDateInput(new Date());
    const [X, setX] = useState<Draft>({
        who: who && people.some((p) => p.id === who && p.ok) ? String(who) : '',
        reason: '',
        from: today,
        until: '',
    });
    const [step, setStep] = useState(0);
    const [errs, setErrs] = useState<Record<string, string>>({});
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [guard, setGuard] = useState(false);
    const person = people.find((p) => String(p.id) === X.who) ?? null;
    const last = addDays(X.from, longestDays);
    const up = (patch: Partial<Draft>) => {
        setX({ ...X, ...patch });
        setDirty(true);
    };
    const errsFor = (s: number) => {
        const e: Record<string, string> = {};
        if (s === 0) {
            if (!X.who) e.who = 'Choose who the exemption is for.';
            if (X.reason.trim().length < 10)
                e.reason = 'Say why, in at least 10 characters.';
        }
        if (s === 1) {
            if (X.from < today)
                e.from = 'An exemption can’t start in the past.';
            if (!X.until || X.until <= X.from)
                e.until = 'The end date must be after the start date.';
            else if (X.until > last)
                e.until = `That’s longer than your organisation allows (${longestDays} ${longestDays === 1 ? 'day' : 'days'}). Choose ${formatDateOnly(last)} or earlier.`;
        }
        return e;
    };
    const next = () => {
        const e = errsFor(step);
        setErrs(e);
        if (!Object.keys(e).length) setStep(step + 1);
    };
    const save = () => {
        for (const s of [0, 1]) {
            const e = errsFor(s);
            if (Object.keys(e).length) {
                setErrs(e);
                setStep(s);
                return;
            }
        }
        router.post(
            '/emar/competency/exemptions',
            {
                user_id: person!.id,
                site_id: person!.house_id,
                reason: X.reason.trim(),
                starts_on: X.from,
                ends_on: X.until,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onSuccess: () => {
                    setSaved(true);
                    setDirty(false);
                },
                onError: (server: Record<string, string>) => {
                    const e: Record<string, string> = {
                        who: server.user_id ?? server.site_id ?? '',
                        reason: server.reason ?? '',
                        from: server.starts_on ?? '',
                        until: server.ends_on ?? '',
                    };
                    setErrs(e);
                    setStep(e.who || e.reason ? 0 : e.from || e.until ? 1 : 2);
                },
                onFinish: () => setSaving(false),
            },
        );
    };
    const close = () => (dirty && !saved ? setGuard(true) : onClose());
    const pct = Math.round(
        ([
            !!X.who,
            X.reason.trim().length >= 10,
            !!X.until && X.until > X.from && X.until <= last,
        ].filter(Boolean).length /
            3) *
            100,
    );
    return (
        <>
            <WizardShell
                frontline
                open
                onClose={close}
                title="Grant an exemption"
                description="Lets one person record doses as given at one house without a current assessment, until a fixed end date."
                railIcon={ShieldCheck}
                railTitle="Grant an exemption"
                railSub="Staff eligibility › Exemptions"
                steps={STEPS}
                stepIndex={step}
                onStepClick={setStep}
                pct={pct}
                success={
                    saved && person ? (
                        <WizardSuccessPane
                            title="Exemption granted"
                            blurb={`${person.name} can record doses as given at ${person.house} until ${formatDateOnly(X.until)}. It ends by itself. Recorded in the audit log.`}
                            actions={
                                <Button onClick={onClose} autoFocus>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                footerStart={
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={close}>
                            Cancel
                        </Button>
                        {step > 0 ? (
                            <Button
                                variant="outline"
                                onClick={() => setStep(step - 1)}
                            >
                                <ChevronLeft />
                                Back
                            </Button>
                        ) : null}
                    </div>
                }
                footerEnd={
                    step < 2 ? (
                        <Button onClick={next}>
                            Continue
                            <ChevronRight />
                        </Button>
                    ) : (
                        <Button onClick={save} disabled={saving}>
                            <Check />
                            Grant exemption
                        </Button>
                    )
                }
            >
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-5">
                            <StepHead
                                icon={User}
                                title="Person & reason"
                                blurb="Only people without a current assessment can be chosen."
                            />
                            <RecordPicker
                                id="xw-who"
                                label="Person"
                                required
                                value={X.who}
                                items={people.map((p) => ({
                                    id: String(p.id),
                                    name: p.name,
                                    sub: [p.role, p.house, p.status]
                                        .filter(Boolean)
                                        .join(' · '),
                                    ok: p.ok && p.house_id !== null,
                                    why:
                                        p.house_id === null
                                            ? 'has no house'
                                            : p.why,
                                }))}
                                error={errs.who}
                                onChange={(v) => {
                                    up({ who: v });
                                    setErrs({ ...errs, who: '' });
                                }}
                                foot="Only people without a current assessment can be chosen."
                            />
                            {person ? (
                                <InfoCard icon={FileText}>
                                    <b>{person.house}</b> — an exemption covers
                                    one house, their own.
                                </InfoCard>
                            ) : null}
                            <Field
                                label="Why"
                                required
                                error={errs.reason}
                                hint="At least 10 characters"
                                htmlFor="xw-why"
                            >
                                <Textarea
                                    id="xw-why"
                                    rows={3}
                                    value={X.reason}
                                    aria-invalid={!!errs.reason || undefined}
                                    placeholder="For example: renewal booked for 3 October — the assessor is on leave until then"
                                    onChange={(e) => {
                                        up({ reason: e.target.value });
                                        setErrs({ ...errs, reason: '' });
                                    }}
                                />
                            </Field>
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-5">
                            <StepHead
                                icon={FileText}
                                title="Dates"
                                blurb={`Longest allowed: ${longestDays} ${longestDays === 1 ? 'day' : 'days'} — on or before ${formatDateOnly(last)}.`}
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="From"
                                    required
                                    error={errs.from}
                                    hint={WORKER_TIMEZONE}
                                    htmlFor="xw-from"
                                >
                                    <DatePicker
                                        compact
                                        id="xw-from"
                                        label="From"
                                        value={X.from}
                                        invalid={!!errs.from}
                                        onChange={(v) => {
                                            up({ from: v });
                                            setErrs({ ...errs, from: '' });
                                        }}
                                    />
                                </Field>
                                <Field
                                    label="Until"
                                    required
                                    error={errs.until}
                                    hint={`On or before ${formatDateOnly(last)}`}
                                    htmlFor="xw-until"
                                >
                                    <DatePicker
                                        compact
                                        id="xw-until"
                                        label="Until"
                                        value={X.until}
                                        invalid={!!errs.until}
                                        onChange={(v) => {
                                            up({ until: v });
                                            setErrs({ ...errs, until: '' });
                                        }}
                                    />
                                </Field>
                            </div>
                            <p className="text-caption">
                                The longest exemption is set in Settings › Staff
                                &amp; PINs › Exemption limit (
                                {limitReviewed
                                    ? 'set'
                                    : 'default — not yet reviewed'}
                                ).
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead
                                icon={Check}
                                title="Review & grant"
                                blurb={`Approved by you (${approver}). Recorded in the audit log.`}
                            />
                            <ReviewCard
                                icon={User}
                                title="Exemption"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Person"
                                    value={person?.name}
                                />
                                <ReviewRow
                                    label="Where"
                                    value={person?.house}
                                />
                                <ReviewRow label="Why" value={X.reason} />
                            </ReviewCard>
                            <ReviewCard
                                icon={FileText}
                                title="Dates"
                                onEdit={() => setStep(1)}
                            >
                                <ReviewRow
                                    label="From"
                                    value={formatDateOnly(X.from)}
                                />
                                <ReviewRow
                                    label="Until"
                                    value={formatDateOnly(X.until)}
                                />
                            </ReviewCard>
                            <p className="text-sm font-semibold">
                                What this does
                            </p>
                            <ul className="divide-y divide-border rounded-xl border border-border">
                                <li className="flex items-start gap-3 p-3">
                                    <StatusBadge
                                        variant="warning"
                                        size="sm"
                                        className="mt-0.5 shrink-0"
                                    >
                                        With conditions
                                    </StatusBadge>
                                    <span className="min-w-0 text-[13px]">
                                        {person
                                            ? firstName(person.name)
                                            : 'They'}{' '}
                                        can record doses as given at{' '}
                                        {person?.house ?? 'their house'} from{' '}
                                        {formatDateOnly(X.from)} until{' '}
                                        {formatDateOnly(X.until)}, then it ends
                                        by itself.
                                    </span>
                                </li>
                            </ul>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog
                frontline
                open={guard}
                mode="create"
                description="Nothing you’ve entered here has been saved. Closing now loses it."
                onKeepEditing={() => setGuard(false)}
                onDiscard={() => {
                    setGuard(false);
                    onClose();
                }}
            />
        </>
    );
}

/** End early: a consequential action with a required reason — Fleet Modal with the destructive verb. */
export function EndExemption({
    exemption,
    onClose,
}: {
    exemption: ActiveExemption;
    onClose: () => void;
}) {
    const [why, setWhy] = useState('');
    const [err, setErr] = useState('');
    const [saving, setSaving] = useState(false);
    const end = () => {
        // The server asks for at least 10 characters (v5 asked for 3).
        if (why.trim().length < 10) {
            setErr('Say why, in at least 10 characters.');
            setTimeout(() => document.getElementById('xe-why')?.focus(), 0);
            return;
        }
        router.post(
            `/emar/competency/exemptions/${exemption.id}/end`,
            { reason: why.trim() },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onSuccess: () => onClose(),
                onError: (server: Record<string, string>) =>
                    setErr(
                        server.reason ??
                            'Couldn’t end the exemption — nothing was changed. Try again.',
                    ),
                onFinish: () => setSaving(false),
            },
        );
    };
    return (
        <SettingsModal
            frontline
            title={`End ${exemption.person}’s exemption early?`}
            description={`From now, ${firstName(exemption.person)} can’t record doses as given at ${exemption.house} until they have a current assessment.`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        onClick={end}
                        disabled={saving}
                    >
                        End exemption
                    </Button>
                </>
            }
        >
            <Field label="Why" required error={err} htmlFor="xe-why">
                <Textarea
                    id="xe-why"
                    rows={2}
                    value={why}
                    aria-invalid={!!err || undefined}
                    placeholder="For example: renewal done"
                    onChange={(e) => {
                        setWhy(e.target.value);
                        setErr('');
                    }}
                />
            </Field>
            <p className="text-caption">
                Recorded in the audit log with your name and the time.
            </p>
        </SettingsModal>
    );
}
