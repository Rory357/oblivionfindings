import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Field, InfoCard, Ring, Segmented, SelectInput, StepHead, TilePicker } from '@/components/wizard/primitives';
import { useForm, usePage } from '@inertiajs/react';
import { Activity, AlertTriangle, CheckCircle2, ClipboardList, FileText, Landmark, Lock, Search, ShieldAlert, Users } from 'lucide-react';
import { useMemo, useState } from 'react';

type Opt = { id: number; name: string };

type RaiseForm = {
    subject_type: string;
    subject_id: string;
    other_subject_name: string;
    concern_type: string;
    description: string;
    witnesses: string;
    has_perpetrator: boolean;
    alleged_perpetrator_type: string;
    alleged_perpetrator_id: string;
    other_perpetrator_name: string;
    perpetrator_relationship: string;
    severity: string;
    abuse_category: string;
    occurred_at: string;
    location: string;
    immediate_action_description: string;
    subject_informed: string;
    requires_external_referral: string;
    is_sensitive: boolean;
    site_id: string;
};

const CONCERN_TYPES = [
    { key: 'abuse', label: 'Abuse' },
    { key: 'neglect', label: 'Neglect' },
    { key: 'self_neglect', label: 'Self-neglect' },
    { key: 'exploitation', label: 'Exploitation' },
    { key: 'discrimination', label: 'Discrimination' },
    { key: 'organisational', label: 'Organisational' },
];

const SEVERITY_TILES = [
    { key: 'low', label: 'Low', description: 'Low risk of harm', icon: CheckCircle2 },
    { key: 'medium', label: 'Medium', description: 'Some risk of harm', icon: Activity },
    { key: 'high', label: 'High', description: 'Serious risk of harm', icon: AlertTriangle },
    { key: 'critical', label: 'Critical', description: 'Immediate / severe risk', icon: ShieldAlert },
];

const ABUSE_CATEGORIES = [
    { key: 'physical', label: 'Physical' },
    { key: 'sexual', label: 'Sexual' },
    { key: 'emotional', label: 'Emotional' },
    { key: 'psychological', label: 'Psychological' },
    { key: 'financial', label: 'Financial' },
    { key: 'discriminatory', label: 'Discriminatory' },
    { key: 'institutional', label: 'Institutional' },
    { key: 'neglect', label: 'Neglect' },
    { key: 'self-neglect', label: 'Self-neglect' },
    { key: 'domestic_violence', label: 'Domestic violence' },
    { key: 'modern_slavery', label: 'Modern slavery' },
    { key: 'other', label: 'Other' },
];

const LIKELY_CRIMINAL = ['sexual', 'physical', 'financial', 'modern_slavery'];

const STEPS = [
    { key: 'subject', label: 'Subject & type', blurb: 'Who & what kind', icon: Users },
    { key: 'what', label: 'What happened', blurb: 'Describe it', icon: Search },
    { key: 'severity', label: 'Severity & category', blurb: 'Risk & abuse type', icon: Activity },
    { key: 'response', label: 'Immediate response', blurb: 'Actions & subject', icon: CheckCircle2 },
    { key: 'referral', label: 'External referral', blurb: 'NZ authority check', icon: Landmark },
    { key: 'review', label: 'Review', blurb: 'Raise the concern', icon: ClipboardList },
];

export function SafeguardingRaiseWizard({
    open,
    onClose,
    clients,
    staff,
    sites,
    onOpenConcern,
}: {
    open: boolean;
    onClose: () => void;
    clients: Opt[];
    staff: Opt[];
    sites: Opt[];
    onOpenConcern?: (id: number) => void;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [submitted, setSubmitted] = useState(false);
    const page = usePage().props as { flash?: { created_concern_id?: number; error?: string } };

    const form = useForm<RaiseForm>({
        subject_type: 'client',
        subject_id: '',
        other_subject_name: '',
        concern_type: '',
        description: '',
        witnesses: '',
        has_perpetrator: false,
        alleged_perpetrator_type: '',
        alleged_perpetrator_id: '',
        other_perpetrator_name: '',
        perpetrator_relationship: '',
        severity: '',
        abuse_category: '',
        occurred_at: '',
        location: '',
        immediate_action_description: '',
        subject_informed: '',
        requires_external_referral: '',
        is_sensitive: false,
        site_id: '',
    });
    const d = form.data;

    const opt = (xs: Opt[]) => xs.map((x) => ({ value: String(x.id), label: x.name }));
    const clientOptions = opt(clients);
    const staffOptions = opt(staff);

    const subjectChosen = d.subject_type === 'other' ? !!d.other_subject_name.trim() : !!d.subject_id;

    const pct = useMemo(() => {
        const checks = [subjectChosen, !!d.concern_type, !!d.description.trim(), !!d.severity, !!d.subject_informed, !!d.requires_external_referral];
        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
    }, [d, subjectChosen]);

    const stepKey = STEPS[stepIndex].key;
    const lastIndex = STEPS.length - 1;
    const stepValid = (key: string): boolean => {
        switch (key) {
            case 'subject':
                return subjectChosen && !!d.concern_type;
            case 'what':
                return !!d.description.trim();
            case 'severity':
                return !!d.severity;
            case 'response':
                return !!d.subject_informed;
            case 'referral':
                return !!d.requires_external_referral;
            default:
                return true;
        }
    };
    const canSubmit = subjectChosen && !!d.concern_type && !!d.description.trim() && !!d.severity;

    const submit = () => {
        form.transform((data) => ({
            subject_type: data.subject_type || null,
            subject_id: (data.subject_type === 'client' || data.subject_type === 'staff') && data.subject_id ? Number(data.subject_id) : null,
            other_subject_name: data.subject_type === 'other' ? data.other_subject_name : null,
            concern_type: data.concern_type,
            abuse_category: data.abuse_category || null,
            severity: data.severity,
            description: data.description,
            occurred_at: data.occurred_at || null,
            location: data.location || null,
            witnesses: data.witnesses || null,
            alleged_perpetrator_type: data.has_perpetrator ? data.alleged_perpetrator_type || null : null,
            alleged_perpetrator_id: data.has_perpetrator && (data.alleged_perpetrator_type === 'client' || data.alleged_perpetrator_type === 'staff') && data.alleged_perpetrator_id ? Number(data.alleged_perpetrator_id) : null,
            other_perpetrator_name: data.has_perpetrator ? data.other_perpetrator_name || null : null,
            perpetrator_relationship: data.has_perpetrator ? data.perpetrator_relationship || null : null,
            immediate_action_taken: !!data.immediate_action_description.trim(),
            immediate_action_description: data.immediate_action_description || null,
            subject_informed: data.subject_informed === 'yes',
            requires_external_referral: data.requires_external_referral === 'yes',
            is_sensitive: data.is_sensitive,
            site_id: data.site_id ? Number(data.site_id) : null,
        }));
        form.post('/safeguarding', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (pg) => {
                const flash = (pg.props as { flash?: { error?: string } }).flash;
                if (!flash?.error) setSubmitted(true);
            },
        });
    };

    const reset = () => {
        form.reset();
        form.clearErrors();
        setStepIndex(0);
        setSubmitted(false);
    };

    const newId = page.flash?.created_concern_id;
    const subjectName = d.subject_type === 'other' ? d.other_subject_name : (d.subject_type === 'staff' ? staff : clients).find((x) => String(x.id) === d.subject_id)?.name ?? '—';

    const success = submitted ? (
        <WizardSuccessPane
            title="Concern raised"
            blurb={
                <>
                    Thank you for raising this. It is on the register as <span className="font-semibold">Awaiting triage</span>, a Health &amp; Safety event and Control Room alert were created automatically, and it is visible only to those who need to know. Triage is the next step.
                </>
            }
            actions={
                <>
                    {newId && onOpenConcern ? <Button onClick={() => onOpenConcern(newId)}>Open concern</Button> : null}
                    <Button variant="outline" onClick={reset}>
                        Raise another
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
            title="Raise a safeguarding concern"
            description="A confidential, blame-free safeguarding concern."
            railIcon={ShieldAlert}
            railTitle="Raise concern"
            railSub="Confidential · need-to-know"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={pct}
            footerStart={!submitted ? <Ring pct={pct} size={40} /> : undefined}
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
                                Next
                            </Button>
                        ) : (
                            <Button onClick={submit} disabled={form.processing || !canSubmit}>
                                Raise concern
                            </Button>
                        )}
                    </div>
                )
            }
            success={success}
        >
            <WizardStepPane>
                {stepKey === 'subject' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Users} title="Subject & concern type" blurb="Who is the concern about, and what kind of concern is it?" />
                        <Field label="Subject type" required>
                            <Segmented value={d.subject_type} onChange={(v) => form.setData('subject_type', v)} options={[{ value: 'client', label: 'Client' }, { value: 'staff', label: 'Staff' }, { value: 'other', label: 'Other person' }]} />
                        </Field>
                        {d.subject_type === 'other' ? (
                            <Field label="Subject name" required>
                                <Input value={d.other_subject_name} onChange={(e) => form.setData('other_subject_name', e.target.value)} placeholder="Name of the person at risk" />
                            </Field>
                        ) : (
                            <Field label={d.subject_type === 'staff' ? 'Staff member' : 'Client'} required error={form.errors.subject_id}>
                                <SelectInput value={d.subject_id} onChange={(v) => form.setData('subject_id', v)} placeholder={`Select ${d.subject_type === 'staff' ? 'staff member' : 'client'}`} options={d.subject_type === 'staff' ? staffOptions : clientOptions} />
                            </Field>
                        )}
                        <Field label="Concern type" required error={form.errors.concern_type}>
                            <TilePicker cols={3} value={d.concern_type} onChange={(v) => form.setData('concern_type', v)} options={CONCERN_TYPES} />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'what' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Search} title="What happened" blurb="Describe the concern factually — what was seen, heard or disclosed." />
                        <Field label="What was raised" required error={form.errors.description}>
                            <Textarea rows={5} value={d.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="What happened, where, and who was involved…" />
                        </Field>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="When it occurred" hint="Optional">
                                <Input type="datetime-local" value={d.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
                            </Field>
                            <Field label="Witnesses" hint="Optional">
                                <Input value={d.witnesses} onChange={(e) => form.setData('witnesses', e.target.value)} placeholder="Names of any witnesses" />
                            </Field>
                        </div>
                        <label className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-sm">
                            <input type="checkbox" checked={d.has_perpetrator} onChange={(e) => form.setData('has_perpetrator', e.target.checked)} className="h-4 w-4 rounded border-border" />
                            <span className="font-medium text-foreground">Record an alleged person</span>
                        </label>
                        {d.has_perpetrator ? (
                            <div className="flex flex-col gap-3 rounded-xl border border-border bg-muted/30 p-3">
                                <Field label="Who is alleged">
                                    <Segmented value={d.alleged_perpetrator_type} onChange={(v) => form.setData('alleged_perpetrator_type', v)} options={[{ value: 'client', label: 'Client' }, { value: 'staff', label: 'Staff' }, { value: 'family', label: 'Family/whānau' }, { value: 'other', label: 'Other' }]} />
                                </Field>
                                {d.alleged_perpetrator_type === 'client' || d.alleged_perpetrator_type === 'staff' ? (
                                    <Field label="Person">
                                        <SelectInput value={d.alleged_perpetrator_id} onChange={(v) => form.setData('alleged_perpetrator_id', v)} placeholder="Select" options={d.alleged_perpetrator_type === 'staff' ? staffOptions : clientOptions} />
                                    </Field>
                                ) : d.alleged_perpetrator_type ? (
                                    <Field label="Name">
                                        <Input value={d.other_perpetrator_name} onChange={(e) => form.setData('other_perpetrator_name', e.target.value)} placeholder="Name (if known)" />
                                    </Field>
                                ) : null}
                                <Field label="Relationship / details" hint="Optional">
                                    <Input value={d.perpetrator_relationship} onChange={(e) => form.setData('perpetrator_relationship', e.target.value)} placeholder="e.g. Visitor, co-resident, agency staff" />
                                </Field>
                            </div>
                        ) : null}
                        <InfoCard icon={FileText} tone="info">
                            Photos &amp; documents can be attached from the concern once it&apos;s raised.
                        </InfoCard>
                    </div>
                ) : null}

                {stepKey === 'severity' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Activity} title="Severity & abuse category" blurb="An initial sense of severity — triage will confirm the risk level." />
                        <Field label="Severity" required error={form.errors.severity}>
                            <TilePicker cols={2} value={d.severity} onChange={(v) => form.setData('severity', v)} options={SEVERITY_TILES} />
                        </Field>
                        <Field label="Abuse category" hint="Optional">
                            <TilePicker cols={3} value={d.abuse_category} onChange={(v) => form.setData('abuse_category', v)} options={ABUSE_CATEGORIES} />
                        </Field>
                    </div>
                ) : null}

                {stepKey === 'response' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={CheckCircle2} title="Immediate response & subject" blurb="What was done straight away to keep the person safe, and have they been told?" />
                        <Field label="Immediate response" hint="Optional">
                            <Textarea rows={3} value={d.immediate_action_description} onChange={(e) => form.setData('immediate_action_description', e.target.value)} placeholder="What did you do to make the person safe right now?" />
                        </Field>
                        <Field label="Has the subject been informed?" required>
                            <Segmented value={d.subject_informed} onChange={(v) => form.setData('subject_informed', v)} options={[{ value: 'yes', label: 'Yes' }, { value: 'not_yet', label: 'Not yet' }, { value: 'not_appropriate', label: 'Not appropriate' }]} />
                        </Field>
                        {d.subject_informed === 'not_appropriate' ? (
                            <InfoCard icon={AlertTriangle} tone="warn">Telling the subject isn&apos;t always appropriate (e.g. an active Police matter). This is recorded for triage.</InfoCard>
                        ) : null}
                    </div>
                ) : null}

                {stepKey === 'referral' ? (
                    <div className="flex flex-col gap-5">
                        <StepHead icon={Landmark} title="External-referral check" blurb="There's no single adult-safeguarding law in Aotearoa — it's a patchwork. Decide whether an external referral is indicated; you log the specific authority after raising." />
                        {LIKELY_CRIMINAL.includes(d.abuse_category) ? (
                            <InfoCard icon={ShieldAlert} tone="crit">
                                This may be a <span className="font-semibold">criminal matter</span> — consider notifying <span className="font-semibold">NZ Police</span> (and Oranga Tamariki if a child or young person is involved).
                            </InfoCard>
                        ) : (
                            <InfoCard icon={Landmark} tone="info">NZ authorities include Police, Oranga Tamariki, HDC, Te Whatu Ora, MSD–Disability Support Services, Privacy Commissioner, WorkSafe and the Coroner.</InfoCard>
                        )}
                        <Field label="Is an external referral indicated?" required>
                            <Segmented value={d.requires_external_referral} onChange={(v) => form.setData('requires_external_referral', v)} options={[{ value: 'yes', label: 'Refer externally' }, { value: 'no', label: 'No referral needed' }]} />
                        </Field>
                        {d.requires_external_referral === 'yes' ? (
                            <InfoCard icon={Landmark} tone="warn">The concern will be flagged for referral. Log the report to the chosen authority from the concern — it then moves to <b>Referred external</b>.</InfoCard>
                        ) : null}
                    </div>
                ) : null}

                {stepKey === 'review' ? (
                    <div className="flex flex-col gap-4">
                        <StepHead icon={ClipboardList} title="Review & raise" blurb="Check the details, then raise the concern." />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard icon={Users} title="Subject & type" onEdit={() => setStepIndex(0)}>
                                <ReviewRow label="Subject" value={subjectName} />
                                <ReviewRow label="Type" value={CONCERN_TYPES.find((t) => t.key === d.concern_type)?.label} />
                            </ReviewCard>
                            <ReviewCard icon={Search} title="What happened" onEdit={() => setStepIndex(1)}>
                                <ReviewRow label="Description" value={d.description} />
                                {d.has_perpetrator ? <ReviewRow label="Alleged person" value={d.other_perpetrator_name || d.alleged_perpetrator_type} /> : null}
                            </ReviewCard>
                            <ReviewCard icon={Activity} title="Severity & category" onEdit={() => setStepIndex(2)}>
                                <ReviewRow label="Severity" value={SEVERITY_TILES.find((s) => s.key === d.severity)?.label} />
                                <ReviewRow label="Category" value={ABUSE_CATEGORIES.find((c) => c.key === d.abuse_category)?.label} />
                            </ReviewCard>
                            <ReviewCard icon={CheckCircle2} title="Response" onEdit={() => setStepIndex(3)}>
                                <ReviewRow label="Immediate response" value={d.immediate_action_description} />
                                <ReviewRow label="Subject informed" value={d.subject_informed ? d.subject_informed.replace(/_/g, ' ') : undefined} />
                            </ReviewCard>
                            <ReviewCard icon={Landmark} title="External referral" span onEdit={() => setStepIndex(4)}>
                                <ReviewRow label="Referral" value={d.requires_external_referral === 'yes' ? 'Indicated — log the report after raising' : d.requires_external_referral === 'no' ? 'Not indicated' : undefined} />
                            </ReviewCard>
                        </div>
                        <label className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition-colors ${d.is_sensitive ? 'border-status-critical/40 bg-status-critical-bg/30' : 'border-border hover:bg-muted/40'}`}>
                            <input type="checkbox" checked={d.is_sensitive} onChange={(e) => form.setData('is_sensitive', e.target.checked)} className="mt-0.5 h-4 w-4 rounded border-border" />
                            <span className="min-w-0">
                                <span className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                                    <Lock className="h-3.5 w-3.5" /> Mark as sensitive — need-to-know
                                </span>
                                <span className="mt-0.5 block text-xs text-muted-foreground">
                                    Restricts the subject, category and evidence to the assigned lead, the reporter and staff cleared to view sensitive concerns. Everyone else sees only a redacted row.
                                </span>
                            </span>
                        </label>
                        <InfoCard icon={ShieldAlert} tone="info">Raising creates the concern as <b>Awaiting triage</b> and auto-generates a Health &amp; Safety event and Control Room alert.</InfoCard>
                    </div>
                ) : null}
            </WizardStepPane>
        </WizardShell>
    );
}
