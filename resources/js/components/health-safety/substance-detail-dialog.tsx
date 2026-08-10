/* eslint-disable no-restricted-syntax -- Detail-modal sections compose bespoke
 * card/chip/quantity-bar layout surfaces (mirroring the Safeguarding concern
 * dialog and hs-hero-kit). Every colour is a semantic design token; these are
 * intentional custom surfaces, not the generic Card primitive. */
import { StorageLocationFields } from '@/components/health-safety/storage-location-fields';
import { Button } from '@/components/ui/button';
import {
    FileDropzone,
    formatFileSize,
    StagedFileCard,
} from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    Segmented,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { WizardShell } from '@/components/wizard/shell';
import { formatDate, formatDateTime } from '@/lib/datetime';
import { FlagBadge } from '@/pages/health-safety/components/register-row-kit';
import {
    EXPOSURE_TYPES,
    GHS_BY_CODE,
    MEDICAL_TREATMENTS,
    SDS_STATE_META,
    STATUS_META,
    substanceRiskTone,
    type SdsState,
    type Tone,
} from '@/pages/health-safety/substances/constants';
import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleSlash,
    Download,
    ExternalLink,
    FileText,
    FlaskConical,
    HeartPulse,
    History,
    MapPin,
    Pencil,
    Plus,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    Upload,
    User as UserIcon,
    type LucideIcon,
} from 'lucide-react';
import {
    useState,
    type ComponentType,
    type FormEvent,
    type ReactNode,
} from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirrors HazardousSubstanceController::buildSubstanceDetail() */
/* ------------------------------------------------------------------ */

export type SiteOption = { id: number; name: string };
export type StaffOption = { id: number; name: string };

export type SdsRecord = {
    id: number;
    version: string;
    issue_date: string | null;
    review_date: string | null;
    supplier_name: string | null;
    supplier_contact: string | null;
    status: string;
    state: SdsState;
    file_name: string | null;
    uploaded_by: string | null;
    created_at: string | null;
    download_url: string | null;
};

export type StorageLocation = {
    id: number;
    site: { id: number; name: string } | null;
    location_description: string;
    current_quantity: number | null;
    quantity_unit: string | null;
    maximum_quantity: number | null;
    container_type: string | null;
    properly_labelled: boolean;
    segregation_compliant: boolean;
    last_audit_date: string | null;
};

export type ExposureRecord = {
    id: number;
    user: { id: number; name: string } | null;
    exposed_at: string | null;
    exposure_type: string;
    exposure_duration: string | null;
    circumstances: string | null;
    symptoms: string | null;
    first_aid_given: string | null;
    medical_attention_sought: boolean;
    medical_treatment: string | null;
    medical_outcome: string | null;
    incident_reported: boolean;
    related_incident_id: number | null;
};

export type SubstanceDetail = {
    id: number;
    name: string;
    common_name: string | null;
    un_number: string | null;
    hsno_approval: string | null;
    hsno_classification: string | null;
    hazard_classifications: string[];
    ghs_pictograms: string[];
    signal_word: string | null;
    hazard_statements: string | null;
    precautionary_statements: string | null;
    physical_form: string | null;
    is_controlled_substance: boolean;
    requires_tracking: boolean;
    status: string;
    status_reason: string | null;
    sds_state: SdsState;
    ppe_required: string | null;
    storage_requirements: string | null;
    handling_precautions: string | null;
    first_aid_measures: string | null;
    firefighting_measures: string | null;
    spill_procedures: string | null;
    exposure_limit_type: string | null;
    exposure_limit_value: string | null;
    sds_records: SdsRecord[];
    storage_locations: StorageLocation[];
    exposure_records: ExposureRecord[];
    counts: { sds: number; storage: number; exposures: number };
    created_by: string | null;
    created_at: string | null;
    updated_at: string | null;
    can: { create: boolean; manage: boolean };
    staff: StaffOption[];
};

export type ActionKey =
    | 'add_sds'
    | 'add_storage'
    | 'record_exposure'
    | 'deactivate';
export type SectionKey =
    | 'overview'
    | 'safety'
    | 'sds'
    | 'storage'
    | 'exposures'
    | 'history';

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

const SDS_MAX_BYTES = 10 * 1024 * 1024;
const SDS_EXT = ['pdf', 'doc', 'docx'];

function validateSdsFile(file: File): string | null {
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (!SDS_EXT.includes(ext))
        return 'Use a PDF or Word document (.pdf, .doc, .docx).';
    if (file.size > SDS_MAX_BYTES)
        return `That file is ${formatFileSize(file.size)} — the limit is 10 MB.`;
    return null;
}

function titleCase(s: string | null | undefined): string {
    return (s ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

const todayStr = (): string => {
    const dt = new Date();
    return new Date(dt.getTime() - dt.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
};

const nowLocal = (): string => {
    const dt = new Date();
    return new Date(dt.getTime() - dt.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 16);
};

/** Post, keep the pane open if the server flashed an error (302 + flash.error). */
function onSuccessGuard(onDone: () => void) {
    return (page: { props: Record<string, unknown> }) => {
        const flash = page.props.flash as { error?: string } | undefined;
        if (!flash?.error) onDone();
    };
}

function GhsChips({ codes }: { codes: string[] }) {
    if (!codes.length)
        return (
            <span className="text-sm text-muted-foreground">
                No pictograms recorded
            </span>
        );
    return (
        <div className="flex flex-wrap gap-1.5">
            {codes.map((code) => {
                const meta = GHS_BY_CODE[code];
                if (!meta) return null;
                const Icon = meta.icon;
                return (
                    <span
                        key={code}
                        title={meta.label}
                        className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-2 py-1 text-[12px] font-medium"
                    >
                        <span
                            className={`grid h-5 w-5 place-items-center rounded-md ${TONE_SOFT[meta.tone]}`}
                        >
                            <Icon className="h-3 w-3" />
                        </span>
                        {meta.label}
                    </span>
                );
            })}
        </div>
    );
}

const TONE_SOFT: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

function SdsBadge({ state }: { state: SdsState }) {
    const meta = SDS_STATE_META[state];
    return (
        <FlagBadge icon={meta.icon} tone={meta.tone} title={meta.label}>
            {meta.label}
        </FlagBadge>
    );
}

function Chips({ items }: { items: string[] }) {
    if (!items.length)
        return <span className="text-sm text-muted-foreground">—</span>;
    return (
        <div className="flex flex-wrap gap-1.5">
            {items.map((c) => (
                <span
                    key={c}
                    className="rounded-full border border-border bg-muted/50 px-2.5 py-0.5 text-[12px] font-medium"
                >
                    {c}
                </span>
            ))}
        </div>
    );
}

function TextBlock({
    label,
    value,
}: {
    label: string;
    value: string | null | undefined;
}) {
    return (
        <div>
            <div className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                {label}
            </div>
            {value ? (
                <p className="mt-1 text-sm leading-relaxed whitespace-pre-wrap text-foreground">
                    {value}
                </p>
            ) : (
                <p className="mt-1 text-sm text-muted-foreground/70">
                    Not recorded
                </p>
            )}
        </div>
    );
}

function MetaRow({ label, value }: { label: string; value: ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 border-b border-border py-2 last:border-0">
            <span className="text-[13px] text-muted-foreground">{label}</span>
            <span className="min-w-0 text-right text-[13px] font-medium">
                {value || <span className="text-muted-foreground/70">—</span>}
            </span>
        </div>
    );
}

function BoolToggle({
    value,
    onChange,
    yes = 'Yes',
    no = 'No',
}: {
    value: boolean;
    onChange: (v: boolean) => void;
    yes?: string;
    no?: string;
}) {
    return (
        <Segmented<'yes' | 'no'>
            value={value ? 'yes' : 'no'}
            onChange={(v) => onChange(v === 'yes')}
            options={[
                { value: 'yes', label: yes },
                { value: 'no', label: no },
            ]}
        />
    );
}

function EmptyState({
    icon: Icon,
    title,
    hint,
}: {
    icon: LucideIcon;
    title: string;
    hint: string;
}) {
    return (
        <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border py-10 text-center">
            <Icon className="mb-2 h-8 w-8 text-muted-foreground/40" />
            <p className="text-sm font-semibold text-foreground">{title}</p>
            <p className="mt-0.5 max-w-xs text-xs text-muted-foreground">
                {hint}
            </p>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Dialog                                                            */
/* ------------------------------------------------------------------ */

export function SubstanceDetailDialog({
    detail,
    sites,
    open,
    onClose,
    onEdit,
    initialAction = null,
    initialSection = 'overview',
}: {
    detail: SubstanceDetail;
    sites: SiteOption[];
    open: boolean;
    onClose: () => void;
    onEdit?: (detail: SubstanceDetail) => void;
    initialAction?: ActionKey | null;
    initialSection?: SectionKey;
}) {
    const [section, setSection] = useState<SectionKey>(initialSection);
    const [action, setAction] = useState<ActionKey | null>(initialAction);
    const d = detail;

    const status = STATUS_META[d.status] ?? {
        label: titleCase(d.status),
        tone: 'neutral' as Tone,
    };
    const can = d.can ?? { create: false, manage: false };
    const isRemoved = d.status === 'removed';

    const SECTIONS: {
        key: SectionKey;
        label: string;
        blurb: string;
        icon: ComponentType<{ className?: string }>;
    }[] = [
        {
            key: 'overview',
            label: 'Overview',
            blurb: 'Identity & classification',
            icon: FlaskConical,
        },
        {
            key: 'safety',
            label: 'Safety & handling',
            blurb: 'PPE, storage, first aid',
            icon: ShieldCheck,
        },
        {
            key: 'sds',
            label: 'SDS',
            blurb: `${d.counts.sds} on file`,
            icon: FileText,
        },
        {
            key: 'storage',
            label: 'Storage',
            blurb: `${d.counts.storage} location${d.counts.storage === 1 ? '' : 's'}`,
            icon: MapPin,
        },
        {
            key: 'exposures',
            label: 'Exposures',
            blurb: `${d.counts.exposures} record${d.counts.exposures === 1 ? '' : 's'}`,
            icon: HeartPulse,
        },
        {
            key: 'history',
            label: 'History',
            blurb: 'Provenance',
            icon: History,
        },
    ];
    const stepIndex = Math.max(
        0,
        SECTIONS.findIndex((s) => s.key === section),
    );

    const footerStart = (
        <div className="flex flex-wrap items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span
                    className={`h-1.5 w-1.5 rounded-full ${DOT[status.tone]}`}
                />
                {status.label}
            </span>
            {d.is_controlled_substance ? (
                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 font-medium text-status-critical">
                    <ShieldAlert className="h-3 w-3" /> Controlled
                </span>
            ) : null}
            <SdsBadge state={d.sds_state} />
        </div>
    );

    const reactivate = () =>
        router.patch(
            `/health-safety/substances/${d.id}/status`,
            { status: 'active' },
            { preserveScroll: true },
        );

    const footerEnd = action ? null : (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <Link
                href={`/health-safety/substances/${d.id}`}
                className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted"
            >
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {can.manage && onEdit ? (
                <OptionBtn
                    icon={Pencil}
                    label="Edit"
                    onClick={() => onEdit(d)}
                />
            ) : null}
            {can.create && !isRemoved ? (
                <OptionBtn
                    icon={Upload}
                    label="Add SDS"
                    onClick={() => setAction('add_sds')}
                />
            ) : null}
            {can.create && !isRemoved ? (
                <OptionBtn
                    icon={MapPin}
                    label="Add storage"
                    onClick={() => setAction('add_storage')}
                />
            ) : null}
            {can.create && !isRemoved ? (
                <OptionBtn
                    icon={HeartPulse}
                    label="Record exposure"
                    onClick={() => setAction('record_exposure')}
                />
            ) : null}
            {can.manage && d.status === 'active' ? (
                <OptionBtn
                    icon={CircleSlash}
                    label="Mark inactive"
                    onClick={() => setAction('deactivate')}
                />
            ) : null}
            {can.manage && d.status !== 'active' ? (
                <OptionBtn
                    icon={RotateCcw}
                    label="Reactivate"
                    onClick={reactivate}
                />
            ) : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Substance ${d.name}`}
            description={`${titleCase(d.physical_form)} · ${d.hsno_classification ?? 'Hazardous substance'}`}
            railIcon={d.is_controlled_substance ? ShieldAlert : FlaskConical}
            railTitle={d.name}
            railSub={
                d.common_name || d.hsno_classification || 'Hazardous substance'
            }
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => {
                setAction(null);
                setSection(SECTIONS[i].key);
            }}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {action === 'add_sds' ? (
                <AddSdsPane d={d} onDone={() => setAction(null)} />
            ) : action === 'add_storage' ? (
                <AddStoragePane
                    d={d}
                    sites={sites}
                    onDone={() => setAction(null)}
                />
            ) : action === 'record_exposure' ? (
                <RecordExposurePane
                    d={d}
                    sites={sites}
                    onDone={() => setAction(null)}
                />
            ) : action === 'deactivate' ? (
                <DeactivatePane d={d} onDone={() => setAction(null)} />
            ) : (
                <>
                    {section === 'overview' ? <OverviewSection d={d} /> : null}
                    {section === 'safety' ? <SafetySection d={d} /> : null}
                    {section === 'sds' ? (
                        <SdsSection
                            d={d}
                            canAdd={can.create && !isRemoved}
                            onAdd={() => setAction('add_sds')}
                        />
                    ) : null}
                    {section === 'storage' ? (
                        <StorageSection
                            d={d}
                            canAdd={can.create && !isRemoved}
                            onAdd={() => setAction('add_storage')}
                        />
                    ) : null}
                    {section === 'exposures' ? (
                        <ExposuresSection
                            d={d}
                            canAdd={can.create && !isRemoved}
                            onAdd={() => setAction('record_exposure')}
                        />
                    ) : null}
                    {section === 'history' ? <HistorySection d={d} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Options-bar button + pane shell                                    */
/* ------------------------------------------------------------------ */

function OptionBtn({
    icon: Icon,
    label,
    onClick,
    disabled,
    reason,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    onClick: () => void;
    disabled?: boolean;
    reason?: string;
}) {
    return (
        <Button
            size="sm"
            variant="outline"
            onClick={onClick}
            disabled={disabled}
            title={disabled ? reason : undefined}
        >
            <Icon className="mr-1.5 h-4 w-4" /> {label}
        </Button>
    );
}

function PaneShell({
    children,
    onCancel,
    onSubmit,
    cta,
    processing,
}: {
    children: ReactNode;
    onCancel: () => void;
    onSubmit: (e: FormEvent) => void;
    cta: string;
    processing: boolean;
}) {
    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
            {children}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {cta}
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Action panes                                                       */
/* ------------------------------------------------------------------ */

function AddSdsPane({ d, onDone }: { d: SubstanceDetail; onDone: () => void }) {
    const form = useForm<{
        version: string;
        issue_date: string;
        review_date: string;
        supplier_name: string;
        supplier_contact: string;
        file: File | null;
    }>({
        version: '',
        issue_date: todayStr(),
        review_date: '',
        supplier_name: '',
        supplier_contact: '',
        file: null,
    });
    const [fileError, setFileError] = useState<string | null>(null);

    const stage = (files: File[]) => {
        const file = files[0];
        if (!file) return;
        const err = validateSdsFile(file);
        if (err) {
            setFileError(err);
            return;
        }
        setFileError(null);
        form.setData('file', file);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.file) {
            setFileError('Attach the SDS document.');
            return;
        }
        form.post(`/health-safety/substances/${d.id}/sds`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onSuccessGuard(onDone),
        });
    };

    return (
        <>
            <StepHead
                icon={Upload}
                title="Add Safety Data Sheet"
                blurb="Uploading a new SDS supersedes the current one. PDF or Word, up to 10 MB."
            />
            <PaneShell
                onCancel={onDone}
                onSubmit={submit}
                cta={form.processing ? 'Uploading…' : 'Upload SDS'}
                processing={form.processing}
            >
                {form.data.file ? (
                    <StagedFileCard
                        file={form.data.file}
                        onRemove={() => form.setData('file', null)}
                    />
                ) : (
                    <FileDropzone
                        multiple={false}
                        accept=".pdf,.doc,.docx"
                        title="Drop the SDS here"
                        hint="PDF or Word — up to 10 MB"
                        onFiles={stage}
                    />
                )}
                {fileError ? (
                    <p className="-mt-1 text-xs text-status-critical">
                        {fileError}
                    </p>
                ) : null}
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Version" required error={form.errors.version}>
                        <Input
                            value={form.data.version}
                            onChange={(e) =>
                                form.setData('version', e.target.value)
                            }
                            placeholder="e.g. 2.1"
                        />
                    </Field>
                    <Field
                        label="Issue date"
                        required
                        error={form.errors.issue_date}
                    >
                        <Input
                            type="date"
                            value={form.data.issue_date}
                            onChange={(e) =>
                                form.setData('issue_date', e.target.value)
                            }
                        />
                    </Field>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        label="Review date"
                        hint="When the SDS is next due"
                        error={form.errors.review_date}
                    >
                        <Input
                            type="date"
                            value={form.data.review_date}
                            onChange={(e) =>
                                form.setData('review_date', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Supplier" error={form.errors.supplier_name}>
                        <Input
                            value={form.data.supplier_name}
                            onChange={(e) =>
                                form.setData('supplier_name', e.target.value)
                            }
                            placeholder="Supplier / manufacturer"
                        />
                    </Field>
                </div>
                <Field
                    label="Supplier contact"
                    hint="Phone or email"
                    error={form.errors.supplier_contact}
                >
                    <Input
                        value={form.data.supplier_contact}
                        onChange={(e) =>
                            form.setData('supplier_contact', e.target.value)
                        }
                    />
                </Field>
            </PaneShell>
        </>
    );
}

function AddStoragePane({
    d,
    sites,
    onDone,
}: {
    d: SubstanceDetail;
    sites: SiteOption[];
    onDone: () => void;
}) {
    const form = useForm<{
        site_id: string;
        location_description: string;
        current_quantity: string;
        maximum_quantity: string;
        quantity_unit: string;
        container_type: string;
        properly_labelled: boolean;
        segregation_compliant: boolean;
        last_audit_date: string;
        storage_notes: string;
    }>({
        site_id: '',
        location_description: '',
        current_quantity: '',
        maximum_quantity: '',
        quantity_unit: 'L',
        container_type: '',
        properly_labelled: true,
        segregation_compliant: true,
        last_audit_date: todayStr(),
        storage_notes: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.site_id) {
            form.setError('site_id', 'Choose the site.');
            return;
        }
        if (!form.data.location_description.trim()) {
            form.setError('location_description', 'Describe where it is held.');
            return;
        }
        form.post(`/health-safety/substances/${d.id}/storage-locations`, {
            preserveScroll: true,
            onSuccess: onSuccessGuard(onDone),
        });
    };

    return (
        <>
            <StepHead
                icon={MapPin}
                title="Add storage location"
                blurb="Where this substance is held, how much, and whether it is labelled and segregated."
            />
            <PaneShell
                onCancel={onDone}
                onSubmit={submit}
                cta="Add location"
                processing={form.processing}
            >
                <Field label="Site" required error={form.errors.site_id}>
                    <SelectInput
                        value={form.data.site_id}
                        onChange={(v) => form.setData('site_id', v)}
                        placeholder="Select site"
                        options={sites.map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </Field>
                <StorageLocationFields
                    values={form.data}
                    set={(k, v) => form.setData(k as never, v as never)}
                    errors={form.errors}
                />
            </PaneShell>
        </>
    );
}

function RecordExposurePane({
    d,
    sites,
    onDone,
}: {
    d: SubstanceDetail;
    sites: SiteOption[];
    onDone: () => void;
}) {
    const form = useForm<{
        user_id: string;
        site_id: string;
        exposed_at: string;
        exposure_type: string;
        exposure_duration: string;
        circumstances: string;
        symptoms: string;
        first_aid_given: string;
        medical_treatment: string;
        medical_outcome: string;
        incident_reported: boolean;
    }>({
        user_id: '',
        site_id: '',
        exposed_at: nowLocal(),
        exposure_type: 'inhalation',
        exposure_duration: '',
        circumstances: '',
        symptoms: '',
        first_aid_given: '',
        medical_treatment: 'none',
        medical_outcome: '',
        incident_reported: false,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.user_id) {
            form.setError('user_id', 'Who was exposed?');
            return;
        }
        form.post(`/health-safety/substances/${d.id}/exposure-records`, {
            preserveScroll: true,
            onSuccess: onSuccessGuard(onDone),
        });
    };

    return (
        <>
            <StepHead
                icon={HeartPulse}
                title="Record an exposure"
                blurb="Logged exposures raise a Health & Safety event automatically. Flag medical attention so it is triaged for WorkSafe notifiability."
            />
            <PaneShell
                onCancel={onDone}
                onSubmit={submit}
                cta="Record exposure"
                processing={form.processing}
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        label="Worker exposed"
                        required
                        error={form.errors.user_id}
                    >
                        <SelectInput
                            value={form.data.user_id}
                            onChange={(v) => form.setData('user_id', v)}
                            placeholder="Select worker"
                            options={d.staff.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            }))}
                        />
                    </Field>
                    <Field label="Site" hint="Optional">
                        <SelectInput
                            value={form.data.site_id}
                            onChange={(v) => form.setData('site_id', v)}
                            placeholder="Select site"
                            options={sites.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            }))}
                        />
                    </Field>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="When" required error={form.errors.exposed_at}>
                        <Input
                            type="datetime-local"
                            value={form.data.exposed_at}
                            onChange={(e) =>
                                form.setData('exposed_at', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Route" required>
                        <SelectInput
                            value={form.data.exposure_type}
                            onChange={(v) => form.setData('exposure_type', v)}
                            placeholder="Exposure route"
                            options={EXPOSURE_TYPES}
                        />
                    </Field>
                </div>
                <Field label="Duration" hint="e.g. 5 minutes">
                    <Input
                        value={form.data.exposure_duration}
                        onChange={(e) =>
                            form.setData('exposure_duration', e.target.value)
                        }
                    />
                </Field>
                <Field label="Circumstances">
                    <Textarea
                        rows={2}
                        value={form.data.circumstances}
                        onChange={(e) =>
                            form.setData('circumstances', e.target.value)
                        }
                        placeholder="What happened?"
                    />
                </Field>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Symptoms">
                        <Textarea
                            rows={2}
                            value={form.data.symptoms}
                            onChange={(e) =>
                                form.setData('symptoms', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="First aid given">
                        <Textarea
                            rows={2}
                            value={form.data.first_aid_given}
                            onChange={(e) =>
                                form.setData('first_aid_given', e.target.value)
                            }
                        />
                    </Field>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        label="Medical treatment"
                        hint="Drives WorkSafe notifiability"
                    >
                        <SelectInput
                            value={form.data.medical_treatment}
                            onChange={(v) =>
                                form.setData('medical_treatment', v)
                            }
                            placeholder="Level of treatment"
                            options={MEDICAL_TREATMENTS}
                        />
                    </Field>
                    <Field label="Reported as incident">
                        <BoolToggle
                            value={form.data.incident_reported}
                            onChange={(v) =>
                                form.setData('incident_reported', v)
                            }
                        />
                    </Field>
                </div>
                {['medical', 'hospitalisation', 'death'].includes(
                    form.data.medical_treatment,
                ) ? (
                    <Field label="Medical outcome">
                        <Input
                            value={form.data.medical_outcome}
                            onChange={(e) =>
                                form.setData('medical_outcome', e.target.value)
                            }
                            placeholder="Treatment / outcome"
                        />
                    </Field>
                ) : null}
            </PaneShell>
        </>
    );
}

function DeactivatePane({
    d,
    onDone,
}: {
    d: SubstanceDetail;
    onDone: () => void;
}) {
    const form = useForm<{ status: string; reason: string }>({
        status: 'inactive',
        reason: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.reason.trim()) {
            form.setError('reason', 'A reason is required.');
            return;
        }
        form.patch(`/health-safety/substances/${d.id}/status`, {
            preserveScroll: true,
            onSuccess: onSuccessGuard(onDone),
        });
    };
    return (
        <>
            <StepHead
                icon={CircleSlash}
                title="Mark inactive or removed"
                blurb="Inactive keeps the record for reference; Removed archives it off the live register. The reason is recorded on the substance."
            />
            <PaneShell
                onCancel={onDone}
                onSubmit={submit}
                cta="Apply"
                processing={form.processing}
            >
                <Field label="New status" required>
                    <Segmented<string>
                        value={form.data.status}
                        onChange={(v) => form.setData('status', v)}
                        options={[
                            { value: 'inactive', label: 'Inactive' },
                            { value: 'removed', label: 'Removed' },
                        ]}
                    />
                </Field>
                <Field label="Reason" required error={form.errors.reason}>
                    <Textarea
                        rows={3}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        placeholder="Why is this substance no longer in use?"
                    />
                </Field>
            </PaneShell>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Read-only sections                                                 */
/* ------------------------------------------------------------------ */

function OverviewSection({ d }: { d: SubstanceDetail }) {
    const riskTone = substanceRiskTone(d.is_controlled_substance, d.sds_state);
    return (
        <div className="flex flex-col gap-5">
            <div className="rounded-xl border border-border bg-card/70 p-4">
                <div className="flex items-start gap-3">
                    <span
                        className={`mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full ${DOT[riskTone]}`}
                    />
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-bold">{d.name}</h3>
                            {d.is_controlled_substance ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">
                                    <ShieldAlert className="h-3 w-3" />{' '}
                                    Controlled
                                </span>
                            ) : null}
                        </div>
                        {d.common_name ? (
                            <p className="text-sm text-muted-foreground">
                                {d.common_name}
                            </p>
                        ) : null}
                    </div>
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <MetaRow label="UN number" value={d.un_number} />
                <MetaRow
                    label="Physical form"
                    value={titleCase(d.physical_form)}
                />
                <MetaRow
                    label="HSNO / EPA classification"
                    value={d.hsno_classification}
                />
                <MetaRow label="HSNO approval" value={d.hsno_approval} />
                <MetaRow label="Signal word" value={d.signal_word} />
                <MetaRow
                    label="Requires tracking"
                    value={d.requires_tracking ? 'Yes' : 'No'}
                />
            </div>

            <div>
                <div className="mb-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                    Hazard pictograms
                </div>
                <GhsChips codes={d.ghs_pictograms} />
            </div>
            <div>
                <div className="mb-1.5 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                    Hazard classes
                </div>
                <Chips items={d.hazard_classifications} />
            </div>
            <TextBlock label="Hazard statements" value={d.hazard_statements} />
            <TextBlock
                label="Precautionary statements"
                value={d.precautionary_statements}
            />

            {d.status !== 'active' && d.status_reason ? (
                <div className="flex gap-2.5 rounded-lg border border-status-warning/35 bg-status-warning-bg p-3 text-status-warning">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <div className="text-[13px] text-foreground">
                        <span className="font-semibold">
                            {titleCase(d.status)}:
                        </span>{' '}
                        {d.status_reason}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function SafetySection({ d }: { d: SubstanceDetail }) {
    return (
        <div className="flex flex-col gap-5">
            <TextBlock label="PPE required" value={d.ppe_required} />
            <TextBlock
                label="Storage requirements"
                value={d.storage_requirements}
            />
            <TextBlock
                label="Handling precautions"
                value={d.handling_precautions}
            />
            <TextBlock
                label="First-aid measures"
                value={d.first_aid_measures}
            />
            <TextBlock
                label="Firefighting measures"
                value={d.firefighting_measures}
            />
            <TextBlock label="Spill procedures" value={d.spill_procedures} />
            {d.exposure_limit_type || d.exposure_limit_value ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    <MetaRow
                        label="WES limit type"
                        value={d.exposure_limit_type}
                    />
                    <MetaRow
                        label="WES limit value"
                        value={d.exposure_limit_value}
                    />
                </div>
            ) : null}
        </div>
    );
}

function SectionHead({
    title,
    count,
    canAdd,
    onAdd,
    addLabel,
}: {
    title: string;
    count: number;
    canAdd: boolean;
    onAdd: () => void;
    addLabel: string;
}) {
    return (
        <div className="mb-3 flex items-center justify-between">
            <h3 className="text-sm font-bold">
                {title} <span className="text-muted-foreground">· {count}</span>
            </h3>
            {canAdd ? (
                <Button size="sm" variant="outline" onClick={onAdd}>
                    <Plus className="mr-1.5 h-3.5 w-3.5" /> {addLabel}
                </Button>
            ) : null}
        </div>
    );
}

function SdsSection({
    d,
    canAdd,
    onAdd,
}: {
    d: SubstanceDetail;
    canAdd: boolean;
    onAdd: () => void;
}) {
    return (
        <div>
            <SectionHead
                title="Safety Data Sheets"
                count={d.counts.sds}
                canAdd={canAdd}
                onAdd={onAdd}
                addLabel="Add SDS"
            />
            {d.sds_records.length === 0 ? (
                <EmptyState
                    icon={FileText}
                    title="No SDS on file"
                    hint="Upload the supplier's Safety Data Sheet to keep this substance compliant."
                />
            ) : (
                <div className="flex flex-col gap-2">
                    {d.sds_records.map((sds) => (
                        <div
                            key={sds.id}
                            className="rounded-xl border border-border bg-card/70 p-3"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-semibold">
                                            Version {sds.version}
                                        </span>
                                        <SdsBadge state={sds.state} />
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        Issued {formatDate(sds.issue_date)}
                                        {sds.review_date
                                            ? ` · Review ${formatDate(sds.review_date)}`
                                            : ''}
                                        {sds.supplier_name
                                            ? ` · ${sds.supplier_name}`
                                            : ''}
                                    </div>
                                </div>
                                {sds.download_url ? (
                                    <a
                                        href={sds.download_url}
                                        className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-muted"
                                    >
                                        <Download className="h-3.5 w-3.5" />{' '}
                                        Download
                                    </a>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function QuantityBar({
    current,
    max,
}: {
    current: number | null;
    max: number | null;
}) {
    if (current == null || max == null || max <= 0) return null;
    const pct = Math.min(100, Math.round((current / max) * 100));
    const tone: Tone =
        pct >= 90 ? 'critical' : pct >= 70 ? 'warning' : 'success';
    return (
        <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-muted">
            <div
                className={`h-full rounded-full ${DOT[tone]}`}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

function CompChip({ ok, label }: { ok: boolean; label: string }) {
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${ok ? 'bg-status-success-bg text-status-success' : 'bg-status-warning-bg text-status-warning'}`}
        >
            {ok ? (
                <CheckCircle2 className="h-3 w-3" />
            ) : (
                <AlertTriangle className="h-3 w-3" />
            )}{' '}
            {label}
        </span>
    );
}

function StorageSection({
    d,
    canAdd,
    onAdd,
}: {
    d: SubstanceDetail;
    canAdd: boolean;
    onAdd: () => void;
}) {
    return (
        <div>
            <SectionHead
                title="Storage locations"
                count={d.counts.storage}
                canAdd={canAdd}
                onAdd={onAdd}
                addLabel="Add storage"
            />
            {d.storage_locations.length === 0 ? (
                <EmptyState
                    icon={MapPin}
                    title="No storage recorded"
                    hint="Record where this substance is held and how much, per site."
                />
            ) : (
                <div className="flex flex-col gap-2">
                    {d.storage_locations.map((loc) => (
                        <div
                            key={loc.id}
                            className="rounded-xl border border-border bg-card/70 p-3"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="text-sm font-semibold">
                                        {loc.site?.name ?? 'Unassigned site'}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {loc.location_description}
                                    </div>
                                </div>
                                <div className="shrink-0 text-right text-xs">
                                    {loc.current_quantity != null ? (
                                        <span className="font-semibold">
                                            {loc.current_quantity}
                                            {loc.maximum_quantity != null
                                                ? ` / ${loc.maximum_quantity}`
                                                : ''}{' '}
                                            {loc.quantity_unit ?? ''}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            Qty —
                                        </span>
                                    )}
                                </div>
                            </div>
                            <QuantityBar
                                current={loc.current_quantity}
                                max={loc.maximum_quantity}
                            />
                            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                {loc.container_type ? (
                                    <span className="text-[11px] text-muted-foreground">
                                        {loc.container_type}
                                    </span>
                                ) : null}
                                <CompChip
                                    ok={loc.properly_labelled}
                                    label="Labelled"
                                />
                                <CompChip
                                    ok={loc.segregation_compliant}
                                    label="Segregated"
                                />
                                {loc.last_audit_date ? (
                                    <span className="text-[11px] text-muted-foreground">
                                        Audited{' '}
                                        {formatDate(loc.last_audit_date)}
                                    </span>
                                ) : null}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function ExposuresSection({
    d,
    canAdd,
    onAdd,
}: {
    d: SubstanceDetail;
    canAdd: boolean;
    onAdd: () => void;
}) {
    return (
        <div>
            <SectionHead
                title="Exposure records"
                count={d.counts.exposures}
                canAdd={canAdd}
                onAdd={onAdd}
                addLabel="Record exposure"
            />
            {d.exposure_records.length === 0 ? (
                <EmptyState
                    icon={HeartPulse}
                    title="No exposures recorded"
                    hint="Logging an exposure raises a Health & Safety event for triage."
                />
            ) : (
                <div className="flex flex-col gap-2">
                    {d.exposure_records.map((rec) => (
                        <div
                            key={rec.id}
                            className="rounded-xl border border-border bg-card/70 p-3"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <div className="flex items-center gap-2 text-sm font-semibold">
                                        <UserIcon className="h-3.5 w-3.5 text-muted-foreground" />{' '}
                                        {rec.user?.name ?? 'Worker'}
                                        <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                            {titleCase(rec.exposure_type)}
                                        </span>
                                    </div>
                                    <div className="mt-1 text-xs text-muted-foreground">
                                        {formatDateTime(rec.exposed_at)}
                                        {rec.exposure_duration
                                            ? ` · ${rec.exposure_duration}`
                                            : ''}
                                    </div>
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-1">
                                    {rec.medical_treatment &&
                                    rec.medical_treatment !== 'none' &&
                                    rec.medical_treatment !== 'first_aid' ? (
                                        <span
                                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${rec.medical_treatment === 'death' || rec.medical_treatment === 'hospitalisation' ? 'bg-status-critical-bg text-status-critical' : 'bg-status-warning-bg text-status-warning'}`}
                                        >
                                            <HeartPulse className="h-3 w-3" />{' '}
                                            {titleCase(rec.medical_treatment)}
                                        </span>
                                    ) : rec.medical_attention_sought ? (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-status-warning-bg px-2 py-0.5 text-[11px] font-medium text-status-warning">
                                            <HeartPulse className="h-3 w-3" />{' '}
                                            Medical
                                        </span>
                                    ) : null}
                                    {rec.incident_reported ? (
                                        <span className="text-[11px] text-muted-foreground">
                                            Reported
                                        </span>
                                    ) : null}
                                </div>
                            </div>
                            {rec.symptoms ? (
                                <p className="mt-2 text-xs text-foreground">
                                    Symptoms: {rec.symptoms}
                                </p>
                            ) : null}
                            {rec.medical_outcome ? (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Outcome: {rec.medical_outcome}
                                </p>
                            ) : null}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function HistorySection({ d }: { d: SubstanceDetail }) {
    return (
        <div className="flex flex-col gap-3">
            <MetaRow label="Registered by" value={d.created_by} />
            <MetaRow
                label="Registered on"
                value={formatDateTime(d.created_at)}
            />
            <MetaRow
                label="Last updated"
                value={formatDateTime(d.updated_at)}
            />
            <MetaRow
                label="Status"
                value={STATUS_META[d.status]?.label ?? titleCase(d.status)}
            />
            {d.status_reason ? (
                <MetaRow label="Status reason" value={d.status_reason} />
            ) : null}
        </div>
    );
}
