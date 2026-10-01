/* The assessment viewer and wizard (eMAR P11 v5 `dialogs-elig.tsx`
 * AssessmentView and AssessmentWizard). They replace Medication › Competency's
 * dialogs: new, renew and remedial assessments, and editing one. Saving goes
 * through the existing competency actions, so the server's rules (pass mark,
 * core areas, observed minimum, how long an assessment lasts, assessed by
 * someone else) decide the result.
 *
 * v5's "Witnessing controlled doses" ability lines are left out: who can
 * witness is enforced with P07b. The "can witness" flag is still recorded. */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { toDateInput, WORKER_TIMEZONE } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileText,
    History,
    LogIn,
    Plus,
    RefreshCw,
    Settings2,
    ShieldCheck,
    Trash2,
    User,
    UserCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Choice, OnOff, RecordPicker } from '../settings/_ui';
import {
    areaAbility,
    day,
    eligLine,
    firstName,
    givenAbility,
    STATUS_META,
    type AreaMeta,
    type AreaResult,
    type EligPerson,
    type EligPolicy,
    type ObservedRound,
} from './_model';
import { AreaTable, CanList } from './_parts';

export type EligClient = { id: number; name: string; house_id: number };
export type AssessMode = 'new' | 'renew' | 'remedial' | 'edit';

/** Observed medicine types: v5's words, the stored keys of today's records. */
const MED_TYPES: [string, string][] = [
    ['oral', 'Tablet or capsule'],
    ['liquid', 'Liquid'],
    ['inhaler', 'Inhaler'],
    ['topical', 'Topical'],
    ['injection', 'Injection'],
    ['controlled', 'Controlled drug'],
    ['prn', 'As needed'],
];
const MED_LEGACY: Record<string, string> = {
    insulin: 'Insulin',
    covert: 'Covert',
    other: 'Other',
};
export const medTypeLabel = (k?: string) =>
    MED_TYPES.find(([v]) => v === k)?.[1] ?? (k ? (MED_LEGACY[k] ?? k) : '—');
const OUTCOMES: [string, string][] = [
    ['safe', 'Safe'],
    ['prompted', 'Prompted'],
    ['intervened', 'Stepped in'],
];

/** "2026-10-02" plus whole months, keeping the day where it exists. */
export const addMonths = (d: string, months: number) => {
    const [y, m, dd] = d.split('-').map(Number);
    if (!y || !m || !dd) return d;
    const last = new Date(Date.UTC(y, m - 1 + months + 1, 0)).getUTCDate();
    return new Date(Date.UTC(y, m - 1 + months, Math.min(dd, last)))
        .toISOString()
        .slice(0, 10);
};

/* ── Assessment detail viewer: WizardShell sections (not sequential) ── */
const AV_SECS = [
    {
        key: 'can',
        label: 'What they can do',
        blurb: 'Given, controlled, covert',
        icon: CheckCircle2,
    },
    {
        key: 'areas',
        label: 'Areas',
        blurb: '12 areas and results',
        icon: ClipboardCheck,
    },
    {
        key: 'assess',
        label: 'Assessment',
        blurb: 'Who, when, flags',
        icon: FileText,
    },
];

/** The abilities v5 lists, without witnessing (P07b). */
export const abilities = (
    x: EligPerson,
    policy: EligPolicy,
    areas: AreaMeta[],
) => {
    const area = (k: string) => areas.find((a) => a.key === k)!;
    return [
        { ...givenAbility(x, policy), head: 'Given doses' },
        ...(x.st === 'none'
            ? []
            : [
                  {
                      ...areaAbility(x, policy, area('controlled_drugs')),
                      head: 'Controlled drugs',
                  },
                  {
                      ...areaAbility(x, policy, area('covert_admin_knowledge')),
                      head: 'Covert administration',
                  },
                  {
                      ...areaAbility(x, policy, area('insulin_competent')),
                      head: 'Insulin',
                  },
              ]),
    ];
};

export function AssessmentView({
    x,
    areas,
    policy,
    onClose,
    onAssess,
}: {
    x: EligPerson;
    areas: AreaMeta[];
    policy: EligPolicy;
    onClose: () => void;
    /** Open the wizard for their next assessment, when the reader can assess them. */
    onAssess?: () => void;
}) {
    const [sec, setSec] = useState(0);
    const [variant, label] = STATUS_META[x.status];
    const g = givenAbility(x, policy);
    const a = x.assessment;
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`${x.name} — medication competency`}
            description="Medication competency, what it allows, and each area result."
            railIcon={UserCheck}
            railTitle={x.name}
            railSub={[x.role, x.house].filter(Boolean).join(' · ')}
            steps={AV_SECS}
            stepIndex={sec}
            onStepClick={setSec}
            sequential={false}
            headerLabel={`${x.name} — ${AV_SECS[sec].label}`}
            railExtra={
                <div className="space-y-1">
                    <StatusBadge variant={variant} size="sm">
                        {label}
                    </StatusBadge>
                    <p className="text-caption">{eligLine(x, areas)}</p>
                </div>
            }
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                onAssess && x.st !== 'ack' ? (
                    <Button onClick={onAssess}>
                        {x.st === 'none'
                            ? 'Start first assessment'
                            : x.st === 'failed'
                              ? 'Start remedial assessment'
                              : 'Renew or reassess'}
                    </Button>
                ) : null
            }
        >
            <WizardStepPane key={sec}>
                {!a ? (
                    <InfoCard icon={User} tone="crit">
                        <b>No assessment yet.</b> {x.name}
                        {x.started ? ` started on ${day(x.started)}` : ''}.
                        Until assessed they can record refused, withheld and
                        away, but not given.
                    </InfoCard>
                ) : sec === 0 ? (
                    <div className="space-y-4">
                        <InfoCard
                            icon={
                                g.v === 'yes'
                                    ? CheckCircle2
                                    : g.v === 'part'
                                      ? AlertTriangle
                                      : XCircle
                            }
                            tone={
                                g.v === 'yes'
                                    ? 'info'
                                    : g.v === 'part'
                                      ? 'warn'
                                      : 'crit'
                            }
                        >
                            <b>{g.t}</b>
                            <br />
                            {label} · {eligLine(x, areas)}
                        </InfoCard>
                        <CanList items={abilities(x, policy, areas)} />
                    </div>
                ) : sec === 1 ? (
                    <AreaTable
                        x={x}
                        areas={areas}
                        policy={policy}
                        corePasses={policy.core_must_pass}
                    />
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <ReviewCard icon={FileText} title="Assessment">
                            <ReviewRow label="Type" value={a.type_label} />
                            <ReviewRow
                                label="Assessed"
                                value={`${day(a.assessed)} by ${a.assessor ?? '—'}`}
                            />
                            <ReviewRow label="Ends" value={day(a.until)} />
                            <ReviewRow
                                label="Result"
                                value={`${a.status === 'passed' ? 'Passed' : 'Not passed'} · ${a.passed} of 12 (pass mark ${a.pass_threshold ?? policy.pass_mark})`}
                            />
                            <ReviewRow
                                label="Observed"
                                value={`${a.observed.length} administrations · minimum: ${policy.observed_minimum ?? 'Not configured'}`}
                            />
                            {a.strengths ? (
                                <ReviewRow
                                    label="What went well"
                                    value={a.strengths}
                                />
                            ) : null}
                            {a.to_work_on ? (
                                <ReviewRow
                                    label="What to work on"
                                    value={a.to_work_on}
                                />
                            ) : null}
                            {a.action_plan ? (
                                <ReviewRow
                                    label="Action plan"
                                    value={a.action_plan}
                                />
                            ) : null}
                            {a.comments ? (
                                <ReviewRow label="Notes" value={a.comments} />
                            ) : null}
                        </ReviewCard>
                        <ReviewCard
                            icon={Settings2}
                            title="Flags and acknowledgement"
                        >
                            <ReviewRow
                                label="Restriction"
                                value={
                                    a.restricted
                                        ? a.restriction_notes || 'Restricted'
                                        : 'None'
                                }
                            />
                            <ReviewRow
                                label="Can give unsupervised"
                                value={`${a.unsupervised ? 'On' : 'Off'} — recorded only, not used yet`}
                            />
                            <ReviewRow
                                label="Can witness controlled drugs"
                                value={`${a.can_witness ? 'On' : 'Off'} — recorded only, not checked yet`}
                            />
                            <ReviewRow
                                label={`${firstName(x)} acknowledged`}
                                value={
                                    a.acknowledged_at ? (
                                        day(a.acknowledged_at)
                                    ) : (
                                        <StatusBadge variant="info" size="sm">
                                            Waiting — from their own login
                                        </StatusBadge>
                                    )
                                }
                            />
                            {x.exemption ? (
                                <ReviewRow
                                    label="Exemption"
                                    value={`${x.exemption.house ?? ''} until ${day(x.exemption.until)} · ${x.exemption.by ?? ''}`}
                                />
                            ) : null}
                        </ReviewCard>
                        {a.observed.length ? (
                            <ReviewCard icon={Activity} title="Observed" span>
                                {a.observed.map((o, i) => (
                                    <ReviewRow
                                        key={i}
                                        label={
                                            o.resident || `Observation ${i + 1}`
                                        }
                                        value={`${medTypeLabel(o.med_type)} · ${OUTCOMES.find(([v]) => v === o.outcome)?.[1] ?? o.outcome ?? '—'}`}
                                    />
                                ))}
                            </ReviewCard>
                        ) : null}
                        {x.history?.length ? (
                            <ReviewCard
                                icon={History}
                                title="Earlier assessments"
                                span
                            >
                                {x.history.map((h) => (
                                    <ReviewRow
                                        key={h.id}
                                        label={`${day(h.assessed)} · ${h.type_label}`}
                                        value={`${h.status === 'passed' ? 'Passed' : h.status === 'failed' ? 'Not passed' : h.status}${h.until ? ` · ended ${day(h.until)}` : ''}${h.assessor ? ` · ${h.assessor}` : ''}`}
                                    />
                                ))}
                            </ReviewCard>
                        ) : null}
                    </div>
                )}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ── Assessment wizard: 5 steps, free navigation, review, success, discard guard ── */
const AW_STEPS = [
    {
        key: 'person',
        label: 'Person & context',
        blurb: 'Who, why and when',
        icon: User,
    },
    {
        key: 'areas',
        label: 'Areas',
        blurb: '12 areas, each answered',
        icon: ClipboardCheck,
    },
    {
        key: 'obs',
        label: 'Observed',
        blurb: 'Administrations watched',
        icon: Activity,
    },
    {
        key: 'result',
        label: 'Result',
        blurb: 'What they’ll be able to do',
        icon: ShieldCheck,
    },
    {
        key: 'review',
        label: 'Review & record',
        blurb: 'Check and declare',
        icon: Check,
    },
];
const TYPE_TILES = [
    {
        key: 'initial',
        label: 'First assessment',
        description: 'First time for this person',
        icon: User,
    },
    {
        key: 'annual',
        label: 'Renewal',
        description: 'Before or after the end date',
        icon: RefreshCw,
    },
    {
        key: 'remedial',
        label: 'Remedial',
        description: 'After an error or not passed',
        icon: AlertTriangle,
    },
    {
        key: 'return_to_work',
        label: 'Return to work',
        description: 'After a long break',
        icon: LogIn,
    },
];
const ERROR_PREFIX = 'Linked medication error: ';

type Draft = {
    who: string;
    type: string;
    date: string;
    until: string;
    untilTouched: boolean;
    err: string;
    res: Record<string, AreaResult>;
    obs: ObservedRound[];
    restricted: boolean;
    rnotes: string;
    witness: boolean;
    unsup: boolean;
    strengths: string;
    improve: string;
    declared: boolean;
};

export function AssessmentWizard({
    people,
    clients,
    areas,
    policy,
    assessor,
    who,
    mode,
    onClose,
    onView,
}: {
    people: EligPerson[];
    clients: EligClient[];
    areas: AreaMeta[];
    policy: EligPolicy;
    assessor: string;
    who?: number;
    mode: AssessMode;
    onClose: () => void;
    onView: (id: number) => void;
}) {
    const today = toDateInput(new Date());
    const start = people.find((p) => p.id === who) ?? null;
    const editing =
        mode === 'edit' && start?.assessment ? start.assessment : null;
    const [A, setA] = useState<Draft>(() =>
        editing
            ? {
                  who: String(start!.id),
                  type: editing.type,
                  date: editing.assessed ?? today,
                  until:
                      editing.until ??
                      addMonths(
                          editing.assessed ?? today,
                          policy.validity_months,
                      ),
                  untilTouched: true,
                  err: editing.comments?.startsWith(ERROR_PREFIX)
                      ? editing.comments.slice(ERROR_PREFIX.length)
                      : '',
                  res: { ...editing.res },
                  obs: editing.observed,
                  restricted: editing.restricted,
                  rnotes: editing.restriction_notes ?? '',
                  witness: editing.can_witness,
                  unsup: editing.unsupervised,
                  strengths: editing.strengths ?? '',
                  improve: editing.to_work_on ?? '',
                  declared: !!editing.declared_at,
              }
            : {
                  who: start && start.can?.assess ? String(start.id) : '',
                  type:
                      mode === 'renew'
                          ? 'annual'
                          : mode === 'remedial'
                            ? 'remedial'
                            : start && start.st !== 'none'
                              ? 'annual'
                              : 'initial',
                  date: today,
                  until: addMonths(today, policy.validity_months),
                  untilTouched: false,
                  err: '',
                  res: {},
                  obs: [],
                  restricted: false,
                  rnotes: '',
                  witness: false,
                  unsup: false,
                  strengths: '',
                  improve: '',
                  declared: false,
              },
    );
    const [step, setStep] = useState(0);
    const [errs, setErrs] = useState<Record<string, string>>({});
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState<{ pass: boolean; who: number } | null>(
        null,
    );
    const [guard, setGuard] = useState(false);
    const up = (patch: Partial<Draft>) => {
        setA((d) => ({ ...d, ...patch }));
        setDirty(true);
    };
    const x = people.find((p) => String(p.id) === A.who) ?? null;
    const answered = areas.filter((a) => A.res[a.key]).length;
    const passed = areas.filter((a) => A.res[a.key] === 'yes').length;
    const coreNot = areas.filter(
        (a) => a.core && A.res[a.key] && A.res[a.key] !== 'yes',
    );
    const pass =
        answered === 12 &&
        passed >= policy.pass_mark &&
        (!policy.core_must_pass || !coreNot.length);
    const cdOk = A.res.controlled_drugs === 'yes';
    const logged = A.obs.filter((o) => o.resident?.trim()).length;
    const latestUntil = addMonths(A.date, policy.validity_months);
    const pct = Math.round(
        ([
            !!A.who,
            !!A.type,
            answered === 12,
            A.obs.length > 0,
            A.declared,
            !!(A.strengths || A.improve),
        ].filter(Boolean).length /
            6) *
            100,
    );
    const title =
        mode === 'edit'
            ? 'Edit assessment'
            : mode === 'renew'
              ? 'Renew assessment'
              : mode === 'remedial'
                ? 'Remedial assessment'
                : 'New assessment';

    const stepErrs = (s: number) => {
        const e: Record<string, string> = {};
        if (s === 0) {
            if (!A.who) e.who = 'Choose who you’re assessing.';
            if (A.date > today)
                e.date = `The assessment date can’t be in the future (today is ${day(today)}).`;
            if (A.until <= A.date)
                e.until = 'The end date must be after the assessment date.';
            else if (A.until > latestUntil)
                e.until = `An assessment stays current for at most ${policy.validity_months} ${policy.validity_months === 1 ? 'month' : 'months'} — choose ${day(latestUntil)} or earlier.`;
        }
        if (s === 1 && answered < 12)
            e.areas = `Choose a result for every area — ${12 - answered} still ${12 - answered === 1 ? 'needs' : 'need'} one.`;
        if (
            s === 2 &&
            policy.observed_minimum !== null &&
            logged < policy.observed_minimum
        )
            e.obs = `Log at least ${policy.observed_minimum} observed administrations — your organisation asks for ${policy.observed_minimum} (Settings › Staff & PINs). ${logged} logged.`;
        if (s === 3 && pass && A.restricted && !A.rnotes.trim())
            e.rnotes =
                'Say what the restriction is — the person and their lead see this.';
        if (s === 4 && !A.declared)
            e.declared = 'Turn on the declaration to record the assessment.';
        return e;
    };
    const next = () => {
        const e = stepErrs(step);
        setErrs(e);
        if (!Object.keys(e).length) setStep(step + 1);
    };
    const save = () => {
        for (const s of [0, 1, 2, 3, 4]) {
            const e = stepErrs(s);
            if (Object.keys(e).length) {
                setErrs(e);
                setStep(s);
                return;
            }
        }
        const data: Record<string, unknown> = {
            user_id: Number(A.who),
            assessment_type: A.type,
            assessment_date: A.date,
            expiry_date: A.until,
            not_seen_areas: areas
                .filter((a) => A.res[a.key] === 'unseen')
                .map((a) => a.key),
            observed_rounds: A.obs
                .filter((o) => o.resident?.trim())
                .map((o) => ({ ...o, cd: o.med_type === 'controlled' })),
            restricted: pass && A.restricted,
            restriction_notes: pass && A.restricted ? A.rnotes.trim() : null,
            can_witness_controlled: A.witness && pass && cdOk && !A.restricted,
            can_administer_unsupervised: A.unsup && pass,
            strengths: A.strengths || null,
            areas_for_improvement: A.improve || null,
            assessor_comments:
                A.type === 'remedial' && A.err.trim()
                    ? `${ERROR_PREFIX}${A.err.trim()}`
                    : (editing?.comments ?? null),
            assessor_declared: A.declared,
        };
        areas.forEach((a) => {
            data[a.key] = A.res[a.key] === 'yes';
        });
        const opts = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onSuccess: () => {
                setSaved({ pass, who: Number(A.who) });
                setDirty(false);
            },
            onError: (server: Record<string, string>) => {
                const e: Record<string, string> = {
                    who: server.user_id ?? '',
                    date: server.assessment_date ?? '',
                    until: server.expiry_date ?? '',
                    obs: server.observed_rounds ?? '',
                };
                const other = Object.entries(server).find(
                    ([k]) =>
                        ![
                            'user_id',
                            'assessment_date',
                            'expiry_date',
                            'observed_rounds',
                        ].includes(k),
                );
                if (other) e.declared = other[1];
                setErrs(e);
                setStep(e.who || e.date || e.until ? 0 : e.obs ? 2 : 4);
            },
            onFinish: () => setSaving(false),
        };
        if (editing)
            router.put(`/emar/competency/${editing.id}`, data as never, opts);
        else router.post('/emar/competency', data as never, opts);
    };
    const close = () => (dirty && !saved ? setGuard(true) : onClose());

    // What they'll be able to do, from today's rules, if this is recorded.
    const px: EligPerson | null = x
        ? {
              ...x,
              st: !pass ? 'failed' : A.restricted ? 'restricted' : 'current',
              status: !pass
                  ? 'failed'
                  : A.restricted
                    ? 'restricted'
                    : 'current',
              prev_valid: null,
              exemption: null,
              until: A.until,
              assessment: {
                  ...(x.assessment ??
                      ({} as NonNullable<EligPerson['assessment']>)),
                  res: Object.fromEntries(
                      areas.map((a) => [a.key, A.res[a.key] ?? 'unseen']),
                  ) as Record<string, AreaResult>,
                  restricted: A.restricted,
                  restriction_notes: A.rnotes,
              },
          }
        : null;
    const consequence = (a: AreaMeta, v?: AreaResult) => {
        if (!v) return null;
        if (a.core && v !== 'yes')
            return (
                <p className="text-xs text-status-critical">
                    Core area —{' '}
                    {policy.core_must_pass
                        ? 'not passed means the assessment isn’t passed'
                        : 'counts towards the pass mark only'}
                </p>
            );
        if (a.rule === 'area' && v !== 'yes')
            return (
                <p className="text-caption">
                    {policy.area_mode === 'off'
                        ? 'Recorded only — the area rule is off'
                        : v === 'no' ||
                            policy.area_mode === 'failed_or_not_seen'
                          ? `They won’t be able to sign ${a.key === 'controlled_drugs' ? 'controlled doses' : 'doses with a covert plan'}`
                          : 'Allowed — the current rule only blocks when the area was failed'}
                </p>
            );
        if (a.rule === 'notyet')
            return (
                <p className="text-caption">
                    Recorded only — the system doesn’t check insulin yet
                </p>
            );
        return null;
    };
    const houseClients = clients.filter((c) => !x || c.house_id === x.house_id);

    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description="Record a medication competency assessment."
                railIcon={UserCheck}
                railTitle={title}
                railSub={x ? x.name : 'Medication competency'}
                steps={AW_STEPS}
                stepIndex={step}
                onStepClick={setStep}
                pct={pct}
                success={
                    saved ? (
                        <WizardSuccessPane
                            title={
                                editing
                                    ? 'Assessment updated'
                                    : 'Assessment recorded'
                            }
                            blurb={
                                <>
                                    Result:{' '}
                                    <b>
                                        {saved.pass ? 'Passed' : 'Not passed'}
                                    </b>{' '}
                                    · {passed} of 12
                                    {saved.pass
                                        ? ` · ends ${day(A.until)}`
                                        : ''}
                                    {A.restricted && saved.pass
                                        ? ' · restricted'
                                        : ''}
                                    . {x ? firstName(x) : 'They'} acknowledges
                                    it from their own login in Meds today › My
                                    eligibility; it counts from then.{' '}
                                    {x?.st === 'current' ||
                                    x?.st === 'restricted'
                                        ? `Until then their current assessment still counts (until ${day(x.until)}).`
                                        : 'Until then they can’t record doses as given.'}
                                </>
                            }
                            actions={
                                <Button
                                    onClick={() => onView(saved.who)}
                                    autoFocus
                                >
                                    View assessment
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
                    step < 4 ? (
                        <Button onClick={next}>
                            Continue
                            <ChevronRight />
                        </Button>
                    ) : (
                        <Button onClick={save} disabled={saving}>
                            <Check />
                            {editing ? 'Save changes' : 'Record assessment'}
                        </Button>
                    )
                }
            >
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-5">
                            <StepHead
                                icon={User}
                                title="Person & context"
                                blurb="Who you’re assessing, and when."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <RecordPicker
                                    id="aw-who"
                                    label="Who you’re assessing"
                                    required
                                    disabled={!!editing}
                                    value={A.who}
                                    items={people.map((p) => ({
                                        id: String(p.id),
                                        name: p.name,
                                        sub: [
                                            p.role,
                                            p.house,
                                            STATUS_META[p.status][1],
                                        ]
                                            .filter(Boolean)
                                            .join(' · '),
                                        ok: !!p.can?.assess,
                                        why: p.can?.assess
                                            ? undefined
                                            : 'you can’t assess yourself or someone outside your houses',
                                    }))}
                                    error={errs.who}
                                    onChange={(v) => {
                                        const p = people.find(
                                            (y) => String(y.id) === v,
                                        );
                                        up({
                                            who: v,
                                            type:
                                                mode === 'new' &&
                                                p &&
                                                p.st !== 'none'
                                                    ? p.st === 'failed'
                                                        ? 'remedial'
                                                        : 'annual'
                                                    : A.type,
                                        });
                                        setErrs({ ...errs, who: '' });
                                    }}
                                    foot="People at your houses. You can’t assess yourself."
                                />
                                <Field label="Assessor">
                                    <InfoCard icon={User}>
                                        <b>{assessor}</b> — must be a different
                                        person.
                                    </InfoCard>
                                </Field>
                            </div>
                            <Field label="Type of assessment" required>
                                <TilePicker
                                    value={A.type}
                                    onChange={(v) => up({ type: v })}
                                    options={TYPE_TILES}
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Assessment date"
                                    required
                                    error={errs.date}
                                    hint={WORKER_TIMEZONE}
                                    htmlFor="aw-date"
                                >
                                    <DatePicker
                                        id="aw-date"
                                        label="Assessment date"
                                        value={A.date}
                                        invalid={!!errs.date}
                                        onChange={(v) => {
                                            up({
                                                date: v,
                                                until: A.untilTouched
                                                    ? A.until
                                                    : addMonths(
                                                          v,
                                                          policy.validity_months,
                                                      ),
                                            });
                                            setErrs({ ...errs, date: '' });
                                        }}
                                    />
                                </Field>
                                <Field
                                    label="Ends"
                                    required
                                    error={errs.until}
                                    hint={`${policy.validity_months} months${policy.validity_reviewed ? '' : ' (default — not yet reviewed)'}`}
                                    htmlFor="aw-until"
                                >
                                    <DatePicker
                                        id="aw-until"
                                        label="Ends"
                                        value={A.until}
                                        invalid={!!errs.until}
                                        onChange={(v) => {
                                            up({
                                                until: v,
                                                untilTouched: true,
                                            });
                                            setErrs({ ...errs, until: '' });
                                        }}
                                    />
                                </Field>
                            </div>
                            {A.type === 'remedial' ? (
                                <Field
                                    label="Linked medication error (optional)"
                                    htmlFor="aw-err"
                                >
                                    <Input
                                        id="aw-err"
                                        value={A.err}
                                        placeholder="For example ME-2026-031"
                                        onChange={(e) =>
                                            up({ err: e.target.value })
                                        }
                                    />
                                </Field>
                            ) : null}
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-4">
                            <StepHead
                                icon={ClipboardCheck}
                                title="Areas"
                                blurb="Choose a result for every area. Nothing is chosen for you. “Not assessed” means you didn’t see it today."
                            />
                            <InfoCard
                                icon={ClipboardCheck}
                                tone={errs.areas ? 'crit' : 'info'}
                            >
                                <b>
                                    {answered} of 12 answered · {passed} passed
                                </b>{' '}
                                · pass mark {policy.pass_mark} of 12
                                {policy.core_must_pass
                                    ? ', every core area passed'
                                    : ''}
                                {errs.areas ? (
                                    <>
                                        <br />
                                        <span role="alert">{errs.areas}</span>
                                    </>
                                ) : null}
                            </InfoCard>
                            <div className="divide-y divide-border rounded-xl border">
                                {areas.map((a) => (
                                    <div
                                        key={a.key}
                                        className="grid gap-2 p-3 sm:grid-cols-[1fr_auto] sm:items-center"
                                        role="group"
                                        aria-label={a.label}
                                        aria-invalid={
                                            (errs.areas && !A.res[a.key]) ||
                                            undefined
                                        }
                                    >
                                        <div>
                                            <span className="text-[13px] font-medium">
                                                {a.label}
                                            </span>
                                            {a.core ? (
                                                <StatusBadge
                                                    variant="neutral"
                                                    size="sm"
                                                    className="ml-2"
                                                >
                                                    Core
                                                </StatusBadge>
                                            ) : null}
                                            {a.description ? (
                                                <p className="text-caption">
                                                    {a.description}
                                                </p>
                                            ) : null}
                                            {consequence(a, A.res[a.key])}
                                        </div>
                                        <Choice
                                            value={
                                                (A.res[a.key] ??
                                                    '') as AreaResult
                                            }
                                            onChange={(v) => {
                                                const res = {
                                                    ...A.res,
                                                    [a.key]: v,
                                                };
                                                up({
                                                    res,
                                                    witness:
                                                        a.key ===
                                                            'controlled_drugs' &&
                                                        v !== 'yes'
                                                            ? false
                                                            : A.witness,
                                                });
                                                if (errs.areas) {
                                                    const n = areas.filter(
                                                        (y) => res[y.key],
                                                    ).length;
                                                    setErrs(
                                                        n === 12
                                                            ? {}
                                                            : {
                                                                  areas: `Choose a result for every area — ${12 - n} still ${12 - n === 1 ? 'needs' : 'need'} one.`,
                                                              },
                                                    );
                                                }
                                            }}
                                            options={[
                                                ['yes', 'Passed'],
                                                ['no', 'Not passed'],
                                                ['unseen', 'Not assessed'],
                                            ]}
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : step === 2 ? (
                        <div className="space-y-4">
                            <StepHead
                                icon={Activity}
                                title="Observed administrations"
                                blurb="Log each administration you watched."
                            />
                            <InfoCard
                                icon={Activity}
                                tone={errs.obs ? 'crit' : 'info'}
                            >
                                <b>
                                    Minimum:{' '}
                                    {policy.observed_minimum ??
                                        'Not configured'}
                                </b>
                                {policy.observed_minimum === null
                                    ? ' — the organisation sets its own (Settings › Staff & PINs).'
                                    : ''}
                                {errs.obs ? (
                                    <>
                                        <br />
                                        <span role="alert">{errs.obs}</span>
                                    </>
                                ) : null}
                            </InfoCard>
                            {A.obs.length ? (
                                <div className="divide-y divide-border rounded-xl border">
                                    {A.obs.map((o, i) => (
                                        <div
                                            key={i}
                                            className="grid items-end gap-3 p-3 sm:grid-cols-[1fr_1fr_auto_auto]"
                                        >
                                            <RecordPicker
                                                id={`ob-p-${i}`}
                                                label="Person"
                                                value={
                                                    o.client_id
                                                        ? String(o.client_id)
                                                        : ''
                                                }
                                                items={houseClients.map(
                                                    (c) => ({
                                                        id: String(c.id),
                                                        name: c.name,
                                                        sub: x?.house ?? '',
                                                        ok: true,
                                                    }),
                                                )}
                                                onChange={(v) => {
                                                    const c = clients.find(
                                                        (y) =>
                                                            String(y.id) === v,
                                                    );
                                                    up({
                                                        obs: A.obs.map(
                                                            (y, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...y,
                                                                          client_id:
                                                                              c?.id ??
                                                                              null,
                                                                          resident:
                                                                              c?.name ??
                                                                              '',
                                                                      }
                                                                    : y,
                                                        ),
                                                    });
                                                    setErrs({
                                                        ...errs,
                                                        obs: '',
                                                    });
                                                }}
                                                placeholder={
                                                    o.resident ||
                                                    'Search and choose'
                                                }
                                                search="Search people…"
                                            />
                                            <Field label="Medicine type">
                                                <SelectInput
                                                    value={o.med_type ?? ''}
                                                    placeholder="Choose"
                                                    onChange={(v) =>
                                                        up({
                                                            obs: A.obs.map(
                                                                (y, j) =>
                                                                    j === i
                                                                        ? {
                                                                              ...y,
                                                                              med_type:
                                                                                  v,
                                                                          }
                                                                        : y,
                                                            ),
                                                        })
                                                    }
                                                    options={MED_TYPES.map(
                                                        ([value, label]) => ({
                                                            value,
                                                            label,
                                                        }),
                                                    )}
                                                    ariaLabel={`Observation ${i + 1} medicine type`}
                                                />
                                            </Field>
                                            <Field label="Outcome">
                                                <Choice
                                                    value={o.outcome ?? 'safe'}
                                                    onChange={(v) =>
                                                        up({
                                                            obs: A.obs.map(
                                                                (y, j) =>
                                                                    j === i
                                                                        ? {
                                                                              ...y,
                                                                              outcome:
                                                                                  v,
                                                                          }
                                                                        : y,
                                                            ),
                                                        })
                                                    }
                                                    options={OUTCOMES}
                                                />
                                            </Field>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                aria-label={`Remove observation ${i + 1}`}
                                                onClick={() =>
                                                    up({
                                                        obs: A.obs.filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    })
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-subtle">None logged yet.</p>
                            )}
                            <div className="flex items-center gap-3">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        up({
                                            obs: [
                                                ...A.obs,
                                                {
                                                    resident: '',
                                                    client_id: null,
                                                    med_type: 'oral',
                                                    outcome: 'safe',
                                                },
                                            ],
                                        })
                                    }
                                >
                                    <Plus />
                                    Add an observed administration
                                </Button>
                                <span className="text-caption">
                                    {logged} logged
                                </span>
                            </div>
                        </div>
                    ) : step === 3 ? (
                        <div className="space-y-4">
                            <StepHead
                                icon={ShieldCheck}
                                title="Result"
                                blurb="What they’ll be able to do under today’s rules."
                            />
                            <InfoCard
                                icon={pass ? CheckCircle2 : XCircle}
                                tone={pass ? 'info' : 'crit'}
                            >
                                <b>{pass ? 'Passed' : 'Not passed'}</b> ·{' '}
                                {passed} of 12 areas passed · pass mark{' '}
                                {policy.pass_mark}
                                {coreNot.length
                                    ? ` · core not passed: ${coreNot.map((a) => a.label.toLowerCase()).join(', ')}`
                                    : ''}
                            </InfoCard>
                            {pass ? (
                                <div className="divide-y divide-border rounded-xl border">
                                    <div className="space-y-2 p-3">
                                        <div className="flex items-start justify-between gap-4">
                                            <div>
                                                <label
                                                    htmlFor="aw-f-restricted"
                                                    className="text-[13px] font-semibold"
                                                >
                                                    Restrict their practice
                                                </label>
                                                <p className="text-caption">
                                                    Organisation rule for
                                                    restricted competency:{' '}
                                                    {
                                                        {
                                                            off: 'Off',
                                                            block: 'Block',
                                                            cosigner:
                                                                'Co-signer with witness PIN',
                                                        }[
                                                            policy
                                                                .restricted_mode
                                                        ]
                                                    }{' '}
                                                    (Settings › Safety checks).
                                                </p>
                                            </div>
                                            <OnOff
                                                id="aw-f-restricted"
                                                checked={A.restricted}
                                                onChange={(v) =>
                                                    up({
                                                        restricted: v,
                                                        witness: v
                                                            ? false
                                                            : A.witness,
                                                    })
                                                }
                                            />
                                        </div>
                                        {A.restricted ? (
                                            <Field
                                                label="What’s the restriction?"
                                                required
                                                error={errs.rnotes}
                                                htmlFor="aw-rn"
                                            >
                                                <Input
                                                    id="aw-rn"
                                                    value={A.rnotes}
                                                    placeholder="For example: supervised practice until reassessed"
                                                    aria-invalid={
                                                        !!errs.rnotes ||
                                                        undefined
                                                    }
                                                    onChange={(e) => {
                                                        up({
                                                            rnotes: e.target
                                                                .value,
                                                        });
                                                        setErrs({
                                                            ...errs,
                                                            rnotes: '',
                                                        });
                                                    }}
                                                />
                                            </Field>
                                        ) : null}
                                    </div>
                                    <div className="flex items-start justify-between gap-4 p-3">
                                        <div>
                                            <label
                                                htmlFor="aw-f-witness"
                                                className="text-[13px] font-semibold"
                                            >
                                                Can witness controlled drugs
                                            </label>
                                            <p className="text-caption">
                                                {cdOk
                                                    ? A.restricted
                                                        ? 'Not while restricted.'
                                                        : 'Recorded on the assessment — not checked when a dose is witnessed yet.'
                                                    : 'Needs the controlled drugs area passed.'}
                                            </p>
                                        </div>
                                        <OnOff
                                            id="aw-f-witness"
                                            checked={
                                                A.witness &&
                                                cdOk &&
                                                !A.restricted
                                            }
                                            disabled={!cdOk || A.restricted}
                                            onChange={(v) => up({ witness: v })}
                                        />
                                    </div>
                                    <div className="flex items-start justify-between gap-4 p-3">
                                        <div>
                                            <label
                                                htmlFor="aw-f-unsup"
                                                className="text-[13px] font-semibold"
                                            >
                                                Can give medicines unsupervised
                                            </label>
                                            <p className="text-caption">
                                                Recorded only — not used to
                                                decide who can record yet.
                                            </p>
                                        </div>
                                        <OnOff
                                            id="aw-f-unsup"
                                            checked={A.unsup}
                                            onChange={(v) => up({ unsup: v })}
                                        />
                                    </div>
                                </div>
                            ) : (
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    <b>
                                        They won’t be able to record doses as
                                        given.
                                    </b>{' '}
                                    Refused, withheld and away can still be
                                    recorded. Plan a remedial assessment, and
                                    note what to work on in the next step.
                                </InfoCard>
                            )}
                            {px ? (
                                <>
                                    <p className="text-sm font-semibold">
                                        What {firstName(px)} will be able to do
                                    </p>
                                    <CanList
                                        items={abilities(px, policy, areas)}
                                    />
                                </>
                            ) : null}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead
                                icon={Check}
                                title="Review & record"
                                blurb="Check everything, then declare."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard
                                    icon={User}
                                    title="Person & context"
                                    onEdit={() => setStep(0)}
                                >
                                    <ReviewRow label="Person" value={x?.name} />
                                    <ReviewRow
                                        label="Type"
                                        value={
                                            TYPE_TILES.find(
                                                (t) => t.key === A.type,
                                            )?.label
                                        }
                                    />
                                    <ReviewRow
                                        label="Assessed"
                                        value={`${day(A.date)} by ${assessor}`}
                                    />
                                    <ReviewRow
                                        label="Ends"
                                        value={pass ? day(A.until) : '—'}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ClipboardCheck}
                                    title="Areas"
                                    onEdit={() => setStep(1)}
                                >
                                    <ReviewRow
                                        label="Result"
                                        value={
                                            <b>
                                                {pass ? 'Passed' : 'Not passed'}{' '}
                                                · {passed} of 12
                                            </b>
                                        }
                                    />
                                    <ReviewRow
                                        label="Not passed"
                                        value={
                                            areas
                                                .filter(
                                                    (a) =>
                                                        A.res[a.key] === 'no',
                                                )
                                                .map((a) => a.label)
                                                .join(', ') || 'None'
                                        }
                                    />
                                    <ReviewRow
                                        label="Not assessed"
                                        value={
                                            areas
                                                .filter(
                                                    (a) =>
                                                        A.res[a.key] ===
                                                        'unseen',
                                                )
                                                .map((a) => a.label)
                                                .join(', ') || 'None'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={Activity}
                                    title="Observed"
                                    onEdit={() => setStep(2)}
                                >
                                    <ReviewRow
                                        label="Administrations"
                                        value={String(logged)}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ShieldCheck}
                                    title="Result"
                                    onEdit={() => setStep(3)}
                                >
                                    <ReviewRow
                                        label="Restricted"
                                        value={
                                            A.restricted && pass
                                                ? A.rnotes
                                                : 'No'
                                        }
                                    />
                                    <ReviewRow
                                        label="Can witness"
                                        value={
                                            A.witness &&
                                            pass &&
                                            cdOk &&
                                            !A.restricted
                                                ? 'On'
                                                : 'Off'
                                        }
                                    />
                                    <ReviewRow
                                        label="Can give unsupervised"
                                        value={
                                            A.unsup && pass
                                                ? 'On (recorded only)'
                                                : 'Off'
                                        }
                                    />
                                </ReviewCard>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="What went well" htmlFor="aw-s">
                                    <Textarea
                                        id="aw-s"
                                        rows={2}
                                        value={A.strengths}
                                        onChange={(e) =>
                                            up({ strengths: e.target.value })
                                        }
                                    />
                                </Field>
                                <Field label="What to work on" htmlFor="aw-i">
                                    <Textarea
                                        id="aw-i"
                                        rows={2}
                                        value={A.improve}
                                        onChange={(e) =>
                                            up({ improve: e.target.value })
                                        }
                                    />
                                </Field>
                            </div>
                            <div className="flex items-start justify-between gap-4 rounded-xl border p-3">
                                <div>
                                    <label
                                        htmlFor="aw-f-declared"
                                        className="text-[13px] font-semibold"
                                    >
                                        I observed this assessment myself and
                                        the results are accurate{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </label>
                                    <p className="text-caption">
                                        {x ? firstName(x) : 'The person'} then
                                        acknowledges it from their own login —
                                        only they can.
                                    </p>
                                    {errs.declared ? (
                                        <p
                                            className="mt-1 text-xs text-status-critical"
                                            role="alert"
                                        >
                                            {errs.declared}
                                        </p>
                                    ) : null}
                                </div>
                                <span
                                    aria-invalid={!!errs.declared || undefined}
                                >
                                    <OnOff
                                        id="aw-f-declared"
                                        checked={A.declared}
                                        onChange={(v) => {
                                            up({ declared: v });
                                            setErrs({});
                                        }}
                                    />
                                </span>
                            </div>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog
                open={guard}
                mode={editing ? 'edit' : 'create'}
                description="Nothing you’ve entered in this assessment has been saved. Closing now loses it."
                onKeepEditing={() => setGuard(false)}
                onDiscard={() => {
                    setGuard(false);
                    onClose();
                }}
            />
        </>
    );
}
