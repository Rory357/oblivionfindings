/* The six Compliance hub wizards (Record · Requirement · Vetting · Driver ·
 * Waive · Assign), built on the shared Add-Client wizard kit. Each posts to a
 * real, permission-gated route — no dead steps. */
import { PeoplePicker, type PersonOption } from '@/components/hr/people-picker';
import {
    Field,
    Segmented,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ChipMulti } from '@/components/wizard/primitives';
import { router } from '@inertiajs/react';
import {
    Ban,
    Building2,
    Car,
    CheckCircle2,
    ChevronLeft,
    ClipboardCheck,
    FileText,
    IdCard,
    ListChecks,
    ScrollText,
    ShieldCheck,
    Sparkles,
    Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    FileField,
    LabeledChipMulti,
    TextAreaField,
    TextField,
    Toggle,
    WizardGrid,
} from './wizard-fields';

export type RoleOption = { value: string; label: string };

export type ReqOption = {
    id: number;
    code: string;
    name: string;
    category: string;
    check_type: string;
    validity_months: number | null;
    hard_stop: boolean;
};

export type WizardType =
    | 'record'
    | 'requirement'
    | 'vetting'
    | 'driver'
    | 'waive'
    | 'assign';

export type WizardState = {
    type: WizardType;
    preset?: Record<string, unknown>;
    /** Cohort record / bulk waive target ids (from the Overview bulk bar). */
    userIds?: number[];
} | null;

type WizardCtx = {
    people: PersonOption[];
    requirements: ReqOption[];
    roles: RoleOption[];
    siteTypes: string[];
};

const CATEGORIES = [
    'Health & Safety',
    'Safety check',
    'Clinical',
    'Compliance',
    'Policy',
    'Eligibility',
];
const EVIDENCE_CATEGORIES = [
    'Certificate',
    'Letter',
    'System record',
    'Photo',
    'Other',
];
const PROVIDERS = [
    'NZ Police',
    'Ministry of Justice',
    'Ministry of Social Development',
    'Internal',
];
const LICENCE_CLASSES = ['1', '2', '3', '4', '5', '6'];
const ENDORSEMENTS = [
    'P · Passenger',
    'V · Vehicle recovery',
    'I · Dangerous goods',
    'O · Tracks',
    'F · Forklift',
    'D · Driving instructor',
    'T · Testing officer',
    'R · Roller',
    'W · Wheels',
];

const CHECK_TYPE_TILES = [
    {
        key: 'training_course',
        label: 'Training course',
        description: 'Completed via LMS',
    },
    {
        key: 'credential',
        label: 'Credential',
        description: 'Held qualification',
    },
    {
        key: 'background_check',
        label: 'Background check',
        description: 'Police / MOJ',
    },
    {
        key: 'policy_attestation',
        label: 'Attestation',
        description: 'Signed policy',
    },
    {
        key: 'driver_licence',
        label: 'Driver licence',
        description: 'From the driver register',
    },
    { key: 'manual', label: 'Manual', description: 'Recorded by hand' },
];

const VETTING_TYPE_TILES = [
    { key: 'police_check', label: 'Police vetting', description: 'NZ Police' },
    {
        key: 'ministry_of_justice',
        label: 'MOJ criminal record',
        description: 'Ministry of Justice',
    },
    {
        key: 'vulnerable_children_act',
        label: "Children's Act check",
        description: 'Safety check',
    },
    { key: 'other', label: 'Referee check', description: 'Manual' },
];

/* ================================================================== */
/*  Shared scaffold                                                    */
/* ================================================================== */

function selectOptions(values: string[]) {
    return values.map((v) => ({ value: v, label: v }));
}

function WizardScaffold({
    open,
    onClose,
    railIcon,
    railTitle,
    railSub,
    steps,
    index,
    goTo,
    isFirst,
    isLast,
    pct,
    onBack,
    onNext,
    onSubmit,
    submitLabel = 'Save',
    processing,
    done,
    doneTitle,
    doneBlurb,
    onAddAnother,
    children,
}: {
    open: boolean;
    onClose: () => void;
    railIcon: WizardStep['icon'];
    railTitle: string;
    railSub: string;
    steps: readonly WizardStep[];
    index: number;
    goTo: (i: number) => void;
    isFirst: boolean;
    isLast: boolean;
    pct: number;
    onBack: () => void;
    onNext: () => void;
    onSubmit: () => void;
    submitLabel?: string;
    processing: boolean;
    done: boolean;
    doneTitle: string;
    doneBlurb: string;
    onAddAnother: () => void;
    children: React.ReactNode;
}) {
    const success = done ? (
        <WizardSuccessPane
            title={doneTitle}
            blurb={doneBlurb}
            actions={
                <>
                    <Button variant="outline" onClick={onAddAnother}>
                        Save &amp; add another
                    </Button>
                    <Button onClick={onClose}>Done</Button>
                </>
            }
        />
    ) : undefined;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={railTitle}
            description={railSub}
            railIcon={railIcon}
            railTitle={railTitle}
            railSub={railSub}
            steps={steps}
            stepIndex={index}
            onStepClick={goTo}
            pct={pct}
            success={success}
            footerStart={
                <Button
                    variant="ghost"
                    size="sm"
                    disabled={isFirst}
                    onClick={onBack}
                >
                    <ChevronLeft className="h-4 w-4" /> Back
                </Button>
            }
            footerEnd={
                <>
                    <Button variant="ghost" size="sm" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        disabled={processing}
                        onClick={isLast ? onSubmit : onNext}
                    >
                        {isLast ? submitLabel : 'Next'}
                    </Button>
                </>
            }
        >
            {children}
        </WizardShell>
    );
}

/** Flash-aware success handler shared by every wizard submit. */
function submitWith(
    method: 'post' | 'put',
    url: string,
    data: Record<string, unknown>,
    opts: { forceFormData?: boolean; onOk: () => void; onFail: () => void },
) {
    router[method](url, data as never, {
        forceFormData: opts.forceFormData,
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = (page.props as { flash?: { error?: string } }).flash;
            if (flash?.error) {
                toast.error(flash.error);
                opts.onFail();
                return;
            }
            opts.onOk();
        },
        onError: () => {
            toast.error('Could not save — check the highlighted fields.');
            opts.onFail();
        },
    });
}

function ReviewList({
    rows,
}: {
    rows: { label: string; value: React.ReactNode }[];
}) {
    return (
        <div className="grid gap-2">
            {rows.map((r) => (
                <div
                    key={r.label}
                    className="flex justify-between gap-4 border-b border-border py-2 last:border-0"
                >
                    <span className="text-[13px] text-muted-foreground">
                        {r.label}
                    </span>
                    <span className="text-right text-[13px] font-semibold">
                        {r.value || (
                            <span className="font-normal text-muted-foreground">
                                —
                            </span>
                        )}
                    </span>
                </div>
            ))}
        </div>
    );
}

function WarnBanner({ children }: { children: React.ReactNode }) {
    return (
        <div className="mb-4 flex gap-2.5 rounded-lg border border-status-warning/35 bg-status-warning-bg p-3 text-[12.5px] text-status-warning-foreground">
            <Sparkles className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
            <span>{children}</span>
        </div>
    );
}

/* ================================================================== */
/*  1 · Record / update compliance                                     */
/* ================================================================== */

function RecordWizard({
    state,
    onClose,
    ctx,
}: {
    state: WizardState;
    onClose: () => void;
    ctx: WizardCtx;
}) {
    const cohort = (state?.userIds?.length ?? 0) > 0;
    const steps: WizardStep[] = [
        { key: 'who', label: 'Who', blurb: 'Staff member', icon: Users },
        {
            key: 'req',
            label: 'Requirement',
            blurb: 'What to record',
            icon: ListChecks,
        },
        {
            key: 'outcome',
            label: 'Outcome',
            blurb: 'Status & dates',
            icon: ClipboardCheck,
        },
        ...(cohort
            ? []
            : [
                  {
                      key: 'evidence',
                      label: 'Evidence',
                      blurb: 'Upload & notes',
                      icon: FileText,
                  },
              ]),
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm & save',
            icon: CheckCircle2,
        },
    ];
    const wiz = useWizard(steps.length);
    const [person, setPerson] = useState<string>(
        (state?.preset?.person as string) ?? '',
    );
    const [requirementId, setRequirementId] = useState<string>(
        (state?.preset?.requirement as string) ?? '',
    );
    const [status, setStatus] = useState<string>(
        (state?.preset?.status as string) ?? 'compliant',
    );
    const [validFrom, setValidFrom] = useState('');
    const [expiresAt, setExpiresAt] = useState('');
    const [evidenceCategory, setEvidenceCategory] = useState('');
    const [notes, setNotes] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [err, setErr] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState(false);

    const req = ctx.requirements.find((r) => String(r.id) === requirementId);

    // Auto-suggest expiry from validity period when valid_from is set.
    useEffect(() => {
        if (req?.validity_months && validFrom && !expiresAt) {
            const d = new Date(validFrom);
            d.setMonth(d.getMonth() + req.validity_months);
            setExpiresAt(d.toISOString().slice(0, 10));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [requirementId, validFrom]);

    const reset = () => {
        setPerson((state?.preset?.person as string) ?? '');
        setRequirementId('');
        setStatus('compliant');
        setValidFrom('');
        setExpiresAt('');
        setEvidenceCategory('');
        setNotes('');
        setFile(null);
        setErr({});
        setDone(false);
        wiz.goTo(0);
    };

    const stepKey = steps[wiz.index].key;
    const validate = () => {
        const e: Record<string, string> = {};
        if (stepKey === 'who' && !cohort && !person)
            e.person = 'Select a staff member.';
        if (stepKey === 'req' && !requirementId)
            e.req = 'Choose a requirement.';
        setErr(e);
        return Object.keys(e).length === 0;
    };
    const next = () => validate() && wiz.next();

    const personName = ctx.people.find((p) => p.value === person)?.label ?? '—';

    const submit = (addAnother = false) => {
        if (!validate()) return;
        setSaving(true);
        const onOk = () => {
            setSaving(false);
            toast.success(
                cohort
                    ? 'Compliance recorded for selected staff.'
                    : 'Compliance recorded.',
            );
            if (addAnother) reset();
            else setDone(true);
        };
        const onFail = () => setSaving(false);

        if (cohort) {
            submitWith(
                'post',
                '/hr/compliance/bulk-record',
                {
                    user_ids: state?.userIds,
                    requirement_id: Number(requirementId),
                    status,
                    valid_from: validFrom || null,
                    expires_at: expiresAt || null,
                    notes: notes || null,
                },
                { onOk, onFail },
            );
        } else {
            submitWith(
                'post',
                `/hr/compliance/staff/${person}/status`,
                {
                    requirement_id: Number(requirementId),
                    status,
                    valid_from: validFrom || null,
                    expires_at: expiresAt || null,
                    evidence_category: evidenceCategory || null,
                    notes: notes || null,
                    evidence_file: file,
                },
                { forceFormData: true, onOk, onFail },
            );
        }
    };

    return (
        <WizardScaffold
            open
            onClose={onClose}
            railIcon={ClipboardCheck}
            railTitle="Record compliance"
            railSub="Update a staff status"
            steps={steps}
            index={wiz.index}
            goTo={wiz.goTo}
            isFirst={wiz.isFirst}
            isLast={wiz.isLast}
            pct={wiz.progress}
            onBack={wiz.back}
            onNext={next}
            onSubmit={() => submit(false)}
            processing={saving}
            done={done}
            doneTitle="Compliance recorded"
            doneBlurb="The status was updated and hard-stops re-evaluated. Affected shifts are now unblocked."
            onAddAnother={reset}
        >
            {stepKey === 'who' && (
                <>
                    <StepHead
                        icon={Users}
                        title="Who"
                        blurb="Pick the staff member to record against."
                    />
                    {cohort ? (
                        <div className="rounded-lg border border-primary/30 bg-primary/5 p-4 text-sm">
                            <span className="font-semibold text-primary">
                                {state?.userIds?.length} staff selected
                            </span>
                            <p className="mt-1 text-muted-foreground">
                                This records the same outcome for everyone
                                selected on the Overview tab.
                            </p>
                        </div>
                    ) : (
                        <Field label="Staff member" required error={err.person}>
                            <PeoplePicker
                                value={person}
                                onChange={setPerson}
                                people={ctx.people}
                            />
                        </Field>
                    )}
                </>
            )}

            {stepKey === 'req' && (
                <>
                    <StepHead
                        icon={ListChecks}
                        title="Requirement"
                        blurb="Which requirement are you recording?"
                    />
                    <Field label="Requirement" required error={err.req}>
                        <TilePicker
                            value={requirementId}
                            onChange={setRequirementId}
                            options={ctx.requirements.map((r) => ({
                                key: String(r.id),
                                label: r.name,
                                description: r.category,
                            }))}
                        />
                    </Field>
                </>
            )}

            {stepKey === 'outcome' && (
                <>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Outcome"
                        blurb="Set the status and validity dates."
                    />
                    <WizardGrid>
                        <Field label="Status" span>
                            <Segmented
                                value={status}
                                onChange={setStatus}
                                options={[
                                    { value: 'compliant', label: 'Compliant' },
                                    {
                                        value: 'expiring_soon',
                                        label: 'Expiring',
                                    },
                                    { value: 'expired', label: 'Expired' },
                                    {
                                        value: 'not_started',
                                        label: 'Not started',
                                    },
                                ]}
                            />
                        </Field>
                        <Field label="Valid from">
                            <TextField
                                type="date"
                                value={validFrom}
                                onChange={setValidFrom}
                            />
                        </Field>
                        <Field
                            label="Expires"
                            hint="Auto-suggested from validity period"
                        >
                            <TextField
                                type="date"
                                value={expiresAt}
                                onChange={setExpiresAt}
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'evidence' && (
                <>
                    <StepHead
                        icon={FileText}
                        title="Evidence"
                        blurb="Attach a certificate and add notes (optional)."
                    />
                    <WizardGrid>
                        <Field label="Evidence file" span>
                            <FileField
                                fileName={file?.name ?? null}
                                onPick={setFile}
                            />
                        </Field>
                        <Field label="Evidence type">
                            <Select
                                value={evidenceCategory || undefined}
                                onValueChange={setEvidenceCategory}
                            >
                                <SelectTrigger aria-label="Evidence type">
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {EVIDENCE_CATEGORIES.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Notes" span>
                            <TextAreaField
                                value={notes}
                                onChange={setNotes}
                                placeholder="e.g. Renewed at St John course, certificate verified."
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'review' && (
                <>
                    <h3 className="mb-1 text-lg font-bold">
                        Review &amp; confirm
                    </h3>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Double-check the details below, then save.
                    </p>
                    {status === 'expired' && (
                        <WarnBanner>
                            Recording this as Expired may block the staff member
                            from upcoming shifts.
                        </WarnBanner>
                    )}
                    <ReviewList
                        rows={[
                            {
                                label: cohort ? 'Staff' : 'Staff member',
                                value: cohort
                                    ? `${state?.userIds?.length} selected`
                                    : personName,
                            },
                            { label: 'Requirement', value: req?.name },
                            {
                                label: 'Status',
                                value: status.replace('_', ' '),
                            },
                            { label: 'Valid from', value: validFrom },
                            { label: 'Expires', value: expiresAt },
                            ...(cohort
                                ? []
                                : [{ label: 'Evidence', value: file?.name }]),
                            { label: 'Notes', value: notes },
                        ]}
                    />
                </>
            )}
        </WizardScaffold>
    );
}

/* ================================================================== */
/*  2 · Create / edit requirement                                      */
/* ================================================================== */

function RequirementWizard({
    state,
    onClose,
    ctx,
}: {
    state: WizardState;
    onClose: () => void;
    ctx: WizardCtx;
}) {
    const editId = state?.preset?.id as number | undefined;
    const steps: WizardStep[] = [
        {
            key: 'basics',
            label: 'Basics',
            blurb: 'Code, name, type',
            icon: ScrollText,
        },
        {
            key: 'rules',
            label: 'Rules',
            blurb: 'Validity & hard-stop',
            icon: ShieldCheck,
        },
        {
            key: 'assign',
            label: 'Assignment',
            blurb: 'Roles & sites',
            icon: Users,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm & save',
            icon: CheckCircle2,
        },
    ];
    const wiz = useWizard(steps.length);
    const p = state?.preset ?? {};
    const [code, setCode] = useState<string>((p.code as string) ?? '');
    const [name, setName] = useState<string>((p.name as string) ?? '');
    const [category, setCategory] = useState<string>(
        (p.category as string) ?? '',
    );
    const [checkType, setCheckType] = useState<string>(
        (p.check_type as string) ?? '',
    );
    const [validity, setValidity] = useState<string>(
        p.validity_months ? String(p.validity_months) : '',
    );
    const [reminder, setReminder] = useState<string>(
        p.renewal_reminder_days ? String(p.renewal_reminder_days) : '',
    );
    const [hardStop, setHardStop] = useState<boolean>(Boolean(p.hard_stop));
    const [isActive, setIsActive] = useState<boolean>(
        p.is_active === undefined ? true : Boolean(p.is_active),
    );
    const [rolesSel, setRolesSel] = useState<string[]>([]);
    const [siteTypesSel, setSiteTypesSel] = useState<string[]>([]);
    const [err, setErr] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState(false);

    const reset = () => {
        setCode('');
        setName('');
        setCategory('');
        setCheckType('');
        setValidity('');
        setReminder('');
        setHardStop(false);
        setIsActive(true);
        setRolesSel([]);
        setSiteTypesSel([]);
        setErr({});
        setDone(false);
        wiz.goTo(0);
    };

    const stepKey = steps[wiz.index].key;
    const validate = () => {
        const e: Record<string, string> = {};
        if (stepKey === 'basics') {
            if (!code.trim()) e.code = 'Required.';
            if (!name.trim()) e.name = 'Required.';
            if (!category) e.category = 'Required.';
            if (!checkType) e.checkType = 'Choose a check type.';
        }
        setErr(e);
        return Object.keys(e).length === 0;
    };
    const next = () => validate() && wiz.next();

    const submit = (addAnother = false) => {
        if (!validate()) return;
        setSaving(true);
        const onOk = () => {
            setSaving(false);
            toast.success(
                editId ? 'Requirement updated.' : 'Requirement created.',
            );
            if (addAnother) reset();
            else setDone(true);
        };
        const payload = {
            code,
            name,
            category,
            check_type: checkType,
            validity_months: validity ? Number(validity) : null,
            renewal_reminder_days: reminder ? Number(reminder) : null,
            hard_stop: hardStop,
            is_active: isActive,
            roles: rolesSel,
            site_types: siteTypesSel,
        };
        submitWith(
            editId ? 'put' : 'post',
            editId
                ? `/hr/compliance/requirements/${editId}`
                : '/hr/compliance/requirements',
            payload,
            { onOk, onFail: () => setSaving(false) },
        );
    };

    return (
        <WizardScaffold
            open
            onClose={onClose}
            railIcon={ShieldCheck}
            railTitle={editId ? 'Edit requirement' : 'New requirement'}
            railSub="Compliance library"
            steps={steps}
            index={wiz.index}
            goTo={wiz.goTo}
            isFirst={wiz.isFirst}
            isLast={wiz.isLast}
            pct={wiz.progress}
            onBack={wiz.back}
            onNext={next}
            onSubmit={() => submit(false)}
            processing={saving}
            done={done}
            doneTitle={editId ? 'Requirement updated' : 'Requirement created'}
            doneBlurb="The requirement is in your library. Assign it to roles from the matrix to start tracking staff."
            onAddAnother={reset}
        >
            {stepKey === 'basics' && (
                <>
                    <StepHead
                        icon={ScrollText}
                        title="Basics"
                        blurb="Name the requirement and pick how it's checked."
                    />
                    <WizardGrid>
                        <Field label="Code" required error={err.code}>
                            <TextField
                                value={code}
                                onChange={setCode}
                                placeholder="e.g. FA-02"
                            />
                        </Field>
                        <Field label="Name" required error={err.name}>
                            <TextField
                                value={name}
                                onChange={setName}
                                placeholder="First Aid Refresher"
                            />
                        </Field>
                        <Field
                            label="Category"
                            required
                            error={err.category}
                            span
                        >
                            <Select
                                value={category || undefined}
                                onValueChange={setCategory}
                            >
                                <SelectTrigger aria-label="Category">
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {CATEGORIES.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Check type"
                            required
                            error={err.checkType}
                            span
                        >
                            <TilePicker
                                value={checkType}
                                onChange={setCheckType}
                                options={CHECK_TYPE_TILES}
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'rules' && (
                <>
                    <StepHead
                        icon={ShieldCheck}
                        title="Rules"
                        blurb="How long is it valid, and does it block shifts?"
                    />
                    <WizardGrid>
                        <Field label="Validity (months)">
                            <TextField
                                type="number"
                                value={validity}
                                onChange={setValidity}
                                placeholder="12"
                            />
                        </Field>
                        <Field label="Reminder (days before)">
                            <TextField
                                type="number"
                                value={reminder}
                                onChange={setReminder}
                                placeholder="30"
                            />
                        </Field>
                        <Field span>
                            <Toggle
                                checked={hardStop}
                                onChange={setHardStop}
                                label="Hard-stop — blocks shift assignment when expired"
                            />
                        </Field>
                        <Field span>
                            <Toggle
                                checked={isActive}
                                onChange={setIsActive}
                                label="Active"
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'assign' && (
                <>
                    <StepHead
                        icon={Users}
                        title="Assignment"
                        blurb="Which roles and site types must hold this?"
                    />
                    <WizardGrid>
                        <Field label="Applies to roles" span>
                            <LabeledChipMulti
                                values={rolesSel}
                                onChange={setRolesSel}
                                options={ctx.roles}
                            />
                        </Field>
                        <Field label="Site types" span>
                            <ChipMulti
                                values={siteTypesSel}
                                onChange={setSiteTypesSel}
                                options={ctx.siteTypes}
                            />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Leave empty to apply to all Sites. Choices come
                                from the active Sites register.
                            </p>
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'review' && (
                <>
                    <h3 className="mb-1 text-lg font-bold">
                        Review &amp; confirm
                    </h3>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Double-check the details below, then save.
                    </p>
                    <ReviewList
                        rows={[
                            { label: 'Code', value: code },
                            { label: 'Name', value: name },
                            { label: 'Category', value: category },
                            {
                                label: 'Check type',
                                value: checkType.replace('_', ' '),
                            },
                            {
                                label: 'Validity',
                                value: validity ? `${validity} months` : '',
                            },
                            {
                                label: 'Hard-stop',
                                value: hardStop ? 'Yes' : 'No',
                            },
                            { label: 'Roles', value: rolesSel.join(', ') },
                            {
                                label: 'Site types',
                                value: siteTypesSel.join(', '),
                            },
                        ]}
                    />
                </>
            )}
        </WizardScaffold>
    );
}

/* ================================================================== */
/*  3 · Add / edit vetting check                                       */
/* ================================================================== */

function VettingWizard({
    state,
    onClose,
    ctx,
}: {
    state: WizardState;
    onClose: () => void;
    ctx: WizardCtx;
}) {
    const steps: WizardStep[] = [
        {
            key: 'person',
            label: 'Person & type',
            blurb: 'Who & what check',
            icon: Users,
        },
        {
            key: 'provider',
            label: 'Provider',
            blurb: 'Reference & dates',
            icon: Building2,
        },
        {
            key: 'risk',
            label: 'Risk',
            blurb: 'Outcome & disclosures',
            icon: ShieldCheck,
        },
        { key: 'consent', label: 'Consent', blurb: 'Capture', icon: FileText },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm & save',
            icon: CheckCircle2,
        },
    ];
    const wiz = useWizard(steps.length);
    const [person, setPerson] = useState<string>(
        (state?.preset?.person as string) ?? '',
    );
    const [checkType, setCheckType] = useState('police_check');
    const [provider, setProvider] = useState('');
    const [reference, setReference] = useState('');
    const [checkDate, setCheckDate] = useState('');
    const [outcome, setOutcome] = useState('clear');
    const [disclosures, setDisclosures] = useState('');
    const [consent, setConsent] = useState(false);
    const [consentMethod, setConsentMethod] = useState('');
    const [err, setErr] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState(false);

    const reset = () => {
        setPerson('');
        setCheckType('police_check');
        setProvider('');
        setReference('');
        setCheckDate('');
        setOutcome('clear');
        setDisclosures('');
        setConsent(false);
        setConsentMethod('');
        setErr({});
        setDone(false);
        wiz.goTo(0);
    };

    const stepKey = steps[wiz.index].key;
    const validate = () => {
        const e: Record<string, string> = {};
        if (stepKey === 'person' && !person)
            e.person = 'Select a staff member.';
        setErr(e);
        return Object.keys(e).length === 0;
    };
    const next = () => validate() && wiz.next();
    const personName =
        ctx.people.find((pp) => pp.value === person)?.label ?? '—';

    const submit = (addAnother = false) => {
        if (!person) {
            setErr({ person: 'Select a staff member.' });
            wiz.goTo(0);
            return;
        }
        setSaving(true);
        const noteParts = [
            `Outcome: ${outcome}.`,
            disclosures ? `Disclosures: ${disclosures}` : '',
            consent
                ? `Consent captured${consentMethod ? ` (${consentMethod})` : ''}.`
                : '',
        ].filter(Boolean);
        submitWith(
            'post',
            '/hr/compliance/vetting',
            {
                user_id: Number(person),
                check_type: checkType,
                provider: provider || null,
                reference_number: reference || null,
                check_date: checkDate || null,
                notes: noteParts.join(' '),
            },
            {
                onOk: () => {
                    setSaving(false);
                    toast.success('Vetting check logged.');
                    if (addAnother) reset();
                    else setDone(true);
                },
                onFail: () => setSaving(false),
            },
        );
    };

    return (
        <WizardScaffold
            open
            onClose={onClose}
            railIcon={ShieldCheck}
            railTitle="Add vetting check"
            railSub="Police / MOJ / safety"
            steps={steps}
            index={wiz.index}
            goTo={wiz.goTo}
            isFirst={wiz.isFirst}
            isLast={wiz.isLast}
            pct={wiz.progress}
            onBack={wiz.back}
            onNext={next}
            onSubmit={() => submit(false)}
            processing={saving}
            done={done}
            doneTitle="Vetting check logged"
            doneBlurb="The check is now tracked. Compliance will re-evaluate when the result returns."
            onAddAnother={reset}
        >
            {stepKey === 'person' && (
                <>
                    <StepHead
                        icon={Users}
                        title="Person & type"
                        blurb="Who is being vetted, and which check?"
                    />
                    <Field label="Staff member" required error={err.person}>
                        <PeoplePicker
                            value={person}
                            onChange={setPerson}
                            people={ctx.people}
                        />
                    </Field>
                    <div className="mt-4">
                        <Field label="Check type">
                            <TilePicker
                                value={checkType}
                                onChange={setCheckType}
                                options={VETTING_TYPE_TILES}
                            />
                        </Field>
                    </div>
                </>
            )}

            {stepKey === 'provider' && (
                <>
                    <StepHead
                        icon={Building2}
                        title="Provider"
                        blurb="Where was it requested, and the reference."
                    />
                    <WizardGrid>
                        <Field label="Provider" span>
                            <Select
                                value={provider || undefined}
                                onValueChange={setProvider}
                            >
                                <SelectTrigger aria-label="Provider">
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {PROVIDERS.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Reference no.">
                            <TextField
                                value={reference}
                                onChange={setReference}
                                placeholder="NZP-2026-…"
                            />
                        </Field>
                        <Field label="Check date">
                            <TextField
                                type="date"
                                value={checkDate}
                                onChange={setCheckDate}
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'risk' && (
                <>
                    <StepHead
                        icon={ShieldCheck}
                        title="Risk"
                        blurb="Outcome and any disclosed matters."
                    />
                    <WizardGrid>
                        <Field label="Outcome" span>
                            <Segmented
                                value={outcome}
                                onChange={setOutcome}
                                options={[
                                    { value: 'clear', label: 'Clear' },
                                    {
                                        value: 'considerations',
                                        label: 'Considerations',
                                    },
                                    { value: 'adverse', label: 'Adverse' },
                                ]}
                            />
                        </Field>
                        <Field label="Disclosures" span>
                            <TextAreaField
                                value={disclosures}
                                onChange={setDisclosures}
                                placeholder="Any disclosed matters…"
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'consent' && (
                <>
                    <StepHead
                        icon={FileText}
                        title="Consent"
                        blurb="Record that the staff member consented."
                    />
                    <WizardGrid>
                        <Field span>
                            <Toggle
                                checked={consent}
                                onChange={setConsent}
                                label="Consent captured from staff member"
                            />
                        </Field>
                        <Field label="Method">
                            <Select
                                value={consentMethod || undefined}
                                onValueChange={setConsentMethod}
                            >
                                <SelectTrigger aria-label="Consent method">
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {[
                                        'Signed form',
                                        'Digital signature',
                                        'Verbal (witnessed)',
                                    ].map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'review' && (
                <>
                    <h3 className="mb-1 text-lg font-bold">
                        Review &amp; confirm
                    </h3>
                    {outcome === 'adverse' && (
                        <WarnBanner>
                            Marking an adverse outcome triggers a
                            risk-assessment workflow.
                        </WarnBanner>
                    )}
                    <ReviewList
                        rows={[
                            { label: 'Staff member', value: personName },
                            {
                                label: 'Check type',
                                value: VETTING_TYPE_TILES.find(
                                    (t) => t.key === checkType,
                                )?.label,
                            },
                            { label: 'Provider', value: provider },
                            { label: 'Reference', value: reference },
                            { label: 'Outcome', value: outcome },
                            {
                                label: 'Consent',
                                value: consent
                                    ? consentMethod || 'Captured'
                                    : 'Not captured',
                            },
                        ]}
                    />
                </>
            )}
        </WizardScaffold>
    );
}

/* ================================================================== */
/*  4 · Add / edit driver                                              */
/* ================================================================== */

function DriverWizard({
    state,
    onClose,
    ctx,
}: {
    state: WizardState;
    onClose: () => void;
    ctx: WizardCtx;
}) {
    const steps: WizardStep[] = [
        { key: 'person', label: 'Person', blurb: 'Staff member', icon: Users },
        {
            key: 'licence',
            label: 'Licence',
            blurb: 'Class & endorsements',
            icon: IdCard,
        },
        {
            key: 'history',
            label: 'History',
            blurb: 'Incidents',
            icon: ScrollText,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm & save',
            icon: CheckCircle2,
        },
    ];
    const wiz = useWizard(steps.length);
    const [person, setPerson] = useState<string>(
        (state?.preset?.person as string) ?? '',
    );
    const [number, setNumber] = useState('');
    const [licenceClass, setLicenceClass] = useState('1');
    const [endorsements, setEndorsements] = useState<string[]>([]);
    const [expiry, setExpiry] = useState('');
    const [incidentFree, setIncidentFree] = useState('');
    const [notes, setNotes] = useState('');
    const [err, setErr] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState(false);

    const reset = () => {
        setPerson('');
        setNumber('');
        setLicenceClass('1');
        setEndorsements([]);
        setExpiry('');
        setIncidentFree('');
        setNotes('');
        setErr({});
        setDone(false);
        wiz.goTo(0);
    };

    const stepKey = steps[wiz.index].key;
    const validate = () => {
        const e: Record<string, string> = {};
        if (stepKey === 'person' && !person)
            e.person = 'Select a staff member.';
        if (stepKey === 'licence') {
            if (!number.trim()) e.number = 'Required.';
            if (!expiry) e.expiry = 'Required.';
        }
        setErr(e);
        return Object.keys(e).length === 0;
    };
    const next = () => validate() && wiz.next();
    const personName =
        ctx.people.find((pp) => pp.value === person)?.label ?? '—';

    const submit = (addAnother = false) => {
        if (!person || !number || !expiry) {
            validate();
            wiz.goTo(!person ? 0 : 1);
            return;
        }
        setSaving(true);
        submitWith(
            'post',
            '/hr/compliance/drivers',
            {
                user_id: Number(person),
                licence_number: number,
                licence_class: licenceClass,
                licence_endorsements: endorsements.map(
                    (e) => e.split(' · ')[0],
                ),
                licence_expires_at: expiry,
                incident_free_since: incidentFree || null,
                notes: notes || null,
            },
            {
                onOk: () => {
                    setSaving(false);
                    toast.success('Driver added.');
                    if (addAnother) reset();
                    else setDone(true);
                },
                onFail: () => setSaving(false),
            },
        );
    };

    return (
        <WizardScaffold
            open
            onClose={onClose}
            railIcon={Car}
            railTitle="Add driver"
            railSub="Licence eligibility"
            steps={steps}
            index={wiz.index}
            goTo={wiz.goTo}
            isFirst={wiz.isFirst}
            isLast={wiz.isLast}
            pct={wiz.progress}
            onBack={wiz.back}
            onNext={next}
            onSubmit={() => submit(false)}
            processing={saving}
            done={done}
            doneTitle="Driver added"
            doneBlurb="Licence details recorded. The driver is pending review until approved for shift eligibility."
            onAddAnother={reset}
        >
            {stepKey === 'person' && (
                <>
                    <StepHead
                        icon={Users}
                        title="Person"
                        blurb="Who holds this licence?"
                    />
                    <Field label="Staff member" required error={err.person}>
                        <PeoplePicker
                            value={person}
                            onChange={setPerson}
                            people={ctx.people}
                        />
                    </Field>
                </>
            )}

            {stepKey === 'licence' && (
                <>
                    <StepHead
                        icon={IdCard}
                        title="Licence"
                        blurb="Class, number, endorsements and expiry."
                    />
                    <WizardGrid>
                        <Field
                            label="Licence number"
                            required
                            error={err.number}
                        >
                            <TextField
                                value={number}
                                onChange={setNumber}
                                placeholder="AB123456"
                            />
                        </Field>
                        <Field label="Class">
                            <Select
                                value={licenceClass}
                                onValueChange={setLicenceClass}
                            >
                                <SelectTrigger aria-label="Licence class">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {LICENCE_CLASSES.map((c) => (
                                        <SelectItem key={c} value={c}>
                                            Class {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field label="Endorsements (NZTA)" span>
                            <ChipMulti
                                values={endorsements}
                                onChange={setEndorsements}
                                options={ENDORSEMENTS}
                            />
                        </Field>
                        <Field
                            label="Licence expiry"
                            required
                            error={err.expiry}
                        >
                            <TextField
                                type="date"
                                value={expiry}
                                onChange={setExpiry}
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'history' && (
                <>
                    <StepHead
                        icon={ScrollText}
                        title="History"
                        blurb="Incident-free record and any suspensions."
                    />
                    <WizardGrid>
                        <Field label="Incident-free since">
                            <TextField
                                type="date"
                                value={incidentFree}
                                onChange={setIncidentFree}
                            />
                        </Field>
                        <Field label="Suspension history" span>
                            <TextAreaField
                                value={notes}
                                onChange={setNotes}
                                placeholder="None recorded."
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'review' && (
                <>
                    <h3 className="mb-1 text-lg font-bold">
                        Review &amp; confirm
                    </h3>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Double-check the details below, then save.
                    </p>
                    <ReviewList
                        rows={[
                            { label: 'Staff member', value: personName },
                            { label: 'Licence number', value: number },
                            { label: 'Class', value: `Class ${licenceClass}` },
                            {
                                label: 'Endorsements',
                                value: endorsements
                                    .map((e) => e.split(' · ')[0])
                                    .join(', '),
                            },
                            { label: 'Expiry', value: expiry },
                        ]}
                    />
                </>
            )}
        </WizardScaffold>
    );
}

/* ================================================================== */
/*  5 · Waive / exempt                                                 */
/* ================================================================== */

function WaiveWizard({
    state,
    onClose,
    ctx,
}: {
    state: WizardState;
    onClose: () => void;
    ctx: WizardCtx;
}) {
    const presetUsers = state?.userIds;
    const steps: WizardStep[] = [
        { key: 'scope', label: 'Scope', blurb: 'Who & what', icon: Users },
        {
            key: 'reason',
            label: 'Reason',
            blurb: 'Justification',
            icon: ScrollText,
        },
        {
            key: 'approval',
            label: 'Approval',
            blurb: 'Approver & ack.',
            icon: ShieldCheck,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm & save',
            icon: CheckCircle2,
        },
    ];
    const wiz = useWizard(steps.length);
    const [person, setPerson] = useState<string>(
        (state?.preset?.person as string) ?? '',
    );
    const [requirementId, setRequirementId] = useState<string>(
        state?.preset?.requirement ? String(state?.preset?.requirement) : '',
    );
    const [reason, setReason] = useState('');
    const [duration, setDuration] = useState('permanent');
    const [until, setUntil] = useState('');
    const [approver, setApprover] = useState('');
    const [acknowledge, setAcknowledge] = useState(false);
    const [err, setErr] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState(false);

    const reset = () => {
        setPerson((state?.preset?.person as string) ?? '');
        setRequirementId('');
        setReason('');
        setDuration('permanent');
        setUntil('');
        setApprover('');
        setAcknowledge(false);
        setErr({});
        setDone(false);
        wiz.goTo(0);
    };

    const stepKey = steps[wiz.index].key;
    const validate = () => {
        const e: Record<string, string> = {};
        if (stepKey === 'scope') {
            if (!presetUsers && !person) e.person = 'Select a staff member.';
            if (!requirementId) e.req = 'Choose a requirement.';
        }
        if (stepKey === 'reason') {
            if (!reason.trim()) e.reason = 'A reason is required.';
            if (duration === 'until' && !until) e.until = 'Set an end date.';
        }
        if (stepKey === 'approval' && !acknowledge)
            e.ack = 'You must acknowledge the risk.';
        setErr(e);
        return Object.keys(e).length === 0;
    };
    const next = () => validate() && wiz.next();
    const personName =
        ctx.people.find((pp) => pp.value === person)?.label ?? '—';
    const req = ctx.requirements.find((r) => String(r.id) === requirementId);

    const submit = (addAnother = false) => {
        if (!validate()) {
            // jump to first invalid step
            if ((!presetUsers && !person) || !requirementId) wiz.goTo(0);
            else if (!reason.trim() || (duration === 'until' && !until))
                wiz.goTo(1);
            else wiz.goTo(2);
            return;
        }
        setSaving(true);
        submitWith(
            'post',
            '/hr/compliance/bulk-exempt',
            {
                user_ids: presetUsers ?? [Number(person)],
                requirement_id: Number(requirementId),
                exemption_reason: approver
                    ? `${reason} (approved by ${approver})`
                    : reason,
                exempted_until: duration === 'until' ? until : null,
                acknowledge: true,
            },
            {
                onOk: () => {
                    setSaving(false);
                    toast.success('Exemption recorded and hard-stop lifted.');
                    if (addAnother) reset();
                    else setDone(true);
                },
                onFail: () => setSaving(false),
            },
        );
    };

    return (
        <WizardScaffold
            open
            onClose={onClose}
            railIcon={Ban}
            railTitle="Waive / exempt"
            railSub="Record an exemption"
            steps={steps}
            index={wiz.index}
            goTo={wiz.goTo}
            isFirst={wiz.isFirst}
            isLast={wiz.isLast}
            pct={wiz.progress}
            onBack={wiz.back}
            onNext={next}
            onSubmit={() => submit(false)}
            processing={saving}
            done={done}
            doneTitle="Exemption recorded"
            doneBlurb="The waiver is logged with reason and approver. An audit entry was created and the hard-stop lifted."
            onAddAnother={reset}
        >
            {stepKey === 'scope' && (
                <>
                    <StepHead
                        icon={Users}
                        title="Scope"
                        blurb="Who and which requirement is being waived."
                    />
                    {!presetUsers && (
                        <Field label="Staff member" required error={err.person}>
                            <PeoplePicker
                                value={person}
                                onChange={setPerson}
                                people={ctx.people}
                            />
                        </Field>
                    )}
                    {presetUsers && (
                        <div className="rounded-lg border border-primary/30 bg-primary/5 p-3 text-sm font-semibold text-primary">
                            {presetUsers.length} staff selected
                        </div>
                    )}
                    <div className="mt-4">
                        <Field label="Requirement" required error={err.req}>
                            <Select
                                value={requirementId || undefined}
                                onValueChange={setRequirementId}
                            >
                                <SelectTrigger aria-label="Requirement">
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {ctx.requirements.map((r) => (
                                        <SelectItem
                                            key={r.id}
                                            value={String(r.id)}
                                        >
                                            {r.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </div>
                </>
            )}

            {stepKey === 'reason' && (
                <>
                    <StepHead
                        icon={ScrollText}
                        title="Reason"
                        blurb="Why is this exemption being granted?"
                    />
                    <WizardGrid>
                        <Field label="Reason" required error={err.reason} span>
                            <TextAreaField
                                value={reason}
                                onChange={setReason}
                                placeholder="Why is this exemption being granted?"
                            />
                        </Field>
                        <Field label="Duration">
                            <Segmented
                                value={duration}
                                onChange={setDuration}
                                options={[
                                    { value: 'permanent', label: 'Permanent' },
                                    { value: 'until', label: 'Until date' },
                                ]}
                            />
                        </Field>
                        {duration === 'until' && (
                            <Field label="Expires" required error={err.until}>
                                <TextField
                                    type="date"
                                    value={until}
                                    onChange={setUntil}
                                />
                            </Field>
                        )}
                    </WizardGrid>
                </>
            )}

            {stepKey === 'approval' && (
                <>
                    <StepHead
                        icon={ShieldCheck}
                        title="Approval"
                        blurb="Who approved it, and acknowledge the risk."
                    />
                    <WizardGrid>
                        <Field label="Approver" span>
                            <Select
                                value={approver || undefined}
                                onValueChange={setApprover}
                            >
                                <SelectTrigger aria-label="Approver">
                                    <SelectValue placeholder="Select…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {[
                                        'Service Manager',
                                        'HR Manager',
                                        'Clinical Lead',
                                    ].map((c) => (
                                        <SelectItem key={c} value={c}>
                                            {c}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field span error={err.ack}>
                            <Toggle
                                checked={acknowledge}
                                onChange={setAcknowledge}
                                label="I acknowledge the risk of this exemption"
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'review' && (
                <>
                    <h3 className="mb-1 text-lg font-bold">
                        Review &amp; confirm
                    </h3>
                    <WarnBanner>
                        Waiving a hard-stop requirement allows shift assignment
                        despite non-compliance.
                    </WarnBanner>
                    <ReviewList
                        rows={[
                            {
                                label: 'Staff',
                                value: presetUsers
                                    ? `${presetUsers.length} selected`
                                    : personName,
                            },
                            { label: 'Requirement', value: req?.name },
                            { label: 'Reason', value: reason },
                            {
                                label: 'Duration',
                                value:
                                    duration === 'until'
                                        ? `Until ${until}`
                                        : 'Permanent',
                            },
                            { label: 'Approver', value: approver },
                        ]}
                    />
                </>
            )}
        </WizardScaffold>
    );
}

/* ================================================================== */
/*  6 · Bulk assign                                                    */
/* ================================================================== */

function AssignWizard({
    state,
    onClose,
    ctx,
}: {
    state: WizardState;
    onClose: () => void;
    ctx: WizardCtx;
}) {
    const steps: WizardStep[] = [
        {
            key: 'reqs',
            label: 'Requirements',
            blurb: 'What to assign',
            icon: ListChecks,
        },
        {
            key: 'audience',
            label: 'Audience',
            blurb: 'Roles & sites',
            icon: Users,
        },
        {
            key: 'review',
            label: 'Review',
            blurb: 'Confirm & save',
            icon: CheckCircle2,
        },
    ];
    const wiz = useWizard(steps.length);
    const [reqIds, setReqIds] = useState<string[]>(
        state?.preset?.requirement_id
            ? [String(state?.preset?.requirement_id)]
            : [],
    );
    const [rolesSel, setRolesSel] = useState<string[]>([]);
    const [siteTypesSel, setSiteTypesSel] = useState<string[]>([]);
    const [mandatory, setMandatory] = useState(true);
    const [err, setErr] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [done, setDone] = useState(false);

    const reqOptions = useMemo(
        () =>
            ctx.requirements.map((r) => ({
                value: String(r.id),
                label: r.name,
            })),
        [ctx.requirements],
    );
    const selectedNames = reqIds
        .map((id) => ctx.requirements.find((r) => String(r.id) === id)?.name)
        .filter(Boolean) as string[];

    const reset = () => {
        setReqIds([]);
        setRolesSel([]);
        setSiteTypesSel([]);
        setMandatory(true);
        setErr({});
        setDone(false);
        wiz.goTo(0);
    };

    const stepKey = steps[wiz.index].key;
    const validate = () => {
        const e: Record<string, string> = {};
        if (stepKey === 'reqs' && reqIds.length === 0)
            e.reqs = 'Choose at least one requirement.';
        if (stepKey === 'audience' && rolesSel.length === 0)
            e.roles = 'Choose at least one role.';
        setErr(e);
        return Object.keys(e).length === 0;
    };
    const next = () => validate() && wiz.next();

    const submit = (addAnother = false) => {
        if (reqIds.length === 0) {
            setErr({ reqs: 'Choose at least one requirement.' });
            wiz.goTo(0);
            return;
        }
        if (rolesSel.length === 0) {
            setErr({ roles: 'Choose at least one role.' });
            wiz.goTo(1);
            return;
        }
        setSaving(true);
        submitWith(
            'post',
            '/hr/compliance/assign',
            {
                requirement_ids: reqIds.map(Number),
                roles: rolesSel,
                site_types: siteTypesSel,
                is_mandatory: mandatory,
            },
            {
                onOk: () => {
                    setSaving(false);
                    toast.success('Requirements assigned.');
                    if (addAnother) reset();
                    else setDone(true);
                },
                onFail: () => setSaving(false),
            },
        );
    };

    return (
        <WizardScaffold
            open
            onClose={onClose}
            railIcon={ListChecks}
            railTitle="Assign requirements"
            railSub="Bulk role assignment"
            steps={steps}
            index={wiz.index}
            goTo={wiz.goTo}
            isFirst={wiz.isFirst}
            isLast={wiz.isLast}
            pct={wiz.progress}
            onBack={wiz.back}
            onNext={next}
            onSubmit={() => submit(false)}
            processing={saving}
            done={done}
            doneTitle="Requirements assigned"
            doneBlurb="Matrix rows were created for each role and site type. Staff in those roles are now tracked."
            onAddAnother={reset}
        >
            {stepKey === 'reqs' && (
                <>
                    <StepHead
                        icon={ListChecks}
                        title="Requirements"
                        blurb="Which requirements do you want to assign?"
                    />
                    <Field label="Requirements" required error={err.reqs}>
                        <LabeledChipMulti
                            values={reqIds}
                            onChange={setReqIds}
                            options={reqOptions}
                        />
                    </Field>
                </>
            )}

            {stepKey === 'audience' && (
                <>
                    <StepHead
                        icon={Users}
                        title="Audience"
                        blurb="Which roles and site types must hold these?"
                    />
                    <WizardGrid>
                        <Field label="Roles" required error={err.roles} span>
                            <LabeledChipMulti
                                values={rolesSel}
                                onChange={setRolesSel}
                                options={ctx.roles}
                            />
                        </Field>
                        <Field label="Site types" span>
                            <ChipMulti
                                values={siteTypesSel}
                                onChange={setSiteTypesSel}
                                options={ctx.siteTypes}
                            />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Leave empty to apply to all Sites. Choices come
                                from the active Sites register.
                            </p>
                        </Field>
                        <Field span>
                            <Toggle
                                checked={mandatory}
                                onChange={setMandatory}
                                label="Mandatory for these roles"
                            />
                        </Field>
                    </WizardGrid>
                </>
            )}

            {stepKey === 'review' && (
                <>
                    <h3 className="mb-1 text-lg font-bold">
                        Review &amp; confirm
                    </h3>
                    <p className="mb-4 text-sm text-muted-foreground">
                        This creates{' '}
                        {reqIds.length *
                            rolesSel.length *
                            Math.max(siteTypesSel.length, 1)}{' '}
                        matrix assignment
                        {reqIds.length * rolesSel.length === 1 ? '' : 's'}.
                    </p>
                    <ReviewList
                        rows={[
                            {
                                label: 'Requirements',
                                value: selectedNames.join(', '),
                            },
                            { label: 'Roles', value: rolesSel.join(', ') },
                            {
                                label: 'Site types',
                                value: siteTypesSel.join(', ') || 'All',
                            },
                            {
                                label: 'Mandatory',
                                value: mandatory ? 'Yes' : 'No',
                            },
                        ]}
                    />
                </>
            )}
        </WizardScaffold>
    );
}

/* ================================================================== */
/*  Dispatcher                                                         */
/* ================================================================== */

export function ComplianceWizards({
    state,
    onClose,
    ...ctx
}: {
    state: WizardState;
    onClose: () => void;
} & WizardCtx) {
    if (!state) return null;
    switch (state.type) {
        case 'record':
            return <RecordWizard state={state} onClose={onClose} ctx={ctx} />;
        case 'requirement':
            return (
                <RequirementWizard state={state} onClose={onClose} ctx={ctx} />
            );
        case 'vetting':
            return <VettingWizard state={state} onClose={onClose} ctx={ctx} />;
        case 'driver':
            return <DriverWizard state={state} onClose={onClose} ctx={ctx} />;
        case 'waive':
            return <WaiveWizard state={state} onClose={onClose} ctx={ctx} />;
        case 'assign':
            return <AssignWizard state={state} onClose={onClose} ctx={ctx} />;
        default:
            return null;
    }
}

export default ComplianceWizards;
