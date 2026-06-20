/* eslint-disable no-restricted-syntax -- Bespoke full-height wizard surface built on the
 * shared WizardShell + wizard primitives (the Add-client modal contract). The ambulance
 * toggle and incident-mode tiles are styled native controls; every colour is a semantic
 * design token. This is the SINGLE record-first-aid experience — used by both the register
 * page and the command-centre launcher. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Ring,
    SelectInput,
    Segmented,
    StepHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import {
    INJURY_TYPES,
    injuryLabel,
    OUTCOMES,
    outcomeLabel,
    PERSON_TYPES,
    personTypeLabel,
} from '@/pages/health-safety/first-aid/options';
import { useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    HeartPulse,
    Link2,
    Loader2,
    MapPin,
    Plus,
    Stethoscope,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type Opt = { id: number; name: string };
type IncidentOpt = { id: number; reference: string; label: string };

type FirstAidForm = {
    site_id: string;
    treatment_date: string;
    treated_person_type: string;
    treated_person_id: string;
    client_id: string;
    treated_person_name: string;
    first_aider_id: string;
    injury_illness_type: string;
    body_part: string;
    injury_illness_description: string;
    treatment_given: string;
    treatment_outcome: string;
    ambulance_called: boolean;
    incident_mode: 'none' | 'link' | 'reported';
    related_incident_id: string;
    first_aider_notes: string;
    stay: boolean;
};

type StepKey = 'who' | 'injury' | 'treatment' | 'incident' | 'review';

const STEPS: WizardStep[] = [
    { key: 'who', label: 'Who & where', blurb: 'Site, person & first-aider', icon: MapPin },
    { key: 'injury', label: 'Injury / illness', blurb: 'What happened', icon: Activity },
    { key: 'treatment', label: 'Treatment & outcome', blurb: 'Care given', icon: Stethoscope },
    { key: 'incident', label: 'Incident & notes', blurb: 'Link & follow-up', icon: Link2 },
    { key: 'review', label: 'Review', blurb: 'Confirm & record', icon: ClipboardCheck },
];

function nowLocal(): string {
    // datetime-local wants `YYYY-MM-DDTHH:mm` in local time.
    const d = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function initialForm(): FirstAidForm {
    return {
        site_id: '',
        treatment_date: nowLocal(),
        treated_person_type: 'staff',
        treated_person_id: '',
        client_id: '',
        treated_person_name: '',
        first_aider_id: '',
        injury_illness_type: '',
        body_part: '',
        injury_illness_description: '',
        treatment_given: '',
        treatment_outcome: '',
        ambulance_called: false,
        incident_mode: 'none',
        related_incident_id: '',
        first_aider_notes: '',
        stay: false,
    };
}

export function FirstAidReportDialog({
    open,
    onClose,
    sites,
    firstAiders,
    clients,
    staff,
    incidents,
    onOpenRecord,
}: {
    open: boolean;
    onClose: () => void;
    sites: Opt[];
    firstAiders: Opt[];
    clients: Opt[];
    staff: Opt[];
    incidents: IncidentOpt[];
    onOpenRecord?: (id: number) => void;
}) {
    const page = usePage<{ flash?: { created_first_aid_id?: number | null; error?: string } }>();
    const form = useForm<FirstAidForm>(initialForm());
    const d = form.data;
    const [stepIndex, setStepIndex] = useState(0);
    const [submitted, setSubmitted] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const opt = (xs: Opt[]) => xs.map((x) => ({ value: String(x.id), label: x.name }));
    const isClient = d.treated_person_type === 'client';
    const isStaff = d.treated_person_type === 'staff';
    const lastIndex = STEPS.length - 1;
    const stepKey = STEPS[stepIndex].key as StepKey;

    const personChosen = isClient ? !!d.client_id : !!d.treated_person_name.trim();

    const pct = useMemo(() => {
        const checks = [
            !!d.site_id,
            personChosen,
            !!d.first_aider_id,
            !!d.injury_illness_type,
            !!d.injury_illness_description.trim(),
            !!d.treatment_given.trim(),
            !!d.treatment_outcome,
        ];
        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
    }, [d, personChosen]);

    const set = <K extends keyof FirstAidForm>(k: K, v: FirstAidForm[K]) =>
        // Inertia's setData value type doesn't simplify for a generic key; the call
        // site is type-safe via the K constraint above (Add-client wizard idiom).
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        form.setData(k, v as any);

    const fieldError = (name: string) => errors[name] ?? (form.errors as Record<string, string>)[name];

    const validateStep = (key: StepKey): Record<string, string> => {
        const e: Record<string, string> = {};
        if (key === 'who') {
            if (!d.site_id) e.site_id = 'Choose a site';
            if (isClient && !d.client_id) e.client_id = 'Choose the client treated';
            if (!isClient && !d.treated_person_name.trim()) e.treated_person_name = 'Enter who was treated';
            if (!d.first_aider_id) e.first_aider_id = 'Choose the first-aider who responded';
        }
        if (key === 'injury') {
            if (!d.injury_illness_type) e.injury_illness_type = 'Select the injury or illness';
            if (!d.injury_illness_description.trim()) e.injury_illness_description = 'Describe what happened';
        }
        if (key === 'treatment') {
            if (!d.treatment_given.trim()) e.treatment_given = 'Describe the treatment given';
            if (!d.treatment_outcome) e.treatment_outcome = 'Select an outcome';
        }
        if (key === 'incident') {
            if (d.incident_mode === 'link' && !d.related_incident_id) e.related_incident_id = 'Pick the incident to link';
        }
        return e;
    };

    const next = () => {
        const e = validateStep(stepKey);
        setErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(lastIndex, i + 1));
    };
    const back = () => setStepIndex((i) => Math.max(0, i - 1));

    const reset = () => {
        form.reset();
        form.clearErrors();
        setErrors({});
        setStepIndex(0);
        setSubmitted(false);
    };

    const submit = (stay: boolean) => {
        // Validate every gating step; jump to the first failure.
        const all: Record<string, string> = {};
        const order: StepKey[] = ['who', 'injury', 'treatment', 'incident'];
        for (const k of order) Object.assign(all, validateStep(k));
        if (Object.keys(all).length) {
            setErrors(all);
            const firstBad = order.find((k) => Object.keys(validateStep(k)).length) ?? 'who';
            setStepIndex(STEPS.findIndex((s) => s.key === firstBad));
            return;
        }
        setErrors({});

        form.transform(() => ({
            site_id: d.site_id ? Number(d.site_id) : null,
            treated_person_type: d.treated_person_type,
            treated_person_id: !isClient && d.treated_person_id ? Number(d.treated_person_id) : null,
            client_id: isClient && d.client_id ? Number(d.client_id) : null,
            treated_person_name: d.treated_person_name,
            treatment_date: d.treatment_date,
            first_aider_id: d.first_aider_id ? Number(d.first_aider_id) : null,
            injury_illness_type: d.injury_illness_type,
            body_part: d.body_part || null,
            injury_illness_description: d.injury_illness_description,
            treatment_given: d.treatment_given,
            treatment_outcome: d.treatment_outcome,
            ambulance_called: d.ambulance_called,
            incident_reported: d.incident_mode !== 'none',
            related_incident_id: d.incident_mode === 'link' && d.related_incident_id ? Number(d.related_incident_id) : null,
            first_aider_notes: d.first_aider_notes || null,
            stay,
        }));

        form.post('/health-safety/first-aid', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (pg) => {
                const flash = (pg.props as { flash?: { error?: string } }).flash;
                if (flash?.error) return;
                if (stay) reset();
                else setSubmitted(true);
            },
            onError: (errs) => {
                // Server rejected — jump to the step owning the first error.
                const first = Object.keys(errs)[0] ?? '';
                const stepFor: Record<string, StepKey> = {
                    site_id: 'who',
                    treated_person_name: 'who',
                    client_id: 'who',
                    first_aider_id: 'who',
                    treatment_date: 'who',
                    injury_illness_type: 'injury',
                    injury_illness_description: 'injury',
                    body_part: 'injury',
                    treatment_given: 'treatment',
                    treatment_outcome: 'treatment',
                    related_incident_id: 'incident',
                };
                const key = stepFor[first] ?? 'who';
                setStepIndex(STEPS.findIndex((s) => s.key === key));
            },
        });
    };

    const newId = page.props.flash?.created_first_aid_id ?? null;
    const success = submitted ? (
        <WizardSuccessPane
            title="Treatment recorded"
            blurb={
                <>
                    The first-aid treatment is on the register. Add evidence (ACC45, photos) or follow-ups
                    from the record at any time.
                </>
            }
            actions={
                <>
                    {newId && onOpenRecord ? (
                        <Button onClick={() => onOpenRecord(newId)}>
                            <HeartPulse className="h-4 w-4" /> View record
                        </Button>
                    ) : null}
                    <Button variant="outline" onClick={reset}>
                        <Plus className="h-4 w-4" /> Record another
                    </Button>
                    <Button variant="ghost" onClick={onClose}>
                        Done
                    </Button>
                </>
            }
        />
    ) : undefined;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Record a first-aid treatment"
            description="A guided form to log a first-aid treatment, its outcome and any incident link."
            railIcon={HeartPulse}
            railTitle="Record first aid"
            railSub="Treatment register"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={pct}
            footerStart={!submitted ? <Ring pct={pct} size={40} /> : undefined}
            footerEnd={
                submitted ? undefined : (
                    <>
                        {stepIndex > 0 ? (
                            <Button variant="ghost" onClick={back}>
                                <ChevronLeft className="h-4 w-4" /> Back
                            </Button>
                        ) : null}
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        {stepKey === 'review' ? (
                            <>
                                <Button variant="secondary" onClick={() => submit(true)} disabled={form.processing}>
                                    {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                                    Save &amp; add another
                                </Button>
                                <Button onClick={() => submit(false)} disabled={form.processing}>
                                    {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                                    Record treatment
                                </Button>
                            </>
                        ) : (
                            <Button onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                )
            }
            success={success}
        >
            <WizardStepPane>
                {stepKey === 'who' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={MapPin} title="Who & where" blurb="Site, the person treated, and the first-aider who responded." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Site" required error={fieldError('site_id')}>
                                <SelectInput value={d.site_id} onChange={(v) => set('site_id', v)} placeholder="Select site" options={opt(sites)} />
                            </Field>
                            <Field label="Treatment date & time">
                                <Input type="datetime-local" value={d.treatment_date} onChange={(e) => set('treatment_date', e.target.value)} />
                            </Field>
                            <Field label="Person type" span>
                                <Segmented
                                    value={d.treated_person_type}
                                    onChange={(v) => {
                                        // Switching person type clears the now-irrelevant link; keep the typed name.
                                        if (v !== 'client') set('client_id', '');
                                        if (v !== 'staff') set('treated_person_id', '');
                                        set('treated_person_type', v);
                                    }}
                                    options={PERSON_TYPES.map((p) => ({ value: p.value, label: p.label }))}
                                />
                            </Field>
                            {isClient ? (
                                <Field label="Client treated" required span error={fieldError('client_id')} hint="links to their profile">
                                    <SelectInput
                                        value={d.client_id}
                                        onChange={(v) => {
                                            set('client_id', v);
                                            const c = clients.find((x) => String(x.id) === v);
                                            if (c) set('treated_person_name', c.name);
                                        }}
                                        placeholder="Select client"
                                        options={opt(clients)}
                                    />
                                </Field>
                            ) : (
                                <Field label="Person treated" required span error={fieldError('treated_person_name')}>
                                    <Input
                                        value={d.treated_person_name}
                                        onChange={(e) => set('treated_person_name', e.target.value)}
                                        placeholder="Full name"
                                    />
                                </Field>
                            )}
                            {isStaff ? (
                                <Field label="Link to staff record" span hint="optional — links to their user profile">
                                    <SelectInput
                                        value={d.treated_person_id}
                                        onChange={(v) => {
                                            set('treated_person_id', v);
                                            const u = staff.find((x) => String(x.id) === v);
                                            if (u) set('treated_person_name', u.name);
                                        }}
                                        placeholder="Unlinked — use the name above"
                                        options={opt(staff)}
                                    />
                                </Field>
                            ) : null}
                            <Field label="First-aider" required span error={fieldError('first_aider_id')} hint="staff flagged is_first_aider">
                                <SelectInput value={d.first_aider_id} onChange={(v) => set('first_aider_id', v)} placeholder="Select first-aider" options={opt(firstAiders)} />
                            </Field>
                        </div>
                        {isClient ? (
                            <InfoCard icon={HeartPulse}>
                                This treatment links to the client&apos;s profile and appears on their read-only
                                <strong> First-aid treatments</strong> panel.
                            </InfoCard>
                        ) : null}
                    </div>
                ) : null}

                {stepKey === 'injury' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Activity} title="Injury / illness" blurb="What happened, where on the body, and how it presented." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Injury / illness type" required error={fieldError('injury_illness_type')}>
                                <SelectInput value={d.injury_illness_type} onChange={(v) => set('injury_illness_type', v)} placeholder="Select type" options={INJURY_TYPES} />
                            </Field>
                            <Field label="Body part" hint="optional">
                                <Input value={d.body_part} onChange={(e) => set('body_part', e.target.value)} placeholder="e.g. Left hand, Head" />
                            </Field>
                            <Field label="Description" required span error={fieldError('injury_illness_description')}>
                                <Textarea
                                    rows={3}
                                    value={d.injury_illness_description}
                                    onChange={(e) => set('injury_illness_description', e.target.value)}
                                    placeholder="How the injury or illness presented…"
                                />
                            </Field>
                        </div>
                    </div>
                ) : null}

                {stepKey === 'treatment' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Stethoscope} title="Treatment & outcome" blurb="The care given and where the person went next." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Treatment given" required span error={fieldError('treatment_given')}>
                                <Textarea
                                    rows={3}
                                    value={d.treatment_given}
                                    onChange={(e) => set('treatment_given', e.target.value)}
                                    placeholder="What first aid was administered…"
                                />
                            </Field>
                            <Field label="Outcome" required error={fieldError('treatment_outcome')}>
                                <SelectInput value={d.treatment_outcome} onChange={(v) => set('treatment_outcome', v)} placeholder="Select outcome" options={OUTCOMES} />
                            </Field>
                            <Field label="Ambulance called">
                                <div className="flex h-10 items-center gap-2.5 rounded-md border border-border bg-card px-3">
                                    <Switch checked={d.ambulance_called} onCheckedChange={(v) => set('ambulance_called', v)} />
                                    <span className="text-[13px] text-muted-foreground">
                                        {d.ambulance_called ? '111 ambulance was called' : 'No ambulance called'}
                                    </span>
                                </div>
                            </Field>
                        </div>
                        {d.ambulance_called ? (
                            <InfoCard icon={Activity} tone="warn">
                                Ambulance escalations are surfaced for WorkSafe review. Consider linking an incident on the
                                next step.
                            </InfoCard>
                        ) : null}
                    </div>
                ) : null}

                {stepKey === 'incident' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Link2} title="Incident link & notes" blurb="Connect this treatment to its incident and add any follow-up." />
                        <Field label="Incident linkage">
                            <div className="grid gap-2 sm:grid-cols-3">
                                {(
                                    [
                                        { key: 'none', label: 'No link', sub: 'First aid only' },
                                        { key: 'link', label: 'Link incident', sub: 'Pick existing' },
                                        { key: 'reported', label: 'Mark reportable', sub: 'Escalate later' },
                                    ] as const
                                ).map((o) => {
                                    const active = d.incident_mode === o.key;
                                    return (
                                        <button
                                            key={o.key}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() => set('incident_mode', o.key)}
                                            className={`flex flex-col gap-0.5 rounded-lg border p-3 text-left transition-colors ${
                                                active ? 'border-primary bg-primary/10 ring-1 ring-primary/40' : 'border-border bg-card/50 hover:border-primary/50'
                                            }`}
                                        >
                                            <span className="text-sm font-semibold">{o.label}</span>
                                            <span className="text-xs text-muted-foreground">{o.sub}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </Field>
                        {d.incident_mode === 'link' ? (
                            <Field label="Related incident" required error={fieldError('related_incident_id')}>
                                <SelectInput
                                    value={d.related_incident_id}
                                    onChange={(v) => set('related_incident_id', v)}
                                    placeholder="Search recent incidents…"
                                    options={incidents.map((i) => ({ value: String(i.id), label: i.label }))}
                                />
                            </Field>
                        ) : null}
                        <Field label="First-aider notes" hint="optional">
                            <Textarea
                                rows={3}
                                value={d.first_aider_notes}
                                onChange={(e) => set('first_aider_notes', e.target.value)}
                                placeholder="Follow-up, whānau notified, ACC45 lodged…"
                            />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'review' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={ClipboardCheck} title="Review & record" blurb="Confirm the treatment, then record — or record and add another." />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard icon={MapPin} title="Who & where" onEdit={() => setStepIndex(0)}>
                                <ReviewRow label="Site" value={sites.find((s) => String(s.id) === d.site_id)?.name} />
                                <ReviewRow label="Person" value={`${d.treated_person_name || '—'} · ${personTypeLabel(d.treated_person_type)}`} />
                                <ReviewRow label="First-aider" value={firstAiders.find((s) => String(s.id) === d.first_aider_id)?.name} />
                            </ReviewCard>
                            <ReviewCard icon={Activity} title="Injury & outcome" onEdit={() => setStepIndex(1)}>
                                <ReviewRow label="Injury" value={d.injury_illness_type ? injuryLabel(d.injury_illness_type) : undefined} />
                                <ReviewRow label="Outcome" value={d.treatment_outcome ? outcomeLabel(d.treatment_outcome) : undefined} />
                                <ReviewRow label="Ambulance" value={d.ambulance_called ? 'Yes — 111 called' : 'No'} />
                            </ReviewCard>
                            <ReviewCard icon={Link2} title="Incident & notes" span onEdit={() => setStepIndex(3)}>
                                <ReviewRow
                                    label="Linkage"
                                    value={
                                        d.incident_mode === 'none'
                                            ? 'Not linked'
                                            : d.incident_mode === 'link'
                                              ? (incidents.find((i) => String(i.id) === d.related_incident_id)?.reference ?? 'Linked')
                                              : 'Marked reportable'
                                    }
                                />
                                {d.first_aider_notes.trim() ? <ReviewRow label="Notes" value={d.first_aider_notes} /> : null}
                            </ReviewCard>
                        </div>
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}
