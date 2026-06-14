import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FieldLabel } from '@/components/field-label';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import type { WizardStep } from '@/components/wizard-stepper';
import { CONTACT_TYPES } from './contacts/_helpers';
export { CONTACT_TYPES };
import {
    AlertTriangle,
    BedDouble,
    Building2,
    ClipboardCheck,
    DoorOpen,
    FileText,
    Home,
    LayoutGrid,
    MapPin,
    Package,
    Phone,
    Plus,
    Shield,
    Star,
    Trash2,
    Upload,
    User as UserIcon,
    Users,
    Warehouse,
} from 'lucide-react';
import {
    Children,
    cloneElement,
    isValidElement,
    useId,
    useRef,
    useState,
    type ComponentType,
    type ReactElement,
} from 'react';

// ── Types ─────────────────────────────────────────────────────────────────

export type SiteType = 'head_office' | 'house' | 'facility' | 'residential';

export type WizardUser = { id: number; name: string };

export type Contact = {
    id?: number;
    type: string;
    name: string;
    role: string;
    phone: string;
    email: string;
    is_primary: boolean;
    notes: string;
};

export type Room = {
    id?: number;
    name: string;
    notes: string;
};

export type Resource = {
    id?: number;
    name: string;
    resource_type: string;
    capacity: string;
};

export type Zone = {
    id?: number;
    name: string;
    zone_type: string;
};

export type AvailableAsset = {
    id: number;
    name: string;
    asset_tag?: string | null;
    category?: string | null;
    serial_number?: string | null;
    is_assigned_here?: boolean;
};

export type ChecklistAssignment = {
    template_id: number;
    enabled: boolean;
    frequency: string;
    assigned_to_user_id: string;
};

export type ChecklistTemplate = {
    id: number;
    name: string;
    description?: string | null;
    applicable_to_type?: string | null;
    frequency?: string | null;
};

export type DocumentDraft = {
    file: File;
    title: string;
    category: string;
    expiry_date: string;
    notes: string;
};

export type DocumentRecord = {
    id: number;
    title?: string | null;
    category?: string | null;
    expiry_date?: string | null;
    notes?: string | null;
    original_name: string;
    size_bytes: number;
};

export type WizardData = {
    name: string;
    type: SiteType;
    brand_colour: string;
    phone: string;
    email: string;
    emergency_plan_location: string;
    medication_storage_location: string;
    notes: string;
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
    is_active: boolean;
    is_high_risk: boolean;
    is_high_needs: boolean;
    risk_notes: string;
    risk_review_date: string;
    primary_contact_user_id: string;
    contacts: Contact[];
    rooms: Room[];
    resources: Resource[];
    zones: Zone[];
    assets: number[];
    checklists: ChecklistAssignment[];
};

export type SetData = (key: keyof WizardData, value: any) => void;

// ── Constants ─────────────────────────────────────────────────────────────

export const SITE_TYPES: Array<{
    value: SiteType;
    label: string;
    icon: ComponentType<{ className?: string }>;
    description: string;
}> = [
    {
        value: 'head_office',
        label: 'Head Office',
        icon: Building2,
        description: 'Administrative HQ with meeting rooms',
    },
    {
        value: 'house',
        label: 'House',
        icon: Home,
        description: 'Residential home with client bedrooms',
    },
    {
        value: 'facility',
        label: 'Facility',
        icon: Warehouse,
        description: 'Workshop, cafe, or day programme space',
    },
    {
        value: 'residential',
        label: 'Residential',
        icon: Home,
        description: 'Client home for residential / home-support visits',
    },
];

export const RESOURCE_TYPES = [
    { value: 'meeting_room', label: 'Meeting room' },
    { value: 'training_room', label: 'Training room' },
    { value: 'office', label: 'Office' },
    { value: 'workspace', label: 'Workspace' },
    { value: 'parking', label: 'Parking' },
    { value: 'other', label: 'Other' },
];

export const ZONE_TYPES = [
    { value: 'workshop', label: 'Workshop' },
    { value: 'cafe', label: 'Café' },
    { value: 'day_programme', label: 'Day programme' },
    { value: 'storage', label: 'Storage' },
    { value: 'other', label: 'Other' },
];

export const FREQUENCIES = [
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
    { value: 'fortnightly', label: 'Fortnightly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'annually', label: 'Annually' },
];

export const NZ_REGION_OPTIONS = [
    'Northland',
    'Auckland',
    'Waikato',
    'Bay of Plenty',
    'Gisborne',
    "Hawke's Bay",
    'Taranaki',
    'Manawatū-Whanganui',
    'Wellington',
    'Tasman',
    'Nelson',
    'Marlborough',
    'West Coast',
    'Canterbury',
    'Otago',
    'Southland',
];

const CITY_REGION_LOOKUP: Record<string, string> = {
    auckland: 'Auckland',
    manukau: 'Auckland',
    'north shore': 'Auckland',
    waitakere: 'Auckland',
    papakura: 'Auckland',
    devonport: 'Auckland',
    'grey lynn': 'Auckland',
    ponsonby: 'Auckland',
    'mt eden': 'Auckland',
    henderson: 'Auckland',
    takapuna: 'Auckland',
    albany: 'Auckland',
    hamilton: 'Waikato',
    cambridge: 'Waikato',
    'te awamutu': 'Waikato',
    tauranga: 'Bay of Plenty',
    rotorua: 'Bay of Plenty',
    wellington: 'Wellington',
    'lower hutt': 'Wellington',
    porirua: 'Wellington',
    'upper hutt': 'Wellington',
    'te aro': 'Wellington',
    christchurch: 'Canterbury',
    rangiora: 'Canterbury',
    dunedin: 'Otago',
    queenstown: 'Otago',
    whangarei: 'Northland',
    gisborne: 'Gisborne',
    napier: "Hawke's Bay",
    hastings: "Hawke's Bay",
    'new plymouth': 'Taranaki',
    'palmerston north': 'Manawatū-Whanganui',
    whanganui: 'Manawatū-Whanganui',
    nelson: 'Nelson',
    blenheim: 'Marlborough',
    greymouth: 'West Coast',
    invercargill: 'Southland',
};

export function deriveNzRegion(city: string): string | null {
    const needle = city.trim().toLowerCase();
    if (!needle) return null;
    if (CITY_REGION_LOOKUP[needle]) return CITY_REGION_LOOKUP[needle];
    const match = Object.entries(CITY_REGION_LOOKUP).find(([key]) =>
        needle.includes(key),
    );
    return match?.[1] ?? null;
}

export const STEPS: WizardStep[] = [
    { key: 'basics', label: 'Basics' },
    { key: 'address', label: 'Address' },
    { key: 'rooms', label: 'Rooms / Resources' },
    { key: 'contacts', label: 'Contacts' },
    { key: 'assets', label: 'Assets' },
    { key: 'documents', label: 'Documents' },
    { key: 'checklists', label: 'Checklists' },
    { key: 'safety', label: 'Safety & Review' },
];

// ── Factories ─────────────────────────────────────────────────────────────

export const emptyContact = (): Contact => ({
    type: 'site_contact',
    name: '',
    role: '',
    phone: '',
    email: '',
    is_primary: false,
    notes: '',
});

export const emptyRoom = (): Room => ({ name: '', notes: '' });

export const emptyResource = (): Resource => ({
    name: '',
    resource_type: 'meeting_room',
    capacity: '',
});

export const emptyZone = (): Zone => ({ name: '', zone_type: 'workshop' });


// ── Step props ────────────────────────────────────────────────────────────

export type StepProps = {
    data: WizardData;
    setData: SetData;
    errors: Record<string, string | undefined>;
    fieldRefs?: Partial<
        Record<keyof WizardData, React.RefObject<HTMLInputElement | HTMLTextAreaElement | null>>
    >;
    regionOptions?: string[];
};

export type ContactsStepProps = StepProps & {
    onAdd: () => void;
    onUpdate: (i: number, patch: Partial<Contact>) => void;
    onRemove: (i: number) => void;
    onSetPrimary: (i: number) => void;
};

// ── Step: Basics ─────────────────────────────────────────────────────────

export function StepBasics({
    data,
    setData,
    errors,
    fieldRefs,
    users,
}: StepProps & { users: WizardUser[] }) {
    return (
        <div className="space-y-6">
            <Header
                title="Site basics"
                subtitle="What kind of site is this, and what should we call it?"
            />

            <div>
                <FieldLabel required>Site type</FieldLabel>
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {SITE_TYPES.map((type) => {
                        const Icon = type.icon;
                        const isSelected = data.type === type.value;
                        return (
                            <button
                                key={type.value}
                                type="button"
                                onClick={() => setData('type', type.value)}
                                className={`flex flex-col items-start gap-2 rounded-lg border p-3 text-left transition-all ${
                                    isSelected
                                        ? 'border-primary bg-primary/10 ring-1 ring-primary/40'
                                        : 'border-border hover:border-primary/40 hover:bg-muted/40'
                                }`}
                            >
                                <div className="flex h-9 w-9 items-center justify-center rounded-md bg-primary/10">
                                    <Icon
                                        className={`h-4 w-4 ${isSelected ? 'text-primary' : 'text-muted-foreground'}`}
                                    />
                                </div>
                                <div>
                                    <div
                                        className={`text-sm font-semibold ${isSelected ? 'text-primary' : 'text-foreground'}`}
                                    >
                                        {type.label}
                                    </div>
                                    <div className="mt-0.5 text-xs leading-snug text-muted-foreground">
                                        {type.description}
                                    </div>
                                </div>
                            </button>
                        );
                    })}
                </div>
                {errors.type && (
                    <p className="mt-2 text-sm text-status-critical">
                        {errors.type}
                    </p>
                )}
            </div>

            <div>
                <FieldLabel htmlFor="name" required>
                    Site name
                </FieldLabel>
                <Input
                    id="name"
                    ref={fieldRefs?.name as React.RefObject<HTMLInputElement>}
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder="e.g. Aroha House"
                    aria-invalid={!!errors.name}
                    aria-describedby={errors.name ? 'name-error' : undefined}
                    className={`mt-1 ${errors.name ? 'border-status-critical ring-1 ring-status-critical/40' : ''}`}
                />
                {errors.name && (
                    <p id="name-error" className="mt-1 text-sm text-status-critical">
                        {errors.name}
                    </p>
                )}
            </div>

            <div>
                <FieldLabel htmlFor="brand_colour">Brand colour</FieldLabel>
                <div className="mt-1 flex items-center gap-2">
                    <input
                        type="color"
                        aria-label="Brand colour picker"
                        value={data.brand_colour || '#7c3aed'}
                        onChange={(e) => setData('brand_colour', e.target.value)}
                        className="h-9 w-12 shrink-0 cursor-pointer rounded-md border bg-transparent p-1"
                    />
                    <Input
                        id="brand_colour"
                        value={data.brand_colour}
                        onChange={(e) => setData('brand_colour', e.target.value)}
                        placeholder="#RRGGBB — leave blank for theme default"
                        aria-invalid={!!errors.brand_colour}
                        className={`flex-1 ${errors.brand_colour ? 'border-status-critical ring-1 ring-status-critical/40' : ''}`}
                    />
                    {data.brand_colour ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setData('brand_colour', '')}
                        >
                            Reset
                        </Button>
                    ) : null}
                </div>
                <p className="mt-1 text-xs text-muted-foreground">
                    Tints this site&apos;s medication (eMAR) page hero. Leave blank to inherit the default theme colour.
                </p>
                {errors.brand_colour && (
                    <p className="mt-1 text-sm text-status-critical">
                        {errors.brand_colour}
                    </p>
                )}
            </div>

            <div>
                <FieldLabel htmlFor="primary_contact_user_id" recommended>
                    Site Lead / Manager
                </FieldLabel>
                <Select
                    value={data.primary_contact_user_id || undefined}
                    onValueChange={(v) =>
                        setData('primary_contact_user_id', v)
                    }
                >
                    <SelectTrigger className="mt-1">
                        <SelectValue placeholder="Select manager…" />
                    </SelectTrigger>
                    <SelectContent>
                        {users.map((u) => (
                            <SelectItem key={u.id} value={u.id.toString()}>
                                {u.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="flex items-center gap-2 rounded-lg border bg-muted/30 p-3">
                <Checkbox
                    id="is_active"
                    checked={data.is_active}
                    onCheckedChange={(checked) =>
                        setData('is_active', checked as boolean)
                    }
                />
                <Label
                    htmlFor="is_active"
                    className="cursor-pointer font-normal"
                >
                    Site is active and operational
                </Label>
            </div>
        </div>
    );
}

// ── Step: Address ────────────────────────────────────────────────────────

export function StepAddress({
    data,
    setData,
    errors,
    regionOptions = NZ_REGION_OPTIONS,
}: StepProps) {
    const updateCity = (city: string) => {
        const previousDerived = deriveNzRegion(data.city);
        const nextDerived = deriveNzRegion(city);
        setData('city', city);
        if (nextDerived && (!data.region || data.region === previousDerived)) {
            setData('region', nextDerived);
        }
    };

    return (
        <div className="space-y-6">
            <Header
                title="Address"
                subtitle="Where the site is and how to find it."
            />

            <Section icon={MapPin} title="Address">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Address Line 1" error={errors.address_line_1}>
                        <Input
                            value={data.address_line_1}
                            onChange={(e) =>
                                setData('address_line_1', e.target.value)
                            }
                            placeholder="123 Example St"
                        />
                    </Field>
                    <Field label="Address Line 2" error={errors.address_line_2}>
                        <Input
                            value={data.address_line_2}
                            onChange={(e) =>
                                setData('address_line_2', e.target.value)
                            }
                            placeholder="Apt / Unit (optional)"
                        />
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Field label="Suburb" error={errors.suburb}>
                        <Input
                            value={data.suburb}
                            onChange={(e) => setData('suburb', e.target.value)}
                        />
                    </Field>
                    <Field label="City" error={errors.city}>
                        <Input
                            value={data.city}
                            onChange={(e) => updateCity(e.target.value)}
                        />
                    </Field>
                    <Field label="Postcode" error={errors.postcode}>
                        <Input
                            value={data.postcode}
                            onChange={(e) =>
                                setData('postcode', e.target.value)
                            }
                        />
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Country" error={errors.country}>
                        <Input
                            value={data.country}
                            onChange={(e) =>
                                setData('country', e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Region" recommended error={errors.region}>
                        <Select
                            value={data.region || undefined}
                            onValueChange={(v) => setData('region', v)}
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="Select region..." />
                            </SelectTrigger>
                            <SelectContent>
                                {regionOptions.map((region) => (
                                    <SelectItem key={region} value={region}>
                                        {region}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Latitude (GPS)" error={errors.latitude}>
                        <Input
                            type="number"
                            step="any"
                            value={data.latitude}
                            onChange={(e) =>
                                setData('latitude', e.target.value)
                            }
                            placeholder="-36.8485"
                        />
                    </Field>
                    <Field label="Longitude (GPS)" error={errors.longitude}>
                        <Input
                            type="number"
                            step="any"
                            value={data.longitude}
                            onChange={(e) =>
                                setData('longitude', e.target.value)
                            }
                            placeholder="174.7633"
                        />
                    </Field>
                </div>

                <Field
                    label="Access instructions"
                    hint="Permission-protected. Gate codes, key locations, parking…"
                    error={errors.access_instructions}
                >
                    <Textarea
                        value={data.access_instructions}
                        onChange={(e) =>
                            setData('access_instructions', e.target.value)
                        }
                        rows={3}
                    />
                </Field>
            </Section>
        </div>
    );
}

// ── Step: Rooms / Resources / Zones (type-specific) ──────────────────────

export function StepRoomsOrResources({ data, setData }: StepProps) {
    if (data.type === 'house') {
        return (
            <div className="space-y-6">
                <Header
                    title="Rooms"
                    subtitle="Add the bedrooms, bathrooms, and shared spaces in this house. You can assign clients to rooms later."
                />
                <Section icon={BedDouble} title="House rooms">
                    {data.rooms.length === 0 ? (
                        <EmptyState
                            icon={DoorOpen}
                            title="No rooms yet"
                            hint="Add rooms like Bedroom 1, Bathroom, Kitchen, Garage…"
                        />
                    ) : (
                        <div className="space-y-2">
                            {data.rooms.map((room, i) => (
                                <div
                                    key={room.id ?? `new-${i}`}
                                    className="flex items-start gap-2 rounded-lg border p-3"
                                >
                                    <div className="flex-1 space-y-2">
                                        <Input
                                            value={room.name}
                                            onChange={(e) =>
                                                setData(
                                                    'rooms',
                                                    data.rooms.map((r, j) =>
                                                        j === i
                                                            ? {
                                                                  ...r,
                                                                  name: e.target
                                                                      .value,
                                                              }
                                                            : r,
                                                    ),
                                                )
                                            }
                                            placeholder="Room name"
                                            aria-invalid={!!roomNameWarning(room.name)}
                                        />
                                        {roomNameWarning(room.name) && (
                                            <p className="text-xs text-status-warning">
                                                {roomNameWarning(room.name)}
                                            </p>
                                        )}
                                        <Input
                                            value={room.notes}
                                            onChange={(e) =>
                                                setData(
                                                    'rooms',
                                                    data.rooms.map((r, j) =>
                                                        j === i
                                                            ? {
                                                                  ...r,
                                                                  notes: e
                                                                      .target
                                                                      .value,
                                                              }
                                                            : r,
                                                    ),
                                                )
                                            }
                                            placeholder="Notes (optional)"
                                            className="text-sm"
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            setData(
                                                'rooms',
                                                data.rooms.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                        className="text-status-critical hover:text-status-critical"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            ))}
                        </div>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            setData('rooms', [...data.rooms, emptyRoom()])
                        }
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add room
                    </Button>
                </Section>
            </div>
        );
    }

    if (data.type === 'head_office') {
        return (
            <div className="space-y-6">
                <Header
                    title="Resources"
                    subtitle="Bookable rooms and resources at this head office."
                />
                <Section icon={DoorOpen} title="Rooms & resources">
                    {data.resources.length === 0 ? (
                        <EmptyState
                            icon={DoorOpen}
                            title="No resources yet"
                            hint="Add meeting rooms, training rooms, offices, parking spots…"
                        />
                    ) : (
                        <div className="space-y-3">
                            {data.resources.map((r, i) => (
                                <div
                                    key={r.id ?? `new-${i}`}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="text-sm font-semibold">
                                            {r.name.trim() ||
                                                `Resource ${i + 1}`}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setData(
                                                    'resources',
                                                    data.resources.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                            className="text-status-critical hover:text-status-critical"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                    <div className="mt-3 grid gap-3 sm:grid-cols-3">
                                        <Field label="Name">
                                            <Input
                                                value={r.name}
                                                onChange={(e) =>
                                                    setData(
                                                        'resources',
                                                        data.resources.map(
                                                            (x, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...x,
                                                                          name: e
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : x,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field label="Type">
                                            <Select
                                                value={r.resource_type}
                                                onValueChange={(v) =>
                                                    setData(
                                                        'resources',
                                                        data.resources.map(
                                                            (x, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...x,
                                                                          resource_type:
                                                                              v,
                                                                      }
                                                                    : x,
                                                        ),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {RESOURCE_TYPES.map(
                                                        (t) => (
                                                            <SelectItem
                                                                key={t.value}
                                                                value={t.value}
                                                            >
                                                                {t.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <Field label="Capacity">
                                            <Input
                                                type="number"
                                                min={0}
                                                value={r.capacity}
                                                onChange={(e) =>
                                                    setData(
                                                        'resources',
                                                        data.resources.map(
                                                            (x, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...x,
                                                                          capacity:
                                                                              e
                                                                                  .target
                                                                                  .value,
                                                                      }
                                                                    : x,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            setData('resources', [
                                ...data.resources,
                                emptyResource(),
                            ])
                        }
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add resource
                    </Button>
                </Section>
            </div>
        );
    }

    if (data.type === 'facility') {
        return (
            <div className="space-y-6">
                <Header
                    title="Areas & zones"
                    subtitle="Define operational zones at this facility."
                />
                <Section icon={LayoutGrid} title="Facility zones">
                    {data.zones.length === 0 ? (
                        <EmptyState
                            icon={LayoutGrid}
                            title="No zones yet"
                            hint="Add zones like Kitchen, Workshop, Café front-of-house…"
                        />
                    ) : (
                        <div className="space-y-3">
                            {data.zones.map((z, i) => (
                                <div
                                    key={z.id ?? `new-${i}`}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="text-sm font-semibold">
                                            {z.name.trim() ||
                                                `Zone ${i + 1}`}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setData(
                                                    'zones',
                                                    data.zones.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                            className="text-status-critical hover:text-status-critical"
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                    <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                        <Field label="Name">
                                            <Input
                                                value={z.name}
                                                onChange={(e) =>
                                                    setData(
                                                        'zones',
                                                        data.zones.map(
                                                            (x, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...x,
                                                                          name: e
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : x,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        <Field label="Type">
                                            <Select
                                                value={
                                                    z.zone_type || 'workshop'
                                                }
                                                onValueChange={(v) =>
                                                    setData(
                                                        'zones',
                                                        data.zones.map(
                                                            (x, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...x,
                                                                          zone_type:
                                                                              v,
                                                                      }
                                                                    : x,
                                                        ),
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {ZONE_TYPES.map((t) => (
                                                        <SelectItem
                                                            key={t.value}
                                                            value={t.value}
                                                        >
                                                            {t.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            setData('zones', [...data.zones, emptyZone()])
                        }
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        Add zone
                    </Button>
                </Section>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <Header
                title="Rooms"
                subtitle="No type-specific rooms apply to this site type."
            />
            <p className="text-sm text-muted-foreground">
                Residential client homes don't have shared rooms tracked here.
                Continue to the next step.
            </p>
        </div>
    );
}

// ── Step: Contacts ────────────────────────────────────────────────────────

export function StepContacts({
    data,
    setData,
    errors,
    onAdd,
    onUpdate,
    onRemove,
    onSetPrimary,
}: ContactsStepProps) {
    return (
        <div className="space-y-6">
            <Header
                title="Contacts & Vendors"
                subtitle="Site-level phone & email, plus any people we should know about."
            />

            <Section icon={Phone} title="Site contact details">
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Primary phone" recommended error={errors.phone}>
                        <Input
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                        />
                    </Field>
                    <Field label="Email" recommended error={errors.email}>
                        <Input
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                    </Field>
                </div>

                <p className="text-sm text-muted-foreground">
                    Add site leads, managers, and after-hours contacts in the
                    people list below so the Overview and Contacts tab stay in
                    sync.
                </p>
            </Section>

            <Section icon={Users} title="Additional contacts & vendors">
                {data.contacts.length === 0 ? (
                    <EmptyState
                        icon={UserIcon}
                        title="No additional contacts yet"
                        hint="Add family, GPs, vendors, or anyone else worth keeping on file."
                    />
                ) : (
                    <div className="space-y-3">
                        {data.contacts.map((contact, index) => (
                            <ContactCard
                                key={contact.id ?? `new-${index}`}
                                index={index}
                                contact={contact}
                                onUpdate={onUpdate}
                                onRemove={onRemove}
                                onSetPrimary={onSetPrimary}
                            />
                        ))}
                    </div>
                )}

                <Button
                    type="button"
                    variant="outline"
                    onClick={onAdd}
                    className="w-full sm:w-auto"
                >
                    <Plus className="mr-1.5 h-4 w-4" />
                    Add contact
                </Button>
            </Section>
        </div>
    );
}

function ContactCard({
    index,
    contact,
    onUpdate,
    onRemove,
    onSetPrimary,
}: {
    index: number;
    contact: Contact;
    onUpdate: (i: number, patch: Partial<Contact>) => void;
    onRemove: (i: number) => void;
    onSetPrimary: (i: number) => void;
}) {
    return (
        <div
            className={`relative rounded-lg border p-4 transition-colors ${contact.is_primary ? 'border-primary/40 bg-primary/5' : 'border-border bg-background'}`}
        >
            <div className="mb-3 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className="flex h-7 w-7 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground">
                        {index + 1}
                    </span>
                    <span className="text-sm font-semibold">
                        {contact.name.trim() || `Contact ${index + 1}`}
                    </span>
                    {contact.is_primary && (
                        <span className="inline-flex items-center gap-1 rounded-full border border-primary/30 bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary">
                            <Star className="h-3 w-3 fill-primary" />
                            Primary
                        </span>
                    )}
                </div>
                <div className="flex items-center gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onSetPrimary(index)}
                        className="h-8 px-2"
                        title="Mark as primary"
                    >
                        <Star
                            className={`h-3.5 w-3.5 ${contact.is_primary ? 'fill-primary text-primary' : ''}`}
                        />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onRemove(index)}
                        className="h-8 px-2 text-status-critical hover:text-status-critical"
                        title="Remove contact"
                    >
                        <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Name">
                    <Input
                        value={contact.name}
                        onChange={(e) =>
                            onUpdate(index, { name: e.target.value })
                        }
                        placeholder="Full name"
                    />
                </Field>
                <Field label="Type">
                    <Select
                        value={contact.type || 'site_contact'}
                        onValueChange={(v) => onUpdate(index, { type: v })}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {CONTACT_TYPES.map((t) => (
                                <SelectItem key={t.key} value={t.key}>
                                    {t.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field label="Role">
                    <Input
                        value={contact.role}
                        onChange={(e) =>
                            onUpdate(index, { role: e.target.value })
                        }
                        placeholder="e.g. Mother, GP"
                    />
                </Field>
                <Field label="Phone">
                    <Input
                        value={contact.phone}
                        onChange={(e) =>
                            onUpdate(index, { phone: e.target.value })
                        }
                    />
                </Field>
                <Field label="Email">
                    <Input
                        type="email"
                        value={contact.email}
                        onChange={(e) =>
                            onUpdate(index, { email: e.target.value })
                        }
                    />
                </Field>
            </div>

            <Field label="Notes">
                <Textarea
                    value={contact.notes}
                    onChange={(e) =>
                        onUpdate(index, { notes: e.target.value })
                    }
                    rows={2}
                    placeholder="Best time to call, preferred channel, etc."
                />
            </Field>
        </div>
    );
}

// ── Step: Assets ──────────────────────────────────────────────────────────

export function StepAssets({
    data,
    setData,
    availableAssets,
}: StepProps & { availableAssets: AvailableAsset[] }) {
    const [search, setSearch] = useState('');
    const selectedSet = new Set(data.assets);

    const filtered = availableAssets.filter((a) => {
        if (!search.trim()) return true;
        const q = search.trim().toLowerCase();
        return (
            a.name.toLowerCase().includes(q) ||
            (a.asset_tag ?? '').toLowerCase().includes(q) ||
            (a.category ?? '').toLowerCase().includes(q) ||
            (a.serial_number ?? '').toLowerCase().includes(q)
        );
    });

    const toggle = (id: number) => {
        if (selectedSet.has(id)) {
            setData(
                'assets',
                data.assets.filter((x) => x !== id),
            );
        } else {
            setData('assets', [...data.assets, id]);
        }
    };

    return (
        <div className="space-y-6">
            <Header
                title="Assign assets"
                subtitle="Pick from the assets pool. Only unassigned assets (and ones already on this site) are listed. To create a new asset, use the Assets module."
            />

            <Section icon={Package} title={`Available assets (${availableAssets.length})`}>
                {availableAssets.length === 0 ? (
                    <EmptyState
                        icon={Package}
                        title="No assets available"
                        hint="All assets are already assigned to other sites. Create new ones from the Assets module."
                    />
                ) : (
                    <>
                        <Input
                            type="search"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by name, tag, category…"
                        />

                        {data.assets.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                {data.assets.length} selected
                            </p>
                        )}

                        <div className="max-h-96 space-y-1 overflow-y-auto rounded-lg border p-1">
                            {filtered.length === 0 ? (
                                <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                                    No assets match "{search}".
                                </p>
                            ) : (
                                filtered.map((asset) => {
                                    const checked = selectedSet.has(asset.id);
                                    return (
                                        <label
                                            key={asset.id}
                                            htmlFor={`asset-${asset.id}`}
                                            className={`flex cursor-pointer items-start gap-3 rounded-md p-2 transition-colors ${
                                                checked
                                                    ? 'bg-primary/10'
                                                    : 'hover:bg-muted/50'
                                            }`}
                                        >
                                            <Checkbox
                                                id={`asset-${asset.id}`}
                                                checked={checked}
                                                onCheckedChange={() =>
                                                    toggle(asset.id)
                                                }
                                                className="mt-0.5"
                                            />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span
                                                        className={`text-sm font-medium ${checked ? 'text-primary' : ''}`}
                                                    >
                                                        {asset.name}
                                                    </span>
                                                    {asset.is_assigned_here && (
                                                        <span className="rounded-full border border-status-success/30 bg-status-success-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-success">
                                                            Currently here
                                                        </span>
                                                    )}
                                                </div>
                                                <div className="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                                                    {asset.asset_tag && (
                                                        <span>
                                                            #{asset.asset_tag}
                                                        </span>
                                                    )}
                                                    {asset.category && (
                                                        <span>
                                                            {asset.category}
                                                        </span>
                                                    )}
                                                    {asset.serial_number && (
                                                        <span>
                                                            S/N{' '}
                                                            {asset.serial_number}
                                                        </span>
                                                    )}
                                                </div>
                                            </div>
                                        </label>
                                    );
                                })
                            )}
                        </div>
                    </>
                )}
            </Section>
        </div>
    );
}

// ── Step: Documents ───────────────────────────────────────────────────────

export type DocumentsStepProps = {
    /** Pending file uploads (used in CREATE mode — staged until site exists). */
    pending: DocumentDraft[];
    /** Already-uploaded documents (EDIT mode only). */
    existing?: DocumentRecord[];
    onAddPending: (draft: DocumentDraft) => void;
    onRemovePending: (index: number) => void;
    onDeleteExisting?: (id: number) => Promise<void> | void;
};

export function StepDocuments({
    pending,
    existing,
    onAddPending,
    onRemovePending,
    onDeleteExisting,
}: DocumentsStepProps) {
    return (
        <div className="space-y-6">
            <Header
                title="Key documents"
                subtitle="Evacuation plans, compliance certificates, leases — anything worth keeping on the site record."
            />

            <DocumentUploader onAdd={onAddPending} />

            {pending.length > 0 && (
                <Section
                    icon={Upload}
                    title={`Ready to upload (${pending.length})`}
                >
                    <div className="space-y-2">
                        {pending.map((draft, i) => (
                            <div
                                key={`pending-${i}`}
                                className="flex items-center justify-between rounded-lg border bg-muted/20 p-3 text-sm"
                            >
                                <div className="flex items-center gap-3 min-w-0">
                                    <FileText className="h-4 w-4 shrink-0 text-primary" />
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {draft.title || draft.file.name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {draft.file.name}
                                            {draft.category &&
                                                ` · ${draft.category}`}
                                            {' · '}
                                            {formatFileSize(draft.file.size)}
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => onRemovePending(i)}
                                    className="text-status-critical hover:text-status-critical"
                                >
                                    <Trash2 className="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        ))}
                    </div>
                </Section>
            )}

            {existing && existing.length > 0 && (
                <Section
                    icon={FileText}
                    title={`Saved documents (${existing.length})`}
                >
                    <div className="space-y-2">
                        {existing.map((doc) => (
                            <div
                                key={doc.id}
                                className="flex items-center justify-between rounded-lg border p-3 text-sm"
                            >
                                <div className="flex items-center gap-3 min-w-0">
                                    <FileText className="h-4 w-4 shrink-0 text-primary" />
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {doc.title || doc.original_name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {doc.original_name}
                                            {doc.category &&
                                                ` · ${doc.category}`}
                                            {doc.size_bytes
                                                ? ` · ${formatFileSize(doc.size_bytes)}`
                                                : ''}
                                            {doc.expiry_date &&
                                                ` · expires ${doc.expiry_date}`}
                                        </p>
                                    </div>
                                </div>
                                {onDeleteExisting && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            onDeleteExisting(doc.id)
                                        }
                                        className="text-status-critical hover:text-status-critical"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                )}
                            </div>
                        ))}
                    </div>
                </Section>
            )}
        </div>
    );
}

function DocumentUploader({
    onAdd,
}: {
    onAdd: (draft: DocumentDraft) => void;
}) {
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [title, setTitle] = useState('');
    const [category, setCategory] = useState<string | undefined>(undefined);
    const [expiryDate, setExpiryDate] = useState('');
    const [notes, setNotes] = useState('');
    const [uploadError, setUploadError] = useState<string | null>(null);

    const reset = () => {
        if (fileInputRef.current) fileInputRef.current.value = '';
        setTitle('');
        setCategory(undefined);
        setExpiryDate('');
        setNotes('');
        setUploadError(null);
    };

    return (
        <Section icon={Upload} title="Upload document">
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Title *">
                    <Input
                        value={title}
                        onChange={(e) => setTitle(e.target.value)}
                        placeholder="e.g. Fire Evacuation Plan"
                    />
                </Field>
                <Field label="Category">
                    <Select value={category} onValueChange={setCategory}>
                        <SelectTrigger>
                            <SelectValue placeholder="Select category" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="evacuation_plan">
                                Evacuation Plan
                            </SelectItem>
                            <SelectItem value="compliance_cert">
                                Compliance Certificate
                            </SelectItem>
                            <SelectItem value="insurance">Insurance</SelectItem>
                            <SelectItem value="lease">
                                Lease / Tenancy
                            </SelectItem>
                            <SelectItem value="safety">
                                Health & Safety
                            </SelectItem>
                            <SelectItem value="policy">Policy</SelectItem>
                            <SelectItem value="other">Other</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
            </div>

            <Field label="Expiry date">
                <Input
                    type="date"
                    value={expiryDate}
                    onChange={(e) => setExpiryDate(e.target.value)}
                />
            </Field>

            <Field label="Comments">
                <Textarea
                    value={notes}
                    onChange={(e) => setNotes(e.target.value)}
                    rows={2}
                    placeholder="Add any comments or notes about this document…"
                />
            </Field>

            <Field
                label="File *"
                hint="PDF, Word, Excel, or images. Max 20MB."
            >
                <Input
                    ref={fileInputRef}
                    type="file"
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp"
                    className="cursor-pointer"
                />
            </Field>

            {uploadError && (
                <p
                    role="alert"
                    className="rounded-md border border-status-critical/30 bg-status-critical-bg px-3 py-2 text-sm text-status-critical"
                >
                    {uploadError}
                </p>
            )}

            <Button
                type="button"
                onClick={() => {
                    const file = fileInputRef.current?.files?.[0];
                    if (!file || !title.trim()) {
                        setUploadError(
                            'Please choose a file and enter a title.',
                        );
                        return;
                    }
                    setUploadError(null);
                    onAdd({
                        file,
                        title: title.trim(),
                        category: category ?? '',
                        expiry_date: expiryDate,
                        notes,
                    });
                    reset();
                }}
            >
                <Upload className="mr-1.5 h-4 w-4" />
                Add to upload list
            </Button>
        </Section>
    );
}

// ── Step: Checklists ──────────────────────────────────────────────────────

export function StepChecklists({
    data,
    setData,
    templates,
    users,
}: StepProps & { templates: ChecklistTemplate[]; users: WizardUser[] }) {
    const applicable = templates.filter(
        (t) =>
            !t.applicable_to_type ||
            t.applicable_to_type === 'all' ||
            t.applicable_to_type === data.type,
    );

    const toggleTemplate = (templateId: number, enabled: boolean) => {
        const existing = data.checklists.find(
            (c) => c.template_id === templateId,
        );
        if (existing) {
            setData(
                'checklists',
                data.checklists.map((c) =>
                    c.template_id === templateId ? { ...c, enabled } : c,
                ),
            );
        } else {
            const tmpl = templates.find((t) => t.id === templateId);
            setData('checklists', [
                ...data.checklists,
                {
                    template_id: templateId,
                    enabled,
                    frequency: tmpl?.frequency || 'monthly',
                    assigned_to_user_id: '',
                },
            ]);
        }
    };

    const updateAssignment = (
        templateId: number,
        patch: Partial<ChecklistAssignment>,
    ) => {
        setData(
            'checklists',
            data.checklists.map((c) =>
                c.template_id === templateId ? { ...c, ...patch } : c,
            ),
        );
    };

    return (
        <div className="space-y-6">
            <Header
                title="Checklist scheduling"
                subtitle="Set up recurring checklists for this site. You can change these any time."
            />

            <Section icon={ClipboardCheck} title="Available checklists">
                {applicable.length === 0 ? (
                    <EmptyState
                        icon={ClipboardCheck}
                        title="No templates available"
                        hint={`No checklist templates exist for ${data.type.replace('_', ' ')} sites yet.`}
                    />
                ) : (
                    <div className="space-y-3">
                        {applicable.map((template) => {
                            const assignment = data.checklists.find(
                                (c) => c.template_id === template.id,
                            );
                            const isEnabled = assignment?.enabled ?? false;
                            return (
                                <div
                                    key={template.id}
                                    className={`rounded-lg border p-4 transition-colors ${isEnabled ? 'border-primary/40 bg-primary/5' : 'border-border'}`}
                                >
                                    <div className="flex items-start gap-3">
                                        <Checkbox
                                            checked={isEnabled}
                                            onCheckedChange={(v) =>
                                                toggleTemplate(
                                                    template.id,
                                                    v as boolean,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-semibold">
                                                {template.name}
                                            </div>
                                            {template.description && (
                                                <div className="mt-0.5 text-xs text-muted-foreground">
                                                    {template.description}
                                                </div>
                                            )}

                                            {isEnabled && (
                                                <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                                    <Field label="Frequency">
                                                        <Select
                                                            value={
                                                                assignment?.frequency ||
                                                                'monthly'
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                updateAssignment(
                                                                    template.id,
                                                                    {
                                                                        frequency:
                                                                            v,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {FREQUENCIES.map(
                                                                    (f) => (
                                                                        <SelectItem
                                                                            key={
                                                                                f.value
                                                                            }
                                                                            value={
                                                                                f.value
                                                                            }
                                                                        >
                                                                            {
                                                                                f.label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                    </Field>
                                                    <Field label="Assign to">
                                                        <Select
                                                            value={
                                                                assignment?.assigned_to_user_id ||
                                                                undefined
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                updateAssignment(
                                                                    template.id,
                                                                    {
                                                                        assigned_to_user_id:
                                                                            v,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Anyone" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {users.map(
                                                                    (u) => (
                                                                        <SelectItem
                                                                            key={
                                                                                u.id
                                                                            }
                                                                            value={u.id.toString()}
                                                                        >
                                                                            {
                                                                                u.name
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                    </Field>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </Section>
        </div>
    );
}

// ── Step: Safety ──────────────────────────────────────────────────────────

export function StepSafety({ data, setData, errors }: StepProps) {
    const showRiskFields = data.is_high_risk || data.is_high_needs;
    return (
        <div className="space-y-6">
            <Header
                title="Safety & risk"
                subtitle="Optional. Update any time from this screen."
            />

            <Section icon={Shield} title="Safety information">
                <Field
                    label="Emergency plan location"
                    recommended
                    error={errors.emergency_plan_location}
                >
                    <Input
                        value={data.emergency_plan_location}
                        onChange={(e) =>
                            setData(
                                'emergency_plan_location',
                                e.target.value,
                            )
                        }
                        placeholder="e.g. Kitchen drawer, Office filing cabinet"
                    />
                </Field>
                <Field
                    label="Medication storage location"
                    recommended
                    error={errors.medication_storage_location}
                >
                    <Input
                        value={data.medication_storage_location}
                        onChange={(e) =>
                            setData(
                                'medication_storage_location',
                                e.target.value,
                            )
                        }
                        placeholder="e.g. Locked cabinet in office"
                    />
                </Field>
            </Section>

            <Section icon={AlertTriangle} title="Risk assessment" tone="warning">
                <div className="grid gap-3 sm:grid-cols-2">
                    <FlagOption
                        id="is_high_risk"
                        label="High Risk Site"
                        description="Hazards, complex environment, or special precautions."
                        checked={data.is_high_risk}
                        onChange={(v) => setData('is_high_risk', v)}
                    />
                    <FlagOption
                        id="is_high_needs"
                        label="High Needs Site"
                        description="Clients require intensive support or supervision."
                        checked={data.is_high_needs}
                        onChange={(v) => setData('is_high_needs', v)}
                    />
                </div>

                {showRiskFields && (
                    <div className="space-y-4 rounded-lg border border-status-warning/30 bg-status-warning-bg/40 p-4">
                        <Field
                            label="Risk notes / reason"
                            error={errors.risk_notes}
                        >
                            <Textarea
                                value={data.risk_notes}
                                onChange={(e) =>
                                    setData('risk_notes', e.target.value)
                                }
                                rows={3}
                            />
                        </Field>
                        <Field
                            label="Risk review date"
                            error={errors.risk_review_date}
                        >
                            <Input
                                type="date"
                                value={data.risk_review_date}
                                onChange={(e) =>
                                    setData(
                                        'risk_review_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                )}
            </Section>

            <Field label="General notes" error={errors.notes}>
                <Textarea
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    rows={4}
                    placeholder="Anything else worth knowing about this site…"
                />
            </Field>
        </div>
    );
}

// ── Shared helpers ────────────────────────────────────────────────────────

export function Header({
    title,
    subtitle,
}: {
    title: string;
    subtitle: string;
}) {
    return (
        <div>
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
        </div>
    );
}

export function Section({
    icon: Icon,
    title,
    tone = 'default',
    children,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    tone?: 'default' | 'warning';
    children: React.ReactNode;
}) {
    const accent =
        tone === 'warning'
            ? 'bg-status-warning-bg text-status-warning'
            : 'bg-primary/10 text-primary';
    return (
        <div className="space-y-4">
            <div className="flex items-center gap-2">
                <span
                    className={`flex h-8 w-8 items-center justify-center rounded-lg ${accent}`}
                >
                    <Icon className="h-4 w-4" />
                </span>
                <h3 className="text-sm font-semibold">{title}</h3>
            </div>
            <div className="space-y-4">{children}</div>
        </div>
    );
}

export function Field({
    label,
    error,
    hint,
    required,
    recommended,
    optional,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    recommended?: boolean;
    optional?: boolean;
    children: React.ReactNode;
}) {
    const reactId = useId();
    const errorId = `${reactId}-error`;
    const hasError = Boolean(error);

    const enhancedChildren = Children.map(children, (child) => {
        if (!isValidElement(child)) return child;
        const element = child as ReactElement<{
            'aria-invalid'?: boolean;
            'aria-describedby'?: string;
        }>;
        const props: Record<string, unknown> = {};
        if (hasError) {
            props['aria-invalid'] = true;
            const existingDescribedBy = element.props['aria-describedby'];
            props['aria-describedby'] = existingDescribedBy
                ? `${existingDescribedBy} ${errorId}`
                : errorId;
        }
        return Object.keys(props).length > 0
            ? cloneElement(element, props)
            : element;
    });

    return (
        <div
            className={
                hasError
                    ? '[&_input]:border-status-critical [&_input]:ring-1 [&_input]:ring-status-critical/40 [&_textarea]:border-status-critical [&_textarea]:ring-1 [&_textarea]:ring-status-critical/40'
                    : undefined
            }
        >
            <FieldLabel
                required={required}
                recommended={recommended}
                optional={optional}
            >
                {label}
            </FieldLabel>
            {enhancedChildren}
            {hint && !error && (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            )}
            {error && (
                <p id={errorId} className="mt-1 text-sm text-status-critical">{error}</p>
            )}
        </div>
    );
}

export function FlagOption({
    id,
    label,
    description,
    checked,
    onChange,
}: {
    id: string;
    label: string;
    description: string;
    checked: boolean;
    onChange: (v: boolean) => void;
}) {
    return (
        <label
            htmlFor={id}
            className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors ${
                checked
                    ? 'border-status-warning/50 bg-status-warning-bg/40'
                    : 'border-border hover:bg-muted/40'
            }`}
        >
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(v) => onChange(v as boolean)}
                className="mt-0.5"
            />
            <div>
                <div className="text-sm font-medium">{label}</div>
                <div className="mt-0.5 text-xs text-muted-foreground">
                    {description}
                </div>
            </div>
        </label>
    );
}

function EmptyState({
    icon: Icon,
    title,
    hint,
}: {
    icon: ComponentType<{ className?: string }>;
    title: string;
    hint: string;
}) {
    return (
        <div className="rounded-lg border border-dashed p-6 text-center">
            <div className="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-muted">
                <Icon className="h-5 w-5 text-muted-foreground" />
            </div>
            <p className="mt-2 text-sm text-muted-foreground">{title}</p>
            <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
        </div>
    );
}

function formatFileSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function roomNameWarning(name: string): string | null {
    const value = name.trim();
    if (!value) return null;
    if (value.length < 2) return 'Use a clear room name.';
    const whitelisted = /^(bedroom|kitchen|lounge|bathroom|laundry|hallway|garage|garden|office|dining|living|ensuite)/i;
    if (whitelisted.test(value)) return null;
    if (/^[a-z]{4,8}$/i.test(value)) {
        return "This doesn't look like a real room name. Examples: Bedroom 1, Master bedroom, Lounge, Ensuite.";
    }
    if (!/[aeiou0-9 ]/i.test(value)) {
        return 'This looks like a placeholder. Use names such as Bedroom 1, Kitchen, Bathroom, or Lounge.';
    }
    return null;
}
