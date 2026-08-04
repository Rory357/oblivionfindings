/* eslint-disable no-restricted-syntax -- The Add Site modal mirrors the bespoke
 * Add Client wizard chrome (stepper rail + scroll-contained body + custom footer)
 * via the shared WizardShell, and uses the shared wizard primitives for tile
 * pickers, chips and segmented controls. Every colour is a semantic design token
 * (never hardcoded hex), per docs/DESIGN_TOKENS.md. */
import {
    AddressAutocomplete,
    type GeocodeResult,
} from '@/components/address-autocomplete';
import GeofenceDrawMap, {
    type GeofenceShape,
} from '@/components/geofence-draw-map';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    FieldErr,
    Segmented,
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
import {
    NZ_REGION_OPTIONS,
    RESOURCE_TYPES,
    SITE_TYPES,
    ZONE_TYPES,
    deriveNzRegion,
    type SiteType,
} from '@/pages/sites/_wizard';
import { CONTACT_TYPES } from '@/pages/sites/contacts/_helpers';
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
    Minus,
    Package,
    Pill,
    Plus,
    Shield,
    ShieldCheck,
    Sparkles,
    Star,
    Trash2,
    Users,
    Wallet,
} from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Reference data shape (Inertia `addSite` prop from SiteController)  */
/* ------------------------------------------------------------------ */

export type AddSiteUser = { id: number; name: string };
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
export type SiteContactRow = {
    type: string;
    name: string;
    role: string;
    phone: string;
    email: string;
    is_primary: boolean;
};
export type RoomRow = { name: string; notes: string; is_assignable: boolean };
export type ResourceRow = {
    name: string;
    resource_type: string;
    capacity: string;
};
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
    {
        key: 'basics',
        label: 'Basics',
        icon: Building2,
        blurb: 'Type, name & lead',
    },
    {
        key: 'location',
        label: 'Location',
        icon: MapPin,
        blurb: 'Address & geofence',
    },
    {
        key: 'spaces',
        label: 'Spaces',
        icon: Package,
        blurb: 'Rooms, resources & zones',
    },
    {
        key: 'rostering',
        label: 'Rostering',
        icon: CalendarClock,
        blurb: 'Coverage & credentials',
    },
    { key: 'contacts', label: 'Contacts', icon: Users, blurb: 'Who to call' },
    {
        key: 'equipment',
        label: 'Medication',
        icon: Pill,
        blurb: 'Where meds are stored',
    },
    {
        key: 'documents',
        label: 'Documents',
        icon: FileText,
        blurb: 'Files & certificates',
    },
    {
        key: 'finance',
        label: 'Property & finance',
        icon: Wallet,
        blurb: 'Tenancy & budget',
    },
    {
        key: 'review',
        label: 'Review',
        icon: CheckCircle2,
        blurb: 'Risk, safety & create',
    },
] as const;

export function defaultGeofence(): GeofenceForm {
    return {
        mode: 'radius',
        radius_m: 120,
        breach_type: 'both',
        is_active: true,
    };
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
    if (key === 'rostering') {
        // Mirror the server rules (coverage.*.days min:1, name + times
        // required_with) so a stripped-down rule surfaces inline instead of a
        // silent 422 with no visible message.
        const bad = d.coverage.some(
            (c) =>
                c.days.length === 0 ||
                !c.name.trim() ||
                !c.starts_time ||
                !c.ends_time,
        );
        if (bad)
            e.coverage_days =
                'Each coverage rule needs a name, at least one day, and start & end times.';
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
    /**
     * Fires after a successful create (non "add another"). No id is passed: the
     * created_site_id flash is only reliably readable at render time (the success
     * pane uses it), not inside this post-response closure.
     */
    onSaved?: () => void;
};

export function AddSiteDialog(props: AddSiteDialogProps) {
    // Re-mount the body each open so the form resets cleanly.
    return props.isOpen ? <AddSiteBody {...props} /> : null;
}

function AddSiteBody({ isOpen, onClose, onSaved, ...ref }: AddSiteDialogProps) {
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
                    onSaved?.();
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
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
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
                                            <Loader2 className="h-4 w-4 animate-spin" />{' '}
                                            Creating…
                                        </>
                                    ) : (
                                        <>
                                            <Check className="h-4 w-4" /> Create
                                            site
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
            return <StepRostering ctx={ctx} />;
        case 'contacts':
            return <StepContacts ctx={ctx} />;
        case 'equipment':
            return <StepEquipment ctx={ctx} />;
        case 'documents':
            return <StepDocuments ctx={ctx} />;
        case 'finance':
            return <StepFinance ctx={ctx} />;
        case 'review':
            return <StepReview ctx={ctx} />;
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
            <div className="flex flex-col gap-4">
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
                    label="Responsible staff member"
                    hint="current approved staff accountable for this Site"
                    span
                >
                    <SelectInput
                        value={data.primary_contact_user_id}
                        onChange={(v) => set('primary_contact_user_id', v)}
                        placeholder="Select current staff…"
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
                            Active sites lead the roster. You can deactivate
                            later.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step: Location (S3 shell — geofence + autocomplete land in S5)     */
/* ------------------------------------------------------------------ */

const BREACH_OPTIONS = [
    { value: 'enter', label: 'Enter' },
    { value: 'exit', label: 'Exit' },
    { value: 'both', label: 'Both' },
];

function StepLocation({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set, setMany, err } = ctx;
    // Bumped only when the centre changes (address pick) or the radius slider is
    // released — so the map remounts then, not on every keystroke/drag tick.
    const [mapKey, setMapKey] = useState(0);

    const lat = data.latitude ? Number(data.latitude) : null;
    const lng = data.longitude ? Number(data.longitude) : null;
    const hasCoords =
        lat != null && lng != null && !Number.isNaN(lat) && !Number.isNaN(lng);

    const updateCity = (city: string) => {
        const prev = deriveNzRegion(data.city);
        const nextRegion = deriveNzRegion(city);
        set('city', city);
        if (nextRegion && (!data.region || data.region === prev))
            set('region', nextRegion);
    };

    const onGeocode = (r: GeocodeResult) => {
        const patch: Partial<SiteWizardForm> = {};
        if (r.address_line_1) patch.address_line_1 = r.address_line_1;
        if (r.suburb) patch.suburb = r.suburb;
        if (r.city) patch.city = r.city;
        if (r.postcode) patch.postcode = r.postcode;
        if (r.country) patch.country = r.country;
        const region = r.region || (r.city ? deriveNzRegion(r.city) : null);
        if (region) patch.region = region;
        if (r.lat != null) patch.latitude = String(r.lat);
        if (r.lng != null) patch.longitude = String(r.lng);
        setMany(patch);
        setMapKey((k) => k + 1);
    };

    const onShapeChange = (shape: GeofenceShape | null) => {
        if (!shape || shape.type !== 'circle') return;
        const patch: Partial<SiteWizardForm> = {};
        if (shape.center) {
            patch.latitude = String(shape.center.lat);
            patch.longitude = String(shape.center.lng);
        }
        if (shape.radius_m)
            patch.geofence = {
                ...data.geofence,
                radius_m: Math.round(shape.radius_m),
            };
        setMany(patch);
    };

    const setRadius = (radius: number) =>
        set('geofence', { ...data.geofence, radius_m: radius });

    return (
        <div>
            <StepHead
                icon={MapPin}
                title="Where is this site?"
                blurb="Search the address to drop a pin, then size the geofence boundary."
            />
            <div className="grid gap-5 lg:grid-cols-2">
                {/* Address */}
                <div className="grid gap-4">
                    <SubHead icon={MapPin}>Address</SubHead>
                    <Field
                        label="Find address"
                        hint="type to search — powered by OpenStreetMap"
                        error={err('address_line_1')}
                    >
                        <AddressAutocomplete
                            value={data.address_line_1}
                            onChange={(v) => set('address_line_1', v)}
                            onSelect={onGeocode}
                            endpoint="/sites/geocode/search"
                            placeholder="Start typing an address…"
                        />
                    </Field>
                    <Field label="Address line 2">
                        <Input
                            value={data.address_line_2}
                            onChange={(e) =>
                                set('address_line_2', e.target.value)
                            }
                            placeholder="Apartment, unit (optional)"
                        />
                    </Field>
                    <div className="grid gap-3 sm:grid-cols-2">
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
                                    set(
                                        'postcode',
                                        e.target.value
                                            .replace(/\D/g, '')
                                            .slice(0, 4),
                                    )
                                }
                                placeholder="1010"
                            />
                        </Field>
                        <Field label="Region">
                            <SelectInput
                                value={data.region}
                                onChange={(v) => set('region', v)}
                                placeholder="Select region"
                                options={NZ_REGION_OPTIONS.map((r) => ({
                                    value: r,
                                    label: r,
                                }))}
                            />
                        </Field>
                    </div>
                    <Field label="Access instructions">
                        <Textarea
                            rows={2}
                            value={data.access_instructions}
                            onChange={(e) =>
                                set('access_instructions', e.target.value)
                            }
                            placeholder="Lockbox code, parking, gate access…"
                        />
                    </Field>
                </div>

                {/* Map + geofence */}
                <div className="grid content-start gap-3">
                    <SubHead icon={Shield}>Geofence</SubHead>
                    {hasCoords ? (
                        <>
                            <div className="overflow-hidden rounded-xl border border-border">
                                <GeofenceDrawMap
                                    key={mapKey}
                                    center={{
                                        lat: lat as number,
                                        lng: lng as number,
                                    }}
                                    zoom={16}
                                    height={240}
                                    initialShape={{
                                        type: 'circle',
                                        center: {
                                            lat: lat as number,
                                            lng: lng as number,
                                        },
                                        radius_m: data.geofence.radius_m,
                                    }}
                                    onShapeChange={onShapeChange}
                                />
                            </div>
                            <div className="rounded-lg border border-border bg-card/60 p-3">
                                <div className="mb-1.5 flex items-center justify-between text-[13px]">
                                    <span className="font-medium text-muted-foreground">
                                        Radius
                                    </span>
                                    <span className="font-semibold text-primary">
                                        {data.geofence.radius_m} m
                                    </span>
                                </div>
                                <input
                                    type="range"
                                    min={50}
                                    max={500}
                                    step={10}
                                    value={data.geofence.radius_m}
                                    onChange={(e) =>
                                        setRadius(Number(e.target.value))
                                    }
                                    onPointerUp={() => setMapKey((k) => k + 1)}
                                    onKeyUp={() => setMapKey((k) => k + 1)}
                                    className="w-full accent-primary"
                                    aria-label="Geofence radius in metres"
                                />
                            </div>
                            <Field label="Breach alerts">
                                <Segmented
                                    value={data.geofence.breach_type}
                                    onChange={(v) =>
                                        set('geofence', {
                                            ...data.geofence,
                                            breach_type:
                                                v as GeofenceForm['breach_type'],
                                        })
                                    }
                                    options={BREACH_OPTIONS}
                                />
                            </Field>
                            <label className="flex items-center gap-2.5 rounded-lg border border-border bg-muted/40 p-3">
                                <Switch
                                    checked={data.geofence.is_active}
                                    onCheckedChange={(v) =>
                                        set('geofence', {
                                            ...data.geofence,
                                            is_active: v,
                                        })
                                    }
                                />
                                <span className="text-[13px] text-muted-foreground">
                                    Geofence active (feeds breach alerts &amp;
                                    readiness)
                                </span>
                            </label>
                        </>
                    ) : (
                        <div className="grid h-[240px] place-items-center rounded-xl border border-dashed border-border bg-muted/30 px-6 text-center text-[13px] text-muted-foreground">
                            Search an address to drop a pin and draw the
                            geofence here.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step: Spaces                                                       */
/* ------------------------------------------------------------------ */

const ROOM_TYPE_OPTIONS = [
    { value: 'bedroom', label: 'Bedroom' },
    { value: 'communal', label: 'Communal / shared' },
];

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
            <div className="flex flex-col gap-4">
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
                            onChange={(e) =>
                                set('total_capacity', e.target.value)
                            }
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
                        makeEmpty={() => ({
                            name: '',
                            resource_type: 'meeting_room',
                            capacity: '',
                        })}
                        renderRow={(row, update) => (
                            <div className="grid gap-2 sm:grid-cols-[1.5fr_1fr_0.8fr]">
                                <Input
                                    value={row.name}
                                    onChange={(e) =>
                                        update({ name: e.target.value })
                                    }
                                    placeholder="Resource name"
                                />
                                <SelectInput
                                    value={row.resource_type}
                                    onChange={(v) =>
                                        update({ resource_type: v })
                                    }
                                    placeholder="Type"
                                    options={RESOURCE_TYPES}
                                />
                                <Input
                                    type="number"
                                    min={0}
                                    value={row.capacity}
                                    onChange={(e) =>
                                        update({ capacity: e.target.value })
                                    }
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
                                    onChange={(e) =>
                                        update({ name: e.target.value })
                                    }
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
                        empty="No rooms yet — add bedrooms and communal spaces like the lounge or kitchen."
                        makeEmpty={() => ({
                            name: '',
                            notes: '',
                            is_assignable: true,
                        })}
                        renderRow={(row, update) => (
                            <div className="grid gap-2 sm:grid-cols-[1.3fr_1fr_1.3fr]">
                                <Input
                                    value={row.name}
                                    onChange={(e) =>
                                        update({ name: e.target.value })
                                    }
                                    placeholder="e.g. Bedroom 1, Lounge"
                                />
                                <SelectInput
                                    value={
                                        row.is_assignable
                                            ? 'bedroom'
                                            : 'communal'
                                    }
                                    onChange={(v) =>
                                        update({
                                            is_assignable: v === 'bedroom',
                                        })
                                    }
                                    placeholder="Room type"
                                    options={ROOM_TYPE_OPTIONS}
                                />
                                <Input
                                    value={row.notes}
                                    onChange={(e) =>
                                        update({ notes: e.target.value })
                                    }
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
                            onChange(
                                rows.map((r, idx) =>
                                    idx === i ? { ...r, ...patch } : r,
                                ),
                            ),
                        )}
                    </div>
                    <button
                        type="button"
                        aria-label={`Remove ${title.toLowerCase()}`}
                        onClick={() =>
                            onChange(rows.filter((_, idx) => idx !== i))
                        }
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
                        No contacts yet. Add at least a site lead and an
                        emergency contact.
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
                                onChange={(e) =>
                                    update(i, { name: e.target.value })
                                }
                                placeholder="Full name"
                            />
                            <Input
                                value={c.role}
                                onChange={(e) =>
                                    update(i, { role: e.target.value })
                                }
                                placeholder="Role (optional)"
                            />
                            <Input
                                value={c.phone}
                                onChange={(e) =>
                                    update(i, { phone: e.target.value })
                                }
                                placeholder="Phone"
                            />
                            <Input
                                type="email"
                                value={c.email}
                                onChange={(e) =>
                                    update(i, { email: e.target.value })
                                }
                                placeholder="Email (optional)"
                            />
                        </div>
                        <div className="flex items-center justify-between">
                            <button
                                type="button"
                                onClick={() => setPrimary(i)}
                                className={cn(
                                    'inline-flex items-center gap-1.5 text-[13px] font-medium',
                                    c.is_primary
                                        ? 'text-primary'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Star
                                    className={cn(
                                        'h-3.5 w-3.5',
                                        c.is_primary && 'fill-primary',
                                    )}
                                />
                                {c.is_primary
                                    ? 'Primary contact'
                                    : 'Set as primary'}
                            </button>
                            <button
                                type="button"
                                aria-label="Remove contact"
                                onClick={() =>
                                    set(
                                        'contacts',
                                        contacts.filter((_, idx) => idx !== i),
                                    )
                                }
                                className="text-muted-foreground hover:text-status-critical"
                            >
                                <Trash2 className="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                ))}
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={add}
                    >
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
    const { data, set } = ctx;
    return (
        <div>
            <StepHead
                icon={Pill}
                title="Medication storage"
                blurb="Where medication is kept on site — this feeds eMAR and site readiness."
            />
            <div className="flex flex-col gap-4">
                <Field label="Medication storage location" span>
                    <Input
                        value={data.medication_storage_location}
                        onChange={(e) =>
                            set('medication_storage_location', e.target.value)
                        }
                        placeholder="e.g. Locked cabinet in the office"
                    />
                </Field>
                <p className="text-[13px] leading-relaxed text-muted-foreground">
                    Devices, assets and recurring checklists are set up from the
                    site profile once it&apos;s created.
                </p>
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

    const addFiles = (files: File[]) => {
        const drafts: SiteDocumentDraft[] = files.map((file) => ({
            file,
            title: file.name.replace(/\.[^.]+$/, ''),
            category: 'compliance',
            expiry_date: '',
        }));
        set('documents', [...data.documents, ...drafts]);
    };
    const update = (i: number, patch: Partial<SiteDocumentDraft>) =>
        set(
            'documents',
            data.documents.map((d, idx) =>
                idx === i ? { ...d, ...patch } : d,
            ),
        );
    const remove = (i: number) =>
        set(
            'documents',
            data.documents.filter((_, idx) => idx !== i),
        );

    return (
        <div>
            <StepHead
                icon={FileText}
                title="Documents"
                blurb="Drag in site certificates, the lease, evacuation plans and more."
            />
            <div className="grid gap-3">
                <FileDropzone
                    onFiles={addFiles}
                    hint="PDF, Word, images — up to 50 MB each"
                />

                {/* Staged files */}
                {data.documents.length > 0 ? (
                    <div className="grid gap-2">
                        {data.documents.map((d, i) => (
                            <StagedFileCard
                                key={i}
                                file={d.file}
                                onRemove={() => remove(i)}
                            >
                                <div className="grid gap-2 sm:grid-cols-[1.4fr_1fr_1fr]">
                                    <Input
                                        value={d.title}
                                        onChange={(e) =>
                                            update(i, { title: e.target.value })
                                        }
                                        placeholder="Title"
                                        className="h-8"
                                    />
                                    <SelectInput
                                        value={d.category}
                                        onChange={(v) =>
                                            update(i, { category: v })
                                        }
                                        placeholder="Category"
                                        options={DOCUMENT_CATEGORIES}
                                    />
                                    <Input
                                        type="date"
                                        value={d.expiry_date}
                                        onChange={(e) =>
                                            update(i, {
                                                expiry_date: e.target.value,
                                            })
                                        }
                                        className="h-8"
                                    />
                                </div>
                            </StagedFileCard>
                        ))}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  WIP step heads (built in S4 / S6)                                  */
/* ------------------------------------------------------------------ */

/* ---- rostering reference + presets (from the design prototype) ---- */

const COVERAGE_TYPE_OPTIONS = [
    { value: 'day', label: 'Day' },
    { value: 'evening', label: 'Evening' },
    { value: 'overnight', label: 'Overnight' },
    { value: 'custom', label: 'Custom' },
];
const SHIFT_TYPE_OPTIONS = [
    { value: 'standard', label: 'Standard' },
    { value: 'sleepover', label: 'Sleepover' },
    { value: 'on_call', label: 'On-call' },
    { value: 'split', label: 'Split' },
    { value: 'travel', label: 'Travel' },
];
const DAY_DEFS: { key: string; label: string }[] = [
    { key: 'mon', label: 'M' },
    { key: 'tue', label: 'T' },
    { key: 'wed', label: 'W' },
    { key: 'thu', label: 'T' },
    { key: 'fri', label: 'F' },
    { key: 'sat', label: 'S' },
    { key: 'sun', label: 'S' },
];
const ALL_WEEK = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri'];

const PRESETS: { key: string; label: string; desc: string }[] = [
    {
        key: '247',
        label: '24/7 staffed',
        desc: 'Day, evening & overnight, every day',
    },
    {
        key: 'wakingnights',
        label: 'Waking nights',
        desc: 'Awake overnight cover, every night',
    },
    { key: 'daysupport', label: 'Day support', desc: '7am–7pm, every day' },
    { key: 'office', label: 'Office hours', desc: 'Mon–Fri, 9–5' },
];

function emptyRoles() {
    return { caregiver: 0, driver: 0, med_competent: 0 };
}

function presetRows(key: string): CoverageRule[] {
    const base = {
        service_context_id: '',
    };
    if (key === '247')
        return [
            {
                ...base,
                name: 'Day cover',
                coverage_type: 'day',
                days: [...ALL_WEEK],
                starts_time: '07:00',
                ends_time: '15:00',
                minimum_staff: 2,
                shift_type: 'standard',
                allow_overstaffing: true,
                roles: { caregiver: 2, driver: 0, med_competent: 1 },
            },
            {
                ...base,
                name: 'Evening cover',
                coverage_type: 'evening',
                days: [...ALL_WEEK],
                starts_time: '15:00',
                ends_time: '23:00',
                minimum_staff: 2,
                shift_type: 'standard',
                allow_overstaffing: true,
                roles: { caregiver: 2, driver: 0, med_competent: 1 },
            },
            {
                ...base,
                name: 'Overnight cover',
                coverage_type: 'overnight',
                days: [...ALL_WEEK],
                starts_time: '23:00',
                ends_time: '07:00',
                minimum_staff: 1,
                shift_type: 'sleepover',
                allow_overstaffing: false,
                roles: { caregiver: 1, driver: 0, med_competent: 0 },
            },
        ];
    if (key === 'wakingnights')
        return [
            {
                ...base,
                name: 'Waking night',
                coverage_type: 'overnight',
                days: [...ALL_WEEK],
                starts_time: '22:00',
                ends_time: '06:00',
                minimum_staff: 1,
                shift_type: 'standard',
                allow_overstaffing: false,
                roles: { caregiver: 1, driver: 0, med_competent: 0 },
            },
        ];
    if (key === 'daysupport')
        return [
            {
                ...base,
                name: 'Day support',
                coverage_type: 'day',
                days: [...ALL_WEEK],
                starts_time: '07:00',
                ends_time: '19:00',
                minimum_staff: 1,
                shift_type: 'standard',
                allow_overstaffing: true,
                roles: { caregiver: 1, driver: 0, med_competent: 0 },
            },
        ];
    if (key === 'office')
        return [
            {
                ...base,
                name: 'Office hours',
                coverage_type: 'day',
                days: [...WEEKDAYS],
                starts_time: '09:00',
                ends_time: '17:00',
                minimum_staff: 1,
                shift_type: 'standard',
                allow_overstaffing: true,
                roles: emptyRoles(),
            },
        ];
    return [];
}

function emptyCoverageRule(): CoverageRule {
    return {
        name: 'Coverage rule',
        coverage_type: 'day',
        days: [...WEEKDAYS],
        starts_time: '09:00',
        ends_time: '17:00',
        minimum_staff: 1,
        shift_type: 'standard',
        allow_overstaffing: true,
        service_context_id: '',
        roles: emptyRoles(),
    };
}

/** Collapse a source site's per-day coverage rows into editable cards. */
function groupCopiedCoverage(rows: AddSiteCopyableCoverage[]): CoverageRule[] {
    const map = new Map<string, CoverageRule>();
    for (const r of rows) {
        const roles = emptyRoles();
        for (const rr of r.role_requirements ?? []) {
            if (rr.key in roles)
                roles[rr.key as keyof typeof roles] = rr.minimum;
        }
        const key = JSON.stringify([
            r.name,
            r.coverage_type,
            r.starts_time,
            r.ends_time,
            r.shift_type,
            r.allow_overstaffing,
            r.service_context_id,
            roles,
        ]);
        const existing = map.get(key);
        if (existing) {
            if (!existing.days.includes(r.day_of_week))
                existing.days.push(r.day_of_week);
        } else {
            map.set(key, {
                name: r.name,
                coverage_type:
                    (r.coverage_type as CoverageRule['coverage_type']) ??
                    'custom',
                days: [r.day_of_week],
                starts_time: r.starts_time,
                ends_time: r.ends_time,
                minimum_staff: r.minimum_staff,
                shift_type:
                    (r.shift_type as CoverageRule['shift_type']) ?? 'standard',
                allow_overstaffing: r.allow_overstaffing,
                service_context_id: r.service_context_id
                    ? String(r.service_context_id)
                    : '',
                roles,
            });
        }
    }
    // Keep days in week order.
    return Array.from(map.values()).map((c) => ({
        ...c,
        days: ALL_WEEK.filter((d) => c.days.includes(d)),
    }));
}

function credentialKeyForName(
    name: string,
    catalogue: AddSiteCredential[],
): string {
    const hit = catalogue.find(
        (c) => c.name.toLowerCase() === name.toLowerCase(),
    );
    return hit
        ? hit.key
        : name
              .toLowerCase()
              .replace(/[^a-z0-9]+/g, '_')
              .slice(0, 50);
}

/* ---- small controls ---- */

function Stepper({
    value,
    onChange,
    min = 0,
    max = 12,
    label,
}: {
    value: number;
    onChange: (v: number) => void;
    min?: number;
    max?: number;
    label?: string;
}) {
    const clamp = (v: number) => Math.max(min, Math.min(max, v));
    return (
        <div className="flex items-center justify-between gap-2">
            {label ? (
                <span className="text-[13px] text-muted-foreground">
                    {label}
                </span>
            ) : null}
            <div className="inline-flex items-center gap-1">
                <button
                    type="button"
                    aria-label={`Decrease ${label ?? ''}`.trim()}
                    onClick={() => onChange(clamp(value - 1))}
                    className="grid h-7 w-7 place-items-center rounded-md border border-border text-muted-foreground hover:bg-muted disabled:opacity-40"
                    disabled={value <= min}
                >
                    <Minus className="h-3.5 w-3.5" />
                </button>
                <span className="w-7 text-center text-sm font-semibold tabular-nums">
                    {value}
                </span>
                <button
                    type="button"
                    aria-label={`Increase ${label ?? ''}`.trim()}
                    onClick={() => onChange(clamp(value + 1))}
                    className="grid h-7 w-7 place-items-center rounded-md border border-border text-muted-foreground hover:bg-muted disabled:opacity-40"
                    disabled={value >= max}
                >
                    <Plus className="h-3.5 w-3.5" />
                </button>
            </div>
        </div>
    );
}

function DayChips({
    value,
    onChange,
}: {
    value: string[];
    onChange: (days: string[]) => void;
}) {
    const toggle = (k: string) =>
        onChange(
            value.includes(k) ? value.filter((d) => d !== k) : [...value, k],
        );
    return (
        <div className="inline-flex flex-wrap gap-1">
            {DAY_DEFS.map((d, i) => {
                const on = value.includes(d.key);
                return (
                    <button
                        key={`${d.key}-${i}`}
                        type="button"
                        aria-pressed={on}
                        aria-label={d.key}
                        onClick={() => toggle(d.key)}
                        className={cn(
                            'h-8 w-8 rounded-md border text-[13px] font-semibold transition-colors',
                            on
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border bg-card text-muted-foreground hover:border-primary/50',
                        )}
                    >
                        {d.label}
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step: Rostering & coverage                                        */
/* ------------------------------------------------------------------ */

function StepRostering({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set, ref, err } = ctx;

    const applyPreset = (key: string) =>
        set('coverage', [...data.coverage, ...presetRows(key)]);

    const copyFrom = (value: string) => {
        const source = ref.copyableSites.find((s) => String(s.id) === value);
        if (!source) {
            set('copy_from', '');
            return;
        }
        const credentials: CredentialRow[] = source.credentials.map((c) => ({
            key: credentialKeyForName(c.name, ref.credentialCatalogue),
            name: c.name,
            category:
                c.category === 'recommended' ? 'recommended' : 'mandatory',
            expiry_period_months: c.expiry_period_months ?? '',
        }));
        ctx.setMany({
            copy_from: value,
            coverage: groupCopiedCoverage(source.coverage),
            credentials,
        });
    };

    const updateRule = (i: number, patch: Partial<CoverageRule>) =>
        set(
            'coverage',
            data.coverage.map((r, idx) => (idx === i ? { ...r, ...patch } : r)),
        );
    const removeRule = (i: number) =>
        set(
            'coverage',
            data.coverage.filter((_, idx) => idx !== i),
        );

    const toggleCredential = (cat: AddSiteCredential) => {
        const has = data.credentials.some((c) => c.key === cat.key);
        if (has) {
            set(
                'credentials',
                data.credentials.filter((c) => c.key !== cat.key),
            );
        } else {
            set('credentials', [
                ...data.credentials,
                {
                    key: cat.key,
                    name: cat.name,
                    category: 'mandatory',
                    expiry_period_months: cat.default_expiry_months || '',
                },
            ]);
        }
    };
    const updateCredential = (key: string, patch: Partial<CredentialRow>) =>
        set(
            'credentials',
            data.credentials.map((c) =>
                c.key === key ? { ...c, ...patch } : c,
            ),
        );

    return (
        <div>
            <StepHead
                icon={CalendarClock}
                title="Rostering & coverage"
                blurb="Define who needs to be on site, when — plus the credentials staff must hold."
            />
            <div className="flex flex-col gap-6">
                {/* Copy a pattern */}
                {ref.copyableSites.length > 0 ? (
                    <Field
                        label="Copy a pattern"
                        hint="clone coverage & credentials from another site"
                        span
                    >
                        <SelectInput
                            value={data.copy_from}
                            onChange={copyFrom}
                            placeholder="Start from an existing site…"
                            options={ref.copyableSites.map((s) => ({
                                value: String(s.id),
                                label: s.name,
                            }))}
                        />
                    </Field>
                ) : null}

                {/* Presets */}
                <div>
                    <SubHead icon={Sparkles}>Quick coverage presets</SubHead>
                    <p className="mt-1 text-[11px] text-muted-foreground">
                        Tap one to add ready-made coverage rules, then tweak
                        them below.
                    </p>
                    <div className="mt-2.5 grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                        {PRESETS.map((p) => (
                            <button
                                key={p.key}
                                type="button"
                                onClick={() => applyPreset(p.key)}
                                className="flex items-start gap-2.5 rounded-xl border border-border bg-card/60 p-3.5 text-left transition-colors hover:border-primary/50 hover:bg-card"
                            >
                                <span className="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                                    <Plus className="h-4 w-4" />
                                </span>
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold">
                                        {p.label}
                                    </span>
                                    <span className="mt-0.5 block text-[12px] leading-snug text-muted-foreground">
                                        {p.desc}
                                    </span>
                                </span>
                            </button>
                        ))}
                    </div>
                </div>

                {/* Coverage requirements */}
                <div>
                    <SubHead icon={CalendarClock}>
                        Coverage requirements
                    </SubHead>
                    {err('coverage_days') ? (
                        <FieldErr>{err('coverage_days')}</FieldErr>
                    ) : null}
                    <div className="mt-2 grid gap-3">
                        {data.coverage.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-border p-5 text-center text-[13px] text-muted-foreground">
                                No coverage rules yet — pick a preset above or
                                add a custom rule.
                            </div>
                        ) : null}
                        {data.coverage.map((rule, i) => (
                            <CoverageCard
                                key={i}
                                rule={rule}
                                roleKeys={ref.coverageRoleKeys}
                                serviceContexts={ref.serviceContexts}
                                onChange={(patch) => updateRule(i, patch)}
                                onRemove={() => removeRule(i)}
                            />
                        ))}
                        <div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    set('coverage', [
                                        ...data.coverage,
                                        emptyCoverageRule(),
                                    ])
                                }
                            >
                                <Plus className="h-3.5 w-3.5" /> Add coverage
                                rule
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Required credentials */}
                <div>
                    <SubHead icon={ShieldCheck}>
                        Required staff credentials
                    </SubHead>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {ref.credentialCatalogue.map((cat) => {
                            const on = data.credentials.some(
                                (c) => c.key === cat.key,
                            );
                            return (
                                <button
                                    key={cat.key}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => toggleCredential(cat)}
                                    className={cn(
                                        'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                        on
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border bg-card text-foreground hover:border-primary/50',
                                    )}
                                >
                                    {on ? (
                                        <Check className="h-3 w-3" />
                                    ) : (
                                        <Shield className="h-3 w-3" />
                                    )}
                                    {cat.name}
                                </button>
                            );
                        })}
                    </div>
                    {data.credentials.length > 0 ? (
                        <div className="mt-3 grid gap-2">
                            {data.credentials.map((c) => (
                                <div
                                    key={c.key}
                                    className="grid items-center gap-2 rounded-lg border border-border bg-card/70 p-2.5 sm:grid-cols-[1.4fr_1.2fr_1fr]"
                                >
                                    <span className="text-[13px] font-semibold">
                                        {c.name}
                                    </span>
                                    <Segmented
                                        value={c.category}
                                        onChange={(v) =>
                                            updateCredential(c.key, {
                                                category: v,
                                            })
                                        }
                                        options={[
                                            {
                                                value: 'mandatory',
                                                label: 'Mandatory',
                                            },
                                            {
                                                value: 'recommended',
                                                label: 'Recommended',
                                            },
                                        ]}
                                    />
                                    <div className="flex items-center gap-1.5">
                                        <Input
                                            type="number"
                                            min={0}
                                            max={120}
                                            value={String(
                                                c.expiry_period_months ?? '',
                                            )}
                                            onChange={(e) =>
                                                updateCredential(c.key, {
                                                    expiry_period_months:
                                                        e.target.value,
                                                })
                                            }
                                            placeholder="—"
                                            className="h-8"
                                        />
                                        <span className="shrink-0 text-[11px] text-muted-foreground">
                                            mo. expiry
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

function CoverageCard({
    rule,
    roleKeys,
    serviceContexts,
    onChange,
    onRemove,
}: {
    rule: CoverageRule;
    roleKeys: AddSiteRoleKey[];
    serviceContexts: AddSiteServiceContext[];
    onChange: (patch: Partial<CoverageRule>) => void;
    onRemove: () => void;
}) {
    return (
        <div className="rounded-xl border border-border bg-card/70 p-3.5">
            <div className="mb-3 flex items-center gap-2">
                <Input
                    value={rule.name}
                    onChange={(e) => onChange({ name: e.target.value })}
                    placeholder="Rule name"
                    className="h-8 flex-1 font-semibold"
                />
                <button
                    type="button"
                    aria-label="Remove coverage rule"
                    onClick={onRemove}
                    className="text-muted-foreground hover:text-status-critical"
                >
                    <Trash2 className="h-4 w-4" />
                </button>
            </div>

            <div className="grid gap-3">
                <Field label="Days">
                    <DayChips
                        value={rule.days}
                        onChange={(days) => onChange({ days })}
                    />
                </Field>

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Coverage type">
                        <SelectInput
                            value={rule.coverage_type}
                            onChange={(v) =>
                                onChange({
                                    coverage_type:
                                        v as CoverageRule['coverage_type'],
                                })
                            }
                            placeholder="Type"
                            options={COVERAGE_TYPE_OPTIONS}
                        />
                    </Field>
                    <Field label="Shift type">
                        <SelectInput
                            value={rule.shift_type}
                            onChange={(v) =>
                                onChange({
                                    shift_type: v as CoverageRule['shift_type'],
                                })
                            }
                            placeholder="Shift type"
                            options={SHIFT_TYPE_OPTIONS}
                        />
                    </Field>
                    <Field label="Start time">
                        <Input
                            type="time"
                            value={rule.starts_time}
                            onChange={(e) =>
                                onChange({ starts_time: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="End time">
                        <Input
                            type="time"
                            value={rule.ends_time}
                            onChange={(e) =>
                                onChange({ ends_time: e.target.value })
                            }
                        />
                    </Field>
                </div>

                <div className="grid gap-3 rounded-lg border border-border bg-muted/30 p-3 sm:grid-cols-2">
                    <Field label="Minimum staff">
                        <Stepper
                            value={rule.minimum_staff}
                            min={1}
                            max={12}
                            onChange={(v) => onChange({ minimum_staff: v })}
                        />
                    </Field>
                    <div className="grid gap-1.5">
                        <span className="text-[13px] font-medium text-muted-foreground">
                            Role mix
                        </span>
                        {roleKeys.map((rk) => (
                            <Stepper
                                key={rk.key}
                                label={rk.label}
                                value={
                                    rule.roles[
                                        rk.key as keyof CoverageRule['roles']
                                    ] ?? 0
                                }
                                min={0}
                                max={12}
                                onChange={(v) =>
                                    onChange({
                                        roles: { ...rule.roles, [rk.key]: v },
                                    })
                                }
                            />
                        ))}
                    </div>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <label className="flex items-center gap-2.5">
                        <Switch
                            checked={rule.allow_overstaffing}
                            onCheckedChange={(v) =>
                                onChange({ allow_overstaffing: v })
                            }
                        />
                        <span className="text-[13px] text-muted-foreground">
                            Allow overstaffing
                        </span>
                    </label>
                    {serviceContexts.length > 0 ? (
                        <div className="min-w-[200px]">
                            <SelectInput
                                value={rule.service_context_id}
                                onChange={(v) =>
                                    onChange({ service_context_id: v })
                                }
                                placeholder="Service (optional)"
                                options={serviceContexts.map((s) => ({
                                    value: String(s.id),
                                    label: s.name,
                                }))}
                            />
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
const RENT_FREQUENCIES = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'fortnightly', label: 'Fortnightly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'annually', label: 'Annually' },
];

function StepFinance({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set, err } = ctx;
    return (
        <div>
            <StepHead
                icon={Wallet}
                title="Property & finance"
                blurb="Tenancy, lease and budget — all optional, captured here so nothing's missed."
            />
            <div className="flex flex-col gap-4">
                <SubHead icon={Wallet}>Tenancy</SubHead>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Rent amount" hint="$">
                        <Input
                            type="number"
                            min={0}
                            step="0.01"
                            value={data.rent_amount}
                            onChange={(e) => set('rent_amount', e.target.value)}
                            placeholder="0.00"
                        />
                    </Field>
                    <Field label="Rent frequency">
                        <SelectInput
                            value={data.rent_frequency}
                            onChange={(v) =>
                                set(
                                    'rent_frequency',
                                    v as SiteWizardForm['rent_frequency'],
                                )
                            }
                            placeholder="Frequency"
                            options={RENT_FREQUENCIES}
                        />
                    </Field>
                    <Field label="Lease start">
                        <Input
                            type="date"
                            value={data.lease_start_date}
                            onChange={(e) =>
                                set('lease_start_date', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Lease end" error={err('lease_end_date')}>
                        <Input
                            type="date"
                            value={data.lease_end_date}
                            onChange={(e) =>
                                set('lease_end_date', e.target.value)
                            }
                            aria-invalid={!!err('lease_end_date')}
                        />
                    </Field>
                    <Field label="Landlord name">
                        <Input
                            value={data.landlord_name}
                            onChange={(e) =>
                                set('landlord_name', e.target.value)
                            }
                            placeholder="e.g. Acme Property"
                        />
                    </Field>
                    <Field label="Landlord contact">
                        <Input
                            value={data.landlord_contact}
                            onChange={(e) =>
                                set('landlord_contact', e.target.value)
                            }
                            placeholder="Phone or email"
                        />
                    </Field>
                </div>

                <SubHead icon={Wallet}>Budgets</SubHead>
                <Field label="Weekly food budget" hint="$ per week" span>
                    <Input
                        type="number"
                        min={0}
                        step="0.01"
                        value={data.weekly_food_budget}
                        onChange={(e) =>
                            set('weekly_food_budget', e.target.value)
                        }
                        placeholder="0.00"
                        className="max-w-[200px]"
                    />
                </Field>
            </div>
        </div>
    );
}

function RiskTile({
    on,
    onToggle,
    tone,
    title,
    desc,
}: {
    on: boolean;
    onToggle: () => void;
    tone: 'critical' | 'warning';
    title: string;
    desc: string;
}) {
    const toneCls = on
        ? tone === 'critical'
            ? 'border-status-critical bg-status-critical-bg'
            : 'border-status-warning bg-status-warning-bg'
        : 'border-border bg-card/50 hover:border-primary/40';
    const iconCls =
        tone === 'critical' ? 'text-status-critical' : 'text-status-warning';
    return (
        <button
            type="button"
            aria-pressed={on}
            onClick={onToggle}
            className={cn(
                'flex items-start gap-2.5 rounded-lg border p-3 text-left transition-colors',
                toneCls,
            )}
        >
            <span
                className={cn(
                    'mt-0.5 shrink-0',
                    on ? iconCls : 'text-muted-foreground',
                )}
            >
                {on ? (
                    <Check className="h-4 w-4" />
                ) : (
                    <Shield className="h-4 w-4" />
                )}
            </span>
            <span className="min-w-0">
                <span className="block text-sm font-semibold">{title}</span>
                <span className="mt-0.5 block text-xs leading-snug text-muted-foreground">
                    {desc}
                </span>
            </span>
        </button>
    );
}

function StepReview({ ctx }: { ctx: SiteStepCtx }) {
    const { data, set, ref, goToStep } = ctx;
    const showRisk = data.is_high_risk || data.is_high_needs;
    const leadName =
        ref.users.find((u) => String(u.id) === data.primary_contact_user_id)
            ?.name ?? null;
    const typeLabel =
        SITE_TYPES.find((t) => t.value === data.type)?.label ?? data.type;
    const spaceCount =
        data.rooms.length + data.resources.length + data.zones.length;
    const addr = [data.address_line_1, data.suburb, data.city, data.postcode]
        .filter(Boolean)
        .join(', ');
    const money = (v: string) =>
        v
            ? `$${Number(v).toLocaleString('en-NZ', { minimumFractionDigits: 2 })}`
            : null;

    return (
        <div>
            <StepHead
                icon={CheckCircle2}
                title="Review & safety"
                blurb="Flag risk, note the emergency plan, then create the site."
            />
            <div className="grid gap-4">
                {/* Risk flags */}
                <div className="grid gap-2 sm:grid-cols-2">
                    <RiskTile
                        on={data.is_high_risk}
                        onToggle={() => set('is_high_risk', !data.is_high_risk)}
                        tone="critical"
                        title="High-risk site"
                        desc="Elevated safety risk — flags across the app."
                    />
                    <RiskTile
                        on={data.is_high_needs}
                        onToggle={() =>
                            set('is_high_needs', !data.is_high_needs)
                        }
                        tone="warning"
                        title="High-needs site"
                        desc="Complex support needs — extra attention."
                    />
                </div>
                {showRisk ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Risk notes" span>
                            <Textarea
                                rows={2}
                                value={data.risk_notes}
                                onChange={(e) =>
                                    set('risk_notes', e.target.value)
                                }
                                placeholder="What's the risk, and how is it managed?"
                            />
                        </Field>
                        <Field label="Risk review date">
                            <Input
                                type="date"
                                value={data.risk_review_date}
                                onChange={(e) =>
                                    set('risk_review_date', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Emergency / evacuation plan location" span>
                        <Input
                            value={data.emergency_plan_location}
                            onChange={(e) =>
                                set('emergency_plan_location', e.target.value)
                            }
                            placeholder="e.g. Reception noticeboard"
                        />
                    </Field>
                    <Field label="Site notes" span>
                        <Textarea
                            rows={2}
                            value={data.notes}
                            onChange={(e) => set('notes', e.target.value)}
                            placeholder="Anything else the team should know."
                        />
                    </Field>
                </div>

                {/* Summary */}
                <div className="grid gap-3 sm:grid-cols-2">
                    <ReviewCard
                        icon={Building2}
                        title="Basics"
                        onEdit={() => goToStep('basics')}
                    >
                        <ReviewRow label="Type" value={typeLabel} />
                        <ReviewRow label="Name" value={data.name} />
                        <ReviewRow label="Lead" value={leadName} />
                        <ReviewRow
                            label="Status"
                            value={data.is_active ? 'Active' : 'Inactive'}
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={MapPin}
                        title="Location"
                        onEdit={() => goToStep('location')}
                    >
                        <ReviewRow label="Address" value={addr} />
                        <ReviewRow label="Region" value={data.region} />
                        <ReviewRow
                            label="Geofence"
                            value={
                                data.latitude && data.longitude
                                    ? `${data.geofence.radius_m} m · ${data.geofence.breach_type}`
                                    : null
                            }
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={Package}
                        title="Spaces"
                        onEdit={() => goToStep('spaces')}
                    >
                        <ReviewRow
                            label="Rooms / resources / zones"
                            value={spaceCount > 0 ? String(spaceCount) : null}
                        />
                        <ReviewRow
                            label="Total capacity"
                            value={data.total_capacity}
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={CalendarClock}
                        title="Rostering"
                        onEdit={() => goToStep('rostering')}
                    >
                        <ReviewRow
                            label="Coverage rules"
                            value={
                                data.coverage.length > 0
                                    ? String(data.coverage.length)
                                    : null
                            }
                        />
                        <ReviewRow
                            label="Credentials"
                            value={
                                data.credentials.length > 0
                                    ? `${data.credentials.length} required`
                                    : null
                            }
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={Users}
                        title="Contacts & equipment"
                        onEdit={() => goToStep('contacts')}
                    >
                        <ReviewRow
                            label="Contacts"
                            value={
                                data.contacts.length > 0
                                    ? String(data.contacts.length)
                                    : null
                            }
                        />
                        <ReviewRow
                            label="Assets linked"
                            value={
                                data.assets.length > 0
                                    ? String(data.assets.length)
                                    : null
                            }
                        />
                        <ReviewRow
                            label="Medication storage"
                            value={data.medication_storage_location}
                        />
                    </ReviewCard>
                    <ReviewCard
                        icon={Wallet}
                        title="Property & finance"
                        onEdit={() => goToStep('finance')}
                    >
                        <ReviewRow
                            label="Rent"
                            value={
                                money(data.rent_amount)
                                    ? `${money(data.rent_amount)} / ${data.rent_frequency}`
                                    : null
                            }
                        />
                        <ReviewRow
                            label="Lease"
                            value={data.lease_start_date}
                        />
                        <ReviewRow
                            label="Weekly food budget"
                            value={money(data.weekly_food_budget)}
                        />
                    </ReviewCard>
                </div>
            </div>
        </div>
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
                            if (siteId)
                                window.location.href = `/sites/${siteId}`;
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
