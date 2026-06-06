/* eslint-disable no-restricted-syntax -- The Add Client wizard is a bespoke,
 * full-height modal surface (stepper rail + scroll-contained body + custom
 * footer) that intentionally uses styled native controls for the tile pickers,
 * chips, segmented controls and toggles. Every colour is sourced from semantic
 * design tokens (never hardcoded hex), per docs/DESIGN_TOKENS.md. */
import {
    AddressAutocomplete,
    type GeocodeResult,
} from '@/components/address-autocomplete';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    StatusBadge,
    type StatusVariant,
} from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { useForm, usePage } from '@inertiajs/react';
import {
    Accessibility,
    Activity,
    AlertTriangle,
    Cake,
    Calendar,
    Camera,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    Clock,
    Droplet,
    FileText,
    Footprints,
    Globe,
    Heart,
    HeartPulse,
    Home,
    IdCard,
    Info,
    KeyRound,
    Loader2,
    Mail,
    MapPin,
    Pencil,
    Phone,
    Plus,
    PartyPopper,
    Shield,
    ShieldAlert,
    Stethoscope,
    Trash2,
    Truck,
    User,
    UserPlus,
    Wallet,
    X,
} from 'lucide-react';
import {
    useMemo,
    useRef,
    useState,
    type ComponentType,
    type ReactNode,
} from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Option = { id: number; name: string };
type ServiceContextOption = { id: number; type?: string | null; name: string };

type ConditionRow = {
    label: string;
    severity: 'Mild' | 'Moderate' | 'Severe';
    notes: string;
};

type ContactRow = {
    name: string;
    relationship: string;
    phone: string;
    alternate_phone: string;
    email: string;
    address: string;
    preferred_method: 'phone' | 'text' | 'email';
    availability: string;
    notes: string;
    can_view_medical: boolean;
    can_view_medications: boolean;
    can_view_incidents: boolean;
    can_receive_updates: boolean;
};

type MedicalShape = {
    gp_name: string;
    gp_practice: string;
    gp_phone: string;
    hospital_preference: string;
    blood_type: string;
    organ_donor: boolean;
    allergies: string[];
    disabilities: string[];
    medical_history: string;
    mental_health_history: string;
    surgical_history: string;
    immunisation_notes: string;
    notes: string;
};

type ClientWizardForm = {
    _modal: boolean;
    // basics
    site_id: string;
    service_context_id: string;
    status: string;
    first_name: string;
    last_name: string;
    preferred_name: string;
    date_of_birth: string;
    gender: string;
    preferred_pronouns: string;
    nhi_number: string;
    phone: string;
    email: string;
    profile_photo: File | null;
    address_line_1: string;
    address_line_2: string;
    suburb: string;
    city: string;
    postcode: string;
    create_client_portal_user: boolean;
    // cultural
    ethnicity: string;
    languages: string[];
    religion: string;
    // support
    mobility_needs: string;
    sensory_needs: string;
    cognitive_needs: string;
    dietary_requirements: string;
    sleep_preferences: string;
    transport_needs: string[];
    transport_notes: string;
    fluid_intake_min_ml: string;
    fluid_intake_max_ml: string;
    seizure_duration_escalation_seconds: string;
    // about
    interests_hobbies: string;
    strengths_abilities: string;
    life_story: string;
    education_level: string;
    employment_status: string;
    // health
    medical: MedicalShape;
    conditions: ConditionRow[];
    // care
    service_start_date: string;
    key_worker_id: string;
    risk_level: string;
    safeguarding_flag: boolean;
    house_geofence_id: string;
    funding_type: string;
    funding_notes: string;
    // contacts
    emergency_contacts: ContactRow[];
};

type StepKey =
    | 'basics'
    | 'cultural'
    | 'support'
    | 'health'
    | 'about'
    | 'care'
    | 'contacts'
    | 'review';

type IconType = ComponentType<{ className?: string }>;

/* ------------------------------------------------------------------ */
/*  Reference data (NZ supported-living context)                       */
/* ------------------------------------------------------------------ */

const PRONOUNS = [
    'she/her',
    'he/him',
    'they/them',
    'Prefer to self-describe',
    'Prefer not to say',
];
const ETHNICITIES = [
    'NZ European / Pākehā',
    'Māori',
    'Samoan',
    'Cook Islands Māori',
    'Tongan',
    'Niuean',
    'Chinese',
    'Indian',
    'Other Asian',
    'Middle Eastern',
    'Latin American',
    'African',
    'Other',
];
const LANGUAGES = [
    'English',
    'Te Reo Māori',
    'NZ Sign Language',
    'Samoan',
    'Tongan',
    'Mandarin',
    'Hindi',
    'Tagalog',
    'Korean',
    'Other',
];
const RELIGIONS = [
    'No religion',
    'Christian',
    'Catholic',
    'Anglican',
    'Rātana',
    'Ringatū',
    'Hindu',
    'Muslim',
    'Buddhist',
    'Other',
];
const EDUCATION = [
    'Still at school',
    'No formal qualification',
    'NCEA Level 1–3',
    'Tertiary certificate / diploma',
    "Bachelor's or higher",
    'Unknown',
];
const EMPLOYMENT = [
    'Not employed',
    'Supported employment',
    'Open employment — part time',
    'Open employment — full time',
    'Volunteer',
    'Day programme',
    'Retired',
];
const ALLERGIES = [
    'Penicillin',
    'Peanuts',
    'Tree nuts',
    'Shellfish',
    'Eggs',
    'Dairy',
    'Latex',
    'Bee stings',
    'Gluten',
    'Aspirin',
];
const DISABILITIES = [
    'Intellectual disability',
    'Autism spectrum',
    'Cerebral palsy',
    'Down syndrome',
    'Physical disability',
    'Vision impairment',
    'Hearing impairment',
    'Deafblind',
    'Acquired brain injury',
    'Epilepsy',
    'Mental health condition',
];
const BLOOD_TYPES = ['A+', 'A−', 'B+', 'B−', 'AB+', 'AB−', 'O+', 'O−', 'Unknown'];
const TRANSPORT_OPTIONS = [
    'Wheelchair-accessible vehicle',
    'Mobility seat / harness',
    'Travel companion required',
    'Public transport with support',
    'Own vehicle',
    'No specific needs',
];
const FUNDING_OPTIONS = [
    'Whaikaha',
    'Carer Support',
    'NASC-allocated',
    'EGL / Individualised Funding',
    'ACC',
    'Te Whatu Ora',
    'MSD',
    'Private',
    'Other',
];

const STEPS: { key: StepKey; label: string; icon: IconType; blurb: string }[] = [
    { key: 'basics', label: 'Basics', icon: IdCard, blurb: 'Identity, placement & contact' },
    { key: 'cultural', label: 'Cultural identity', icon: Globe, blurb: 'Whakapapa, language & beliefs' },
    { key: 'support', label: 'Support needs', icon: Accessibility, blurb: 'How we keep them safe & well' },
    { key: 'health', label: 'Health & medical', icon: Stethoscope, blurb: 'GP, allergies & conditions' },
    { key: 'about', label: 'About me', icon: Heart, blurb: 'The person, not the file' },
    { key: 'care', label: 'Care setup', icon: ClipboardCheck, blurb: 'Risk, key worker & funding' },
    { key: 'contacts', label: 'Contacts & consent', icon: Phone, blurb: 'Who to call, and what they see' },
    { key: 'review', label: 'Review & create', icon: CheckCircle2, blurb: 'Confirm and save' },
];

// Fields that count toward the "profile completeness" meter.
const COMPLETION_FIELDS: (keyof ClientWizardForm)[] = [
    'site_id', 'service_context_id', 'first_name', 'last_name', 'preferred_name',
    'date_of_birth', 'gender', 'preferred_pronouns', 'nhi_number', 'phone', 'email',
    'address_line_1', 'suburb', 'city', 'postcode', 'ethnicity', 'languages', 'religion',
    'mobility_needs', 'sensory_needs', 'cognitive_needs', 'dietary_requirements',
    'sleep_preferences', 'transport_needs', 'interests_hobbies', 'strengths_abilities',
    'life_story', 'education_level', 'employment_status', 'service_start_date',
    'key_worker_id', 'house_geofence_id', 'funding_type',
];
const COMPLETION_MEDICAL: (keyof MedicalShape)[] = [
    'gp_name', 'gp_phone', 'blood_type', 'allergies', 'disabilities',
    'medical_history', 'mental_health_history',
];

function isFilled(v: unknown): boolean {
    if (Array.isArray(v)) return v.length > 0;
    return v !== '' && v != null && v !== false;
}

function completionPct(data: ClientWizardForm): number {
    const flat = COMPLETION_FIELDS.filter((k) => isFilled(data[k])).length;
    const med = COMPLETION_MEDICAL.filter((k) => isFilled(data.medical[k])).length;
    const hasContact = data.emergency_contacts.some((c) => c.name && c.phone);
    const total = COMPLETION_FIELDS.length + COMPLETION_MEDICAL.length + 1;
    return Math.round(((flat + med + (hasContact ? 1 : 0)) / total) * 100);
}

function ageFromDob(dob: string): number | null {
    if (!dob) return null;
    const d = new Date(dob);
    if (Number.isNaN(d.getTime())) return null;
    const now = new Date();
    let a = now.getFullYear() - d.getFullYear();
    const m = now.getMonth() - d.getMonth();
    if (m < 0 || (m === 0 && now.getDate() < d.getDate())) a -= 1;
    return a >= 0 && a < 130 ? a : null;
}

function nhiState(v: string): 'ok' | 'bad' | null {
    if (!v) return null;
    return /^[A-Z]{3}\d{4}$/i.test(v) ? 'ok' : 'bad';
}

function emptyContact(): ContactRow {
    return {
        name: '', relationship: '', phone: '', alternate_phone: '', email: '',
        address: '', preferred_method: 'phone', availability: '', notes: '',
        can_view_medical: false, can_view_medications: false,
        can_view_incidents: false, can_receive_updates: true,
    };
}

function emptyMedical(): MedicalShape {
    return {
        gp_name: '', gp_practice: '', gp_phone: '', hospital_preference: '',
        blood_type: '', organ_donor: false, allergies: [], disabilities: [],
        medical_history: '', mental_health_history: '', surgical_history: '',
        immunisation_notes: '', notes: '',
    };
}

function initialForm(defaultServiceContextId?: number | null): ClientWizardForm {
    return {
        _modal: true,
        site_id: '', service_context_id: defaultServiceContextId ? String(defaultServiceContextId) : '',
        status: 'onboarding', first_name: '', last_name: '', preferred_name: '',
        date_of_birth: '', gender: '', preferred_pronouns: '', nhi_number: '',
        phone: '', email: '', profile_photo: null,
        address_line_1: '', address_line_2: '', suburb: '', city: '', postcode: '',
        create_client_portal_user: false,
        ethnicity: '', languages: [], religion: '',
        mobility_needs: '', sensory_needs: '', cognitive_needs: '',
        dietary_requirements: '', sleep_preferences: '',
        transport_needs: [], transport_notes: '',
        fluid_intake_min_ml: '', fluid_intake_max_ml: '', seizure_duration_escalation_seconds: '',
        interests_hobbies: '', strengths_abilities: '', life_story: '',
        education_level: '', employment_status: '',
        medical: emptyMedical(), conditions: [],
        service_start_date: '', key_worker_id: '', risk_level: 'low',
        safeguarding_flag: false, house_geofence_id: '', funding_type: '', funding_notes: '',
        emergency_contacts: [emptyContact()],
    };
}

// Which wizard step a (server) validation error belongs to.
const STEP_FOR_PREFIX: { prefix: string; step: StepKey }[] = [
    { prefix: 'medical', step: 'health' },
    { prefix: 'conditions', step: 'health' },
    { prefix: 'emergency_contacts', step: 'contacts' },
    { prefix: 'ethnicity', step: 'cultural' },
    { prefix: 'languages', step: 'cultural' },
    { prefix: 'religion', step: 'cultural' },
    { prefix: 'mobility_needs', step: 'support' },
    { prefix: 'sensory_needs', step: 'support' },
    { prefix: 'cognitive_needs', step: 'support' },
    { prefix: 'dietary_requirements', step: 'support' },
    { prefix: 'sleep_preferences', step: 'support' },
    { prefix: 'transport_', step: 'support' },
    { prefix: 'fluid_', step: 'support' },
    { prefix: 'seizure_', step: 'support' },
    { prefix: 'interests_hobbies', step: 'about' },
    { prefix: 'strengths_abilities', step: 'about' },
    { prefix: 'life_story', step: 'about' },
    { prefix: 'education_level', step: 'about' },
    { prefix: 'employment_status', step: 'about' },
    { prefix: 'service_start_date', step: 'care' },
    { prefix: 'key_worker_id', step: 'care' },
    { prefix: 'risk_level', step: 'care' },
    { prefix: 'safeguarding_flag', step: 'care' },
    { prefix: 'house_geofence_id', step: 'care' },
    { prefix: 'funding_', step: 'care' },
];

function stepForError(field: string): StepKey {
    for (const { prefix, step } of STEP_FOR_PREFIX) {
        if (field.startsWith(prefix)) return step;
    }
    return 'basics';
}

/* ------------------------------------------------------------------ */
/*  Shared primitives                                                  */
/* ------------------------------------------------------------------ */

function FieldErr({ children }: { children?: ReactNode }) {
    if (!children) return null;
    return (
        <p className="mt-1 flex items-center gap-1 text-xs text-status-critical">
            <AlertTriangle className="h-3 w-3 shrink-0" />
            {children}
        </p>
    );
}

function Field({
    label,
    required,
    hint,
    error,
    span,
    children,
}: {
    label?: string;
    required?: boolean;
    hint?: string;
    error?: string;
    span?: boolean;
    children: ReactNode;
}) {
    return (
        <div className={cn('min-w-0', span && 'sm:col-span-2')}>
            {label ? (
                <Label className="mb-1.5 flex items-center gap-1.5">
                    {label}
                    {required ? (
                        <span className="text-status-critical">*</span>
                    ) : null}
                    {hint ? (
                        <span className="text-xs font-normal text-muted-foreground">
                            {hint}
                        </span>
                    ) : null}
                </Label>
            ) : null}
            {children}
            <FieldErr>{error}</FieldErr>
        </div>
    );
}

function SubHead({ icon: Icon, children }: { icon: IconType; children: ReactNode }) {
    return (
        <div className="col-span-full mt-1 flex items-center gap-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">
            <Icon className="h-3.5 w-3.5" />
            {children}
        </div>
    );
}

function StepHead({
    icon: Icon,
    title,
    blurb,
}: {
    icon: IconType;
    title: string;
    blurb: string;
}) {
    return (
        <div className="mb-5 flex items-start gap-3">
            <span className="shrink-0 rounded-xl bg-primary/10 p-2.5 text-primary">
                <Icon className="h-5 w-5" />
            </span>
            <div>
                <h2 className="text-lg font-bold tracking-tight">{title}</h2>
                <p className="mt-0.5 text-sm text-muted-foreground">{blurb}</p>
            </div>
        </div>
    );
}

function InfoCard({
    icon: Icon,
    tone = 'info',
    children,
}: {
    icon: IconType;
    tone?: 'info' | 'warn' | 'crit';
    children: ReactNode;
}) {
    const tones = {
        info: 'border-primary/35 bg-primary/10 text-primary',
        warn: 'border-status-warning/35 bg-status-warning-bg text-status-warning',
        crit: 'border-status-critical/35 bg-status-critical-bg text-status-critical',
    }[tone];
    return (
        <div className={cn('col-span-full flex gap-2.5 rounded-lg border p-3', tones)}>
            <Icon className="mt-0.5 h-4 w-4 shrink-0" />
            <div className="text-[13px] leading-relaxed text-foreground">
                {children}
            </div>
        </div>
    );
}

function SelectInput({
    value,
    onChange,
    placeholder,
    options,
}: {
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
    options: { value: string; label: string }[];
}) {
    return (
        <Select value={value || undefined} onValueChange={onChange}>
            <SelectTrigger className="w-full">
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                {options.map((o) => (
                    <SelectItem key={o.value} value={o.value}>
                        {o.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function Segmented<T extends string>({
    value,
    onChange,
    options,
}: {
    value: T;
    onChange: (v: T) => void;
    options: { value: T; label: string; icon?: IconType }[];
}) {
    return (
        <div className="inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1">
            {options.map((o) => {
                const active = value === o.value;
                const Icon = o.icon;
                return (
                    <button
                        key={o.value}
                        type="button"
                        onClick={() => onChange(o.value)}
                        aria-pressed={active}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-[13px] font-semibold transition-colors',
                            active
                                ? 'bg-card text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {Icon ? <Icon className="h-3.5 w-3.5" /> : null}
                        {o.label}
                    </button>
                );
            })}
        </div>
    );
}

function ChipMulti({
    values,
    onChange,
    options,
}: {
    values: string[];
    onChange: (v: string[]) => void;
    options: string[];
}) {
    const toggle = (o: string) =>
        onChange(values.includes(o) ? values.filter((v) => v !== o) : [...values, o]);
    return (
        <div className="flex flex-wrap gap-1.5">
            {options.map((o) => {
                const active = values.includes(o);
                return (
                    <button
                        key={o}
                        type="button"
                        aria-pressed={active}
                        onClick={() => toggle(o)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                            active
                                ? 'border-primary bg-primary/10 text-primary'
                                : 'border-border bg-card text-foreground hover:border-primary/50',
                        )}
                    >
                        {active ? <Check className="h-3 w-3" /> : null}
                        {o}
                    </button>
                );
            })}
        </div>
    );
}

function TilePicker({
    value,
    onChange,
    options,
    cols = 2,
}: {
    value: string;
    onChange: (v: string) => void;
    options: { key: string; label: string; description?: string; icon?: IconType; accent?: string }[];
    cols?: 2 | 3;
}) {
    return (
        <div
            className={cn(
                'grid gap-2',
                cols === 3 ? 'grid-cols-2 sm:grid-cols-3' : 'grid-cols-1 sm:grid-cols-2',
            )}
        >
            {options.map((o) => {
                const Icon = o.icon;
                const active = value === o.key;
                return (
                    <button
                        key={o.key}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(o.key)}
                        className={cn(
                            'flex items-start gap-2.5 rounded-lg border bg-card/50 p-3 text-left transition-all hover:border-primary/50 hover:bg-card focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                            active
                                ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                : 'border-border',
                        )}
                    >
                        {Icon ? (
                            <span
                                className={cn(
                                    'mt-0.5 shrink-0 rounded-lg p-1.5',
                                    active ? 'bg-primary/15' : 'bg-muted',
                                )}
                            >
                                <Icon
                                    className={cn(
                                        'h-4 w-4',
                                        active ? 'text-primary' : o.accent ?? 'text-muted-foreground',
                                    )}
                                />
                            </span>
                        ) : null}
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold">{o.label}</span>
                            {o.description ? (
                                <span className="mt-0.5 block text-xs leading-snug text-muted-foreground">
                                    {o.description}
                                </span>
                            ) : null}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}

function ConsentChip({
    on,
    onToggle,
    icon: Icon,
    label,
}: {
    on: boolean;
    onToggle: () => void;
    icon: IconType;
    label: string;
}) {
    return (
        <button
            type="button"
            aria-pressed={on}
            onClick={onToggle}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                on
                    ? 'border-primary bg-primary/10 text-primary'
                    : 'border-border bg-card text-muted-foreground hover:border-primary/50',
            )}
        >
            {on ? <Check className="h-3 w-3" /> : <Icon className="h-3 w-3" />}
            {label}
        </button>
    );
}

function Ring({ pct, size = 56 }: { pct: number; size?: number }) {
    const r = (size - 7) / 2;
    const c = 2 * Math.PI * r;
    return (
        <div className="relative shrink-0" style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90">
                <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="var(--muted)" strokeWidth="6" />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke="var(--primary)"
                    strokeWidth="6"
                    strokeLinecap="round"
                    strokeDasharray={c}
                    strokeDashoffset={c * (1 - pct / 100)}
                    className="transition-[stroke-dashoffset] duration-500"
                />
            </svg>
            <span className="absolute inset-0 grid place-items-center text-[13px] font-bold">
                {pct}%
            </span>
        </div>
    );
}

function PhotoField({ ctx }: { ctx: StepCtx }) {
    const { data, set } = ctx;
    const [preview, setPreview] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const initials = (
        (data.first_name?.[0] ?? '') + (data.last_name?.[0] ?? '')
    ).toUpperCase();
    const pick = (file?: File | null) => {
        if (!file) return;
        set('profile_photo', file);
        setPreview(URL.createObjectURL(file));
    };
    const clear = () => {
        set('profile_photo', null);
        setPreview(null);
        if (inputRef.current) inputRef.current.value = '';
    };
    return (
        <div className="flex items-center gap-3.5">
            <div className="relative grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-2xl border border-border bg-primary/10">
                {preview ? (
                    <img src={preview} alt="" className="h-full w-full object-cover" />
                ) : initials ? (
                    <span className="text-lg font-bold text-primary">{initials}</span>
                ) : (
                    <Camera className="h-5 w-5 text-primary" />
                )}
            </div>
            <div>
                <input
                    ref={inputRef}
                    type="file"
                    accept="image/png,image/jpeg"
                    className="hidden"
                    onChange={(e) => pick(e.target.files?.[0])}
                />
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => inputRef.current?.click()}
                    >
                        <Camera className="h-3.5 w-3.5" />
                        {data.profile_photo ? 'Change photo' : 'Upload photo'}
                    </Button>
                    {data.profile_photo ? (
                        <button
                            type="button"
                            onClick={clear}
                            className="text-[13px] text-muted-foreground hover:underline"
                        >
                            Remove
                        </button>
                    ) : null}
                </div>
                <p className="mt-1.5 text-xs text-muted-foreground">
                    JPG or PNG, up to 5&nbsp;MB. Optional.
                </p>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Client-side validation (mirrors StoreClientRequest)                */
/* ------------------------------------------------------------------ */

function validateStep(key: StepKey, d: ClientWizardForm): Record<string, string> {
    const e: Record<string, string> = {};
    if (key === 'basics') {
        if (!d.first_name.trim()) e.first_name = 'First name is required';
        if (!d.last_name.trim()) e.last_name = 'Last name is required';
        if (!d.date_of_birth) e.date_of_birth = 'Date of birth is required';
        if (!d.site_id) e.site_id = 'Choose a site';
        if (!d.service_context_id) e.service_context_id = 'Choose a service context';
        if (d.email && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(d.email))
            e.email = 'Enter a valid email';
        if (d.nhi_number && nhiState(d.nhi_number) === 'bad')
            e.nhi_number = 'NHI must be 3 letters + 4 digits';
        if (d.create_client_portal_user && !d.email)
            e.email = 'Email required for portal access';
    }
    if (key === 'contacts') {
        const c = d.emergency_contacts[0];
        const anyData = d.emergency_contacts.some((x) =>
            Object.entries(x).some(
                ([k, v]) =>
                    k !== 'preferred_method' &&
                    k !== 'can_receive_updates' &&
                    (typeof v === 'string' ? v.trim() !== '' : v === true),
            ),
        );
        if (anyData && c) {
            if (!c.name.trim()) e['emergency_contacts.0.name'] = 'Primary contact name is required';
            if (!c.phone.trim()) e['emergency_contacts.0.phone'] = 'Primary contact phone is required';
        }
    }
    return e;
}

/* ------------------------------------------------------------------ */
/*  Shell                                                              */
/* ------------------------------------------------------------------ */

export type AddClientDialogProps = {
    isOpen: boolean;
    onClose: () => void;
    sites: Option[];
    serviceContexts: ServiceContextOption[];
    keyWorkers: Option[];
    geofences: Option[];
    defaultServiceContextId?: number | null;
    clientSingular?: string;
};

export function AddClientDialog(props: AddClientDialogProps) {
    const { isOpen, onClose } = props;
    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="overflow-hidden p-0 [&>button]:hidden"
                style={{ maxWidth: 'min(94vw, 1080px)', width: 'min(94vw, 1080px)' }}
            >
                <DialogTitle className="sr-only">Add client</DialogTitle>
                <DialogDescription className="sr-only">
                    A guided wizard to create a complete, person-centred client
                    profile.
                </DialogDescription>
                {isOpen ? <AddClientBody {...props} /> : null}
            </DialogContent>
        </Dialog>
    );
}

/* ------------------------------------------------------------------ */
/*  Body                                                               */
/* ------------------------------------------------------------------ */

function AddClientBody({
    onClose,
    sites,
    serviceContexts,
    keyWorkers,
    geofences,
    defaultServiceContextId,
    clientSingular = 'Client',
}: AddClientDialogProps) {
    const page = usePage<{ flash?: { created_client_id?: number | null } }>();
    const form = useForm<ClientWizardForm>(initialForm(defaultServiceContextId));
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    const cur = STEPS[stepIndex];
    const pct = useMemo(() => completionPct(data), [data]);

    const set = <K extends keyof ClientWizardForm>(k: K, v: ClientWizardForm[K]) =>
        // Inertia's setData value type (FormDataValues) doesn't simplify for a
        // generic key; the call site is type-safe via the K constraint above.
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        setData(k, v as any);
    const setMedical = <K extends keyof MedicalShape>(k: K, v: MedicalShape[K]) =>
        setData('medical', { ...data.medical, [k]: v });
    const setMany = (partial: Partial<ClientWizardForm>) =>
        setData((prev) => ({ ...prev, ...partial }));

    const fieldError = (name: string): string | undefined =>
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

    const resetAll = () => {
        form.reset();
        form.clearErrors();
        setData(initialForm(defaultServiceContextId));
        setErrors({});
        setStepIndex(0);
        setDone(false);
    };

    const submit = (addAnother: boolean) => {
        // Re-validate every gating step; jump to the first that fails.
        const all: Record<string, string> = {};
        for (const s of STEPS) Object.assign(all, validateStep(s.key, data));
        if (Object.keys(all).length) {
            setErrors(all);
            goToStep(stepForError(Object.keys(all)[0]));
            return;
        }
        setErrors({});
        form.post('/operations/clients', {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (addAnother) {
                    resetAll();
                } else {
                    setDone(true);
                }
            },
            onError: (errs) => {
                const first = Object.keys(errs)[0];
                if (first) goToStep(stepForError(first));
            },
        });
    };

    const ctx: StepCtx = {
        data,
        set,
        setMedical,
        setMany,
        err: fieldError,
        sites,
        serviceContexts,
        keyWorkers,
        geofences,
    };

    if (done) {
        return (
            <SuccessPane
                data={data}
                pct={pct}
                clientSingular={clientSingular}
                createdId={page.props.flash?.created_client_id ?? null}
                onClose={onClose}
                onAddAnother={resetAll}
            />
        );
    }

    const isReview = cur.key === 'review';

    return (
        <div className="flex h-[min(92vh,860px)] min-h-0 overflow-hidden">
            {/* ── Stepper rail ── */}
            <aside className="hidden w-[248px] shrink-0 flex-col gap-1 overflow-y-auto border-r border-sidebar-border bg-sidebar p-4 sm:flex">
                <div className="mb-3 flex items-center gap-2.5">
                    <span className="grid h-9 w-9 place-items-center rounded-lg bg-primary text-primary-foreground">
                        <UserPlus className="h-5 w-5" />
                    </span>
                    <div>
                        <div className="text-sm font-bold leading-tight">
                            Add {clientSingular.toLowerCase()}
                        </div>
                        <div className="text-[11px] text-muted-foreground">
                            New intake
                        </div>
                    </div>
                </div>

                {STEPS.map((s, i) => {
                    const active = i === stepIndex;
                    const complete = i < stepIndex;
                    const Icon = s.icon;
                    return (
                        <button
                            key={s.key}
                            type="button"
                            onClick={() => setStepIndex(i)}
                            className={cn(
                                'flex items-center gap-2.5 rounded-md p-2 text-left transition-colors',
                                active ? 'bg-primary/10' : 'hover:bg-accent',
                            )}
                        >
                            <span
                                className={cn(
                                    'grid h-[26px] w-[26px] shrink-0 place-items-center rounded-full text-[11px] font-bold transition-colors',
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : complete
                                          ? 'bg-status-success-bg text-status-success'
                                          : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {complete ? (
                                    <Check className="h-3.5 w-3.5" />
                                ) : (
                                    <Icon className="h-3.5 w-3.5" />
                                )}
                            </span>
                            <span className="min-w-0">
                                <span
                                    className={cn(
                                        'block text-[13px]',
                                        active
                                            ? 'font-bold text-foreground'
                                            : complete
                                              ? 'font-semibold text-foreground'
                                              : 'font-semibold text-muted-foreground',
                                    )}
                                >
                                    {s.label}
                                </span>
                                <span className="block truncate text-[11px] text-muted-foreground">
                                    {s.blurb}
                                </span>
                            </span>
                        </button>
                    );
                })}

                <div className="mt-auto pt-4">
                    <div className="mb-1.5 flex justify-between text-[11px] text-muted-foreground">
                        <span>Profile completeness</span>
                        <span className="font-bold text-primary">{pct}%</span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-primary transition-[width] duration-500"
                            style={{ width: `${pct}%` }}
                        />
                    </div>
                </div>
            </aside>

            {/* ── Main column ── */}
            <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                <header className="flex shrink-0 items-center justify-between border-b border-border px-5 py-3.5">
                    <div className="text-[13px] font-semibold text-muted-foreground">
                        Step {stepIndex + 1} of {STEPS.length} ·{' '}
                        <span className="text-foreground">{cur.label}</span>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </header>

                {/* top progress bar */}
                <div className="h-[3px] shrink-0 bg-muted">
                    <div
                        className="h-full bg-primary transition-[width] duration-300"
                        style={{ width: `${((stepIndex + 1) / STEPS.length) * 100}%` }}
                    />
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden px-6 py-6">
                    {isReview ? (
                        <ReviewStep ctx={ctx} pct={pct} goToStep={goToStep} />
                    ) : (
                        <StepBody stepKey={cur.key} ctx={ctx} />
                    )}
                </div>

                <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-border bg-muted/30 px-5 py-3.5">
                    <div>
                        {stepIndex > 0 ? (
                            <Button type="button" variant="ghost" onClick={back}>
                                <ChevronLeft className="h-4 w-4" /> Back
                            </Button>
                        ) : null}
                    </div>
                    <div className="flex items-center gap-2.5">
                        <Button type="button" variant="outline" onClick={onClose}>
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
                                    Save & add another
                                </Button>
                                <Button
                                    type="button"
                                    onClick={() => submit(false)}
                                    disabled={processing}
                                >
                                    {processing ? (
                                        <>
                                            <Loader2 className="h-4 w-4 animate-spin" />
                                            Creating…
                                        </>
                                    ) : (
                                        <>
                                            <Check className="h-4 w-4" />
                                            Create {clientSingular.toLowerCase()}
                                        </>
                                    )}
                                </Button>
                            </>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </div>
                </footer>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Step bodies                                                        */
/* ------------------------------------------------------------------ */

type StepCtx = {
    data: ClientWizardForm;
    set: <K extends keyof ClientWizardForm>(k: K, v: ClientWizardForm[K]) => void;
    setMedical: <K extends keyof MedicalShape>(k: K, v: MedicalShape[K]) => void;
    setMany: (partial: Partial<ClientWizardForm>) => void;
    err: (name: string) => string | undefined;
    sites: Option[];
    serviceContexts: ServiceContextOption[];
    keyWorkers: Option[];
    geofences: Option[];
};

function StepBody({ stepKey, ctx }: { stepKey: StepKey; ctx: StepCtx }) {
    switch (stepKey) {
        case 'basics':
            return <StepBasics ctx={ctx} />;
        case 'cultural':
            return <StepCultural ctx={ctx} />;
        case 'support':
            return <StepSupport ctx={ctx} />;
        case 'health':
            return <StepHealth ctx={ctx} />;
        case 'about':
            return <StepAbout ctx={ctx} />;
        case 'care':
            return <StepCare ctx={ctx} />;
        case 'contacts':
            return <StepContacts ctx={ctx} />;
        default:
            return null;
    }
}

function StepBasics({ ctx }: { ctx: StepCtx }) {
    const { data, set, setMany, err, sites, serviceContexts } = ctx;
    const age = ageFromDob(data.date_of_birth);
    const nhi = nhiState(data.nhi_number);
    const contextIcon = (i: number) =>
        i === 0 ? Home : i === 1 ? Heart : i === 2 ? Calendar : KeyRound;
    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHead
                icon={IdCard}
                title="Who are we adding?"
                blurb="Identity, where they're supported, and how to reach them."
            />
            <div className="grid gap-4">
                <Field label="Profile photo">
                    <PhotoField ctx={ctx} />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={User}>Identity</SubHead>
                    <Field label="First name" required error={err('first_name')}>
                        <Input
                            value={data.first_name}
                            onChange={(e) => set('first_name', e.target.value)}
                            placeholder="e.g. Hemi"
                            aria-invalid={!!err('first_name')}
                        />
                    </Field>
                    <Field label="Last name" required error={err('last_name')}>
                        <Input
                            value={data.last_name}
                            onChange={(e) => set('last_name', e.target.value)}
                            placeholder="e.g. Walker"
                            aria-invalid={!!err('last_name')}
                        />
                    </Field>
                    <Field label="Preferred name" hint="what they like to be called">
                        <Input
                            value={data.preferred_name}
                            onChange={(e) => set('preferred_name', e.target.value)}
                            placeholder="e.g. Hemi"
                        />
                    </Field>
                    <Field label="Date of birth" required error={err('date_of_birth')}>
                        <div className="flex items-center gap-2.5">
                            <Input
                                type="date"
                                value={data.date_of_birth}
                                onChange={(e) => set('date_of_birth', e.target.value)}
                                aria-invalid={!!err('date_of_birth')}
                            />
                            {age != null ? (
                                <Badge variant="outline" className="shrink-0 gap-1 text-primary">
                                    <Cake className="h-3 w-3" /> {age} yrs
                                </Badge>
                            ) : null}
                        </div>
                    </Field>
                    <Field label="Gender">
                        <Input
                            value={data.gender}
                            onChange={(e) => set('gender', e.target.value)}
                            placeholder="e.g. Male, Female, Non-binary"
                        />
                    </Field>
                    <Field label="Pronouns">
                        <SelectInput
                            value={data.preferred_pronouns}
                            onChange={(v) => set('preferred_pronouns', v)}
                            placeholder="Select pronouns"
                            options={PRONOUNS.map((p) => ({ value: p, label: p }))}
                        />
                    </Field>
                    <Field
                        label="NHI number"
                        hint="NZ National Health Index"
                        error={err('nhi_number')}
                    >
                        <div className="flex items-center gap-2.5">
                            <Input
                                value={data.nhi_number}
                                onChange={(e) =>
                                    set('nhi_number', e.target.value.toUpperCase().slice(0, 7))
                                }
                                placeholder="e.g. ZAC5961"
                                maxLength={7}
                                aria-invalid={!!err('nhi_number') || nhi === 'bad'}
                            />
                            {nhi === 'ok' ? (
                                <Badge variant="outline" className="shrink-0 gap-1 text-status-success">
                                    <Check className="h-3 w-3" /> Valid
                                </Badge>
                            ) : null}
                            {nhi === 'bad' ? (
                                <Badge variant="outline" className="shrink-0 gap-1 text-status-critical">
                                    <AlertTriangle className="h-3 w-3" /> Check
                                </Badge>
                            ) : null}
                        </div>
                    </Field>
                    <div className="flex items-center gap-2.5 self-end rounded-md border border-primary/25 bg-primary/5 px-3 py-2.5">
                        <Info className="h-4 w-4 shrink-0 text-primary" />
                        <span className="text-xs leading-snug text-muted-foreground">
                            Matches the national health record and prevents duplicate
                            profiles. Format: 3 letters + 4 digits.
                        </span>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={MapPin}>Placement</SubHead>
                    <Field label="Site / home" required error={err('site_id')}>
                        <SelectInput
                            value={data.site_id}
                            onChange={(v) => set('site_id', v)}
                            placeholder="Choose a site"
                            options={sites.map((s) => ({ value: String(s.id), label: s.name }))}
                        />
                    </Field>
                    <Field label="Status">
                        <Segmented
                            value={data.status}
                            onChange={(v) => set('status', v)}
                            options={[
                                { value: 'onboarding', label: 'Onboarding' },
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                            ]}
                        />
                    </Field>
                    <Field
                        label="Service context"
                        required
                        span
                        hint="used for audit & reporting"
                        error={err('service_context_id')}
                    >
                        <TilePicker
                            value={data.service_context_id}
                            onChange={(v) => set('service_context_id', v)}
                            cols={2}
                            options={serviceContexts.map((s, i) => ({
                                key: String(s.id),
                                label: s.name,
                                icon: contextIcon(i),
                            }))}
                        />
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={Phone}>Contact &amp; address</SubHead>
                    <Field label="Phone" error={err('phone')}>
                        <Input
                            value={data.phone}
                            onChange={(e) => set('phone', e.target.value)}
                            placeholder="+64 21 …"
                        />
                    </Field>
                    <Field label="Email" error={err('email')}>
                        <Input
                            type="email"
                            value={data.email}
                            onChange={(e) => set('email', e.target.value)}
                            placeholder="name@example.co.nz"
                            aria-invalid={!!err('email')}
                        />
                    </Field>
                    <Field
                        label="Address line 1"
                        span
                        hint="type to search — powered by OpenStreetMap"
                    >
                        <AddressAutocomplete
                            value={data.address_line_1}
                            onChange={(v) => set('address_line_1', v)}
                            onSelect={(r: GeocodeResult) => {
                                // Only fill fields the result actually provides;
                                // never clear what the user already typed.
                                const patch: Partial<ClientWizardForm> = {};
                                if (r.address_line_1)
                                    patch.address_line_1 = r.address_line_1;
                                if (r.suburb) patch.suburb = r.suburb;
                                if (r.city) patch.city = r.city;
                                if (r.postcode) patch.postcode = r.postcode;
                                setMany(patch);
                            }}
                            placeholder="Start typing an address…"
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
                            placeholder="e.g. Hillcrest"
                        />
                    </Field>
                    <Field label="City">
                        <Input
                            value={data.city}
                            onChange={(e) => set('city', e.target.value)}
                            placeholder="e.g. Hamilton"
                        />
                    </Field>
                    <Field label="Postcode">
                        <Input
                            value={data.postcode}
                            onChange={(e) =>
                                set('postcode', e.target.value.replace(/\D/g, '').slice(0, 4))
                            }
                            placeholder="3216"
                        />
                    </Field>
                </div>

                <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-3">
                    <Switch
                        checked={data.create_client_portal_user}
                        onCheckedChange={(v) => set('create_client_portal_user', v)}
                    />
                    <div>
                        <div className="text-sm font-semibold">
                            Create a client portal login
                        </div>
                        <div className="mt-0.5 text-[13px] text-muted-foreground">
                            Uses the email above. An invite is sent on save — email
                            becomes required.
                        </div>
                        {data.create_client_portal_user && !data.email ? (
                            <FieldErr>
                                Add a contact email above to enable portal access.
                            </FieldErr>
                        ) : null}
                    </div>
                </div>
            </div>
        </div>
    );
}

function StepCultural({ ctx }: { ctx: StepCtx }) {
    const { data, set } = ctx;
    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHead
                icon={Globe}
                title="Cultural identity"
                blurb="Honour who they are — whakapapa, language and beliefs guide person-centred, Te Tiriti–aligned care."
            />
            <div className="grid gap-4">
                <Field label="Ethnicity">
                    <SelectInput
                        value={data.ethnicity}
                        onChange={(v) => set('ethnicity', v)}
                        placeholder="Select ethnicity"
                        options={ETHNICITIES.map((e) => ({ value: e, label: e }))}
                    />
                </Field>
                <Field label="Languages spoken" hint="select all that apply">
                    <ChipMulti
                        values={data.languages}
                        onChange={(v) => set('languages', v)}
                        options={LANGUAGES}
                    />
                </Field>
                <Field label="Religion / spiritual beliefs">
                    <SelectInput
                        value={data.religion}
                        onChange={(v) => set('religion', v)}
                        placeholder="Select if relevant"
                        options={RELIGIONS.map((r) => ({ value: r, label: r }))}
                    />
                </Field>
                <div className="grid">
                    <InfoCard icon={Info}>
                        Cultural needs flow through to the support plan, meal planning
                        and rostering — e.g. matching a Te&nbsp;Reo–speaking key worker
                        where possible.
                    </InfoCard>
                </div>
            </div>
        </div>
    );
}

function StepSupport({ ctx }: { ctx: StepCtx }) {
    const { data, set } = ctx;
    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHead
                icon={Accessibility}
                title="Support needs"
                blurb="Capture how we keep this person safe, comfortable and independent day to day."
            />
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={Footprints}>Daily living</SubHead>
                    <Field label="Mobility needs" span>
                        <Textarea
                            rows={2}
                            value={data.mobility_needs}
                            onChange={(e) => set('mobility_needs', e.target.value)}
                            placeholder="e.g. Uses a walking frame indoors, wheelchair for longer distances. Needs assistance on stairs."
                        />
                    </Field>
                    <Field label="Sensory needs">
                        <Textarea
                            rows={2}
                            value={data.sensory_needs}
                            onChange={(e) => set('sensory_needs', e.target.value)}
                            placeholder="e.g. Hard of hearing — face them when speaking. Sensitive to bright light."
                        />
                    </Field>
                    <Field label="Cognitive / communication">
                        <Textarea
                            rows={2}
                            value={data.cognitive_needs}
                            onChange={(e) => set('cognitive_needs', e.target.value)}
                            placeholder="e.g. Uses picture cards and short sentences. Allow extra processing time."
                        />
                    </Field>
                    <Field label="Dietary requirements">
                        <Textarea
                            rows={2}
                            value={data.dietary_requirements}
                            onChange={(e) => set('dietary_requirements', e.target.value)}
                            placeholder="e.g. Gluten-free, soft/minced texture. Nut allergy — EpiPen on file."
                        />
                    </Field>
                    <Field label="Sleep preferences">
                        <Textarea
                            rows={2}
                            value={data.sleep_preferences}
                            onChange={(e) => set('sleep_preferences', e.target.value)}
                            placeholder="e.g. Settles by 9pm with a nightlight. Wakes once for the bathroom."
                        />
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={Truck}>Transport</SubHead>
                    <Field label="Transport needs" span hint="select all that apply">
                        <ChipMulti
                            values={data.transport_needs}
                            onChange={(v) => set('transport_needs', v)}
                            options={TRANSPORT_OPTIONS}
                        />
                    </Field>
                    <Field label="Transport notes" span>
                        <Textarea
                            rows={2}
                            value={data.transport_notes}
                            onChange={(e) => set('transport_notes', e.target.value)}
                            placeholder="e.g. Anxious in traffic — prefers front seat and calm music."
                        />
                    </Field>
                </div>

                <SubHead icon={HeartPulse}>
                    Clinical thresholds{' '}
                    <span className="font-normal normal-case tracking-normal">
                        — optional, feed alerts &amp; charts
                    </span>
                </SubHead>
                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Fluid intake min" hint="ml / day">
                        <Input
                            type="number"
                            value={data.fluid_intake_min_ml}
                            onChange={(e) => set('fluid_intake_min_ml', e.target.value)}
                            placeholder="1500"
                        />
                    </Field>
                    <Field label="Fluid intake max" hint="ml / day">
                        <Input
                            type="number"
                            value={data.fluid_intake_max_ml}
                            onChange={(e) => set('fluid_intake_max_ml', e.target.value)}
                            placeholder="2500"
                        />
                    </Field>
                    <Field label="Seizure escalation" hint="seconds">
                        <Input
                            type="number"
                            value={data.seizure_duration_escalation_seconds}
                            onChange={(e) =>
                                set('seizure_duration_escalation_seconds', e.target.value)
                            }
                            placeholder="300"
                        />
                    </Field>
                </div>
                <div className="grid">
                    <InfoCard icon={Stethoscope}>
                        Full medical profile, conditions and medications are set up on
                        the client's <strong>Medical</strong> tab after creation. These
                        thresholds give the care team early-warning alerts from day one.
                    </InfoCard>
                </div>
            </div>
        </div>
    );
}

function StepHealth({ ctx }: { ctx: StepCtx }) {
    const { data, set, setMedical } = ctx;
    const m = data.medical;
    const conditions = data.conditions;
    const updC = (i: number, k: keyof ConditionRow, v: string) =>
        set('conditions', conditions.map((c, idx) => (idx === i ? { ...c, [k]: v } : c)));
    const addC = () =>
        set('conditions', [...conditions, { label: '', severity: 'Mild', notes: '' }]);
    const rmC = (i: number) =>
        set('conditions', conditions.filter((_, idx) => idx !== i));
    const sevVariant = (s: string): StatusVariant =>
        s === 'Severe' ? 'critical' : s === 'Moderate' ? 'warning' : 'success';
    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHead
                icon={Stethoscope}
                title="Health & medical"
                blurb="The clinical essentials. Medications and full clinical records are managed on the Medical tab after creation."
            />
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={Stethoscope}>GP &amp; primary care</SubHead>
                    <Field label="GP name">
                        <Input
                            value={m.gp_name}
                            onChange={(e) => setMedical('gp_name', e.target.value)}
                            placeholder="Dr …"
                        />
                    </Field>
                    <Field label="GP practice">
                        <Input
                            value={m.gp_practice}
                            onChange={(e) => setMedical('gp_practice', e.target.value)}
                            placeholder="e.g. Hamilton East Medical"
                        />
                    </Field>
                    <Field label="GP phone">
                        <Input
                            value={m.gp_phone}
                            onChange={(e) => setMedical('gp_phone', e.target.value)}
                            placeholder="+64 7 …"
                        />
                    </Field>
                    <Field label="Preferred hospital">
                        <Input
                            value={m.hospital_preference}
                            onChange={(e) => setMedical('hospital_preference', e.target.value)}
                            placeholder="e.g. Waikato Hospital"
                        />
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={HeartPulse}>Vitals &amp; flags</SubHead>
                    <Field label="Blood type">
                        <SelectInput
                            value={m.blood_type}
                            onChange={(v) => setMedical('blood_type', v)}
                            placeholder="Select"
                            options={BLOOD_TYPES.map((b) => ({ value: b, label: b }))}
                        />
                    </Field>
                    <Field label="Organ donor">
                        <div className="flex h-10 items-center gap-2.5">
                            <Switch
                                checked={m.organ_donor}
                                onCheckedChange={(v) => setMedical('organ_donor', v)}
                            />
                            <span className="text-[13px] text-muted-foreground">
                                {m.organ_donor ? 'Registered donor' : 'Not registered'}
                            </span>
                        </div>
                    </Field>
                    <Field label="Allergies" span hint="select all that apply">
                        <ChipMulti
                            values={m.allergies}
                            onChange={(v) => setMedical('allergies', v)}
                            options={ALLERGIES}
                        />
                    </Field>
                    <Field label="Disabilities" span hint="select all that apply">
                        <ChipMulti
                            values={m.disabilities}
                            onChange={(v) => setMedical('disabilities', v)}
                            options={DISABILITIES}
                        />
                    </Field>
                </div>

                <SubHead icon={Activity}>Diagnosed conditions</SubHead>
                <div className="grid gap-2.5">
                    {conditions.length === 0 ? (
                        <div className="rounded-lg border border-dashed border-border p-3.5 text-center text-[13px] text-muted-foreground">
                            No conditions added yet. Add any diagnoses the care team
                            should be aware of.
                        </div>
                    ) : null}
                    {conditions.map((c, i) => (
                        <div
                            key={i}
                            className="grid grid-cols-[1.4fr_1fr_auto] items-start gap-2.5 rounded-lg border border-border bg-card/70 p-3"
                        >
                            <div className="space-y-2">
                                <Input
                                    value={c.label}
                                    onChange={(e) => updC(i, 'label', e.target.value)}
                                    placeholder="e.g. Type 2 diabetes"
                                />
                                <Input
                                    value={c.notes}
                                    onChange={(e) => updC(i, 'notes', e.target.value)}
                                    placeholder="Notes (optional)"
                                />
                            </div>
                            <div className="space-y-2">
                                <Segmented
                                    value={c.severity}
                                    onChange={(v) => updC(i, 'severity', v)}
                                    options={[
                                        { value: 'Mild', label: 'Mild' },
                                        { value: 'Moderate', label: 'Moderate' },
                                        { value: 'Severe', label: 'Severe' },
                                    ]}
                                />
                                <StatusBadge variant={sevVariant(c.severity)}>
                                    {c.severity}
                                </StatusBadge>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove condition"
                                onClick={() => rmC(i)}
                            >
                                <Trash2 className="h-4 w-4" />
                            </Button>
                        </div>
                    ))}
                    <div>
                        <Button type="button" variant="outline" onClick={addC}>
                            <Plus className="h-4 w-4" /> Add condition
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={FileText}>History</SubHead>
                    <Field label="Medical history" span>
                        <Textarea
                            rows={2}
                            value={m.medical_history}
                            onChange={(e) => setMedical('medical_history', e.target.value)}
                            placeholder="Significant past medical history, ongoing concerns…"
                        />
                    </Field>
                    <Field label="Mental health history">
                        <Textarea
                            rows={2}
                            value={m.mental_health_history}
                            onChange={(e) =>
                                setMedical('mental_health_history', e.target.value)
                            }
                            placeholder="Relevant mental-health background and supports."
                        />
                    </Field>
                    <Field label="Surgical history">
                        <Textarea
                            rows={2}
                            value={m.surgical_history}
                            onChange={(e) => setMedical('surgical_history', e.target.value)}
                            placeholder="Past operations and dates."
                        />
                    </Field>
                    <Field label="Immunisation notes">
                        <Textarea
                            rows={2}
                            value={m.immunisation_notes}
                            onChange={(e) => setMedical('immunisation_notes', e.target.value)}
                            placeholder="Vaccination status, due dates…"
                        />
                    </Field>
                    <Field label="Other medical notes">
                        <Textarea
                            rows={2}
                            value={m.notes}
                            onChange={(e) => setMedical('notes', e.target.value)}
                            placeholder="Anything else clinically relevant."
                        />
                    </Field>
                </div>
                <div className="grid">
                    <InfoCard icon={Shield} tone="warn">
                        Medical information is sensitive. It's encrypted at rest and only
                        visible to staff with clinical permissions — and to contacts you
                        authorise on the next step.
                    </InfoCard>
                </div>
            </div>
        </div>
    );
}

function StepAbout({ ctx }: { ctx: StepCtx }) {
    const { data, set } = ctx;
    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHead
                icon={Heart}
                title="About me"
                blurb="The person, not the file. This is what support workers read first."
            />
            <div className="grid gap-4">
                <Field label="Interests & hobbies">
                    <Textarea
                        rows={2}
                        value={data.interests_hobbies}
                        onChange={(e) => set('interests_hobbies', e.target.value)}
                        placeholder="e.g. Loves rugby (Chiefs fan), gardening, and Friday fish & chips."
                    />
                </Field>
                <Field label="Strengths & abilities">
                    <Textarea
                        rows={2}
                        value={data.strengths_abilities}
                        onChange={(e) => set('strengths_abilities', e.target.value)}
                        placeholder="e.g. Independent with personal care, great memory for names, makes everyone laugh."
                    />
                </Field>
                <Field label="Life story">
                    <Textarea
                        rows={4}
                        value={data.life_story}
                        onChange={(e) => set('life_story', e.target.value)}
                        placeholder="A short narrative — where they grew up, important people, milestones, what matters to them."
                    />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Education level">
                        <SelectInput
                            value={data.education_level}
                            onChange={(v) => set('education_level', v)}
                            placeholder="Select"
                            options={EDUCATION.map((e) => ({ value: e, label: e }))}
                        />
                    </Field>
                    <Field label="Employment status">
                        <SelectInput
                            value={data.employment_status}
                            onChange={(v) => set('employment_status', v)}
                            placeholder="Select"
                            options={EMPLOYMENT.map((e) => ({ value: e, label: e }))}
                        />
                    </Field>
                </div>
            </div>
        </div>
    );
}

function StepCare({ ctx }: { ctx: StepCtx }) {
    const { data, set, keyWorkers, geofences } = ctx;
    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHead
                icon={ClipboardCheck}
                title="Care setup"
                blurb="Who's responsible, the risk picture, and how it's funded."
            />
            <div className="grid gap-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Service start date">
                        <Input
                            type="date"
                            value={data.service_start_date}
                            onChange={(e) => set('service_start_date', e.target.value)}
                        />
                    </Field>
                    <Field label="Key worker">
                        <SelectInput
                            value={data.key_worker_id}
                            onChange={(v) => set('key_worker_id', v)}
                            placeholder="Assign a key worker"
                            options={keyWorkers.map((k) => ({
                                value: String(k.id),
                                label: k.name,
                            }))}
                        />
                    </Field>
                </div>

                <Field label="Risk level">
                    <TilePicker
                        value={data.risk_level}
                        onChange={(v) => set('risk_level', v)}
                        cols={3}
                        options={[
                            { key: 'low', label: 'Low', icon: Shield, accent: 'text-status-success' },
                            { key: 'medium', label: 'Medium', icon: ShieldAlert, accent: 'text-status-warning' },
                            { key: 'high', label: 'High', icon: AlertTriangle, accent: 'text-status-critical' },
                        ]}
                    />
                </Field>

                <div
                    className={cn(
                        'flex items-start gap-3 rounded-lg border p-3',
                        data.safeguarding_flag
                            ? 'border-status-critical/40 bg-status-critical-bg'
                            : 'border-border bg-muted/40',
                    )}
                >
                    <Switch
                        checked={data.safeguarding_flag}
                        onCheckedChange={(v) => set('safeguarding_flag', v)}
                    />
                    <div>
                        <div className="flex items-center gap-1.5 text-sm font-semibold">
                            <Shield
                                className={cn(
                                    'h-3.5 w-3.5',
                                    data.safeguarding_flag
                                        ? 'text-status-critical'
                                        : 'text-muted-foreground',
                                )}
                            />
                            Active safeguarding concern
                        </div>
                        <div className="mt-0.5 text-[13px] text-muted-foreground">
                            Adds a safeguarding ribbon to the profile and restricts
                            visibility to authorised staff.
                        </div>
                    </div>
                </div>

                <Field
                    label="Monitored home (geofence)"
                    hint="for resident safety tracking"
                >
                    <SelectInput
                        value={data.house_geofence_id}
                        onChange={(v) => set('house_geofence_id', v)}
                        placeholder="Link a property boundary"
                        options={geofences.map((g) => ({
                            value: String(g.id),
                            label: g.name,
                        }))}
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <SubHead icon={Wallet}>Funding</SubHead>
                    <Field label="Funding type" span>
                        <Segmented
                            value={data.funding_type}
                            onChange={(v) => set('funding_type', v)}
                            options={FUNDING_OPTIONS.map((f) => ({ value: f, label: f }))}
                        />
                    </Field>
                    <Field label="Funding notes" span>
                        <Textarea
                            rows={3}
                            value={data.funding_notes}
                            onChange={(e) => set('funding_notes', e.target.value)}
                            placeholder="Plan number, review dates, allocated hours, contact at funder…"
                        />
                    </Field>
                </div>
            </div>
        </div>
    );
}

function StepContacts({ ctx }: { ctx: StepCtx }) {
    const { data, set, err } = ctx;
    const contacts = data.emergency_contacts;
    const update = (i: number, k: keyof ContactRow, v: ContactRow[keyof ContactRow]) =>
        set(
            'emergency_contacts',
            contacts.map((c, idx) => (idx === i ? { ...c, [k]: v } : c)),
        );
    const add = () => set('emergency_contacts', [...contacts, emptyContact()]);
    const remove = (i: number) =>
        set(
            'emergency_contacts',
            contacts.length > 1 ? contacts.filter((_, idx) => idx !== i) : contacts,
        );
    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHead
                icon={Phone}
                title="Contacts & consent"
                blurb="Who to call in an emergency, in priority order — and what information each person is authorised to see."
            />
            <div className="grid gap-3.5">
                {contacts.map((c, i) => (
                    <div
                        key={i}
                        className="rounded-xl border border-border bg-card/70 p-4"
                    >
                        <div className="mb-3.5 flex items-center justify-between">
                            <div className="flex items-center gap-2.5">
                                <span className="grid h-6 w-6 place-items-center rounded-full bg-primary text-[11px] font-bold text-primary-foreground">
                                    {i + 1}
                                </span>
                                <span className="text-sm font-semibold">
                                    {i === 0 ? 'Primary contact' : `Contact ${i + 1}`}
                                </span>
                                {c.relationship ? (
                                    <Badge variant="outline">{c.relationship}</Badge>
                                ) : null}
                            </div>
                            {contacts.length > 1 ? (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-status-critical hover:text-status-critical"
                                    onClick={() => remove(i)}
                                >
                                    <Trash2 className="h-3.5 w-3.5" /> Remove
                                </Button>
                            ) : null}
                        </div>
                        <div className="grid gap-3.5 sm:grid-cols-2">
                            <Field
                                label="Full name"
                                required={i === 0}
                                error={i === 0 ? err('emergency_contacts.0.name') : undefined}
                            >
                                <Input
                                    value={c.name}
                                    onChange={(e) => update(i, 'name', e.target.value)}
                                    placeholder="e.g. Sarah Walker"
                                    aria-invalid={
                                        i === 0 && !!err('emergency_contacts.0.name')
                                    }
                                />
                            </Field>
                            <Field label="Relationship">
                                <Input
                                    value={c.relationship}
                                    onChange={(e) =>
                                        update(i, 'relationship', e.target.value)
                                    }
                                    placeholder="e.g. Mother, EPOA, sibling"
                                />
                            </Field>
                            <Field
                                label="Phone"
                                required={i === 0}
                                error={i === 0 ? err('emergency_contacts.0.phone') : undefined}
                            >
                                <Input
                                    value={c.phone}
                                    onChange={(e) => update(i, 'phone', e.target.value)}
                                    placeholder="+64 21 …"
                                    aria-invalid={
                                        i === 0 && !!err('emergency_contacts.0.phone')
                                    }
                                />
                            </Field>
                            <Field label="Alternate phone">
                                <Input
                                    value={c.alternate_phone}
                                    onChange={(e) =>
                                        update(i, 'alternate_phone', e.target.value)
                                    }
                                    placeholder="Landline / work"
                                />
                            </Field>
                            <Field label="Email">
                                <Input
                                    type="email"
                                    value={c.email}
                                    onChange={(e) => update(i, 'email', e.target.value)}
                                    placeholder="name@example.co.nz"
                                />
                            </Field>
                            <Field label="Availability">
                                <Input
                                    value={c.availability}
                                    onChange={(e) =>
                                        update(i, 'availability', e.target.value)
                                    }
                                    placeholder="e.g. Weekdays after 5pm"
                                />
                            </Field>
                            <Field label="Address" span>
                                <Input
                                    value={c.address}
                                    onChange={(e) => update(i, 'address', e.target.value)}
                                    placeholder="Postal address (optional)"
                                />
                            </Field>
                            <Field label="Preferred method">
                                <Segmented
                                    value={c.preferred_method}
                                    onChange={(v) => update(i, 'preferred_method', v)}
                                    options={[
                                        { value: 'phone', label: 'Call', icon: Phone },
                                        { value: 'text', label: 'Text' },
                                        { value: 'email', label: 'Email', icon: Mail },
                                    ]}
                                />
                            </Field>
                            <Field label="Notes">
                                <Input
                                    value={c.notes}
                                    onChange={(e) => update(i, 'notes', e.target.value)}
                                    placeholder="Anything the on-call team should know"
                                />
                            </Field>
                            <div className="col-span-full mt-0.5 border-t border-border pt-3">
                                <Label className="mb-2 flex items-center gap-1.5">
                                    Information sharing &amp; consent
                                    <span className="text-xs font-normal text-muted-foreground">
                                        what this person is authorised to access
                                    </span>
                                </Label>
                                <div className="flex flex-wrap gap-2">
                                    <ConsentChip
                                        on={c.can_view_medical}
                                        onToggle={() =>
                                            update(i, 'can_view_medical', !c.can_view_medical)
                                        }
                                        icon={Stethoscope}
                                        label="Medical info"
                                    />
                                    <ConsentChip
                                        on={c.can_view_medications}
                                        onToggle={() =>
                                            update(
                                                i,
                                                'can_view_medications',
                                                !c.can_view_medications,
                                            )
                                        }
                                        icon={HeartPulse}
                                        label="Medications"
                                    />
                                    <ConsentChip
                                        on={c.can_view_incidents}
                                        onToggle={() =>
                                            update(
                                                i,
                                                'can_view_incidents',
                                                !c.can_view_incidents,
                                            )
                                        }
                                        icon={ShieldAlert}
                                        label="Incidents"
                                    />
                                    <ConsentChip
                                        on={c.can_receive_updates}
                                        onToggle={() =>
                                            update(
                                                i,
                                                'can_receive_updates',
                                                !c.can_receive_updates,
                                            )
                                        }
                                        icon={Mail}
                                        label="General updates"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                ))}
                <div>
                    <Button type="button" variant="outline" onClick={add}>
                        <Plus className="h-4 w-4" /> Add another contact
                    </Button>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Review + Success                                                   */
/* ------------------------------------------------------------------ */

function ReviewRow({ label, value }: { label: string; value: ReactNode }) {
    const empty = value == null || value === '';
    return (
        <div className="flex justify-between gap-4 border-b border-border py-1.5 last:border-0">
            <span className="shrink-0 text-[13px] text-muted-foreground">{label}</span>
            <span className="min-w-0 text-right text-[13px] font-medium">
                {empty ? <span className="font-normal text-muted-foreground">—</span> : value}
            </span>
        </div>
    );
}

function ReviewCard({
    icon: Icon,
    title,
    onEdit,
    span,
    children,
}: {
    icon: IconType;
    title: string;
    onEdit: () => void;
    span?: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'rounded-xl border border-border bg-card/70 p-4',
                span && 'sm:col-span-2',
            )}
        >
            <div className="mb-2 flex items-center justify-between">
                <div className="flex items-center gap-2 text-sm font-bold">
                    <Icon className="h-4 w-4 text-primary" /> {title}
                </div>
                <button
                    type="button"
                    onClick={onEdit}
                    className="inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline"
                >
                    <Pencil className="h-3 w-3" /> Edit
                </button>
            </div>
            <div>{children}</div>
        </div>
    );
}

function ReviewStep({
    ctx,
    pct,
    goToStep,
}: {
    ctx: StepCtx;
    pct: number;
    goToStep: (k: StepKey) => void;
}) {
    const { data, sites, serviceContexts, keyWorkers } = ctx;
    const siteName = sites.find((s) => String(s.id) === data.site_id)?.name;
    const sc = serviceContexts.find((s) => String(s.id) === data.service_context_id)?.name;
    const kw = keyWorkers.find((k) => String(k.id) === data.key_worker_id)?.name;
    const age = ageFromDob(data.date_of_birth);
    const namedContacts = data.emergency_contacts.filter((c) => c.name || c.phone);
    const conds = data.conditions.filter((c) => c.label);
    return (
        <div className="animate-in fade-in slide-in-from-right-2 duration-300">
            <StepHead
                icon={CheckCircle2}
                title="Review & create"
                blurb="Quick check before we add them. You can edit any section now or later from the profile."
            />

            <div className="mb-4 flex items-center gap-4 rounded-xl border border-primary/30 bg-primary/10 p-4">
                <Ring pct={pct} />
                <div className="flex-1">
                    <div className="text-[15px] font-bold">Profile {pct}% complete</div>
                    <div className="mt-0.5 text-[13px] text-muted-foreground">
                        {pct >= 80
                            ? 'Great — a rich, person-centred record from day one.'
                            : pct >= 50
                              ? 'Solid start. Remaining details can be added on the profile anytime.'
                              : 'The essentials are in. Add more later to complete onboarding.'}
                    </div>
                </div>
            </div>

            <div className="grid gap-3.5 sm:grid-cols-2">
                <ReviewCard icon={IdCard} title="Basics" onEdit={() => goToStep('basics')}>
                    <ReviewRow label="Name" value={`${data.first_name} ${data.last_name}`.trim()} />
                    <ReviewRow label="Preferred" value={data.preferred_name} />
                    <ReviewRow
                        label="Age / DOB"
                        value={
                            data.date_of_birth
                                ? `${age != null ? age + ' yrs · ' : ''}${data.date_of_birth}`
                                : ''
                        }
                    />
                    <ReviewRow label="NHI" value={data.nhi_number} />
                    <ReviewRow label="Site" value={siteName} />
                    <ReviewRow label="Service" value={sc} />
                    <ReviewRow
                        label="Status"
                        value={
                            <Badge variant="outline" className="capitalize">
                                {data.status}
                            </Badge>
                        }
                    />
                </ReviewCard>

                <ReviewCard
                    icon={Globe}
                    title="Cultural identity"
                    onEdit={() => goToStep('cultural')}
                >
                    <ReviewRow label="Ethnicity" value={data.ethnicity} />
                    <ReviewRow label="Languages" value={data.languages.join(', ')} />
                    <ReviewRow label="Religion" value={data.religion} />
                    <ReviewRow label="Pronouns" value={data.preferred_pronouns} />
                </ReviewCard>

                <ReviewCard
                    icon={Accessibility}
                    title="Support needs"
                    onEdit={() => goToStep('support')}
                >
                    <ReviewRow label="Mobility" value={data.mobility_needs} />
                    <ReviewRow label="Dietary" value={data.dietary_requirements} />
                    <ReviewRow label="Transport" value={data.transport_needs.join(', ')} />
                    <ReviewRow
                        label="Fluid target"
                        value={
                            data.fluid_intake_min_ml || data.fluid_intake_max_ml
                                ? `${data.fluid_intake_min_ml || '?'}–${data.fluid_intake_max_ml || '?'} ml`
                                : ''
                        }
                    />
                </ReviewCard>

                <ReviewCard
                    icon={Stethoscope}
                    title="Health & medical"
                    onEdit={() => goToStep('health')}
                >
                    <ReviewRow
                        label="GP"
                        value={
                            data.medical.gp_name
                                ? `${data.medical.gp_name}${data.medical.gp_practice ? ' · ' + data.medical.gp_practice : ''}`
                                : ''
                        }
                    />
                    <ReviewRow label="Blood type" value={data.medical.blood_type} />
                    <ReviewRow label="Allergies" value={data.medical.allergies.join(', ')} />
                    <ReviewRow
                        label="Disabilities"
                        value={data.medical.disabilities.join(', ')}
                    />
                    <ReviewRow
                        label="Conditions"
                        value={conds.map((c) => `${c.label} (${c.severity})`).join(', ')}
                    />
                </ReviewCard>

                <ReviewCard
                    icon={ClipboardCheck}
                    title="Care setup"
                    onEdit={() => goToStep('care')}
                >
                    <ReviewRow label="Start date" value={data.service_start_date} />
                    <ReviewRow label="Key worker" value={kw} />
                    <ReviewRow
                        label="Risk"
                        value={
                            <StatusBadge
                                variant={
                                    data.risk_level === 'low'
                                        ? 'success'
                                        : data.risk_level === 'medium'
                                          ? 'warning'
                                          : 'critical'
                                }
                                className="capitalize"
                            >
                                {data.risk_level}
                            </StatusBadge>
                        }
                    />
                    <ReviewRow
                        label="Safeguarding"
                        value={
                            data.safeguarding_flag ? (
                                <StatusBadge variant="critical">Flagged</StatusBadge>
                            ) : (
                                'No'
                            )
                        }
                    />
                    <ReviewRow label="Funding" value={data.funding_type} />
                </ReviewCard>

                <ReviewCard
                    icon={Phone}
                    title={`Emergency contacts (${namedContacts.length})`}
                    onEdit={() => goToStep('contacts')}
                    span
                >
                    {namedContacts.length === 0 ? (
                        <span className="text-[13px] text-muted-foreground">
                            None added.
                        </span>
                    ) : (
                        <div className="grid gap-2">
                            {namedContacts.map((c, i) => (
                                <div
                                    key={i}
                                    className="flex flex-wrap items-center gap-2 text-[13px]"
                                >
                                    <span className="grid h-5 w-5 place-items-center rounded-full bg-muted text-[11px] font-bold">
                                        {i + 1}
                                    </span>
                                    <strong>{c.name || '—'}</strong>
                                    {c.relationship ? (
                                        <span className="text-muted-foreground">
                                            · {c.relationship}
                                        </span>
                                    ) : null}
                                    {c.phone ? (
                                        <span className="text-muted-foreground">
                                            · {c.phone}
                                        </span>
                                    ) : null}
                                    {c.can_view_medical ? (
                                        <StatusBadge variant="success">
                                            Health-info OK
                                        </StatusBadge>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    )}
                </ReviewCard>
            </div>
        </div>
    );
}

function SuccessPane({
    data,
    pct,
    clientSingular,
    createdId,
    onClose,
    onAddAnother,
}: {
    data: ClientWizardForm;
    pct: number;
    clientSingular: string;
    createdId: number | null;
    onClose: () => void;
    onAddAnother: () => void;
}) {
    return (
        <div className="flex min-h-0 w-full flex-col items-center justify-center px-10 py-12 text-center"
            style={{ minHeight: 'min(70vh, 560px)' }}
        >
            <div className="relative mb-5">
                <span className="grid h-[76px] w-[76px] place-items-center rounded-full bg-status-success-bg text-status-success">
                    <Check className="h-10 w-10" strokeWidth={2.5} />
                </span>
                <PartyPopper className="absolute -right-3.5 -top-1.5 h-5 w-5 text-primary" />
            </div>
            <h2 className="text-2xl font-bold">
                {data.first_name} {data.last_name} added
            </h2>
            <p className="mt-2 max-w-md text-sm leading-relaxed text-muted-foreground">
                The profile is created with a {pct}% complete record. You can finish
                onboarding, set up medical details and add documents from their profile.
            </p>
            <div className="mt-6 flex gap-3">
                <Button variant="outline" onClick={onAddAnother}>
                    <UserPlus className="h-4 w-4" /> Add another
                </Button>
                {createdId ? (
                    <Button asChild>
                        <a href={`/operations/clients/${createdId}`}>
                            <User className="h-4 w-4" /> Go to profile
                        </a>
                    </Button>
                ) : (
                    <Button onClick={onClose}>
                        <User className="h-4 w-4" /> Done
                    </Button>
                )}
            </div>
        </div>
    );
}
