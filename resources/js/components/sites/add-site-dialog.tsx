/* eslint-disable no-restricted-syntax -- The Add Site modal mirrors the bespoke
 * Add Client wizard chrome (stepper rail + scroll-contained body + custom footer)
 * via the shared WizardShell, and uses the shared wizard primitives for tile
 * pickers, chips and segmented controls. Every colour is a semantic design token
 * (never hardcoded hex), per docs/DESIGN_TOKENS.md. */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    InfoCard,
    SelectInput,
    StepHead,
    SubHead,
    TilePicker,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import { CONTACT_TYPES } from '@/pages/sites/contacts/_helpers';
import {
    FREQUENCIES,
    NZ_REGION_OPTIONS,
    RESOURCE_TYPES,
    SITE_TYPES,
    ZONE_TYPES,
    deriveNzRegion,
    type SiteType,
} from '@/pages/sites/_wizard';
import { useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    CalendarClock,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    FileText,
    Loader2,
    MapPin,
    Package,
    Plus,
    Shield,
    Star,
    Trash2,
    Users,
    Wallet,
} from 'lucide-react';
import { useMemo, useRef, useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Reference data shape (Inertia `addSite` prop from SiteController)  */
/* ------------------------------------------------------------------ */

export type AddSiteUser = { id: number; name: string };
export type AddSiteChecklistTemplate = {
    id: number;
    name: string;
    description?: string | null;
    applicable_to_type?: string | null;
    frequency?: string | null;
};
export type AddSiteAsset = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    serial_number?: string | null;
    is_assigned_here?: boolean;
};
export type AddSiteServiceContext = {
    id: number;
    name: string;
    type?: string | null;
};
export type AddSiteCopyableCoverage = {
    name: string;
    coverage_type: string;
    day_of_week: string;
    starts_time: string;
    ends_time: string;
    minimum_staff: number;
    role_requirements: { key: string; minimum: number }[];
    allow_overstaffing: boolean;
    shift_type: string | null;
    service_context_id: number | null;
};
export type AddSiteCopyableSite = {
    id: number;
    name: string;
    type: string;
    coverage: AddSiteCopyableCoverage[];
    credentials: {
        name: string;
        category: string;
        expiry_period_months: number | null;
    }[];
};
export type AddSiteCredential = {
    key: string;
    name: string;
    default_expiry_months: number;
};
export type AddSiteRoleKey = { key: string; label: string };

export type AddSiteReferenceData = {
    users: AddSiteUser[];
    checklistTemplates: AddSiteChecklistTemplate[];
    availableAssets: AddSiteAsset[];
    regionOptions: string[];
    serviceContexts: AddSiteServiceContext[];
    copyableSites: AddSiteCopyableSite[];
    credentialCatalogue: AddSiteCredential[];
    coverageRoleKeys: AddSiteRoleKey[];
};

/* ------------------------------------------------------------------ */
/*  Form payload shape (mirrors README state + the backend contract)   */
/* ------------------------------------------------------------------ */

export type CoverageRule = {
    name: string;
    coverage_type: 'day' | 'evening' | 'overnight' | 'custom';
    days: string[];
    starts_time: string;
    ends_time: string;
    minimum_staff: number;
    shift_type: 'standard' | 'sleepover' | 'on_call' | 'split' | 'travel';
    allow_overstaffing: boolean;
    service_context_id: string;
    roles: { caregiver: number; driver: number; med_competent: number };
};
export type CredentialRow = {
    key: string;
    name: string;
    category: 'mandatory' | 'recommended';
    expiry_period_months: number | string;
};
export type ShiftTemplateRow = { name: string; starts_time: string; ends_time: string };
export type SiteContactRow = {
    type: string;
    name: string;
    role: string;
    phone: string;
    email: string;
    is_primary: boolean;
};
export type RoomRow = { name: string; notes: string };
export type ResourceRow = { name: string; resource_type: string; capacity: string };
export type ZoneRow = { name: string; zone_type: string };
export type ChecklistRow = {
    template_id: number;
    enabled: boolean;
    frequency: string;
    assigned_to_user_id: string;
};
export type SiteDocumentDraft = {
    file: File;
    title: string;
    category: string;
    expiry_date: string;
};
export type GeofenceForm = {
    mode: 'radius' | 'draw';
    radius_m: number;
    breach_type: 'enter' | 'exit' | 'both';
    is_active: boolean;
};

export type SiteWizardForm = {
    _modal: boolean;
    // basics
    type: SiteType;
    name: string;
    phone: string;
    email: string;
    primary_contact_user_id: string;
    is_active: boolean;
    total_capacity: string;
    // location
    address_line_1: string;
    address_line_2: string;
    suburb: string;
    city: string;
    postcode: string;
    country: string;
    region: string;
    latitude: string;
    longitude: string;
    access_instructions: string;
    geofence: GeofenceForm;
    // spaces
    rooms: RoomRow[];
    resources: ResourceRow[];
    zones: ZoneRow[];
    // rostering
    copy_from: string;
    coverage: CoverageRule[];
    credentials: CredentialRow[];
    shift_templates: ShiftTemplateRow[];
    // contacts
    contacts: SiteContactRow[];
    // equipment & checks
    assets: number[];
    checklists: ChecklistRow[];
    medication_storage_location: string;
    // documents
    documents: SiteDocumentDraft[];
    // property & finance
    rent_amount: string;
    rent_frequency: 'weekly' | 'fortnightly' | 'monthly' | 'annually';
    lease_start_date: string;
    lease_end_date: string;
    landlord_name: string;
    landlord_contact: string;
    weekly_food_budget: string;
    // review & safety
    is_high_risk: boolean;
    is_high_needs: boolean;
    risk_notes: string;
    risk_review_date: string;
    emergency_plan_location: string;
    notes: string;
};

type StepKey =
    | 'basics'
    | 'location'
    | 'spaces'
    | 'rostering'
    | 'contacts'
    | 'equipment'
    | 'documents'
    | 'finance'
    | 'review';

const STEPS: readonly (WizardStep & { key: StepKey })[] = [
    { key: 'basics', label: 'Basics', icon: Building2, blurb: 'Type, name & lead' },
    { key: 'location', label: 'Location', icon: MapPin, blurb: 'Address & geofence' },
    { key: 'spaces', label: 'Spaces', icon: Package, blurb: 'Rooms, resources & zones' },
    { key: 'rostering', label: 'Rostering', icon: CalendarClock, blurb: 'Coverage & credentials' },
    { key: 'contacts', label: 'Contacts', icon: Users, blurb: 'Who to call' },
    { key: 'equipment', label: 'Equipment & checks', icon: Package, blurb: 'Assets & checklists' },
    { key: 'documents', label: 'Documents', icon: FileText, blurb: 'Files & certificates' },
    { key: 'finance', label: 'Property & finance', icon: Wallet, blurb: 'Tenancy & budget' },
    { key: 'review', label: 'Review', icon: CheckCircle2, blurb: 'Risk, safety & create' },
] as const;

export function defaultGeofence(): GeofenceForm {
    return { mode: 'radius', radius_m: 120, breach_type: 'both', is_active: true };
}

function initialForm(): SiteWizardForm {
    return {
        _modal: true,
        type: 'house',
        name: '',
        phone: '',
        email: '',
        primary_contact_user_id: '',
        is_active: true,
        total_capacity: '',
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
        geofence: defaultGeofence(),
        rooms: [],
        resources: [],
        zones: [],
        copy_from: '',
        coverage: [],
        credentials: [],
        shift_templates: [],
        contacts: [],
        assets: [],
        checklists: [],
        medication_storage_location: '',
        documents: [],
        rent_amount: '',
        rent_frequency: 'weekly',
        lease_start_date: '',
        lease_end_date: '',
        landlord_name: '',
        landlord_contact: '',
        weekly_food_budget: '',
        is_high_risk: false,
        is_high_needs: false,
        risk_notes: '',
        risk_review_date: '',
        emergency_plan_location: '',
        notes: '',
    };
}

/* ---- completeness meter ---- */
function isFilled(v: unknown): boolean {
    if (Array.isArray(v)) return v.length > 0;
    return v !== '' && v != null && v !== false;
}
function completionPct(d: SiteWizardForm): number {
    const checks = [
        !!d.name,
        true, // type always set
        isFilled(d.phone),
        isFilled(d.email),
        isFilled(d.primary_contact_user_id),
        isFilled(d.address_line_1),
        isFilled(d.city),
        isFilled(d.region),
        isFilled(d.latitude) && isFilled(d.longitude),
        d.coverage.length > 0,
        d.credentials.length > 0,
        d.contacts.some((c) => c.name.trim() !== ''),
        d.rooms.length + d.resources.length + d.zones.length > 0,
    ];
    const filled = checks.filter(Boolean).length;
    return Math.round((filled / checks.length) * 100);
}

/* ---- validation (mirrors StoreSiteRequest's hard requirements) ---- */
function validateStep(key: StepKey, d: SiteWizardForm): Record<string, string> {
    const e: Record<string, string> = {};
    if (key === 'basics') {
        if (!d.type) e.type = 'Choose a site type';
        if (!d.name.trim()) e.name = 'Site name is required';
        if (d.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(d.email))
            e.email = 'Enter a valid email';
    }
    if (key === 'finance' && d.lease_start_date && d.lease_end_date) {
        if (d.lease_end_date < d.lease_start_date)
            e.lease_end_date = 'Lease end must be on or after the start date';
    }
    return e;
}

const STEP_FOR_PREFIX: { prefix: string; step: StepKey }[] = [
    { prefix: 'coverage', step: 'rostering' },
    { prefix: 'credentials', step: 'rostering' },
    { prefix: 'shift_templates', step: 'rostering' },
    { prefix: 'address_', step: 'location' },
    { prefix: 'suburb', step: 'location' },
    { prefix: 'city', step: 'location' },
    { prefix: 'postcode', step: 'location' },
    { prefix: 'country', step: 'location' },
    { prefix: 'region', step: 'location' },
    { prefix: 'latitude', step: 'location' },
    { prefix: 'longitude', step: 'location' },
    { prefix: 'access_instructions', step: 'location' },
    { prefix: 'geofence', step: 'location' },
    { prefix: 'rooms', step: 'spaces' },
    { prefix: 'resources', step: 'spaces' },
    { prefix: 'zones', step: 'spaces' },
    { prefix: 'total_capacity', step: 'spaces' },
    { prefix: 'contacts', step: 'contacts' },
    { prefix: 'assets', step: 'equipment' },
    { prefix: 'checklists', step: 'equipment' },
    { prefix: 'medication_storage_location', step: 'equipment' },
    { prefix: 'documents', step: 'documents' },
    { prefix: 'rent_', step: 'finance' },
    { prefix: 'lease_', step: 'finance' },
    { prefix: 'landlord_', step: 'finance' },
    { prefix: 'weekly_food_budget', step: 'finance' },
    { prefix: 'is_high_', step: 'review' },
    { prefix: 'risk_', step: 'review' },
    { prefix: 'emergency_plan_location', step: 'review' },
    { prefix: 'notes', step: 'review' },
];
function stepForError(field: string): StepKey {
    for (const { prefix, step } of STEP_FOR_PREFIX) {
        if (field.startsWith(prefix)) return step;
    }
    return 'basics';
}

/* ------------------------------------------------------------------ */
/*  Step context                                                       */
/* ------------------------------------------------------------------ */

export type SiteStepCtx = {
    data: SiteWizardForm;
    set: <K extends keyof SiteWizardForm>(k: K, v: SiteWizardForm[K]) => void;
    setMany: (partial: Partial<SiteWizardForm>) => void;
    err: (name: string) => string | undefined;
    ref: AddSiteReferenceData;
    goToStep: (key: StepKey) => void;
};

/* ------------------------------------------------------------------ */
/*  Public component                                                   */
/* ------------------------------------------------------------------ */

export type AddSiteDialogProps = AddSiteReferenceData & {
    isOpen: boolean;
    onClose: () => void;
    onSaved?: (siteId: number | null) => void;
};

export function AddSiteDialog(props: AddSiteDialogProps) {
    // Re-mount the body each open so the form resets cleanly.
    return props.isOpen ? <AddSiteBody {...props} /> : null;
}

function AddSiteBody({
    isOpen,
    onClose,
    onSaved,
    ...ref
}: AddSiteDialogProps) {
    const page = usePage<{ flash?: { created_site_id?: number | null } }>();
    const form = useForm<SiteWizardForm>(initialForm());
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const cur = STEPS[stepIndex];
    const pct = useMemo(() => completionPct(data), [data]);

    const set = <K extends keyof SiteWizardForm>(k: K, v: SiteWizardForm[K]) =>
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        setData(k, v as any);
    const setMany = (partial: Partial<SiteWizardForm>) =>
        setData((prev) => ({ ...prev, ...partial }));
    const err = (name: string): string | undefined =>
        errors[name] ?? (form.errors as Record<string, string>)[name];

    const goToStep = (key: StepKey) => {
        const idx = STEPS.findIndex((s) => s.key === key);
        if (idx >= 0) setStepIndex(idx);
    };
    const next = () => {
        const e = validateStep(cur.key, data);
        setErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };
    const back = () => setStepIndex((i) => Math.max(i - 1, 0));

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const resetAll = () => {
        form.reset();
        form.clearErrors();
        setData(initialForm());
        setErrors({});
        setStepIndex(0);
        setDone(false);
    };

    const submit = (addAnother: boolean) => {
        const all: Record<string, string> = {};
        for (const s of STEPS) Object.assign(all, validateStep(s.key, data));
        if (Object.keys(all).length) {
            setErrors(all);
            goToStep(stepForError(Object.keys(all)[0]));
            return;
        }
        setErrors({});
        form.post('/sites', {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (addAnother) {
                    resetAll();
                } else {
                    setDone(true);
                    onSaved?.(page.props.flash?.created_site_id ?? null);
                }
            },
            onError: (errs: Record<string, string>) => {
                const first = Object.keys(errs)[0];
                if (first) goToStep(stepForError(first));
            },
        });
    };

    const ctx: SiteStepCtx = { data, set, setMany, err, ref, goToStep };
    const isReview = cur.key === 'review';

    const success = done ? (
        <SuccessPane
            siteName={data.name || 'The site'}
            siteId={page.props.flash?.created_site_id ?? null}
            onClose={onClose}
            onAddAnother={resetAll}
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={isOpen}
                onClose={requestClose}
                title="Add site"
                description="A guided wizard to set up a new site, its coverage and geofence."
                railIcon={Building2}
                railTitle="Add site"
                railSub="New location"
                steps={STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button type="button" variant="ghost" onClick={back}>
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button type="button" variant="outline" onClick={requestClose}>
                            Cancel
                        </Button>
                        {isReview ? (
                            <>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => submit(true)}
                                    disabled={processing}
                                >
                                    {processing ? (
                                        <Loader2 className="h-4 w-4 animate-spin" />
                                    ) : (
                                        <Plus className="h-4 w-4" />
                                    )}
                                    Save &amp; add another
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => submit(false)}
                                    disabled={processing}
                                >
                                    {processing ? (
                                        <>
                                            <Loader2 className="h-4 w-4 animate-spin" /> Creating…
                                        </>
                                    ) : (
                                        <>
                                            <Check className="h-4 w-4" /> Create site
                                        </>
                                    )}
                                </Button>
                            </>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane>
                    <StepBody stepKey={cur.key} ctx={ctx} />
                </WizardStepPane>
            </WizardShell>

            <Dialog open={confirmClose} onOpenChange={setConfirmClose}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Discard this draft?</DialogTitle>
                        <DialogDescription>
                            Any details entered for this site will be lost.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmClose(false)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setConfirmClose(false);
                                onClose();
                            }}
                        >
                            Discard
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function StepBody({ stepKey, ctx }: { stepKey: StepKey; ctx: SiteStepCtx }) {
    switch (stepKey) {
        case 'basics':
            return <StepBasics ctx={ctx} />;
        case 'location':
            return <StepLocation ctx={ctx} />;
        case 'spaces':
            return <StepSpaces ctx={ctx} />;
        case 'rostering':
            return <StepRosteringPlaceholder />;
        case 'contacts':
            return <StepContacts ctx={ctx} />;
        case 'equipment':
            return <StepEquipment ctx={ctx} />;
        case 'documents':
            return <StepDocuments ctx={ctx} />;
        case 'finance':
            return <StepFinancePlaceholder />;
        case 'review':
            return <StepReviewPlaceholder />;
        default:
            return null;
    }
}

/* ------------------------------------------------------------------ */
/*  Step: Basics                                                       */
/* ------------------------------------------------------------------ */

function StepBasics({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set, err, ref } = ctx;
    return (
        <div>
            <StepHead
                icon={Building2}
                title="What kind of site is this?"
                blurb="Pick the site type, name it, and choose who leads it."
            />
            <div className="grid gap-4">
                <Field label="Site type" required error={err('type')} span>
                    <TilePicker
                        value={data.type}
                        onChange={(v) => set('type', v as SiteType)}
                        cols={2}
                        options={SITE_TYPES.map((t) => ({
                            key: t.value,
                            label: t.label,
                            description: t.description,
                            icon: t.icon,
                        }))}
                    />
                </Field>

                <Field label="Site name" required error={err('name')} span>
                    <Input
                        value={data.name}
                        onChange={(e) => set('name', e.target.value)}
                        placeholder="e.g. Aroha House"
                        aria-invalid={!!err('name')}
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Site phone" error={err('phone')}>
                        <Input
                            value={data.phone}
                            onChange={(e) => set('phone', e.target.value)}
                            placeholder="09 555 1234"
                        />
                    </Field>
                    <Field label="Site email" error={err('email')}>
                        <Input
                            type="email"
                            value={data.email}
                            onChange={(e) => set('email', e.target.value)}
                            placeholder="site@example.co.nz"
                            aria-invalid={!!err('email')}
                        />
                    </Field>
                </div>

                <Field
                    label="Site lead / manager"
                    hint="who's responsible for this site"
                    span
                >
                    <SelectInput
                        value={data.primary_contact_user_id}
                        onChange={(v) => set('primary_contact_user_id', v)}
                        placeholder="Select a lead…"
                        options={ref.users.map((u) => ({
                            value: String(u.id),
                            label: u.name,
                        }))}
                    />
                </Field>

                <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-3">
                    <Switch
                        checked={data.is_active}
                        onCheckedChange={(v) => set('is_active', v)}
                    />
                    <div>
                        <div className="text-sm font-semibold">
                            Site is active and operational
                        </div>
                        <div className="mt-0.5 text-[13px] text-muted-foreground">
                            Active sites lead the roster. You can deactivate later.
                        </div>
                    </div>
                </div>

                <InfoCard icon={Shield}>
                    Brand colour is set per-site in{' '}
                    <strong>Settings → Branding</strong>, not here.
                </InfoCard>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step: Location (S3 shell — geofence + autocomplete land in S5)     */
/* ------------------------------------------------------------------ */

function StepLocation({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set, err } = ctx;
    const updateCity = (city: string) => {
        const prev = deriveNzRegion(data.city);
        const nextRegion = deriveNzRegion(city);
        set('city', city);
        if (nextRegion && (!data.region || data.region === prev))
            set('region', nextRegion);
    };
    return (
        <div>
            <StepHead
                icon={MapPin}
                title="Where is this site?"
                blurb="The address powers the map pin and the geofence boundary."
            />
            <div className="grid gap-4">
                <SubHead icon={MapPin}>Address</SubHead>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Address line 1" error={err('address_line_1')} span>
                        <Input
                            value={data.address_line_1}
                            onChange={(e) => set('address_line_1', e.target.value)}
                            placeholder="123 Example Street"
                        />
                    </Field>
                    <Field label="Address line 2" span>
                        <Input
                            value={data.address_line_2}
                            onChange={(e) => set('address_line_2', e.target.value)}
                            placeholder="Apartment, unit (optional)"
                        />
                    </Field>
                    <Field label="Suburb">
                        <Input
                            value={data.suburb}
                            onChange={(e) => set('suburb', e.target.value)}
                        />
                    </Field>
                    <Field label="City">
                        <Input
                            value={data.city}
                            onChange={(e) => updateCity(e.target.value)}
                            placeholder="e.g. Auckland"
                        />
                    </Field>
                    <Field label="Postcode">
                        <Input
                            value={data.postcode}
                            onChange={(e) =>
                                set('postcode', e.target.value.replace(/\D/g, '').slice(0, 4))
                            }
                            placeholder="1010"
                        />
                    </Field>
                    <Field label="Region">
                        <SelectInput
                            value={data.region}
                            onChange={(v) => set('region', v)}
                            placeholder="Select region"
                            options={NZ_REGION_OPTIONS.map((r) => ({ value: r, label: r }))}
                        />
                    </Field>
                </div>
                <Field label="Access instructions" span>
                    <Textarea
                        rows={2}
                        value={data.access_instructions}
                        onChange={(e) => set('access_instructions', e.target.value)}
                        placeholder="Lockbox code, parking, gate access…"
                    />
                </Field>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step: Spaces                                                       */
/* ------------------------------------------------------------------ */

function StepSpaces({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set } = ctx;
    const showCapacity = data.type === 'house' || data.type === 'residential';

    return (
        <div>
            <StepHead
                icon={Package}
                title="Spaces"
                blurb="Set up the rooms, resources or zones this site has."
            />
            <div className="grid gap-4">
                {showCapacity ? (
                    <Field
                        label="Total capacity / beds"
                        hint="how many people this site can support"
                        span
                    >
                        <Input
                            type="number"
                            min={0}
                            value={data.total_capacity}
                            onChange={(e) => set('total_capacity', e.target.value)}
                            placeholder="e.g. 6"
                            className="max-w-[160px]"
                        />
                    </Field>
                ) : null}

                {data.type === 'head_office' ? (
                    <RepeatableSpaces
                        title="Resources"
                        addLabel="Add resource"
                        rows={data.resources}
                        onChange={(rows) => set('resources', rows)}
                        empty="No resources yet — add meeting rooms, offices or parking."
                        makeEmpty={() => ({ name: '', resource_type: 'meeting_room', capacity: '' })}
                        renderRow={(row, update) => (
                            <div className="grid gap-2 sm:grid-cols-[1.5fr_1fr_0.8fr]">
                                <Input
                                    value={row.name}
                                    onChange={(e) => update({ name: e.target.value })}
                                    placeholder="Resource name"
                                />
                                <SelectInput
                                    value={row.resource_type}
                                    onChange={(v) => update({ resource_type: v })}
                                    placeholder="Type"
                                    options={RESOURCE_TYPES}
                                />
                                <Input
                                    type="number"
                                    min={0}
                                    value={row.capacity}
                                    onChange={(e) => update({ capacity: e.target.value })}
                                    placeholder="Cap."
                                />
                            </div>
                        )}
                    />
                ) : data.type === 'facility' ? (
                    <RepeatableSpaces
                        title="Zones"
                        addLabel="Add zone"
                        rows={data.zones}
                        onChange={(rows) => set('zones', rows)}
                        empty="No zones yet — add workshop, café or day-programme spaces."
                        makeEmpty={() => ({ name: '', zone_type: 'workshop' })}
                        renderRow={(row, update) => (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Input
                                    value={row.name}
                                    onChange={(e) => update({ name: e.target.value })}
                                    placeholder="Zone name"
                                />
                                <SelectInput
                                    value={row.zone_type}
                                    onChange={(v) => update({ zone_type: v })}
                                    placeholder="Type"
                                    options={ZONE_TYPES}
                                />
                            </div>
                        )}
                    />
                ) : (
                    <RepeatableSpaces
                        title="Rooms"
                        addLabel="Add room"
                        rows={data.rooms}
                        onChange={(rows) => set('rooms', rows)}
                        empty="No rooms yet — add the bedrooms and communal spaces."
                        makeEmpty={() => ({ name: '', notes: '' })}
                        renderRow={(row, update) => (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <Input
                                    value={row.name}
                                    onChange={(e) => update({ name: e.target.value })}
                                    placeholder="Room name"
                                />
                                <Input
                                    value={row.notes}
                                    onChange={(e) => update({ notes: e.target.value })}
                                    placeholder="Notes (optional)"
                                />
                            </div>
                        )}
                    />
                )}
            </div>
        </div>
    );
}

function RepeatableSpaces<T>({
    title,
    addLabel,
    rows,
    onChange,
    empty,
    makeEmpty,
    renderRow,
}: {
    title: string;
    addLabel: string;
    rows: T[];
    onChange: (rows: T[]) => void;
    empty: string;
    makeEmpty: () => T;
    renderRow: (row: T, update: (patch: Partial<T>) => void) => ReactNode;
}) {
    return (
        <div className="grid gap-2.5">
            <SubHead icon={Package}>{title}</SubHead>
            {rows.length === 0 ? (
                <div className="rounded-lg border border-dashed border-border p-3.5 text-center text-[13px] text-muted-foreground">
                    {empty}
                </div>
            ) : null}
            {rows.map((row, i) => (
                <div
                    key={i}
                    className="flex items-start gap-2 rounded-lg border border-border bg-card/70 p-3"
                >
                    <div className="min-w-0 flex-1">
                        {renderRow(row, (patch) =>
                            onChange(rows.map((r, idx) => (idx === i ? { ...r, ...patch } : r))),
                        )}
                    </div>
                    <button
                        type="button"
                        aria-label={`Remove ${title.toLowerCase()}`}
                        onClick={() => onChange(rows.filter((_, idx) => idx !== i))}
                        className="mt-1 text-muted-foreground hover:text-status-critical"
                    >
                        <Trash2 className="h-4 w-4" />
                    </button>
                </div>
            ))}
            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...rows, makeEmpty()])}
                >
                    <Plus className="h-3.5 w-3.5" /> {addLabel}
                </Button>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step: Contacts                                                     */
/* ------------------------------------------------------------------ */

function StepContacts({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set } = ctx;
    const contacts = data.contacts;
    const update = (i: number, patch: Partial<SiteContactRow>) =>
        set(
            'contacts',
            contacts.map((c, idx) => (idx === i ? { ...c, ...patch } : c)),
        );
    const setPrimary = (i: number) =>
        set(
            'contacts',
            contacts.map((c, idx) => ({ ...c, is_primary: idx === i })),
        );
    const add = () =>
        set('contacts', [
            ...contacts,
            {
                type: 'site_contact',
                name: '',
                role: '',
                phone: '',
                email: '',
                is_primary: contacts.length === 0,
            },
        ]);

    return (
        <div>
            <StepHead
                icon={Users}
                title="Site contacts"
                blurb="Who to call — site lead, manager, emergency, clinical and more."
            />
            <div className="grid gap-2.5">
                {contacts.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border p-3.5 text-center text-[13px] text-muted-foreground">
                        No contacts yet. Add at least a site lead and an emergency contact.
                    </div>
                ) : null}
                {contacts.map((c, i) => (
                    <div
                        key={i}
                        className="grid gap-2.5 rounded-lg border border-border bg-card/70 p-3"
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            <SelectInput
                                value={c.type}
                                onChange={(v) => update(i, { type: v })}
                                placeholder="Contact type"
                                options={CONTACT_TYPES.map((t) => ({
                                    value: t.key,
                                    label: t.label,
                                }))}
                            />
                            <Input
                                value={c.name}
                                onChange={(e) => update(i, { name: e.target.value })}
                                placeholder="Full name"
                            />
                            <Input
                                value={c.role}
                                onChange={(e) => update(i, { role: e.target.value })}
                                placeholder="Role (optional)"
                            />
                            <Input
                                value={c.phone}
                                onChange={(e) => update(i, { phone: e.target.value })}
                                placeholder="Phone"
                            />
                            <Input
                                type="email"
                                value={c.email}
                                onChange={(e) => update(i, { email: e.target.value })}
                                placeholder="Email (optional)"
                            />
                        </div>
                        <div className="flex items-center justify-between">
                            <button
                                type="button"
                                onClick={() => setPrimary(i)}
                                className={cn(
                                    'inline-flex items-center gap-1.5 text-[13px] font-medium',
                                    c.is_primary ? 'text-primary' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Star
                                    className={cn('h-3.5 w-3.5', c.is_primary && 'fill-primary')}
                                />
                                {c.is_primary ? 'Primary contact' : 'Set as primary'}
                            </button>
                            <button
                                type="button"
                                aria-label="Remove contact"
                                onClick={() =>
                                    set('contacts', contacts.filter((_, idx) => idx !== i))
                                }
                                className="text-muted-foreground hover:text-status-critical"
                            >
                                <Trash2 className="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                ))}
                <div>
                    <Button type="button" variant="outline" size="sm" onClick={add}>
                        <Plus className="h-3.5 w-3.5" /> Add contact
                    </Button>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step: Equipment & checks                                           */
/* ------------------------------------------------------------------ */

function StepEquipment({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set, ref } = ctx;
    const toggleAsset = (id: number, on: boolean) =>
        set('assets', on ? [...data.assets, id] : data.assets.filter((a) => a !== id));
    const checklistFor = (templateId: number) =>
        data.checklists.find((c) => c.template_id === templateId);
    const setChecklist = (templateId: number, patch: Partial<ChecklistRow>) => {
        const existing = checklistFor(templateId);
        if (existing) {
            set(
                'checklists',
                data.checklists.map((c) =>
                    c.template_id === templateId ? { ...c, ...patch } : c,
                ),
            );
        } else {
            set('checklists', [
                ...data.checklists,
                {
                    template_id: templateId,
                    enabled: true,
                    frequency: 'monthly',
                    assigned_to_user_id: '',
                    ...patch,
                },
            ]);
        }
    };

    return (
        <div>
            <StepHead
                icon={Package}
                title="Equipment & checks"
                blurb="Link assets, schedule recurring checklists, and note medication storage."
            />
            <div className="grid gap-4">
                <SubHead icon={Package}>Assets &amp; devices</SubHead>
                {ref.availableAssets.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border p-3.5 text-center text-[13px] text-muted-foreground">
                        No unassigned assets available to link.
                    </div>
                ) : (
                    <div className="grid gap-2 sm:grid-cols-2">
                        {ref.availableAssets.map((a) => {
                            const on = data.assets.includes(a.id);
                            return (
                                <label
                                    key={a.id}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-2.5 rounded-lg border p-2.5 transition-colors',
                                        on ? 'border-primary bg-primary/10' : 'border-border bg-card/60',
                                    )}
                                >
                                    <Checkbox
                                        checked={on}
                                        onCheckedChange={(v) => toggleAsset(a.id, v as boolean)}
                                    />
                                    <span className="min-w-0">
                                        <span className="block truncate text-[13px] font-semibold">
                                            {a.name}
                                        </span>
                                        <span className="block truncate text-[11px] text-muted-foreground">
                                            {[a.asset_tag, a.category].filter(Boolean).join(' · ')}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                )}

                <SubHead icon={CheckCircle2}>Recurring checklists</SubHead>
                {ref.checklistTemplates.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border p-3.5 text-center text-[13px] text-muted-foreground">
                        No checklist templates configured.
                    </div>
                ) : (
                    <div className="grid gap-2">
                        {ref.checklistTemplates.map((t) => {
                            const row = checklistFor(t.id);
                            const on = !!row?.enabled;
                            return (
                                <div
                                    key={t.id}
                                    className="rounded-lg border border-border bg-card/60 p-3"
                                >
                                    <div className="flex items-center gap-2.5">
                                        <Switch
                                            checked={on}
                                            onCheckedChange={(v) =>
                                                setChecklist(t.id, { enabled: v })
                                            }
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="text-[13px] font-semibold">{t.name}</div>
                                            {t.description ? (
                                                <div className="truncate text-[11px] text-muted-foreground">
                                                    {t.description}
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                    {on ? (
                                        <div className="mt-2.5 grid gap-2 sm:grid-cols-2">
                                            <SelectInput
                                                value={row?.frequency ?? 'monthly'}
                                                onChange={(v) => setChecklist(t.id, { frequency: v })}
                                                placeholder="Frequency"
                                                options={FREQUENCIES}
                                            />
                                            <SelectInput
                                                value={row?.assigned_to_user_id ?? ''}
                                                onChange={(v) =>
                                                    setChecklist(t.id, { assigned_to_user_id: v })
                                                }
                                                placeholder="Assign to…"
                                                options={ref.users.map((u) => ({
                                                    value: String(u.id),
                                                    label: u.name,
                                                }))}
                                            />
                                        </div>
                                    ) : null}
                                </div>
                            );
                        })}
                    </div>
                )}

                <Field label="Medication storage location" span>
                    <Input
                        value={data.medication_storage_location}
                        onChange={(e) => set('medication_storage_location', e.target.value)}
                        placeholder="e.g. Locked cabinet in the office"
                    />
                </Field>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step: Documents                                                    */
/* ------------------------------------------------------------------ */

const DOCUMENT_CATEGORIES = [
    { value: 'compliance', label: 'Compliance' },
    { value: 'safety', label: 'Safety' },
    { value: 'lease', label: 'Lease / tenancy' },
    { value: 'insurance', label: 'Insurance' },
    { value: 'other', label: 'Other' },
];

function StepDocuments({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set } = ctx;
    const inputRef = useRef<HTMLInputElement>(null);
    const addFiles = (files: FileList | null) => {
        if (!files) return;
        const drafts: SiteDocumentDraft[] = Array.from(files).map((file) => ({
            file,
            title: file.name.replace(/\.[^.]+$/, ''),
            category: 'compliance',
            expiry_date: '',
        }));
        set('documents', [...data.documents, ...drafts]);
        if (inputRef.current) inputRef.current.value = '';
    };
    const update = (i: number, patch: Partial<SiteDocumentDraft>) =>
        set(
            'documents',
            data.documents.map((d, idx) => (idx === i ? { ...d, ...patch } : d)),
        );

    return (
        <div>
            <StepHead
                icon={FileText}
                title="Documents"
                blurb="Attach site certificates, the lease, evacuation plans and more."
            />
            <div className="grid gap-3">
                <button
                    type="button"
                    onClick={() => inputRef.current?.click()}
                    className="flex flex-col items-center gap-1.5 rounded-xl border border-dashed border-border bg-muted/30 px-4 py-7 text-center transition-colors hover:border-primary/50 hover:bg-muted/50"
                >
                    <FileText className="h-6 w-6 text-muted-foreground" />
                    <span className="text-[13px] font-semibold">Click to upload files</span>
                    <span className="text-[11px] text-muted-foreground">
                        PDF, Word, images — up to 50&nbsp;MB each
                    </span>
                </button>
                <input
                    ref={inputRef}
                    type="file"
                    multiple
                    className="hidden"
                    onChange={(e) => addFiles(e.target.files)}
                />

                {data.documents.map((d, i) => (
                    <div
                        key={i}
                        className="grid gap-2 rounded-lg border border-border bg-card/70 p-3 sm:grid-cols-[1.4fr_1fr_1fr_auto]"
                    >
                        <Input
                            value={d.title}
                            onChange={(e) => update(i, { title: e.target.value })}
                            placeholder="Title"
                        />
                        <SelectInput
                            value={d.category}
                            onChange={(v) => update(i, { category: v })}
                            placeholder="Category"
                            options={DOCUMENT_CATEGORIES}
                        />
                        <Input
                            type="date"
                            value={d.expiry_date}
                            onChange={(e) => update(i, { expiry_date: e.target.value })}
                        />
                        <button
                            type="button"
                            aria-label="Remove document"
                            onClick={() =>
                                set('documents', data.documents.filter((_, idx) => idx !== i))
                            }
                            className="self-center text-muted-foreground hover:text-status-critical"
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  WIP step heads (built in S4 / S6)                                  */
/* ------------------------------------------------------------------ */

function StepRosteringPlaceholder() {
    return (
        <StepHead
            icon={CalendarClock}
            title="Rostering & coverage"
            blurb="Coverage rules, role mix, credentials and shift templates."
        />
    );
}
function StepFinancePlaceholder() {
    return (
        <StepHead
            icon={Wallet}
            title="Property & finance"
            blurb="Tenancy, lease, landlord and the weekly food budget."
        />
    );
}
function StepReviewPlaceholder() {
    return (
        <StepHead
            icon={CheckCircle2}
            title="Review & safety"
            blurb="Risk flags, emergency plan, notes — then create the site."
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Success pane                                                       */
/* ------------------------------------------------------------------ */

function SuccessPane({
    siteName,
    siteId,
    onClose,
    onAddAnother,
}: {
    siteName: string;
    siteId: number | null;
    onClose: () => void;
    onAddAnother: () => void;
}) {
    return (
        <WizardSuccessPane
            title={`${siteName} is live`}
            blurb="The site is set up. Open its profile to add clients, finish onboarding and review readiness."
            actions={
                <>
                    <Button variant="outline" onClick={onAddAnother}>
                        <Plus className="h-4 w-4" /> Add another site
                    </Button>
                    <Button
                        onClick={() => {
                            if (siteId) window.location.href = `/sites/${siteId}`;
                            else onClose();
                        }}
                    >
                        Open site profile <ChevronRight className="h-4 w-4" />
                    </Button>
                </>
            }
        />
    );
}
