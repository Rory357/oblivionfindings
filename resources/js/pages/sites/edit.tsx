import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import WizardStepper from '@/components/wizard-stepper';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Loader2,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    SITE_TYPES,
    STEPS,
    StepAddress,
    StepAssets,
    StepBasics,
    StepChecklists,
    StepContacts,
    StepDocuments,
    StepRoomsOrResources,
    StepSafety,
    emptyContact,
    type AvailableAsset,
    type ChecklistTemplate,
    type Contact,
    type DocumentRecord,
    type Resource,
    type Room,
    type SiteType,
    type WizardData,
    type WizardUser,
    type Zone,
} from './_wizard';

type Site = {
    id: number;
    name: string;
    type: SiteType;
    phone?: string;
    email?: string;
    manager_name?: string;
    manager_phone?: string;
    after_hours_phone?: string;
    emergency_plan_location?: string;
    medication_storage_location?: string;
    notes?: string;
    address_line_1?: string;
    address_line_2?: string;
    suburb?: string;
    city?: string;
    postcode?: string;
    country?: string;
    region?: string;
    latitude?: string;
    longitude?: string;
    access_instructions?: string;
    is_active: boolean;
    is_high_risk: boolean;
    is_high_needs: boolean;
    risk_notes?: string;
    risk_review_date?: string;
    primary_contact_user_id?: number;
    contacts?: Array<{
        id: number;
        type?: string | null;
        name: string;
        role?: string | null;
        phone?: string | null;
        email?: string | null;
        is_primary: boolean;
        notes?: string | null;
    }>;
    rooms?: Array<{ id: number; name: string; notes?: string | null }>;
    resources?: Array<{
        id: number;
        name: string;
        resource_type?: string | null;
        capacity?: number | null;
    }>;
    zones?: Array<{ id: number; name: string; zone_type?: string | null }>;
    checklist_assignments?: Array<{
        id: number;
        template_id: number;
        frequency: string;
        assigned_to_user_id?: number | null;
    }>;
    documents?: DocumentRecord[];
    assigned_asset_ids?: number[];
};

type PageProps = {
    site: Site;
    users: WizardUser[];
    checklistTemplates: ChecklistTemplate[];
    availableAssets: AvailableAsset[];
    regionOptions: string[];
    labels?: Record<string, string>;
};

const WORKFLOW_HELP = [
    'Update saved instantly',
    'Documents managed live',
    'Audit trail captured',
    'Notify managers if needed',
];

export default function EditSite() {
    const { site, users, checklistTemplates, availableAssets, regionOptions, labels } =
        usePage<PageProps>().props;
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';

    const initialContacts: Contact[] = useMemo(
        () =>
            (site.contacts ?? []).map((c) => ({
                id: c.id,
                type: c.type ?? 'general',
                name: c.name ?? '',
                role: c.role ?? '',
                phone: c.phone ?? '',
                email: c.email ?? '',
                is_primary: !!c.is_primary,
                notes: c.notes ?? '',
            })),
        [site.contacts],
    );

    const initialRooms: Room[] = useMemo(
        () =>
            (site.rooms ?? []).map((r) => ({
                id: r.id,
                name: r.name ?? '',
                notes: r.notes ?? '',
            })),
        [site.rooms],
    );

    const initialResources: Resource[] = useMemo(
        () =>
            (site.resources ?? []).map((r) => ({
                id: r.id,
                name: r.name ?? '',
                resource_type: r.resource_type ?? 'meeting_room',
                capacity: r.capacity != null ? String(r.capacity) : '',
            })),
        [site.resources],
    );

    const initialZones: Zone[] = useMemo(
        () =>
            (site.zones ?? []).map((z) => ({
                id: z.id,
                name: z.name ?? '',
                zone_type: z.zone_type ?? 'workshop',
            })),
        [site.zones],
    );

    const initialChecklists = useMemo(
        () =>
            (site.checklist_assignments ?? []).map((a) => ({
                template_id: a.template_id,
                enabled: true,
                frequency: a.frequency ?? 'monthly',
                assigned_to_user_id: a.assigned_to_user_id?.toString() ?? '',
            })),
        [site.checklist_assignments],
    );

    const { data, setData, put, processing, errors, isDirty } = useForm<WizardData>({
        name: site.name,
        type: site.type,
        phone: site.phone ?? '',
        email: site.email ?? '',
        manager_name: site.manager_name ?? '',
        manager_phone: site.manager_phone ?? '',
        after_hours_phone: site.after_hours_phone ?? '',
        emergency_plan_location: site.emergency_plan_location ?? '',
        medication_storage_location: site.medication_storage_location ?? '',
        notes: site.notes ?? '',
        address_line_1: site.address_line_1 ?? '',
        address_line_2: site.address_line_2 ?? '',
        suburb: site.suburb ?? '',
        city: site.city ?? '',
        postcode: site.postcode ?? '',
        country: site.country ?? 'New Zealand',
        region: site.region ?? '',
        latitude: site.latitude ?? '',
        longitude: site.longitude ?? '',
        access_instructions: site.access_instructions ?? '',
        is_active: site.is_active,
        is_high_risk: site.is_high_risk,
        is_high_needs: site.is_high_needs,
        risk_notes: site.risk_notes ?? '',
        risk_review_date: site.risk_review_date ?? '',
        primary_contact_user_id:
            site.primary_contact_user_id?.toString() ?? '',
        contacts: initialContacts,
        rooms: initialRooms,
        resources: initialResources,
        zones: initialZones,
        assets: site.assigned_asset_ids ?? [],
        checklists: initialChecklists,
    });

    const [step, setStep] = useState(0);
    const [stepErrors, setStepErrors] = useState<Record<string, string>>({});
    const [existingDocs, setExistingDocs] = useState<DocumentRecord[]>(
        site.documents ?? [],
    );
    const [confirmCancelOpen, setConfirmCancelOpen] = useState(false);
    const [deleteCandidate, setDeleteCandidate] = useState<number | null>(null);
    const nameRef = useRef<HTMLInputElement | null>(null);

    const selectedType = useMemo(
        () => SITE_TYPES.find((t) => t.value === data.type) ?? SITE_TYPES[1],
        [data.type],
    );

    const summaryAddress = useMemo(() => {
        const parts = [
            data.address_line_1,
            data.suburb,
            data.city,
            data.postcode,
        ].filter(Boolean);
        return parts.length > 0 ? parts.join(', ') : null;
    }, [data.address_line_1, data.suburb, data.city, data.postcode]);

    const goNext = () => {
        if (step === 0) {
            const e: Record<string, string> = {};
            if (!data.name.trim()) e.name = 'Please give the site a name.';
            setStepErrors(e);
            if (Object.keys(e).length > 0) {
                requestAnimationFrame(() => {
                    nameRef.current?.focus();
                    nameRef.current?.scrollIntoView({
                        block: 'center',
                        behavior: 'smooth',
                    });
                });
                return;
            }
        }
        setStepErrors({});
        setStep((s) => Math.min(STEPS.length - 1, s + 1));
    };

    const goBack = () => {
        setStepErrors({});
        setStep((s) => Math.max(0, s - 1));
    };

    const submit = () => {
        put(`/sites/${site.id}`, {
            data: {
                ...data,
                checklists: data.checklists.filter((c) => c.enabled),
            } as any,
        } as any);
    };

    const csrfToken = () =>
        (
            document.querySelector(
                'meta[name="csrf-token"]',
            ) as HTMLMetaElement | null
        )?.content ?? '';

    const xhrConfig = () => ({
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        withCredentials: true,
    });

    const uploadDocument = async (draft: {
        file: File;
        title: string;
        category: string;
        expiry_date: string;
        notes: string;
    }) => {
        const fd = new FormData();
        fd.append('file', draft.file);
        if (draft.title) fd.append('title', draft.title);
        if (draft.category) fd.append('category', draft.category);
        if (draft.expiry_date) fd.append('expiry_date', draft.expiry_date);
        if (draft.notes) fd.append('notes', draft.notes);

        try {
            const res = await axios.post(
                `/sites/${site.id}/documents`,
                fd,
                xhrConfig(),
            );
            if (res.data?.document) {
                setExistingDocs((prev) => [res.data.document, ...prev]);
            } else {
                router.reload({ only: ['site'] });
            }
        } catch {
            toast.error('Failed to upload document. Please try again.');
        }
    };

    const confirmDeleteDocument = async () => {
        if (!deleteCandidate) return;
        try {
            await axios.delete(
                `/sites/${site.id}/documents/${deleteCandidate}`,
                xhrConfig(),
            );
            setExistingDocs((prev) => prev.filter((d) => d.id !== deleteCandidate));
            setDeleteCandidate(null);
            toast.success('Document deleted.');
        } catch {
            toast.error('Failed to delete document.');
        }
    };

    const addContact = () =>
        setData('contacts', [...data.contacts, emptyContact()]);
    const updateContact = (index: number, patch: Partial<Contact>) =>
        setData(
            'contacts',
            data.contacts.map((c, i) =>
                i === index ? { ...c, ...patch } : c,
            ),
        );
    const removeContact = (index: number) =>
        setData(
            'contacts',
            data.contacts.filter((_, i) => i !== index),
        );
    const setPrimaryContact = (index: number) =>
        setData(
            'contacts',
            data.contacts.map((c, i) => ({
                ...c,
                is_primary: i === index,
            })),
        );

    const setDataAdapter = (key: keyof WizardData, value: any) =>
        setData(key, value);

    const allErrors: Record<string, string | undefined> = {
        ...(errors as any),
        ...stepErrors,
    };

    const cancel = () => {
        if (isDirty) {
            setConfirmCancelOpen(true);
            return;
        }

        router.visit(`/sites/${site.id}`);
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: sitePlural, href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Edit', href: `/sites/${site.id}/edit` },
            ]}
        >
            <Head title={`Edit ${siteSingular}`} />

            <div className="mx-auto w-full max-w-2xl space-y-6 px-4 pt-4 pb-8 lg:max-w-5xl">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            Edit {site.name}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Eight steps. Documents save instantly; everything
                            else saves on submit.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={cancel}
                        aria-label="Cancel and return to site"
                        className="shrink-0"
                    >
                        Cancel
                    </Button>
                </div>

                <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-8">
                    <div className="min-w-0 space-y-6 lg:space-y-8">
                        <WizardStepper steps={STEPS} current={step} />
                        <p className="sr-only" aria-live="polite">
                            Step {step + 1} of {STEPS.length}: {STEPS[step].label}
                        </p>
                        <p className="sr-only" role="alert" aria-live="assertive">
                            {Object.values(allErrors).filter(Boolean).join('. ')}
                        </p>

                        <Card className="p-4 sm:p-6 lg:p-8">
                            {step === 0 && (
                                <StepBasics
                                    data={data}
                                    setData={setDataAdapter}
                                    errors={allErrors}
                                    fieldRefs={{ name: nameRef }}
                                    users={users}
                                />
                            )}
                            {step === 1 && (
                                <StepAddress
                                    data={data}
                                    setData={setDataAdapter}
                                    errors={allErrors}
                                    regionOptions={regionOptions}
                                />
                            )}
                            {step === 2 && (
                                <StepRoomsOrResources
                                    data={data}
                                    setData={setDataAdapter}
                                    errors={allErrors}
                                />
                            )}
                            {step === 3 && (
                                <StepContacts
                                    data={data}
                                    setData={setDataAdapter}
                                    errors={allErrors}
                                    onAdd={addContact}
                                    onUpdate={updateContact}
                                    onRemove={removeContact}
                                    onSetPrimary={setPrimaryContact}
                                />
                            )}
                            {step === 4 && (
                                <StepAssets
                                    data={data}
                                    setData={setDataAdapter}
                                    errors={allErrors}
                                    availableAssets={availableAssets}
                                />
                            )}
                            {step === 5 && (
                                <StepDocuments
                                    pending={[]}
                                    existing={existingDocs}
                                    onAddPending={uploadDocument}
                                    onRemovePending={() => undefined}
                                    onDeleteExisting={setDeleteCandidate}
                                />
                            )}
                            {step === 6 && (
                                <StepChecklists
                                    data={data}
                                    setData={setDataAdapter}
                                    errors={allErrors}
                                    templates={checklistTemplates}
                                    users={users}
                                />
                            )}
                            {step === 7 && (
                                <StepSafety
                                    data={data}
                                    setData={setDataAdapter}
                                    errors={allErrors}
                                />
                            )}
                        </Card>

                        <div className="flex items-center gap-2">
                            {step > 0 && (
                                <Button
                                    variant="outline"
                                    size="lg"
                                    onClick={goBack}
                                    disabled={processing}
                                    className="flex-1 lg:flex-none"
                                >
                                    <ArrowLeft className="mr-1.5 h-4 w-4" />
                                    Back
                                </Button>
                            )}
                            {step < STEPS.length - 1 ? (
                                <Button
                                    size="lg"
                                    onClick={goNext}
                                    className="flex-1 lg:min-w-[180px] lg:flex-none"
                                >
                                    Next: {STEPS[step + 1].label}
                                    <ArrowRight className="ml-1.5 h-4 w-4" />
                                </Button>
                            ) : (
                                <Button
                                    size="lg"
                                    onClick={submit}
                                    disabled={processing}
                                    className="flex-1 lg:min-w-[180px] lg:flex-none"
                                >
                                    {processing ? (
                                        <>
                                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                                            Saving…
                                        </>
                                    ) : (
                                        <>
                                            <Check className="mr-1.5 h-4 w-4" />
                                            Save changes
                                        </>
                                    )}
                                </Button>
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Documents save instantly. Everything else is saved when you finish the wizard.
                        </p>
                    </div>

                    <aside aria-label="Site summary" className="hidden lg:block">
                        <Card className="sticky top-4 space-y-6 p-5">
                            <div className="space-y-1">
                                <p className="text-xs font-medium text-muted-foreground">
                                    Step {step + 1} of {STEPS.length}
                                </p>
                                <h2 className="text-base font-semibold">
                                    {siteSingular} summary
                                </h2>
                            </div>

                            <dl className="space-y-4 text-sm">
                                <SummaryRow label="Type">
                                    <span className="flex items-center gap-2 font-medium">
                                        <selectedType.icon className="h-4 w-4 text-primary" />
                                        {selectedType.label}
                                    </span>
                                </SummaryRow>
                                <SummaryRow label="Name">
                                    {data.name || <Empty>Not set</Empty>}
                                </SummaryRow>
                                <SummaryRow label="Address">
                                    {summaryAddress ?? (
                                        <Empty>Not set</Empty>
                                    )}
                                </SummaryRow>
                                <SummaryRow
                                    label={typeAreaLabel(data.type) ?? 'Areas'}
                                >
                                    {typeAreaCount(data) > 0 ? (
                                        `${typeAreaCount(data)} ${typeAreaNoun(data.type, typeAreaCount(data))}`
                                    ) : (
                                        <Empty>None</Empty>
                                    )}
                                </SummaryRow>
                                <SummaryRow label="Contacts">
                                    {data.contacts.length > 0 ? (
                                        `${data.contacts.length} contact${data.contacts.length === 1 ? '' : 's'}`
                                    ) : (
                                        <Empty>None</Empty>
                                    )}
                                </SummaryRow>
                                <SummaryRow label="Documents">
                                    {existingDocs.length > 0 ? (
                                        `${existingDocs.length} on file`
                                    ) : (
                                        <Empty>None</Empty>
                                    )}
                                </SummaryRow>
                                <SummaryRow label="Checklists">
                                    {data.checklists.filter((c) => c.enabled)
                                        .length > 0 ? (
                                        `${data.checklists.filter((c) => c.enabled).length} scheduled`
                                    ) : (
                                        <Empty>None</Empty>
                                    )}
                                </SummaryRow>
                                <SummaryRow label="Risk level">
                                    {data.is_high_risk ||
                                    data.is_high_needs ? (
                                        <RiskPill warning>
                                            {data.is_high_risk &&
                                            data.is_high_needs
                                                ? 'High risk + needs'
                                                : data.is_high_risk
                                                  ? 'High risk'
                                                  : 'High needs'}
                                        </RiskPill>
                                    ) : (
                                        <RiskPill>Standard</RiskPill>
                                    )}
                                </SummaryRow>
                            </dl>

                            <div className="space-y-3">
                                <h3 className="text-sm font-semibold">
                                    What happens next
                                </h3>
                                <ol className="space-y-2">
                                    {WORKFLOW_HELP.map((item, index) => (
                                        <li
                                            key={item}
                                            className="flex items-center gap-2 text-sm text-muted-foreground"
                                        >
                                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border bg-background text-xs font-semibold text-foreground">
                                                {index + 1}
                                            </span>
                                            <span>{item}</span>
                                        </li>
                                    ))}
                                </ol>
                            </div>
                        </Card>
                    </aside>
                </div>
            </div>

            <Dialog open={confirmCancelOpen} onOpenChange={setConfirmCancelOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Discard unsaved changes?</DialogTitle>
                        <DialogDescription>
                            Any unsaved site details on this form will be lost.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setConfirmCancelOpen(false)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => router.visit(`/sites/${site.id}`)}
                        >
                            Discard
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                open={deleteCandidate !== null}
                onOpenChange={(open) => !open && setDeleteCandidate(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this document?</DialogTitle>
                        <DialogDescription>
                            This removes the document from the site file list.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDeleteCandidate(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={confirmDeleteDocument}
                        >
                            Delete
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function typeAreaLabel(type: SiteType): string | null {
    if (type === 'house') return 'Rooms';
    if (type === 'head_office') return 'Resources';
    if (type === 'facility') return 'Zones';
    return null;
}

function typeAreaCount(data: WizardData): number {
    if (data.type === 'house') return data.rooms.length;
    if (data.type === 'head_office') return data.resources.length;
    if (data.type === 'facility') return data.zones.length;
    return 0;
}

function typeAreaNoun(type: SiteType, count: number): string {
    const singular =
        type === 'house'
            ? 'room'
            : type === 'head_office'
              ? 'resource'
              : 'zone';
    return count === 1 ? singular : `${singular}s`;
}

function SummaryRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-xs font-medium text-muted-foreground">
                {label}
            </dt>
            <dd className="mt-1 font-medium">{children}</dd>
        </div>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return (
        <span className="font-normal text-muted-foreground italic">
            {children}
        </span>
    );
}

function RiskPill({
    warning = false,
    children,
}: {
    warning?: boolean;
    children: React.ReactNode;
}) {
    return (
        <span
            className={`inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-semibold text-foreground ${
                warning
                    ? 'border-status-warning/40 bg-status-warning-bg'
                    : 'border-status-success/40 bg-status-success-bg'
            }`}
        >
            <span
                aria-hidden
                className={`h-2 w-2 rounded-full ${
                    warning ? 'bg-status-warning' : 'bg-status-success'
                }`}
            />
            {children}
        </span>
    );
}
