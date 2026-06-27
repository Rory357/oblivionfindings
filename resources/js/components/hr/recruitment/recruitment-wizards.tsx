/* eslint-disable no-restricted-syntax -- Recruitment workflow wizards built on
 * the shared WizardShell (resources/js/components/wizard/shell.tsx) + primitives,
 * mirroring the leave-request-dialog plumbing (useForm + flash-gated success +
 * confetti). A handful of on-brand styled toggles/dropzones use native controls;
 * every colour is a semantic design token. */
import { router, useForm, usePage } from '@inertiajs/react';
import {
    Briefcase,
    CalendarPlus,
    CheckCircle2,
    FileText,
    Megaphone,
    PenLine,
    Send,
    ShieldAlert,
    ShieldCheck,
    Sparkles,
    Upload,
    UserCheck,
    UserPlus,
    Users,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import { toast } from 'sonner';

import {
    Field,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { fireConfetti } from '@/lib/confetti';
import { cn } from '@/lib/utils';
import { initials } from './stage';

/* ------------------------------------------------------------------ */
/*  Shared types                                                       */
/* ------------------------------------------------------------------ */

export type RecruitmentSupport = {
    sites: { id: number; name: string }[];
    roles: { value: string; label: string }[];
    hiring_managers: { id: number; name: string; email: string }[];
    interview_kits: { id: number; name: string; role: string | null }[];
    positions: { id: number; label: string; role: string | null; employment_type: string | null; vacancies: number }[];
    sources: string[];
    employment_types: string[];
    document_categories: Record<string, string>;
    stages: string[];
};

export type WizardKind =
    | 'add'
    | 'requisition'
    | 'interview'
    | 'offer'
    | 'convert'
    | 'reference'
    | 'reject'
    | 'document';

export type WizardContext = {
    candidateId?: number;
    applicationId?: number;
    offerId?: number;
    candidateName?: string;
    role?: string;
    requisitionId?: number;
    siteId?: number;
    canManageEmployees?: boolean;
};

export type WizardState = { kind: WizardKind; context?: WizardContext };

/* ------------------------------------------------------------------ */
/*  Small native helpers                                               */
/* ------------------------------------------------------------------ */

function Toggle({
    checked,
    onChange,
    title,
    sub,
    tone = 'default',
}: {
    checked: boolean;
    onChange: (v: boolean) => void;
    title: string;
    sub?: string;
    tone?: 'default' | 'muted';
}) {
    return (
        <div
            className={cn(
                'flex items-center gap-3 rounded-xl border border-border p-3',
                tone === 'muted' && 'bg-muted',
            )}
        >
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                aria-label={title}
                onClick={() => onChange(!checked)}
                className={cn(
                    'relative h-[22px] w-[38px] shrink-0 rounded-full transition-colors',
                    checked ? 'bg-primary' : 'bg-muted-foreground/30',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 h-[18px] w-[18px] rounded-full bg-white shadow transition-[left]',
                        checked ? 'left-[18px]' : 'left-0.5',
                    )}
                />
            </button>
            <div className="min-w-0 flex-1">
                <div className="text-[13px] font-bold">{title}</div>
                {sub ? <div className="text-[11.5px] text-muted-foreground">{sub}</div> : null}
            </div>
        </div>
    );
}

function Txt({
    label,
    value,
    onChange,
    type = 'text',
    placeholder,
    hint,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    type?: string;
    placeholder?: string;
    hint?: string;
}) {
    return (
        <Field label={label} hint={hint}>
            <input
                type={type}
                value={value}
                placeholder={placeholder}
                onChange={(e) => onChange(e.target.value)}
                className="h-[38px] w-full rounded-[9px] border border-border bg-card px-3 text-[13px] outline-none focus:border-primary"
            />
        </Field>
    );
}

function Area({
    label,
    value,
    onChange,
    placeholder,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    placeholder?: string;
}) {
    return (
        <Field label={label}>
            <textarea
                value={value}
                placeholder={placeholder}
                onChange={(e) => onChange(e.target.value)}
                className="min-h-[88px] w-full resize-y rounded-[9px] border border-border bg-card p-2.5 text-[13px] leading-relaxed outline-none focus:border-primary"
            />
        </Field>
    );
}

function useFlash() {
    const page = usePage();
    return (page.props as { flash?: { error?: string } }).flash;
}

/* ================================================================== */
/*  Dispatcher                                                         */
/* ================================================================== */

export function RecruitmentWizards({
    state,
    onClose,
    support,
}: {
    state: WizardState | null;
    onClose: () => void;
    support: RecruitmentSupport;
}) {
    if (!state) return null;
    const ctx = state.context ?? {};
    switch (state.kind) {
        case 'add':
            return <AddCandidateWizard onClose={onClose} support={support} ctx={ctx} />;
        case 'requisition':
            return <RequisitionWizard onClose={onClose} support={support} ctx={ctx} />;
        case 'interview':
            return <InterviewWizard onClose={onClose} support={support} ctx={ctx} />;
        case 'offer':
            return <OfferWizard onClose={onClose} support={support} ctx={ctx} />;
        case 'convert':
            return <ConvertWizard onClose={onClose} support={support} ctx={ctx} />;
        case 'reference':
            return <ReferenceWizard onClose={onClose} support={support} ctx={ctx} />;
        case 'reject':
            return <RejectWizard onClose={onClose} support={support} ctx={ctx} />;
        case 'document':
            return <DocumentWizard onClose={onClose} support={support} ctx={ctx} />;
        default:
            return null;
    }
}

type WizProps = {
    onClose: () => void;
    support: RecruitmentSupport;
    ctx: WizardContext;
};

function srcLabel(s: string) {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

/* ================================================================== */
/*  Add candidate                                                     */
/* ================================================================== */

function AddCandidateWizard({ onClose, support }: WizProps) {
    const wizard = useWizard(2);
    const flash = useFlash();
    const [done, setDone] = useState(false);
    const [consent, setConsent] = useState(false);
    const form = useForm({
        first_name: '',
        last_name: '',
        personal_email: '',
        personal_phone: '',
        source: 'referral',
        position_title: '',
        target_site_id: '',
    });

    const steps: WizardStep[] = [
        { key: 'person', label: 'Person & application', blurb: 'Name, email, source', icon: UserPlus },
        { key: 'review', label: 'Review', blurb: 'Confirm & add', icon: CheckCircle2 },
    ];

    const canSubmit =
        form.data.first_name.trim() !== '' &&
        form.data.last_name.trim() !== '' &&
        form.data.personal_email.trim() !== '' &&
        consent;

    const submit = () => {
        form.transform((d) => ({
            ...d,
            target_site_id: d.target_site_id || undefined,
            position_title: d.position_title || undefined,
        }));
        form.post('/hr/recruitment/candidates', {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not add candidate', { description: f.error });
                    return;
                }
                toast.success('Candidate added to the pipeline');
                setDone(true);
            },
        });
    };

    if (done) {
        return (
            <WizardSuccessShell
                onClose={onClose}
                title="Candidate added 🎉"
                blurb="They're in the pipeline at the New stage. Open their dossier to schedule an interview or attach documents."
            />
        );
    }

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Add candidate"
            description="Add a candidate to the recruitment pipeline"
            railIcon={UserPlus}
            railTitle="Add candidate"
            railSub="Manual pipeline entry"
            steps={steps}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={Math.round(wizard.progress)}
            footerStart={<CancelBtn onClick={onClose} />}
            footerEnd={
                <FooterNav
                    wizard={wizard}
                    onBack={wizard.back}
                    primaryLabel={wizard.isLast ? 'Add candidate' : 'Continue'}
                    primaryDisabled={wizard.isLast && (!canSubmit || form.processing)}
                    onPrimary={() => (wizard.isLast ? submit() : wizard.next())}
                />
            }
        >
            {wizard.index === 0 ? (
                <WizardStepPane>
                    <StepHead icon={UserPlus} title="Person & application" blurb="Add someone to the pipeline manually, with privacy consent captured." />
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Txt label="First name" value={form.data.first_name} onChange={(v) => form.setData('first_name', v)} />
                        <Txt label="Last name" value={form.data.last_name} onChange={(v) => form.setData('last_name', v)} />
                    </div>
                    <div className="mt-3">
                        <Txt label="Email" type="email" value={form.data.personal_email} onChange={(v) => form.setData('personal_email', v)} />
                    </div>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Txt label="Phone" hint="(optional)" value={form.data.personal_phone} onChange={(v) => form.setData('personal_phone', v)} />
                        <Field label="Source">
                            <SelectInput
                                value={form.data.source}
                                onChange={(v) => form.setData('source', v)}
                                placeholder="Select source"
                                options={support.sources.map((s) => ({ value: s, label: srcLabel(s) }))}
                            />
                        </Field>
                    </div>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Txt label="Applying for" hint="(optional)" value={form.data.position_title} onChange={(v) => form.setData('position_title', v)} placeholder="e.g. Support Worker" />
                        <Field label="Preferred site" hint="(optional)">
                            <SelectInput
                                value={form.data.target_site_id}
                                onChange={(v) => form.setData('target_site_id', v)}
                                placeholder="Any site"
                                options={support.sites.map((s) => ({ value: String(s.id), label: s.name }))}
                            />
                        </Field>
                    </div>
                    <div className="mt-4">
                        <Toggle
                            tone="muted"
                            checked={consent}
                            onChange={setConsent}
                            title="Privacy consent captured"
                            sub="Candidate agreed to us holding their data."
                        />
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <StepHead icon={CheckCircle2} title="Review" blurb="Check everything before you add them." />
                    <ReviewCard icon={UserPlus} title="Candidate" onEdit={() => wizard.goTo(0)}>
                        <ReviewRow label="Name" value={`${form.data.first_name} ${form.data.last_name}`.trim()} />
                        <ReviewRow label="Email" value={form.data.personal_email} />
                        <ReviewRow label="Phone" value={form.data.personal_phone} />
                        <ReviewRow label="Source" value={srcLabel(form.data.source)} />
                        <ReviewRow label="Applying for" value={form.data.position_title} />
                        <ReviewRow label="Consent" value={consent ? 'Captured' : 'Not captured'} />
                    </ReviewCard>
                    {!canSubmit ? <NeedConsent /> : null}
                    {flash?.error ? <FlashErr msg={flash.error} /> : null}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Requisition                                                       */
/* ================================================================== */

function RequisitionWizard({ onClose, support }: WizProps) {
    const wizard = useWizard(5);
    const [done, setDone] = useState(false);
    const [positionId, setPositionId] = useState('');
    const [channels, setChannels] = useState<string[]>(['career_page']);
    const form = useForm({
        title: '',
        position_id: '' as string,
        employment_type: 'full_time',
        openings: '1',
        summary: '',
        description: '',
        requirements: '',
        responsibilities: '',
        hiring_manager_user_id: '',
        default_interview_kit_id: '',
        posting_channels: ['career_page'] as string[],
        closing_at: '',
    });

    const steps: WizardStep[] = [
        { key: 'role', label: 'Role & position', blurb: 'Seat & title', icon: Briefcase },
        { key: 'desc', label: 'Job description', blurb: 'Summary & detail', icon: FileText },
        { key: 'team', label: 'Hiring team', blurb: 'Manager & kit', icon: Users },
        { key: 'post', label: 'Posting', blurb: 'Channels & dates', icon: Megaphone },
        { key: 'review', label: 'Review', blurb: 'Confirm & create', icon: CheckCircle2 },
    ];

    const pickPosition = (id: string) => {
        setPositionId(id);
        form.setData('position_id', id);
        const pos = support.positions.find((p) => String(p.id) === id);
        if (pos && form.data.title === '') form.setData('title', pos.label);
        if (pos?.employment_type) form.setData('employment_type', pos.employment_type);
    };

    const canSubmit = form.data.title.trim() !== '' && form.data.description.trim() !== '';

    const submit = () => {
        form.transform((d) => ({
            ...d,
            openings: Number(d.openings) || 1,
            position_id: d.position_id || undefined,
            hiring_manager_user_id: d.hiring_manager_user_id || undefined,
            default_interview_kit_id: d.default_interview_kit_id || undefined,
            posting_channels: channels,
            closing_at: d.closing_at || undefined,
        }));
        form.post('/hr/recruitment/jobs', {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not create requisition', { description: f.error });
                    return;
                }
                toast.success('Requisition created as a draft');
                setDone(true);
            },
        });
    };

    if (done) {
        return (
            <WizardSuccessShell
                onClose={onClose}
                title="Requisition created"
                blurb="It's saved as a draft. Publish it from the Requisitions tab to open applications and fill the establishment seat."
            />
        );
    }

    const channelLabels: Record<string, string> = {
        career_page: 'Careers page',
        linkedin: 'LinkedIn',
        seek: 'Seek',
        indeed: 'Indeed',
        facebook: 'Facebook',
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="New requisition"
            description="Open a new role"
            railIcon={Briefcase}
            railTitle="New requisition"
            railSub="Open a role"
            steps={steps}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={Math.round(wizard.progress)}
            footerStart={<CancelBtn onClick={onClose} />}
            footerEnd={
                <FooterNav
                    wizard={wizard}
                    onBack={wizard.back}
                    primaryLabel={wizard.isLast ? 'Create requisition' : 'Continue'}
                    primaryDisabled={wizard.isLast && (!canSubmit || form.processing)}
                    onPrimary={() => (wizard.isLast ? submit() : wizard.next())}
                />
            }
        >
            {wizard.index === 0 ? (
                <WizardStepPane>
                    <StepHead icon={Briefcase} title="Role & position" blurb="Pick the establishment seat this role fills — it writes position_id so hires land in a real vacancy." />
                    {support.positions.length > 0 ? (
                        <div className="mb-4">
                            <div className="mb-2 text-[12px] font-bold">Establishment seat</div>
                            <TilePicker
                                value={positionId}
                                onChange={pickPosition}
                                options={support.positions.slice(0, 8).map((p) => ({
                                    key: String(p.id),
                                    label: p.label,
                                    description: p.role ?? undefined,
                                    meta: p.vacancies > 0 ? `${p.vacancies} vacant` : 'No open seat',
                                }))}
                            />
                        </div>
                    ) : null}
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-[2fr_1fr]">
                        <Txt label="Role title" value={form.data.title} onChange={(v) => form.setData('title', v)} />
                        <Txt label="Openings" type="number" value={form.data.openings} onChange={(v) => form.setData('openings', v)} />
                    </div>
                    <div className="mt-3">
                        <Field label="Employment type">
                            <Segmented
                                value={form.data.employment_type}
                                onChange={(v) => form.setData('employment_type', v)}
                                options={support.employment_types.map((t) => ({ value: t, label: srcLabel(t) }))}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {wizard.index === 1 ? (
                <WizardStepPane>
                    <StepHead icon={FileText} title="Job description" blurb="What the role does and who you're looking for. A description is required to create the requisition." />
                    <Area label="Summary" value={form.data.summary} onChange={(v) => form.setData('summary', v)} placeholder="One-line summary for the ad" />
                    <div className="mt-3">
                        <Area label="Description" value={form.data.description} onChange={(v) => form.setData('description', v)} placeholder="Full role description (required)" />
                    </div>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Area label="Responsibilities" value={form.data.responsibilities} onChange={(v) => form.setData('responsibilities', v)} />
                        <Area label="Requirements" value={form.data.requirements} onChange={(v) => form.setData('requirements', v)} />
                    </div>
                </WizardStepPane>
            ) : null}

            {wizard.index === 2 ? (
                <WizardStepPane>
                    <StepHead icon={Users} title="Hiring team" blurb="Who owns this hire and which scorecard the panel uses." />
                    <Field label="Hiring manager" hint="(optional)">
                        <SelectInput
                            value={form.data.hiring_manager_user_id}
                            onChange={(v) => form.setData('hiring_manager_user_id', v)}
                            placeholder="Assign later"
                            options={support.hiring_managers.map((m) => ({ value: String(m.id), label: m.name }))}
                        />
                    </Field>
                    <div className="mt-3">
                        <Field label="Default interview kit" hint="(optional)">
                            <SelectInput
                                value={form.data.default_interview_kit_id}
                                onChange={(v) => form.setData('default_interview_kit_id', v)}
                                placeholder="No default kit"
                                options={support.interview_kits.map((k) => ({ value: String(k.id), label: k.name }))}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {wizard.index === 3 ? (
                <WizardStepPane>
                    <StepHead icon={Megaphone} title="Posting" blurb="Where it's advertised and when applications close." />
                    <Field label="Posting channels">
                        <div className="flex flex-wrap gap-2">
                            {Object.keys(channelLabels).map((c) => {
                                const on = channels.includes(c);
                                return (
                                    <button
                                        key={c}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => setChannels((prev) => (on ? prev.filter((x) => x !== c) : [...prev, c]))}
                                        className={cn(
                                            'rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                            on ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card hover:border-primary/50',
                                        )}
                                    >
                                        {channelLabels[c]}
                                    </button>
                                );
                            })}
                        </div>
                    </Field>
                    <div className="mt-3 max-w-[220px]">
                        <Txt label="Closing date" hint="(optional)" type="date" value={form.data.closing_at} onChange={(v) => form.setData('closing_at', v)} />
                    </div>
                </WizardStepPane>
            ) : null}

            {wizard.index === 4 ? (
                <WizardStepPane>
                    <StepHead icon={CheckCircle2} title="Review" blurb="Check everything before you create it." />
                    <ReviewCard icon={Briefcase} title="Requisition" onEdit={() => wizard.goTo(0)}>
                        <ReviewRow label="Title" value={form.data.title} />
                        <ReviewRow label="Seat" value={support.positions.find((p) => String(p.id) === positionId)?.label} />
                        <ReviewRow label="Openings" value={form.data.openings} />
                        <ReviewRow label="Type" value={srcLabel(form.data.employment_type)} />
                        <ReviewRow label="Channels" value={channels.map((c) => channelLabels[c]).join(', ')} />
                        <ReviewRow label="Closes" value={form.data.closing_at} />
                    </ReviewCard>
                    {!canSubmit ? <Hint msg="A title and description are required." /> : null}
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Schedule interview                                                */
/* ================================================================== */

function InterviewWizard({ onClose, support, ctx }: WizProps) {
    const wizard = useWizard(2);
    const [done, setDone] = useState(false);
    const form = useForm({
        date: '',
        time: '10:00',
        interview_type: 'in_person',
        duration_minutes: '45',
    });

    const steps: WizardStep[] = [
        { key: 'panel', label: 'Panel & time', blurb: 'When & how', icon: CalendarPlus },
        { key: 'review', label: 'Review', blurb: 'Confirm & book', icon: CheckCircle2 },
    ];

    const canSubmit = form.data.date !== '' && Boolean(ctx.applicationId);

    const submit = () => {
        if (!ctx.applicationId) return;
        form.transform((d) => ({
            scheduled_at: `${d.date} ${d.time}:00`,
            duration_minutes: Number(d.duration_minutes) || 45,
            interview_type: d.interview_type,
        }));
        form.post(`/hr/recruitment/applications/${ctx.applicationId}/interviews`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not schedule interview', { description: f.error });
                    return;
                }
                toast.success('Interview scheduled');
                setDone(true);
            },
        });
    };

    if (done) {
        return <WizardSuccessShell onClose={onClose} title="Interview scheduled" blurb="It now shows on the Interviews tab for the week. Panellists and the candidate can be notified from there." />;
    }

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Schedule interview"
            description="Book an interview panel"
            railIcon={CalendarPlus}
            railTitle="Schedule interview"
            railSub={ctx.candidateName ?? 'Book a panel'}
            steps={steps}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={Math.round(wizard.progress)}
            footerStart={<CancelBtn onClick={onClose} />}
            footerEnd={
                <FooterNav
                    wizard={wizard}
                    onBack={wizard.back}
                    primaryLabel={wizard.isLast ? 'Schedule' : 'Continue'}
                    primaryDisabled={wizard.isLast && (!canSubmit || form.processing)}
                    onPrimary={() => (wizard.isLast ? submit() : wizard.next())}
                />
            }
        >
            {wizard.index === 0 ? (
                <WizardStepPane>
                    <StepHead icon={CalendarPlus} title="Panel & time" blurb={`Book the interview${ctx.candidateName ? ` for ${ctx.candidateName}` : ''}.`} />
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Txt label="Date" type="date" value={form.data.date} onChange={(v) => form.setData('date', v)} />
                        <Txt label="Time" type="time" value={form.data.time} onChange={(v) => form.setData('time', v)} />
                    </div>
                    <div className="mt-3">
                        <Field label="Interview type">
                            <Segmented
                                value={form.data.interview_type}
                                onChange={(v) => form.setData('interview_type', v)}
                                options={[
                                    { value: 'phone', label: 'Phone' },
                                    { value: 'video', label: 'Video' },
                                    { value: 'in_person', label: 'In person' },
                                    { value: 'panel', label: 'Panel' },
                                ]}
                            />
                        </Field>
                    </div>
                    <div className="mt-3 max-w-[220px]">
                        <Field label="Duration (minutes)">
                            <SelectInput
                                value={form.data.duration_minutes}
                                onChange={(v) => form.setData('duration_minutes', v)}
                                placeholder="Duration"
                                options={['30', '45', '60', '90'].map((m) => ({ value: m, label: `${m} min` }))}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <StepHead icon={CheckCircle2} title="Review" blurb="Confirm the interview details." />
                    <ReviewCard icon={CalendarPlus} title="Interview" onEdit={() => wizard.goTo(0)}>
                        <ReviewRow label="Candidate" value={ctx.candidateName} />
                        <ReviewRow label="Date" value={form.data.date} />
                        <ReviewRow label="Time" value={form.data.time} />
                        <ReviewRow label="Type" value={srcLabel(form.data.interview_type)} />
                        <ReviewRow label="Duration" value={`${form.data.duration_minutes} min`} />
                    </ReviewCard>
                    {!ctx.applicationId ? <Hint msg="This candidate has no application to attach the interview to." /> : null}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Offer                                                             */
/* ================================================================== */

function OfferWizard({ onClose, support, ctx }: WizProps) {
    const wizard = useWizard(4);
    const [done, setDone] = useState(false);
    const fileRef = useRef<File | null>(null);
    const form = useForm<{
        application_id: number | undefined;
        position_title: string;
        position_id: string;
        employment_type: string;
        hourly_rate: string;
        annual_salary: string;
        hours_per_week: string;
        proposed_start_date: string;
        primary_site_id: string;
        conditions: string;
        offer_letter: File | null;
    }>({
        application_id: ctx.applicationId,
        position_title: ctx.role ?? '',
        position_id: '',
        employment_type: 'full_time',
        hourly_rate: '',
        annual_salary: '',
        hours_per_week: '40',
        proposed_start_date: '',
        primary_site_id: ctx.siteId ? String(ctx.siteId) : '',
        conditions: 'This offer is conditional on satisfactory pre-employment safety checks (right to work, Police vetting, Children’s Act 2014 safety check and reference checks).',
        offer_letter: null,
    });

    const steps: WizardStep[] = [
        { key: 'seat', label: 'Position & seat', blurb: 'Role & seat', icon: Briefcase },
        { key: 'terms', label: 'Terms', blurb: 'Pay & start', icon: PenLine },
        { key: 'letter', label: 'Offer letter', blurb: 'Upload', icon: FileText },
        { key: 'review', label: 'Review', blurb: 'Confirm & draft', icon: CheckCircle2 },
    ];

    const canSubmit =
        Boolean(form.data.application_id) &&
        form.data.position_title.trim() !== '' &&
        form.data.proposed_start_date !== '' &&
        form.data.primary_site_id !== '';

    const submit = () => {
        form.transform((d) => ({
            ...d,
            hours_per_week: Number(d.hours_per_week) || 40,
            hourly_rate: d.hourly_rate || undefined,
            annual_salary: d.annual_salary || undefined,
            position_id: d.position_id || undefined,
        }));
        form.post('/hr/recruitment/offers', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not create offer', { description: f.error });
                    return;
                }
                toast.success('Offer drafted');
                fireConfetti();
                setDone(true);
            },
        });
    };

    if (done) {
        return <WizardSuccessShell onClose={onClose} title="Offer drafted 🎉" blurb="The offer is saved as a draft on the Offers tab. Send it to email the candidate their portal link." />;
    }

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Create offer"
            description="Draft an employment offer"
            railIcon={Send}
            railTitle="Create offer"
            railSub={ctx.candidateName ?? 'Draft an offer'}
            steps={steps}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={Math.round(wizard.progress)}
            footerStart={<CancelBtn onClick={onClose} />}
            footerEnd={
                <FooterNav
                    wizard={wizard}
                    onBack={wizard.back}
                    primaryLabel={wizard.isLast ? 'Create offer' : 'Continue'}
                    primaryDisabled={wizard.isLast && (!canSubmit || form.processing)}
                    onPrimary={() => (wizard.isLast ? submit() : wizard.next())}
                />
            }
        >
            {wizard.index === 0 ? (
                <WizardStepPane>
                    <StepHead icon={Briefcase} title="Position & seat" blurb="Confirm the candidate and the seat this offer fills." />
                    <Txt label="Position title" value={form.data.position_title} onChange={(v) => form.setData('position_title', v)} />
                    {support.positions.length > 0 ? (
                        <div className="mt-3">
                            <Field label="Establishment seat" hint="(writes position_id)">
                                <SelectInput
                                    value={form.data.position_id}
                                    onChange={(v) => form.setData('position_id', v)}
                                    placeholder="Link a seat"
                                    options={support.positions.map((p) => ({ value: String(p.id), label: `${p.label}${p.vacancies > 0 ? ` · ${p.vacancies} vacant` : ''}` }))}
                                />
                            </Field>
                        </div>
                    ) : null}
                    {form.data.position_id ? (
                        <SeatPanel
                            ok={(support.positions.find((p) => String(p.id) === form.data.position_id)?.vacancies ?? 0) > 0}
                        />
                    ) : null}
                </WizardStepPane>
            ) : null}

            {wizard.index === 1 ? (
                <WizardStepPane>
                    <StepHead icon={PenLine} title="Terms" blurb="Pay, hours and start date — NZD, en-NZ." />
                    <Field label="Employment type">
                        <Segmented
                            value={form.data.employment_type}
                            onChange={(v) => form.setData('employment_type', v)}
                            options={support.employment_types.map((t) => ({ value: t, label: srcLabel(t) }))}
                        />
                    </Field>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Txt label="Hourly rate ($)" hint="(or salary)" value={form.data.hourly_rate} onChange={(v) => form.setData('hourly_rate', v)} />
                        <Txt label="Annual salary ($)" hint="(or hourly)" value={form.data.annual_salary} onChange={(v) => form.setData('annual_salary', v)} />
                    </div>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Txt label="Hours / week" type="number" value={form.data.hours_per_week} onChange={(v) => form.setData('hours_per_week', v)} />
                        <Txt label="Start date" type="date" value={form.data.proposed_start_date} onChange={(v) => form.setData('proposed_start_date', v)} />
                    </div>
                    <div className="mt-3">
                        <Field label="Primary site" required>
                            <SelectInput
                                value={form.data.primary_site_id}
                                onChange={(v) => form.setData('primary_site_id', v)}
                                placeholder="Select site"
                                options={support.sites.map((s) => ({ value: String(s.id), label: s.name }))}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {wizard.index === 2 ? (
                <WizardStepPane>
                    <StepHead icon={FileText} title="Offer letter" blurb="Upload a signed copy, or skip and generate later." />
                    <FileTile
                        file={form.data.offer_letter}
                        onPick={(f) => {
                            fileRef.current = f;
                            form.setData('offer_letter', f);
                        }}
                        accept=".pdf,.doc,.docx"
                        hint="PDF, DOC up to 20MB · optional"
                    />
                    <div className="mt-3">
                        <Area label="Conditions of offer" value={form.data.conditions} onChange={(v) => form.setData('conditions', v)} />
                    </div>
                </WizardStepPane>
            ) : null}

            {wizard.index === 3 ? (
                <WizardStepPane>
                    <StepHead icon={CheckCircle2} title="Review" blurb="Check the offer before drafting it." />
                    <ReviewCard icon={Send} title="Offer" onEdit={() => wizard.goTo(0)}>
                        <ReviewRow label="Candidate" value={ctx.candidateName} />
                        <ReviewRow label="Position" value={form.data.position_title} />
                        <ReviewRow label="Type" value={srcLabel(form.data.employment_type)} />
                        <ReviewRow label="Pay" value={form.data.hourly_rate ? `$${form.data.hourly_rate} / hr` : form.data.annual_salary ? `$${form.data.annual_salary} / yr` : undefined} />
                        <ReviewRow label="Hours/wk" value={form.data.hours_per_week} />
                        <ReviewRow label="Start" value={form.data.proposed_start_date} />
                        <ReviewRow label="Site" value={support.sites.find((s) => String(s.id) === form.data.primary_site_id)?.name} />
                        <ReviewRow label="Letter" value={form.data.offer_letter ? form.data.offer_letter.name : 'Generate later'} />
                    </ReviewCard>
                    <div className="mt-2 rounded-xl border border-border bg-muted px-3.5 py-2.5 text-[12.5px] text-muted-foreground">
                        Delivery: drafts the offer. Use <strong className="text-foreground">Send</strong> on the Offers tab to email the candidate their portal link.
                    </div>
                    {!canSubmit ? <Hint msg="A position title, start date and primary site are required." /> : null}
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

function SeatPanel({ ok }: { ok: boolean }) {
    return (
        <div className={cn('mt-3 flex gap-2.5 rounded-xl border p-3', ok ? 'border-status-success/30 bg-status-success-bg' : 'border-status-warning/35 bg-status-warning-bg')}>
            <ShieldCheck className={cn('mt-0.5 h-4 w-4 shrink-0', ok ? 'text-status-success' : 'text-status-warning')} />
            <div className={cn('text-[12.5px]', ok ? 'text-status-success' : 'text-status-warning')}>
                {ok ? (
                    <><strong>Seat available.</strong> This offer writes position_id so the hire fills a budgeted vacancy.</>
                ) : (
                    <><strong>No open seat.</strong> This position has no actionable vacancy against budget — proceed only if establishment is being expanded.</>
                )}
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Convert to employee                                               */
/* ================================================================== */

function ConvertWizard({ onClose, ctx }: WizProps) {
    const [done, setDone] = useState(false);
    const [processing, setProcessing] = useState(false);
    const canEmployees = ctx.canManageEmployees ?? false;

    const submit = () => {
        if (!ctx.offerId) return;
        setProcessing(true);
        router.post(
            `/hr/recruitment/offers/${ctx.offerId}/convert`,
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const f = (page.props as { flash?: { error?: string } }).flash;
                    if (f?.error) {
                        toast.error('Could not convert', { description: f.error });
                        return;
                    }
                    toast.success('Employee profile created 🎉');
                    fireConfetti();
                    setDone(true);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    if (done) {
        return <WizardSuccessShell onClose={onClose} title="Hired & onboarding started 🎉" blurb="An employee profile was created and onboarding kicked off. The candidate now appears in People." />;
    }

    const steps: WizardStep[] = [{ key: 'confirm', label: 'Confirm hire', blurb: 'Account & access', icon: UserCheck }];

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Convert to employee"
            description="Create a staff account from an accepted offer"
            railIcon={UserCheck}
            railTitle="Convert to staff"
            railSub={ctx.candidateName ?? 'Hire handoff'}
            steps={steps}
            stepIndex={0}
            onStepClick={() => undefined}
            footerStart={<CancelBtn onClick={onClose} />}
            footerEnd={
                <button
                    type="button"
                    disabled={!ctx.offerId || processing}
                    onClick={submit}
                    className="h-[38px] rounded-[10px] bg-primary px-5 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                >
                    {processing ? 'Converting…' : 'Create employee'}
                </button>
            }
        >
            <WizardStepPane>
                <StepHead icon={UserCheck} title="Confirm hire & access" blurb="This is the front door to a new staff account — idempotent and audited." />
                <div className="mb-3 flex items-center gap-3 rounded-xl border border-border p-3.5">
                    <span className="grid h-11 w-11 place-items-center rounded-full bg-status-success text-white text-sm font-bold">
                        {initials(ctx.candidateName ?? '?')}
                    </span>
                    <div className="flex-1">
                        <div className="text-[15px] font-bold">{ctx.candidateName ?? 'Candidate'}</div>
                        <div className="text-[12px] text-muted-foreground">{ctx.role ?? 'Offer accepted'} · ready to convert</div>
                    </div>
                    <span className="rounded-md bg-status-success-bg px-2.5 py-1 text-[11px] font-bold text-status-success">Accepted</span>
                </div>
                <div className="flex flex-col gap-2.5">
                    <CreateLine icon={UserCheck} label="Employee profile" sub="HrEmployeeProfile created (or updated) idempotently" />
                    <CreateLine icon={Users} label="Login account" sub="A User record so they can sign in on day one" />
                    <CreateLine icon={ShieldCheck} label="Onboarding kicked off" sub="Onboarding checklist + welcome email" />
                </div>
                {!canEmployees ? (
                    <div className="mt-3 flex gap-2.5 rounded-xl border border-status-warning/35 bg-status-warning-bg p-3">
                        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                        <div className="text-[12.5px] text-status-warning">
                            <strong>Segregation of duties.</strong> Creating a system login also requires <code className="rounded bg-white/50 px-1 py-0.5 text-[11.5px]">hr.employees.manage</code>, which you don't currently hold. The conversion may stop at profile creation.
                        </div>
                    </div>
                ) : null}
                {!ctx.offerId ? <Hint msg="No accepted offer is linked to convert." /> : null}
            </WizardStepPane>
        </WizardShell>
    );
}

function CreateLine({ icon: Icon, label, sub }: { icon: LucideIcon; label: string; sub: string }) {
    return (
        <div className="flex items-center gap-3 rounded-xl border border-border p-3">
            <span className="grid h-7 w-7 place-items-center rounded-md bg-status-success-bg text-status-success">
                <Icon className="h-3.5 w-3.5" />
            </span>
            <div className="flex-1">
                <div className="text-[13px] font-semibold">{label}</div>
                <div className="text-[11.5px] text-muted-foreground">{sub}</div>
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Reference request                                                 */
/* ================================================================== */

function ReferenceWizard({ onClose, ctx }: WizProps) {
    const wizard = useWizard(2);
    const [done, setDone] = useState(false);
    const form = useForm({
        referee_name: '',
        referee_email: '',
        referee_phone: '',
        referee_relationship: 'former_manager',
    });

    const canSubmit = form.data.referee_name.trim() !== '' && Boolean(ctx.applicationId);

    const submit = () => {
        if (!ctx.applicationId) return;
        form.transform((d) => ({ ...d, referee_relationship: srcLabel(d.referee_relationship) }));
        form.post(`/hr/recruitment/applications/${ctx.applicationId}/references`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not request reference', { description: f.error });
                    return;
                }
                toast.success('Reference check requested');
                setDone(true);
            },
        });
    };

    if (done) {
        return <WizardSuccessShell onClose={onClose} title="Reference requested" blurb="The reference check is logged as pending on the candidate's dossier. It gates the offer stage until complete." />;
    }

    const steps: WizardStep[] = [
        { key: 'referee', label: 'Referee', blurb: 'Who to contact', icon: UserCheck },
        { key: 'review', label: 'Review', blurb: 'Confirm & request', icon: CheckCircle2 },
    ];

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Request reference"
            description="Request a pre-employment reference"
            railIcon={UserCheck}
            railTitle="Request reference"
            railSub={ctx.candidateName ?? 'Safety check'}
            steps={steps}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={Math.round(wizard.progress)}
            footerStart={<CancelBtn onClick={onClose} />}
            footerEnd={
                <FooterNav
                    wizard={wizard}
                    onBack={wizard.back}
                    primaryLabel={wizard.isLast ? 'Request reference' : 'Continue'}
                    primaryDisabled={wizard.isLast && (!canSubmit || form.processing)}
                    onPrimary={() => (wizard.isLast ? submit() : wizard.next())}
                />
            }
        >
            {wizard.index === 0 ? (
                <WizardStepPane>
                    <StepHead icon={UserCheck} title="Referee" blurb={`Who should we contact to verify ${ctx.candidateName ?? 'the candidate'}?`} />
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <Txt label="Referee name" value={form.data.referee_name} onChange={(v) => form.setData('referee_name', v)} />
                        <Txt label="Phone" hint="(optional)" value={form.data.referee_phone} onChange={(v) => form.setData('referee_phone', v)} />
                    </div>
                    <div className="mt-3">
                        <Txt label="Referee email" type="email" value={form.data.referee_email} onChange={(v) => form.setData('referee_email', v)} placeholder="referee@email.co.nz" />
                    </div>
                    <div className="mt-3">
                        <Field label="Relationship to candidate">
                            <Segmented
                                value={form.data.referee_relationship}
                                onChange={(v) => form.setData('referee_relationship', v)}
                                options={[
                                    { value: 'former_manager', label: 'Former manager' },
                                    { value: 'colleague', label: 'Colleague' },
                                    { value: 'character', label: 'Character' },
                                ]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <StepHead icon={CheckCircle2} title="Review" blurb="Confirm the referee details." />
                    <ReviewCard icon={UserCheck} title="Referee" onEdit={() => wizard.goTo(0)}>
                        <ReviewRow label="Name" value={form.data.referee_name} />
                        <ReviewRow label="Email" value={form.data.referee_email} />
                        <ReviewRow label="Phone" value={form.data.referee_phone} />
                        <ReviewRow label="Relationship" value={srcLabel(form.data.referee_relationship)} />
                    </ReviewCard>
                    {!ctx.applicationId ? <Hint msg="This candidate has no application to attach the reference to." /> : null}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Reject                                                            */
/* ================================================================== */

const REJECT_REASONS = ['Not enough experience', 'Values mismatch', 'Failed safety check', 'Position filled', 'Withdrew', 'Other'];

function RejectWizard({ onClose, ctx }: WizProps) {
    const [done, setDone] = useState(false);
    const [reason, setReason] = useState('');
    const form = useForm({ rejection_reason: '' });
    const [note, setNote] = useState('');

    const canSubmit = reason !== '' && Boolean(ctx.applicationId);

    const submit = () => {
        if (!ctx.applicationId) return;
        const combined = [reason, note.trim()].filter(Boolean).join(' — ');
        form.transform(() => ({ rejection_reason: combined }));
        form.post(`/hr/recruitment/applications/${ctx.applicationId}/reject`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not reject', { description: f.error });
                    return;
                }
                toast.success('Application closed out');
                setDone(true);
            },
        });
    };

    if (done) {
        return <WizardSuccessShell onClose={onClose} title="Application closed" blurb="The candidate has been moved out of the active pipeline and the reason recorded to the audit trail." />;
    }

    const steps: WizardStep[] = [{ key: 'reason', label: 'Reason & options', blurb: 'Record a reason', icon: XCircle }];

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Reject application"
            description="Close out a candidate's application"
            railIcon={XCircle}
            railTitle="Reject"
            railSub={ctx.candidateName ?? 'Close out'}
            steps={steps}
            stepIndex={0}
            onStepClick={() => undefined}
            footerStart={<CancelBtn onClick={onClose} />}
            footerEnd={
                <button
                    type="button"
                    disabled={!canSubmit || form.processing}
                    onClick={submit}
                    className="h-[38px] rounded-[10px] bg-status-critical px-5 text-[13px] font-bold text-white disabled:opacity-50"
                >
                    Reject application
                </button>
            }
        >
            <WizardStepPane>
                <StepHead icon={XCircle} title="Reason & options" blurb={`Close out ${ctx.candidateName ?? 'this'}'s application. A reason is recorded to the audit trail.`} />
                <Field label="Reason" required>
                    <div className="flex flex-wrap gap-2">
                        {REJECT_REASONS.map((r) => {
                            const on = reason === r;
                            return (
                                <button
                                    key={r}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => setReason(r)}
                                    className={cn(
                                        'rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                        on ? 'border-status-critical bg-status-critical-bg text-status-critical' : 'border-border bg-card hover:border-status-critical/50',
                                    )}
                                >
                                    {r}
                                </button>
                            );
                        })}
                    </div>
                </Field>
                <div className="mt-3">
                    <Area label="Internal note (not shared with candidate)" value={note} onChange={setNote} />
                </div>
                {!ctx.applicationId ? <Hint msg="This candidate has no application to reject." /> : null}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ================================================================== */
/*  Document upload                                                   */
/* ================================================================== */

function DocumentWizard({ onClose, support, ctx }: WizProps) {
    const [done, setDone] = useState(false);
    const cats = Object.entries(support.document_categories);
    const form = useForm<{ file: File | null; category: string; expires_at: string; notes: string }>({
        file: null,
        category: cats[0]?.[0] ?? 'cv',
        expires_at: '',
        notes: '',
    });

    const canSubmit = Boolean(form.data.file) && form.data.category !== '' && Boolean(ctx.candidateId);

    const submit = () => {
        if (!ctx.candidateId) return;
        form.post(`/hr/recruitment/candidates/${ctx.candidateId}/documents`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (page.props as { flash?: { error?: string } }).flash;
                if (f?.error) {
                    toast.error('Could not upload', { description: f.error });
                    return;
                }
                toast.success('Document uploaded');
                setDone(true);
            },
        });
    };

    if (done) {
        return <WizardSuccessShell onClose={onClose} title="Document uploaded" blurb="It's attached to the candidate and transfers to their staff record automatically on hire." />;
    }

    const steps: WizardStep[] = [{ key: 'file', label: 'File & category', blurb: 'Attach a document', icon: Upload }];

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Upload document"
            description="Attach a document to the candidate"
            railIcon={Upload}
            railTitle="Upload document"
            railSub={ctx.candidateName ?? 'Attach a file'}
            steps={steps}
            stepIndex={0}
            onStepClick={() => undefined}
            footerStart={<CancelBtn onClick={onClose} />}
            footerEnd={
                <button
                    type="button"
                    disabled={!canSubmit || form.processing}
                    onClick={submit}
                    className="h-[38px] rounded-[10px] bg-primary px-5 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                >
                    Upload
                </button>
            }
        >
            <WizardStepPane>
                <StepHead icon={Upload} title="File & category" blurb={`Attach a document to ${ctx.candidateName ?? 'the candidate'}.`} />
                <FileTile file={form.data.file} onPick={(f) => form.setData('file', f)} accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" hint="PDF, DOC or image up to 20MB" />
                <div className="mt-3">
                    <Field label="Category" required>
                        <div className="flex flex-wrap gap-2">
                            {cats.map(([key, label]) => {
                                const on = form.data.category === key;
                                return (
                                    <button
                                        key={key}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => form.setData('category', key)}
                                        className={cn(
                                            'rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                            on ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-card hover:border-primary/50',
                                        )}
                                    >
                                        {label}
                                    </button>
                                );
                            })}
                        </div>
                    </Field>
                </div>
                <div className="mt-3 max-w-[220px]">
                    <Txt label="Expiry date" hint="(optional)" type="date" value={form.data.expires_at} onChange={(v) => form.setData('expires_at', v)} />
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ================================================================== */
/*  Shared footer / success / tiny bits                               */
/* ================================================================== */

function CancelBtn({ onClick }: { onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className="h-[38px] rounded-[10px] border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted">
            Cancel
        </button>
    );
}

function FooterNav({
    wizard,
    onBack,
    onPrimary,
    primaryLabel,
    primaryDisabled,
}: {
    wizard: ReturnType<typeof useWizard>;
    onBack: () => void;
    onPrimary: () => void;
    primaryLabel: string;
    primaryDisabled?: boolean;
}) {
    return (
        <>
            {!wizard.isFirst ? (
                <button type="button" onClick={onBack} className="h-[38px] rounded-[10px] border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted">
                    Back
                </button>
            ) : null}
            <button
                type="button"
                onClick={onPrimary}
                disabled={primaryDisabled}
                className="h-[38px] rounded-[10px] bg-primary px-5 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
            >
                {primaryLabel}
            </button>
        </>
    );
}

function WizardSuccessShell({ onClose, title, blurb }: { onClose: () => void; title: string; blurb: ReactNode }) {
    return (
        <WizardShell
            open
            onClose={onClose}
            title={title}
            description="Done"
            railIcon={Sparkles}
            railTitle=""
            railSub=""
            steps={[{ key: 'done', label: 'Done', blurb: '', icon: CheckCircle2 }]}
            stepIndex={0}
            onStepClick={() => undefined}
            success={
                <WizardSuccessPane
                    title={title}
                    blurb={blurb}
                    actions={
                        <button type="button" onClick={onClose} className="h-[38px] rounded-[10px] bg-primary px-5 text-[13px] font-bold text-primary-foreground">
                            Done
                        </button>
                    }
                />
            }
        />
    );
}

function FileTile({
    file,
    onPick,
    accept,
    hint,
}: {
    file: File | null;
    onPick: (f: File | null) => void;
    accept: string;
    hint: string;
}) {
    const ref = useRef<HTMLInputElement | null>(null);
    return (
        <div>
            <button
                type="button"
                onClick={() => ref.current?.click()}
                className="flex w-full flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-border bg-muted p-6 text-center hover:border-primary/50"
            >
                <Upload className="h-6 w-6 text-primary" />
                <div className="text-[13px] font-semibold">{file ? file.name : 'Drop a file or click to browse'}</div>
                <div className="text-[11.5px] text-muted-foreground">{hint}</div>
            </button>
            <input
                ref={ref}
                type="file"
                accept={accept}
                className="hidden"
                onChange={(e) => onPick(e.target.files?.[0] ?? null)}
            />
        </div>
    );
}

function Hint({ msg }: { msg: string }) {
    return <div className="mt-3 rounded-lg border border-status-warning/35 bg-status-warning-bg px-3 py-2 text-[12.5px] text-status-warning">{msg}</div>;
}

function NeedConsent() {
    return <Hint msg="Capture privacy consent and complete the required fields to add the candidate." />;
}

function FlashErr({ msg }: { msg: string }) {
    return <div className="mt-3 rounded-lg border border-status-critical/35 bg-status-critical-bg px-3 py-2 text-[12.5px] text-status-critical">{msg}</div>;
}
