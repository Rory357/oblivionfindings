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
import { PageHero } from '@/components/page';
import WizardStepper from '@/components/wizard-stepper';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowRight,
    Check,
    Loader2,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
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
    type DocumentDraft,
    type SiteType,
    type WizardData,
    type WizardUser,
} from './_wizard';

type PageProps = {
    users: WizardUser[];
    checklistTemplates: ChecklistTemplate[];
    availableAssets: AvailableAsset[];
    regionOptions: string[];
    labels?: Record<string, string>;
};

const WORKFLOW_HELP = [
    'Site is created',
    'Documents uploaded',
    'Checklists scheduled',
    'Ready for clients & shifts',
];

const initialData: WizardData = {
    name: '',
    type: 'house',
    phone: '',
    email: '',
    emergency_plan_location: '',
    medication_storage_location: '',
    notes: '',
    address_line_1: '',
    address_line_2: '',
    suburb: '',
    city: '',
    postcode: '',
    country: 'New Zealand',
    region: '',
    latitude: '',
    longitude: '',
    access_instructions: '',
    is_active: true,
    is_high_risk: false,
    is_high_needs: false,
    risk_notes: '',
    risk_review_date: '',
    primary_contact_user_id: '',
    contacts: [],
    rooms: [],
    resources: [],
    zones: [],
    assets: [],
    checklists: [],
};

export default function CreateSite() {
    const { users, checklistTemplates, availableAssets, regionOptions, labels } =
        usePage<PageProps>().props;
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';

    const [data, setDataState] = useState<WizardData>(initialData);
    const setData = (key: keyof WizardData, value: any) =>
        setDataState((prev) => ({ ...prev, [key]: value }));

    const [pendingDocs, setPendingDocs] = useState<DocumentDraft[]>([]);
    const [step, setStep] = useState(0);
    const [errors, setErrors] = useState<Record<string, string | undefined>>(
        {},
    );
    const [processing, setProcessing] = useState(false);
    const [confirmCancelOpen, setConfirmCancelOpen] = useState(false);
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
            setErrors(e);
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
        setErrors({});
        setStep((s) => Math.min(STEPS.length - 1, s + 1));
    };

    const goBack = () => {
        setErrors({});
        setStep((s) => Math.max(0, s - 1));
    };

    const submit = () => {
        setProcessing(true);
        setErrors({});

        const fd = buildFormData(data, pendingDocs);

        router.post('/sites', fd, {
            forceFormData: true,
            preserveScroll: true,
            onError: (errs) => {
                const flat: Record<string, string> = {};
                for (const [key, val] of Object.entries(errs)) {
                    flat[key] = Array.isArray(val) ? val[0] : String(val);
                }
                setErrors(flat);
                jumpToFirstError(flat, setStep);
            },
            onFinish: () => setProcessing(false),
        });
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

    const hasUnsavedWork =
        JSON.stringify(data) !== JSON.stringify(initialData) ||
        pendingDocs.length > 0;

    const cancel = () => {
        if (hasUnsavedWork) {
            setConfirmCancelOpen(true);
            return;
        }

        router.visit('/sites');
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: sitePlural, href: '/sites' },
                { title: `Add ${siteSingular}`, href: '/sites/create' },
            ]}
        >
            <Head title={`Add ${siteSingular}`} />

            <div className="mx-auto w-full max-w-2xl space-y-6 px-4 pt-4 pb-8 lg:max-w-5xl">
                <PageHero
                    variant="compact"
                    backHref="/sites"
                    backLabel={`Back to ${sitePlural}`}
                    title={`Add a ${siteSingular.toLowerCase()}`}
                    description="Eight quick steps. You can update anything later."
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            onClick={cancel}
                            aria-label="Cancel and return to sites list"
                            className="shrink-0"
                        >
                            Cancel
                        </Button>
                    }
                />

                <div className="lg:grid lg:grid-cols-[minmax(0,1fr)_320px] lg:gap-8">
                    <div className="min-w-0 space-y-6 lg:space-y-8">
                        <WizardStepper steps={STEPS} current={step} />
                        <p className="sr-only" aria-live="polite">
                            Step {step + 1} of {STEPS.length}: {STEPS[step].label}
                        </p>
                        <p className="sr-only" role="alert" aria-live="assertive">
                            {Object.values(errors).filter(Boolean).join('. ')}
                        </p>

                        <Card className="p-4 sm:p-6 lg:p-8">
                            {step === 0 && (
                                <StepBasics
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    fieldRefs={{ name: nameRef }}
                                    users={users}
                                />
                            )}
                            {step === 1 && (
                                <StepAddress
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    regionOptions={regionOptions}
                                />
                            )}
                            {step === 2 && (
                                <StepRoomsOrResources
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                />
                            )}
                            {step === 3 && (
                                <StepContacts
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    onAdd={addContact}
                                    onUpdate={updateContact}
                                    onRemove={removeContact}
                                    onSetPrimary={setPrimaryContact}
                                />
                            )}
                            {step === 4 && (
                                <StepAssets
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    availableAssets={availableAssets}
                                />
                            )}
                            {step === 5 && (
                                <StepDocuments
                                    pending={pendingDocs}
                                    onAddPending={(d) =>
                                        setPendingDocs([...pendingDocs, d])
                                    }
                                    onRemovePending={(i) =>
                                        setPendingDocs(
                                            pendingDocs.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                />
                            )}
                            {step === 6 && (
                                <StepChecklists
                                    data={data}
                                    setData={setData}
                                    errors={errors}
                                    templates={checklistTemplates}
                                    users={users}
                                />
                            )}
                            {step === 7 && (
                                <StepSafety
                                    data={data}
                                    setData={setData}
                                    errors={errors}
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
                                            Creating…
                                        </>
                                    ) : (
                                        <>
                                            <Check className="mr-1.5 h-4 w-4" />
                                            Create {siteSingular.toLowerCase()}
                                        </>
                                    )}
                                </Button>
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground">
                            Changes are saved when you finish the wizard. Documents you add are uploaded straight away.
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
                                    {data.name || (
                                        <Empty>Not set</Empty>
                                    )}
                                </SummaryRow>
                                <SummaryRow label="Address">
                                    {summaryAddress ?? <Empty>Not set</Empty>}
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
                                <SummaryRow label="Assets">
                                    {data.assets.length > 0 ? (
                                        `${data.assets.length} selected`
                                    ) : (
                                        <Empty>None</Empty>
                                    )}
                                </SummaryRow>
                                <SummaryRow label="Documents">
                                    {pendingDocs.length > 0 ? (
                                        `${pendingDocs.length} pending upload`
                                    ) : (
                                        <Empty>None staged</Empty>
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
                        <DialogTitle>Discard this draft?</DialogTitle>
                        <DialogDescription>
                            Any details entered for this site will be lost.
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
                            onClick={() => router.visit('/sites')}
                        >
                            Discard
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

// ── Helpers ──────────────────────────────────────────────────────────────

function buildFormData(
    data: WizardData,
    pendingDocs: DocumentDraft[],
): FormData {
    const fd = new FormData();

    const scalar = (key: string, value: string | number | boolean | null) => {
        if (value === null || value === undefined || value === '') return;
        fd.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value));
    };

    scalar('name', data.name);
    scalar('type', data.type);
    scalar('phone', data.phone);
    scalar('email', data.email);
    scalar('emergency_plan_location', data.emergency_plan_location);
    scalar('medication_storage_location', data.medication_storage_location);
    scalar('notes', data.notes);
    scalar('address_line_1', data.address_line_1);
    scalar('address_line_2', data.address_line_2);
    scalar('suburb', data.suburb);
    scalar('city', data.city);
    scalar('postcode', data.postcode);
    scalar('country', data.country);
    scalar('region', data.region);
    if (data.latitude) scalar('latitude', Number(data.latitude));
    if (data.longitude) scalar('longitude', Number(data.longitude));
    scalar('access_instructions', data.access_instructions);
    fd.append('is_active', data.is_active ? '1' : '0');
    fd.append('is_high_risk', data.is_high_risk ? '1' : '0');
    fd.append('is_high_needs', data.is_high_needs ? '1' : '0');
    scalar('risk_notes', data.risk_notes);
    scalar('risk_review_date', data.risk_review_date);
    scalar('primary_contact_user_id', data.primary_contact_user_id);

    data.contacts.forEach((c, i) => {
        if (c.id) fd.append(`contacts[${i}][id]`, String(c.id));
        fd.append(`contacts[${i}][type]`, c.type || 'general');
        fd.append(`contacts[${i}][name]`, c.name);
        if (c.role) fd.append(`contacts[${i}][role]`, c.role);
        if (c.phone) fd.append(`contacts[${i}][phone]`, c.phone);
        if (c.email) fd.append(`contacts[${i}][email]`, c.email);
        fd.append(`contacts[${i}][is_primary]`, c.is_primary ? '1' : '0');
        if (c.notes) fd.append(`contacts[${i}][notes]`, c.notes);
    });

    data.rooms.forEach((r, i) => {
        if (r.id) fd.append(`rooms[${i}][id]`, String(r.id));
        fd.append(`rooms[${i}][name]`, r.name);
        if (r.notes) fd.append(`rooms[${i}][notes]`, r.notes);
    });

    data.resources.forEach((r, i) => {
        if (r.id) fd.append(`resources[${i}][id]`, String(r.id));
        fd.append(`resources[${i}][name]`, r.name);
        if (r.resource_type)
            fd.append(`resources[${i}][resource_type]`, r.resource_type);
        if (r.capacity) fd.append(`resources[${i}][capacity]`, r.capacity);
    });

    data.zones.forEach((z, i) => {
        if (z.id) fd.append(`zones[${i}][id]`, String(z.id));
        fd.append(`zones[${i}][name]`, z.name);
        if (z.zone_type) fd.append(`zones[${i}][zone_type]`, z.zone_type);
    });

    data.assets.forEach((id, i) => {
        fd.append(`assets[${i}]`, String(id));
    });

    data.checklists
        .filter((c) => c.enabled)
        .forEach((c, i) => {
            fd.append(`checklists[${i}][template_id]`, String(c.template_id));
            fd.append(`checklists[${i}][enabled]`, '1');
            fd.append(`checklists[${i}][frequency]`, c.frequency);
            if (c.assigned_to_user_id)
                fd.append(
                    `checklists[${i}][assigned_to_user_id]`,
                    c.assigned_to_user_id,
                );
        });

    pendingDocs.forEach((d, i) => {
        fd.append(`documents[${i}][file]`, d.file);
        if (d.title) fd.append(`documents[${i}][title]`, d.title);
        if (d.category) fd.append(`documents[${i}][category]`, d.category);
        if (d.expiry_date)
            fd.append(`documents[${i}][expiry_date]`, d.expiry_date);
        if (d.notes) fd.append(`documents[${i}][notes]`, d.notes);
    });

    return fd;
}

function jumpToFirstError(
    errs: Record<string, string>,
    setStep: (n: number) => void,
) {
    const keys = Object.keys(errs);
    if (keys.length === 0) return;
    const k = keys[0];
    const map: Record<string, number> = {
        name: 0,
        type: 0,
        primary_contact_user_id: 0,
        is_active: 0,
        address_line_1: 1,
        address_line_2: 1,
        suburb: 1,
        city: 1,
        postcode: 1,
        country: 1,
        region: 1,
        latitude: 1,
        longitude: 1,
        access_instructions: 1,
        rooms: 2,
        resources: 2,
        zones: 2,
        phone: 3,
        email: 3,
        contacts: 3,
        assets: 4,
        checklists: 6,
        emergency_plan_location: 7,
        medication_storage_location: 7,
        is_high_risk: 7,
        is_high_needs: 7,
        risk_notes: 7,
        risk_review_date: 7,
        notes: 7,
    };
    const root = k.split('.')[0];
    if (map[root] !== undefined) setStep(map[root]);
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
