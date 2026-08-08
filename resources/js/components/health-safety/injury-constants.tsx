/* Injuries & RTW — shared constants (labels, lucide icons, tone maps, lifecycle).
 * Used by the register, the create/edit wizard and the detail dialog so the 15
 * injury types, severities and statuses read identically everywhere. */
import type { Tone } from '@/pages/health-safety/components/register-row-kit';
import {
    Activity,
    Biohazard,
    Bone,
    Brain,
    BrainCircuit,
    CircleDot,
    Flame,
    FlaskConical,
    HelpCircle,
    Package,
    PersonStanding,
    RefreshCw,
    Slice,
    Syringe,
    Thermometer,
    type LucideIcon,
} from 'lucide-react';

export type InjuryTypeOption = {
    key: string;
    label: string;
    description: string;
    icon: LucideIcon;
};

/** The 15 canonical injury_type values (matches ReturnToWorkController). */
export const INJURY_TYPES: InjuryTypeOption[] = [
    {
        key: 'strain',
        label: 'Muscle strain',
        description: 'Soft-tissue / muscle',
        icon: Activity,
    },
    {
        key: 'laceration',
        label: 'Laceration',
        description: 'Cut or open wound',
        icon: Slice,
    },
    {
        key: 'fracture',
        label: 'Fracture',
        description: 'Broken bone',
        icon: Bone,
    },
    {
        key: 'burn',
        label: 'Burn',
        description: 'Heat / friction / chemical',
        icon: Flame,
    },
    {
        key: 'contusion',
        label: 'Contusion / bruise',
        description: 'Bruise or crush',
        icon: CircleDot,
    },
    {
        key: 'concussion',
        label: 'Concussion',
        description: 'Head injury',
        icon: Brain,
    },
    {
        key: 'repetitive_strain',
        label: 'Repetitive strain',
        description: 'Overuse / RSI',
        icon: RefreshCw,
    },
    {
        key: 'chemical_exposure',
        label: 'Chemical exposure',
        description: 'Substance contact',
        icon: FlaskConical,
    },
    {
        key: 'biological_exposure',
        label: 'Biological exposure',
        description: 'Bodily fluids',
        icon: Biohazard,
    },
    {
        key: 'needle_stick',
        label: 'Needle-stick',
        description: 'Sharps injury',
        icon: Syringe,
    },
    {
        key: 'slip_trip_fall',
        label: 'Slip / trip / fall',
        description: 'Loss of footing',
        icon: PersonStanding,
    },
    {
        key: 'manual_handling',
        label: 'Manual handling',
        description: 'Lifting / moving',
        icon: Package,
    },
    {
        key: 'psychological',
        label: 'Psychological',
        description: 'Stress / trauma',
        icon: BrainCircuit,
    },
    {
        key: 'illness',
        label: 'Work illness',
        description: 'Work-related',
        icon: Thermometer,
    },
    {
        key: 'other',
        label: 'Other',
        description: 'Not listed',
        icon: HelpCircle,
    },
];

const TYPE_BY_KEY: Record<string, InjuryTypeOption> = Object.fromEntries(
    INJURY_TYPES.map((t) => [t.key, t]),
);

export function injuryTypeLabel(key: string | null | undefined): string {
    if (!key) return '—';
    return TYPE_BY_KEY[key]?.label ?? key.replace(/_/g, ' ');
}

export function injuryTypeIcon(key: string | null | undefined): LucideIcon {
    return (key && TYPE_BY_KEY[key]?.icon) || HelpCircle;
}

/** medical_treatment_type (8 enum values). */
export const TREATMENT_OPTIONS: { value: string; label: string }[] = [
    { value: 'none', label: 'None required' },
    { value: 'first_aid', label: 'First aid' },
    { value: 'gp_visit', label: 'GP visit' },
    { value: 'hospital', label: 'Hospital' },
    { value: 'emergency_department', label: 'Emergency department' },
    { value: 'hospitalisation', label: 'Hospitalisation' },
    { value: 'specialist', label: 'Specialist' },
    { value: 'ongoing', label: 'Ongoing treatment' },
];

export function treatmentLabel(value: string | null | undefined): string {
    if (!value) return '—';
    return (
        TREATMENT_OPTIONS.find((t) => t.value === value)?.label ??
        value.replace(/_/g, ' ')
    );
}

/** severity → tone (uses register-row-kit TONE_BG/TONE_DOT keys). */
export const SEVERITY_OPTIONS: { value: string; label: string }[] = [
    { value: 'minor', label: 'Minor' },
    { value: 'moderate', label: 'Moderate' },
    { value: 'serious', label: 'Serious' },
    { value: 'critical', label: 'Critical' },
];

export const SEVERITY_TONE: Record<string, Tone> = {
    minor: 'success',
    moderate: 'warning',
    serious: 'critical',
    critical: 'critical',
};

export function severityLabel(value: string | null | undefined): string {
    if (!value) return '—';
    return SEVERITY_OPTIONS.find((s) => s.value === value)?.label ?? value;
}

/** Canonical lifecycle order. */
export const STATUS_ORDER = [
    'reported',
    'under_treatment',
    'return_to_work',
    'recovered',
    'closed',
] as const;
export type InjuryStatus = (typeof STATUS_ORDER)[number];

/** status → label + chip/dot classes (info aliases primary; return_to_work uses the teal --live). */
export const STATUS_META: Record<
    string,
    { label: string; chip: string; dot: string }
> = {
    reported: {
        label: 'Reported',
        chip: 'bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
    },
    under_treatment: {
        label: 'Under treatment',
        chip: 'bg-status-info-bg text-status-info',
        dot: 'bg-status-info',
    },
    return_to_work: {
        label: 'Return to work',
        chip: 'bg-live-bg text-live',
        dot: 'bg-live',
    },
    recovered: {
        label: 'Recovered',
        chip: 'bg-status-success-bg text-status-success',
        dot: 'bg-status-success',
    },
    closed: {
        label: 'Closed',
        chip: 'bg-muted text-muted-foreground',
        dot: 'bg-muted-foreground',
    },
};

export function statusLabel(value: string | null | undefined): string {
    if (!value) return '—';
    return STATUS_META[value]?.label ?? value.replace(/_/g, ' ');
}

export function injuryReference(injury: {
    id: number;
    reference?: string | null;
    reference_number?: string | null;
}): string {
    // Stored ticket number (INJ-YYYY-NNNN) since 2026-07; WI-<id> only for
    // rows that predate the backfill. The RTW payloads send it as `reference`.
    return (
        injury.reference ??
        injury.reference_number ??
        `WI-${String(injury.id).padStart(4, '0')}`
    );
}

/** ACC claim kinds for the premium document upload. */
export const ATTACHMENT_KINDS: { value: string; label: string }[] = [
    { value: 'medical_cert', label: 'Medical certificate' },
    { value: 'acc_form', label: 'ACC form' },
    { value: 'rtw_clearance', label: 'RTW clearance' },
    { value: 'photo', label: 'Photo' },
    { value: 'document', label: 'Document' },
];
