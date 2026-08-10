/* Shared domain constants for the Chemical register (hazardous substances).
 * Single source for the wizard, detail panes, register rows and site panel so
 * the field contracts never drift. NZ framing: HSNO classification, GHS
 * pictograms, WES exposure limits, Hazardous Substances Regulations 2017. */
import {
    AlertTriangle,
    Ban,
    Bomb,
    CheckCircle2,
    Clock,
    Cylinder,
    Droplets,
    Flame,
    HeartPulse,
    History,
    Leaf,
    Skull,
    Zap,
    type LucideIcon,
} from 'lucide-react';

export type Tone = 'success' | 'warning' | 'critical' | 'neutral';
export type FlagTone = 'success' | 'warning' | 'critical' | 'info' | 'neutral';

export type Opt = { value: string; label: string };

export const PHYSICAL_FORMS: Opt[] = [
    { value: 'solid', label: 'Solid' },
    { value: 'liquid', label: 'Liquid' },
    { value: 'gas', label: 'Gas' },
    { value: 'powder', label: 'Powder' },
    { value: 'aerosol', label: 'Aerosol' },
    { value: 'paste', label: 'Paste' },
    { value: 'other', label: 'Other' },
];

export const HAZARD_CLASSES: string[] = [
    'Flammable',
    'Oxidising',
    'Toxic',
    'Corrosive',
    'Eco-toxic',
    'Explosive',
    'Compressed gas',
    'Carcinogenic',
];

export const SIGNAL_WORDS: Opt[] = [
    { value: 'Danger', label: 'Danger' },
    { value: 'Warning', label: 'Warning' },
];

export const EXPOSURE_LIMIT_TYPES: Opt[] = [
    { value: 'WES-TWA', label: 'WES-TWA (8-hour)' },
    { value: 'WES-STEL', label: 'WES-STEL (short-term)' },
    { value: 'WES-Ceiling', label: 'WES-Ceiling' },
    { value: 'BEI', label: 'BEI (biological)' },
];

export const EXPOSURE_TYPES: Opt[] = [
    { value: 'inhalation', label: 'Inhalation' },
    { value: 'skin_contact', label: 'Skin contact' },
    { value: 'eye_contact', label: 'Eye contact' },
    { value: 'ingestion', label: 'Ingestion' },
    { value: 'injection', label: 'Injection' },
    { value: 'other', label: 'Other' },
];

/** Degree of harm — drives WorkSafe notifiability (HSWA 2015 ss.23–25). */
export const MEDICAL_TREATMENTS: Opt[] = [
    { value: 'none', label: 'None' },
    { value: 'first_aid', label: 'First aid' },
    { value: 'medical', label: 'Medical treatment' },
    { value: 'hospitalisation', label: 'Hospitalisation' },
    { value: 'death', label: 'Death' },
];

export const QUANTITY_UNITS: Opt[] = [
    { value: 'L', label: 'Litres (L)' },
    { value: 'mL', label: 'Millilitres (mL)' },
    { value: 'kg', label: 'Kilograms (kg)' },
    { value: 'g', label: 'Grams (g)' },
    { value: 'units', label: 'Units' },
    { value: 'cylinders', label: 'Cylinders' },
];

export const STATUS_OPTIONS: Opt[] = [
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'removed', label: 'Removed' },
];

/* ── GHS pictograms — tinted lucide glyphs (swap for raster assets if available) ── */

export type GhsMeta = {
    code: string;
    label: string;
    icon: LucideIcon;
    tone: Tone;
};

export const GHS_PICTOGRAMS: GhsMeta[] = [
    { code: 'GHS01', label: 'Explosive', icon: Bomb, tone: 'critical' },
    { code: 'GHS02', label: 'Flammable', icon: Flame, tone: 'warning' },
    { code: 'GHS03', label: 'Oxidising', icon: Zap, tone: 'warning' },
    { code: 'GHS04', label: 'Compressed gas', icon: Cylinder, tone: 'neutral' },
    { code: 'GHS05', label: 'Corrosive', icon: Droplets, tone: 'warning' },
    { code: 'GHS06', label: 'Toxic', icon: Skull, tone: 'critical' },
    {
        code: 'GHS07',
        label: 'Harmful / irritant',
        icon: AlertTriangle,
        tone: 'warning',
    },
    {
        code: 'GHS08',
        label: 'Health hazard',
        icon: HeartPulse,
        tone: 'critical',
    },
    { code: 'GHS09', label: 'Environmental', icon: Leaf, tone: 'success' },
];

export const GHS_BY_CODE: Record<string, GhsMeta> = Object.fromEntries(
    GHS_PICTOGRAMS.map((p) => [p.code, p]),
);

/* ── SDS lifecycle state → badge meta ── */

export type SdsState =
    | 'current'
    | 'expiring'
    | 'expired'
    | 'missing'
    | 'superseded';

export const SDS_STATE_META: Record<
    SdsState,
    { label: string; tone: FlagTone; icon: LucideIcon }
> = {
    current: { label: 'Current', tone: 'success', icon: CheckCircle2 },
    expiring: { label: 'Expiring', tone: 'warning', icon: Clock },
    expired: { label: 'Expired', tone: 'critical', icon: AlertTriangle },
    missing: { label: 'Missing', tone: 'critical', icon: Ban },
    superseded: { label: 'Superseded', tone: 'neutral', icon: History },
};

export const STATUS_META: Record<string, { label: string; tone: Tone }> = {
    active: { label: 'Active', tone: 'success' },
    inactive: { label: 'Inactive', tone: 'neutral' },
    removed: { label: 'Removed', tone: 'critical' },
};

/** Risk dot for a substance row: controlled or SDS-to-action ⇒ elevated. */
export function substanceRiskTone(
    isControlled: boolean,
    sdsState: string,
): Tone {
    if (sdsState === 'expired' || sdsState === 'missing') return 'critical';
    if (isControlled || sdsState === 'expiring') return 'warning';
    return 'success';
}
