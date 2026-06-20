import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { ChipMulti, Field, InfoCard, Ring, Segmented, SelectInput, StepHead, SubHead, TilePicker } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane, type WizardStep } from '@/components/wizard/shell';
import {
    durationLabel,
    RESTRAINT_TYPE_META,
    RESTRAINT_TYPE_OPTIONS,
    SEVERITY_OPTIONS,
    titleCase,
    type ClientOption,
    type IncidentOption,
    type SiteOption,
    type StaffOption,
} from '@/pages/health-safety/restraints/shared';
import { useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Camera,
    CheckCircle2,
    ClipboardList,
    HeartPulse,
    ShieldAlert,
    ShieldCheck,
    User,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

export type PlanPickerOption = { id: number; client_id: number | null; title: string; status: string; reference: string; restrictive_practice_type: string | null };

export type Prescope = { client_id: number; client_name?: string; site_id?: number | null; stay_id?: number | null; behaviour_support_plan_id?: number | null };

type EventForm = {
    client_id: string;
    behaviour_support_plan_id: string;
    related_incident_id: string;
    stay_id: string;
    site_id: string;
    started_at: string;
    ended_at: string;
    restraint_type: string;
    severity: string;
    trigger_description: string;
    de_escalation_attempted: string;
    restraint_description: string;
    person_response: string;
    staff_involved: number[];
    injury_occurred: boolean;
    injury_details: string;
    post_incident_support: string;
    within_support_plan: boolean;
    deviation_reason: string;
    authorised_by: string;
};

const STEPS: WizardStep[] = [
    { key: 'context', label: 'Person & context', blurb: 'Who, where, linked plan', icon: User },
    { key: 'episode', label: 'The episode', blurb: 'Type, time & severity', icon: ShieldAlert },
    { key: 'trigger', label: 'Trigger & de-escalation', blurb: 'What led to it', icon: Activity },
    { key: 'response', label: 'Restraint & response', blurb: 'What was done', icon: ClipboardList },
    { key: 'injury', label: 'Injury & aftercare', blurb: 'Harm & support', icon: HeartPulse },
    { key: 'adherence', label: 'Plan adherence', blurb: 'Within plan?', icon: ShieldCheck },
    { key: 'review', label: 'Review & record', blurb: 'Check and save', icon: CheckCircle2 },
];

const TYPE_TILES = RESTRAINT_TYPE_OPTIONS.map((o) => ({
    key: o.value,
    label: o.label,
    description: RESTRAINT_TYPE_META[o.value]?.blurb,
    icon: RESTRAINT_TYPE_META[o.value]?.icon,
    accent: undefined as string | undefined,
}));

// error field prefix → owning step index (mirrors Add-Client's stepForError)
const ERROR_STEP: { prefix: string; step: number }[] = [
    { prefix: 'client_id', step: 0 },
    { prefix: 'behaviour_support_plan_id', step: 0 },
    { prefix: 'related_incident_id', step: 0 },
    { prefix: 'site_id', step: 0 },
    { prefix: 'stay_id', step: 0 },
    { prefix: 'restraint_type', step: 1 },
    { prefix: 'severity', step: 1 },
    { prefix: 'started_at', step: 1 },
    { prefix: 'ended_at', step: 1 },
    { prefix: 'duration_minutes', step: 1 },
    { prefix: 'trigger_description', step: 2 },
    { prefix: 'de_escalation_attempted', step: 2 },
    { prefix: 'restraint_description', step: 3 },
    { prefix: 'person_response', step: 3 },
    { prefix: 'staff_involved', step: 3 },
    { prefix: 'injury', step: 4 },
    { prefix: 'post_incident_support', step: 4 },
    { prefix: 'within_support_plan', step: 5 },
    { prefix: 'deviation_reason', step: 5 },
    { prefix: 'authorised_by', step: 5 },
];

function localNow(): string {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

/**
 * Restraint event wizard — the Add-Client idiom (stepper rail + completeness ring +
 * Save / Save & add another) on the shared WizardShell. Captures the full NZ
 * least-restrictive-practice dataset. One component, two entry points: the register
 * (full picker) and respite (prescoped to a stay). Evidence (body maps, injury
 * photos) attaches from the event detail once the record exists.
 */
export function RestraintEventWizard({
    open,
    onClose,
    clients,
    sites,
    staff,
    incidents,
    plans,
    prescope,
    onOpenEvent,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOption[];
    sites: SiteOption[];
    staff: StaffOption[];
    incidents: IncidentOption[];
    plans: PlanPickerOption[];
    prescope?: Prescope;
    onOpenEvent?: (id: number) => void;
}) {
    const page = usePage().props as { flash?: { error?: string }; detail?: { id?: number } | null };
    const [stepIndex, setStepIndex] = useState(0);
    const [submitted, setSubmitted] = useState(false);

    const form = useForm<EventForm>({
        client_id: prescope ? String(prescope.client_id) : '',
        behaviour_support_plan_id: prescope?.behaviour_support_plan_id ? String(prescope.behaviour_support_plan_id) : '',
        related_incident_id: '',
        stay_id: prescope?.stay_id ? String(prescope.stay_id) : '',
        site_id: prescope?.site_id ? String(prescope.site_id) : '',
        started_at: localNow(),
        ended_at: '',
        restraint_type: '',
        severity: 'medium',
        trigger_description: '',
        de_escalation_attempted: '',
        restraint_description: '',
        person_response: '',
        staff_involved: [],
        injury_occurred: false,
        injury_details: '',
        post_incident_support: '',
        within_support_plan: true,
        deviation_reason: '',
        authorised_by: '',
    });
    const { data, setData, errors, processing } = form;

    const lastIndex = STEPS.length - 1;
    const stepKey = STEPS[stepIndex].key;

    const clientOptions = useMemo(() => clients.map((c) => ({ value: String(c.id), label: c.name })), [clients]);
    const siteOptions = useMemo(() => sites.map((s) => ({ value: String(s.id), label: s.name })), [sites]);
    const staffOptions = useMemo(() => staff.map((s) => ({ value: String(s.id), label: s.name })), [staff]);

    const clientId = data.client_id ? Number(data.client_id) : null;
    const clientPlans = useMemo(
        () => plans.filter((p) => p.client_id === clientId).map((p) => ({ value: String(p.id), label: `${p.reference} · ${p.title}${p.status !== 'active' ? ` (${titleCase(p.status)})` : ''}` })),
        [plans, clientId],
    );
    const clientIncidents = useMemo(
        () => incidents.filter((i) => i.client_id === clientId).map((i) => ({ value: String(i.id), label: i.label })),
        [incidents, clientId],
    );

    const onClient = (v: string) => {
        setData((d) => {
            const next = { ...d, client_id: v, behaviour_support_plan_id: '', related_incident_id: '' };
            // Auto-fill the site from the chosen client (still editable).
            const c = clients.find((x) => String(x.id) === v);
            if (c?.site_id && !prescope?.site_id) next.site_id = String(c.site_id);
            return next;
        });
    };

    const durationPreview = useMemo(() => {
        if (!data.started_at || !data.ended_at) return null;
        const start = new Date(data.started_at).getTime();
        const end = new Date(data.ended_at).getTime();
        if (Number.isNaN(start) || Number.isNaN(end) || end <= start) return null;
        return Math.round((end - start) / 60000);
    }, [data.started_at, data.ended_at]);

    const pct = useMemo(() => {
        const checks = [
            !!data.client_id,
            !!data.restraint_type,
            !!data.started_at,
            !!data.severity,
            !!data.trigger_description.trim(),
            !!data.de_escalation_attempted.trim(),
            !!data.restraint_description.trim(),
            data.within_support_plan || !!data.deviation_reason.trim(),
            !data.injury_occurred || !!data.injury_details.trim(),
        ];
        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
    }, [data]);

    const stepValid = (key: string): boolean => {
        switch (key) {
            case 'context':
                return !!data.client_id;
            case 'episode':
                return !!data.restraint_type && !!data.started_at && !!data.severity;
            case 'trigger':
                return !!data.trigger_description.trim() && !!data.de_escalation_attempted.trim();
            case 'response':
                return !!data.restraint_description.trim();
            case 'injury':
                return !data.injury_occurred || !!data.injury_details.trim();
            case 'adherence':
                return data.within_support_plan || !!data.deviation_reason.trim();
            default:
                return true;
        }
    };

    const canSubmit =
        !!data.client_id &&
        !!data.restraint_type &&
        !!data.severity &&
        !!data.started_at &&
        !!data.trigger_description.trim() &&
        !!data.de_escalation_attempted.trim() &&
        !!data.restraint_description.trim() &&
        (data.within_support_plan || !!data.deviation_reason.trim()) &&
        (!data.injury_occurred || !!data.injury_details.trim());

    const jumpToFirstError = (errKeys: string[]) => {
        for (const k of errKeys) {
            const m = ERROR_STEP.find((e) => k.startsWith(e.prefix));
            if (m) {
                setStepIndex(m.step);
                return;
            }
        }
    };

    const submit = (addAnother: boolean) => {
        form.transform((d) => ({
            ...d,
            client_id: d.client_id ? Number(d.client_id) : null,
            behaviour_support_plan_id: d.behaviour_support_plan_id ? Number(d.behaviour_support_plan_id) : null,
            related_incident_id: d.related_incident_id ? Number(d.related_incident_id) : null,
            stay_id: d.stay_id ? Number(d.stay_id) : null,
            site_id: d.site_id ? Number(d.site_id) : null,
            ended_at: d.ended_at || null,
            authorised_by: d.authorised_by ? Number(d.authorised_by) : null,
            deviation_reason: d.within_support_plan ? null : d.deviation_reason,
            injury_details: d.injury_occurred ? d.injury_details : null,
            stay: prescope?.stay_id ? true : undefined,
        }));
        form.post('/health-safety/restraints/events', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (page.flash?.error) return;
                if (addAnother) {
                    resetForm();
                } else {
                    setSubmitted(true);
                }
            },
            onError: (e) => jumpToFirstError(Object.keys(e)),
        });
    };

    const resetForm = () => {
        form.reset();
        form.clearErrors();
        if (prescope) {
            setData((d) => ({
                ...d,
                client_id: String(prescope.client_id),
                behaviour_support_plan_id: prescope.behaviour_support_plan_id ? String(prescope.behaviour_support_plan_id) : '',
                site_id: prescope.site_id ? String(prescope.site_id) : '',
                stay_id: prescope.stay_id ? String(prescope.stay_id) : '',
                started_at: localNow(),
            }));
        } else {
            setData('started_at', localNow());
        }
        setStepIndex(0);
    };

    const createdId = page.detail?.id;
    const success = submitted ? (
        <WizardSuccessPane
            title="Restraint event recorded"
            blurb={
                <>
                    Recorded against the least-restrictive-practice register and mirrored to Health &amp; Safety.
                    {data.injury_occurred || !data.within_support_plan ? ' A Control Room alert was raised for review.' : ''} You can now attach body maps, injury photos and
                    authorisation forms from the event detail.
                </>
            }
            actions={
                <>
                    {createdId && onOpenEvent ? <Button onClick={() => onOpenEvent(createdId)}>Open event</Button> : null}
                    <Button variant="outline" onClick={resetForm}>
                        Record another
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
            title="Record a restraint event"
            description="Capture a restrictive-practice episode"
            railIcon={ShieldAlert}
            railTitle="Restraint event"
            railSub={prescope?.client_name ? `For ${prescope.client_name}` : 'Restrictive-practice episode'}
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={submitted ? null : pct}
            footerStart={submitted ? undefined : <Ring pct={pct} size={40} />}
            footerEnd={
                submitted ? undefined : (
                    <div className="flex items-center gap-2">
                        {stepIndex > 0 ? (
                            <Button variant="outline" onClick={() => setStepIndex((i) => Math.max(0, i - 1))}>
                                Back
                            </Button>
                        ) : null}
                        {stepIndex < lastIndex ? (
                            <Button onClick={() => setStepIndex((i) => Math.min(lastIndex, i + 1))} disabled={!stepValid(stepKey)}>
                                Continue
                            </Button>
                        ) : (
                            <>
                                <Button variant="outline" onClick={() => submit(true)} disabled={processing || !canSubmit}>
                                    Save &amp; add another
                                </Button>
                                <Button onClick={() => submit(false)} disabled={processing || !canSubmit}>
                                    Save event
                                </Button>
                            </>
                        )}
                    </div>
                )
            }
            success={success}
        >
            <WizardStepPane>
                {stepKey === 'context' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={User} title="Person & context" blurb="Who was supported, where, and which plan applies." />
                        {prescope ? (
                            <InfoCard icon={User} tone="info">
                                Recording against <span className="font-semibold">{prescope.client_name ?? 'this client'}</span>
                                {prescope.stay_id ? ' for the current respite stay' : ''}. The active behaviour support plan links automatically where one exists.
                            </InfoCard>
                        ) : (
                            <Field label="Client" required error={errors.client_id}>
                                <SelectInput value={data.client_id} onChange={onClient} placeholder="Select client" options={clientOptions} />
                            </Field>
                        )}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Site" error={errors.site_id}>
                                <SelectInput value={data.site_id} onChange={(v) => setData('site_id', v)} placeholder="Select site" options={siteOptions} />
                            </Field>
                            <Field label="Linked behaviour support plan" hint={clientId ? undefined : 'Pick a client first'} error={errors.behaviour_support_plan_id}>
                                <SelectInput
                                    value={data.behaviour_support_plan_id}
                                    onChange={(v) => setData('behaviour_support_plan_id', v)}
                                    placeholder={clientPlans.length ? 'Link a plan' : 'No active plan for this client'}
                                    options={clientPlans}
                                />
                            </Field>
                        </div>
                        <Field label="Related incident" hint="Optional — link an incident report" error={errors.related_incident_id}>
                            <SelectInput
                                value={data.related_incident_id}
                                onChange={(v) => setData('related_incident_id', v)}
                                placeholder={clientIncidents.length ? 'Link an incident' : 'No recent incidents for this client'}
                                options={clientIncidents}
                            />
                        </Field>
                        {clientId && clientPlans.length === 0 ? (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                This client has no active behaviour support plan. Restrictive practice should be governed by a current plan — consider creating one.
                            </InfoCard>
                        ) : null}
                    </div>
                ) : null}

                {stepKey === 'episode' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={ShieldAlert} title="The episode" blurb="What type of restrictive practice, and for how long." />
                        <Field label="Type of restrictive practice" required error={errors.restraint_type}>
                            <TilePicker value={data.restraint_type} onChange={(v) => setData('restraint_type', v)} options={TYPE_TILES} cols={3} />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Started at" required error={errors.started_at}>
                                <Input type="datetime-local" value={data.started_at} onChange={(e) => setData('started_at', e.target.value)} />
                            </Field>
                            <Field label="Ended at" hint="Optional" error={errors.ended_at}>
                                <Input type="datetime-local" value={data.ended_at} onChange={(e) => setData('ended_at', e.target.value)} />
                            </Field>
                        </div>
                        {durationPreview != null ? (
                            <InfoCard icon={Activity} tone="info">
                                Duration: <span className="font-semibold">{durationLabel(durationPreview)}</span>. Shorter is safer — least-restrictive practice means the minimum necessary.
                            </InfoCard>
                        ) : null}
                        <Field label="Severity" required error={errors.severity}>
                            <Segmented value={data.severity} onChange={(v) => setData('severity', v)} options={SEVERITY_OPTIONS.map((o) => ({ value: o.value, label: o.label }))} />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'trigger' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={Activity} title="Trigger & de-escalation" blurb="What led to the episode, and what was tried first." />
                        <Field label="Trigger / antecedent" required error={errors.trigger_description}>
                            <Textarea rows={3} value={data.trigger_description} onChange={(e) => setData('trigger_description', e.target.value)} placeholder="What was happening before the restraint?" />
                        </Field>
                        <InfoCard icon={ShieldCheck} tone="info">
                            <span className="font-semibold">Ngā Paerewa NZS 8134:2021:</span> restrictive practice is a last resort. Record the de-escalation and least-restrictive strategies tried first.
                        </InfoCard>
                        <Field label="De-escalation attempted" required error={errors.de_escalation_attempted}>
                            <Textarea rows={3} value={data.de_escalation_attempted} onChange={(e) => setData('de_escalation_attempted', e.target.value)} placeholder="What less-restrictive approaches were tried before restraint?" />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'response' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={ClipboardList} title="Restraint & response" blurb="What was done, who was involved, and how the person responded." />
                        <Field label="What restraint was used" required error={errors.restraint_description}>
                            <Textarea rows={3} value={data.restraint_description} onChange={(e) => setData('restraint_description', e.target.value)} placeholder="Describe the restrictive practice applied" />
                        </Field>
                        <Field label="How the person responded" error={errors.person_response}>
                            <Textarea rows={2} value={data.person_response} onChange={(e) => setData('person_response', e.target.value)} placeholder="The person's response during and after" />
                        </Field>
                        <Field label="Staff involved" hint="Select everyone present">
                            <StaffMultiSelect options={staffOptions} value={data.staff_involved} onChange={(v) => setData('staff_involved', v)} />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'injury' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={HeartPulse} title="Injury & aftercare" blurb="Was anyone harmed, and what support followed." />
                        <label className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-sm">
                            <input type="checkbox" checked={data.injury_occurred} onChange={(e) => setData('injury_occurred', e.target.checked)} className="h-4 w-4 rounded border-border" />
                            <span className="font-medium">An injury occurred during this episode</span>
                        </label>
                        {data.injury_occurred ? (
                            <>
                                <InfoCard icon={AlertTriangle} tone="warn">An injury escalates this event — it raises a Control Room alert and is mirrored to Health &amp; Safety at higher severity.</InfoCard>
                                <Field label="Injury details" required error={errors.injury_details}>
                                    <Textarea rows={3} value={data.injury_details} onChange={(e) => setData('injury_details', e.target.value)} placeholder="Who was injured, the nature of the injury, and treatment given" />
                                </Field>
                            </>
                        ) : null}
                        <Field label="Post-incident support" hint="Debrief, comfort, follow-up" error={errors.post_incident_support}>
                            <Textarea rows={2} value={data.post_incident_support} onChange={(e) => setData('post_incident_support', e.target.value)} placeholder="Support offered to the person (and staff) after the episode" />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'adherence' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={ShieldCheck} title="Plan adherence" blurb="Was this within the behaviour support plan?" />
                        <Field label="Within the support plan?" required>
                            <Segmented
                                value={data.within_support_plan ? 'yes' : 'no'}
                                onChange={(v) => setData('within_support_plan', v === 'yes')}
                                options={[
                                    { value: 'yes', label: 'Within plan' },
                                    { value: 'no', label: 'Outside plan' },
                                ]}
                            />
                        </Field>
                        {!data.within_support_plan ? (
                            <>
                                <InfoCard icon={AlertTriangle} tone="crit">
                                    Restraint outside the agreed plan is a deviation. It raises a Control Room alert for review and must be explained.
                                </InfoCard>
                                <Field label="Reason for deviation" required error={errors.deviation_reason}>
                                    <Textarea rows={3} value={data.deviation_reason} onChange={(e) => setData('deviation_reason', e.target.value)} placeholder="Why was restraint used outside the plan?" />
                                </Field>
                            </>
                        ) : null}
                        <Field label="Authorised by" hint="Who authorised the restraint" error={errors.authorised_by}>
                            <SelectInput value={data.authorised_by} onChange={(v) => setData('authorised_by', v)} placeholder="Select authoriser" options={staffOptions} />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'review' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={CheckCircle2} title="Review & record" blurb="Check the details, then save the event." />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard icon={User} title="Person & context" onEdit={() => setStepIndex(0)}>
                                <ReviewRow label="Client" value={clientOptions.find((o) => o.value === data.client_id)?.label ?? prescope?.client_name} />
                                <ReviewRow label="Site" value={siteOptions.find((o) => o.value === data.site_id)?.label} />
                                <ReviewRow label="Linked plan" value={clientPlans.find((o) => o.value === data.behaviour_support_plan_id)?.label} />
                                <ReviewRow label="Linked incident" value={clientIncidents.find((o) => o.value === data.related_incident_id)?.label} />
                            </ReviewCard>
                            <ReviewCard icon={ShieldAlert} title="The episode" onEdit={() => setStepIndex(1)}>
                                <ReviewRow label="Type" value={data.restraint_type ? titleCase(data.restraint_type) : undefined} />
                                <ReviewRow label="Severity" value={titleCase(data.severity)} />
                                <ReviewRow label="Started" value={data.started_at?.replace('T', ' ')} />
                                <ReviewRow label="Duration" value={durationPreview != null ? durationLabel(durationPreview) : undefined} />
                            </ReviewCard>
                            <ReviewCard icon={Activity} title="Trigger & de-escalation" span onEdit={() => setStepIndex(2)}>
                                <ReviewRow label="Trigger" value={data.trigger_description} />
                                <ReviewRow label="De-escalation" value={data.de_escalation_attempted} />
                            </ReviewCard>
                            <ReviewCard icon={ShieldCheck} title="Adherence & injury" onEdit={() => setStepIndex(5)}>
                                <ReviewRow label="Within plan" value={data.within_support_plan ? 'Yes' : 'No — deviation'} />
                                <ReviewRow label="Injury" value={data.injury_occurred ? 'Yes' : 'No'} />
                                <ReviewRow label="Authorised by" value={staffOptions.find((o) => o.value === data.authorised_by)?.label} />
                            </ReviewCard>
                            <ReviewCard icon={Users} title="Staff involved" onEdit={() => setStepIndex(3)}>
                                <p className="text-[13px] text-foreground">
                                    {data.staff_involved.length ? data.staff_involved.map((id) => staff.find((s) => s.id === id)?.name).filter(Boolean).join(', ') : <span className="text-muted-foreground">—</span>}
                                </p>
                            </ReviewCard>
                        </div>
                        <InfoCard icon={Camera} tone="info">
                            Body maps, injury photos and authorisation forms attach from the event detail once it&apos;s saved — open the event and use the Evidence section.
                        </InfoCard>
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Staff multi-select (id-based chips)                                */
/* ------------------------------------------------------------------ */

function StaffMultiSelect({ options, value, onChange }: { options: { value: string; label: string }[]; value: number[]; onChange: (v: number[]) => void }) {
    const toggle = (id: number) => onChange(value.includes(id) ? value.filter((x) => x !== id) : [...value, id]);
    if (options.length === 0) return <p className="text-sm text-muted-foreground">No staff available.</p>;
    return (
        <div className="flex max-h-44 flex-wrap gap-1.5 overflow-y-auto rounded-lg border border-border bg-card/50 p-2">
            {options.map((o) => {
                const id = Number(o.value);
                const active = value.includes(id);
                return (
                    <button
                        key={o.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => toggle(id)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                            active ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card text-foreground hover:border-primary/50',
                        )}
                    >
                        {active ? <CheckCircle2 className="h-3 w-3" /> : null}
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}
