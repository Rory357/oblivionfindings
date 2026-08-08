/* Hazard kit — the single shared source of hazard domain data + presentational
 * primitives for every Hazards surface (global register, per-site register,
 * site-profile embed, client read-only panel, detail modal, create wizard).
 * Keeps the lifecycle labels, tones, the WorkSafe risk matrix and the chips in
 * ONE place so the surfaces can't drift. Semantic tokens only. NZ-only.
 *
 * The risk matrix mirrors app/Services/Sites/SiteHazardRiskCalculator.php — it
 * is needed client-side for the live create-wizard preview + the clickable
 * matrix; the server stays authoritative on save (it recomputes via the
 * calculator). Keep the two in lockstep. */
import {
    TONE_BG,
    type Tone,
} from '@/pages/health-safety/components/register-row-kit';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    ShieldCheck,
    type LucideIcon,
} from 'lucide-react';

/* ---------------------------------------------------------------- domain */

export const HAZARD_LABELS: Record<string, string> = {
    slip_trip_fall: 'Slip / trip / fall',
    hot_water_temperature: 'Hot water temperature',
    medication_storage_access: 'Medication storage access',
    fire_electrical: 'Fire / electrical',
    manual_handling: 'Manual handling',
    security_behaviour: 'Behavioural / security',
    outdoor_garden: 'Outdoor / gardening',
    cleaning_chemicals: 'Cleaning chemicals storage',
    bathroom_safety: 'Bathroom safety',
    security_access: 'Security / visitor access',
    office_ergonomics: 'Office ergonomics',
    emergency_exits: 'Emergency exits',
    equipment_guarding: 'Equipment guarding',
    ppe_availability: 'PPE availability',
    safety: 'General safety',
    other: 'Other / not listed',
};

export type HazardSeverity = 'low' | 'medium' | 'high' | 'critical';
export type HazardRisk = 'low' | 'medium' | 'high' | 'extreme';
export type HazardStatus =
    | 'open'
    | 'in_progress'
    | 'mitigated'
    | 'closed'
    | 'reopened';

export const SEV: Record<string, { label: string; tone: Tone }> = {
    low: { label: 'Low', tone: 'success' },
    medium: { label: 'Medium', tone: 'warning' },
    high: { label: 'High', tone: 'critical' },
    critical: { label: 'Critical', tone: 'critical' },
};

export const SEVERITY_ORDER: HazardSeverity[] = [
    'low',
    'medium',
    'high',
    'critical',
];

export const LIKELIHOOD_LABELS: Record<string, string> = {
    rare: 'Rare',
    unlikely: 'Unlikely',
    possible: 'Possible',
    likely: 'Likely',
    almost_certain: 'Almost certain',
};

export const LIKELIHOOD_ORDER = [
    'rare',
    'unlikely',
    'possible',
    'likely',
    'almost_certain',
];

export const RISK: Record<string, { label: string; tone: Tone }> = {
    low: { label: 'Low', tone: 'success' },
    medium: { label: 'Medium', tone: 'warning' },
    high: { label: 'High', tone: 'critical' },
    extreme: { label: 'Extreme', tone: 'critical' },
};

/** Status chip styling — tones beyond the 4-value register Tone (info/live/primary). */
export const STATUS: Record<
    string,
    {
        label: string;
        icon: LucideIcon;
        chip: string;
        dot: string;
        tone: 'info' | 'warning' | 'success' | 'neutral' | 'critical';
    }
> = {
    open: {
        label: 'Open',
        icon: AlertTriangle,
        chip: 'bg-status-info-bg text-status-info',
        dot: 'bg-status-info',
        tone: 'info',
    },
    in_progress: {
        label: 'In progress',
        icon: Clock,
        chip: 'bg-live-bg text-live',
        dot: 'bg-live',
        tone: 'info',
    },
    mitigated: {
        label: 'Mitigated',
        icon: ShieldCheck,
        chip: 'bg-primary/10 text-primary',
        dot: 'bg-primary',
        tone: 'neutral',
    },
    closed: {
        label: 'Closed',
        icon: CheckCircle2,
        chip: 'bg-status-success-bg text-status-success',
        dot: 'bg-status-success',
        tone: 'success',
    },
    reopened: {
        label: 'Reopened',
        icon: AlertTriangle,
        chip: 'bg-status-warning-bg text-status-warning',
        dot: 'bg-status-warning',
        tone: 'warning',
    },
};

/** Mirror of SiteHazardRiskCalculator::MATRIX (severity rows × likelihood cols). */
export const RISK_MATRIX: Record<HazardSeverity, Record<string, HazardRisk>> = {
    low: {
        rare: 'low',
        unlikely: 'low',
        possible: 'medium',
        likely: 'medium',
        almost_certain: 'high',
    },
    medium: {
        rare: 'low',
        unlikely: 'medium',
        possible: 'medium',
        likely: 'high',
        almost_certain: 'high',
    },
    high: {
        rare: 'medium',
        unlikely: 'medium',
        possible: 'high',
        likely: 'high',
        almost_certain: 'extreme',
    },
    critical: {
        rare: 'high',
        unlikely: 'high',
        possible: 'extreme',
        likely: 'extreme',
        almost_certain: 'extreme',
    },
};

export function riskOf(
    severity?: string | null,
    likelihood?: string | null,
): HazardRisk | null {
    if (!severity || !likelihood) return null;
    return RISK_MATRIX[severity as HazardSeverity]?.[likelihood] ?? null;
}

/** Mirror of SiteHazardRiskCalculator::suggestedDueDays(). */
export const SUGGESTED_DUE_DAYS: Record<HazardRisk, number> = {
    extreme: 1,
    high: 7,
    medium: 30,
    low: 90,
};

export function requiresOfficer(rating?: string | null): boolean {
    return rating === 'high' || rating === 'extreme';
}

/** NZ HSWA 2015 hierarchy of controls (1–6). */
export const CONTROL_LEVELS: { key: string; label: string; desc: string }[] = [
    {
        key: 'elimination',
        label: 'Elimination',
        desc: 'Remove the hazard completely',
    },
    {
        key: 'substitution',
        label: 'Substitution',
        desc: 'Replace with something safer',
    },
    {
        key: 'isolation',
        label: 'Isolation',
        desc: 'Separate people from the hazard',
    },
    {
        key: 'engineering',
        label: 'Engineering',
        desc: 'Guards, barriers, physical controls',
    },
    {
        key: 'administrative',
        label: 'Administrative',
        desc: 'Procedures, training, signage',
    },
    { key: 'ppe', label: 'PPE', desc: 'Personal protective equipment' },
];

/** Corrective-action control types (a subset — no isolation). */
export const ACTION_TYPES: { value: string; label: string }[] = [
    { value: 'elimination', label: 'Elimination' },
    { value: 'substitution', label: 'Substitution' },
    { value: 'engineering', label: 'Engineering' },
    { value: 'administrative', label: 'Administrative' },
    { value: 'ppe', label: 'PPE' },
];

export const WORKSAFE_BANNER =
    'This hazard meets the threshold for notification to WorkSafe NZ (HSWA 2015). Preserve the scene where required and keep records for at least five years.';

export function controlLabel(key: string): string {
    return CONTROL_LEVELS.find((c) => c.key === key)?.label ?? key;
}

export function hazardLabelOf(h: {
    hazard_type?: string | null;
    hazard_label?: string | null;
    custom_hazard_type?: string | null;
}): string {
    if (h.custom_hazard_type) return h.custom_hazard_type;
    if (h.hazard_type && HAZARD_LABELS[h.hazard_type])
        return HAZARD_LABELS[h.hazard_type];
    return h.hazard_label ?? h.hazard_type ?? 'Hazard';
}

/* ---------------------------------------------------------------- types */

export type HazardFile = { name: string; path: string; size?: number | null };

export type HazardAction = {
    id: number;
    reference_number: string | null;
    title: string;
    action_type: string | null;
    status: 'open' | 'in_progress' | 'completed';
    assigned_to: { id: number; name: string } | null;
    due_date: string | null;
    completed_at: string | null;
    completed_by: { id: number; name: string } | null;
    completion_notes: string | null;
};

export type HazardHistoryEntry = {
    id: number;
    type: string;
    title: string;
    note: string | null;
    actor: string | null;
    at: string | null;
};

export type HazardDetail = {
    id: number;
    reference_number: string;
    site: { id: number; name: string; type: string } | null;
    hazard_type: string;
    hazard_label: string;
    custom_hazard_type: string | null;
    related_procedures?: import('@/components/health-safety/applicable-procedures-panel').ApplicableProcedure[];
    severity: string;
    likelihood: string;
    risk_rating: string;
    residual_severity: string | null;
    residual_likelihood: string | null;
    residual_risk_rating: string | null;
    control_hierarchy: string[];
    description: string;
    location: string | null;
    witnesses: string | null;
    immediate_action_applied: boolean;
    immediate_action_taken: string | null;
    status: HazardStatus;
    reported_by: { id: number; name: string } | null;
    assigned_to: { id: number; name: string } | null;
    due_date: string | null;
    created_at: string;
    closed_at: string | null;
    status_changed_at: string | null;
    status_changed_by: { id: number; name: string } | null;
    worksafe_notifiable: boolean;
    resolution_summary: string | null;
    photo_paths: string[];
    document_paths: HazardFile[];
    resolution_evidence: HazardFile[];
    actions: HazardAction[];
    assignable_staff: Array<{ id: number; name: string }>;
    close_gate: { actions_ok: boolean; blockers: string[] };
    history: HazardHistoryEntry[];
    can: HazardCan;
};

export type HazardCan = {
    manage: boolean;
    assign: boolean;
    close: boolean;
    create?: boolean;
};

export type HazardRow = {
    id: number;
    reference_number: string;
    site_id: number;
    site_name: string | null;
    site_type: string | null;
    hazard_type: string;
    hazard_label: string;
    severity: string;
    likelihood: string;
    risk_rating: string;
    description: string;
    status: HazardStatus;
    assigned_to_id: number | null;
    assigned_to_name: string | null;
    reported_by_name: string | null;
    due_date: string | null;
    created_at: string | null;
    worksafe: boolean;
    open_action_count: number;
    flags: {
        overdue: boolean;
        due_soon: boolean;
        unassigned: boolean;
        awaiting_closure: boolean;
    };
};

export type HazardSectionKey =
    | 'overview'
    | 'risk'
    | 'actions'
    | 'evidence'
    | 'history';
export type HazardActionKey =
    | 'assign'
    | 'start'
    | 'mitigate'
    | 'add_action'
    | 'review'
    | 'close';

/* ---------------------------------------------------------------- helpers */

export function storageUrl(path: string): string {
    return path.startsWith('http') || path.startsWith('/')
        ? path
        : `/storage/${path}`;
}

export function siteTypeLabel(type?: string | null): string {
    if (type === 'house') return 'House';
    if (type === 'facility') return 'Facility';
    if (type === 'head_office') return 'Head office';
    return type ?? '';
}

/** Compact relative "when" — Today / Yesterday / Nd ago / DD Mon + HH:MM. */
export function fmtWhen(iso?: string | null): { main: string; title: string } {
    if (!iso) return { main: '—', title: '' };
    const d = new Date(iso.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return { main: '—', title: '' };
    const now = new Date();
    const startOf = (x: Date) =>
        new Date(x.getFullYear(), x.getMonth(), x.getDate());
    const days = Math.round(
        (startOf(now).getTime() - startOf(d).getTime()) / 86400000,
    );
    const time = d.toLocaleTimeString('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
    });
    let label: string;
    if (days <= 0) label = `Today ${time}`;
    else if (days === 1) label = `Yesterday ${time}`;
    else if (days < 7) label = `${days}d ago ${time}`;
    else
        label = `${d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })} ${time}`;
    return { main: label, title: d.toLocaleString('en-NZ') };
}

export function fmtDay(iso?: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export function fmtDueShort(iso?: string | null): string {
    if (!iso) return '—';
    const d = new Date(iso.replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });
}

/* ---------------------------------------------------------------- chips */

export function SeverityChip({ severity }: { severity: string }) {
    const s = SEV[severity] ?? SEV.low;
    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[s.tone]}`}
        >
            {s.label}
        </span>
    );
}

export function RiskChip({
    rating,
    suffix = false,
}: {
    rating: string;
    suffix?: boolean;
}) {
    const r = RISK[rating] ?? RISK.low;
    return (
        <span
            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[r.tone]}`}
        >
            {r.label}
            {suffix ? ' risk' : ''}
        </span>
    );
}

export function StatusChip({ status }: { status: string }) {
    const s = STATUS[status] ?? STATUS.open;
    const Icon = s.icon;
    return (
        <span
            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${s.chip}`}
        >
            <Icon className="h-3 w-3" /> {s.label}
        </span>
    );
}

/* ---------------------------------------------------------------- matrix */

/**
 * WorkSafe risk matrix — 4 severity rows (critical→low, top→bottom) × 5
 * likelihood cols. Display-only by default; pass `onPick` for the create
 * wizard's click-to-set-both behaviour. Highlights the current cell (solid
 * ring) and an optional residual cell (dashed ring).
 */
export function HazardRiskMatrix({
    severity,
    likelihood,
    residualSeverity = null,
    residualLikelihood = null,
    onPick,
}: {
    severity?: string | null;
    likelihood?: string | null;
    residualSeverity?: string | null;
    residualLikelihood?: string | null;
    onPick?: (severity: HazardSeverity, likelihood: string) => void;
}) {
    const rows: HazardSeverity[] = ['critical', 'high', 'medium', 'low'];
    const cellBase =
        'flex h-8 items-center justify-center rounded-md text-[10px] font-semibold transition-colors';

    return (
        <div className="overflow-x-auto">
            <div
                className="inline-grid min-w-[420px] gap-1"
                style={{
                    gridTemplateColumns: 'auto repeat(5, minmax(0, 1fr))',
                }}
            >
                <div />
                {LIKELIHOOD_ORDER.map((lik) => (
                    <div
                        key={lik}
                        className="px-1 text-center text-[10px] font-medium text-muted-foreground"
                    >
                        {LIKELIHOOD_LABELS[lik]}
                    </div>
                ))}

                {rows.map((sev) => (
                    <div key={sev} className="contents">
                        <div className="flex items-center pr-2 text-right text-[10px] font-medium text-muted-foreground">
                            {SEV[sev].label}
                        </div>
                        {LIKELIHOOD_ORDER.map((lik) => {
                            const rating = RISK_MATRIX[sev][lik];
                            const isCurrent =
                                severity === sev && likelihood === lik;
                            const isResidual =
                                residualSeverity === sev &&
                                residualLikelihood === lik;
                            const cls = [
                                cellBase,
                                TONE_BG[RISK[rating].tone],
                                isCurrent ? 'ring-2 ring-foreground' : '',
                                isResidual && !isCurrent
                                    ? 'ring-2 ring-dashed ring-status-info'
                                    : '',
                                onPick
                                    ? 'cursor-pointer hover:ring-2 hover:ring-ring'
                                    : '',
                            ].join(' ');

                            if (onPick) {
                                return (
                                    // eslint-disable-next-line no-restricted-syntax -- risk-matrix selector cell, custom shaded surface (not a shadcn Button)
                                    <button
                                        key={lik}
                                        type="button"
                                        onClick={() => onPick(sev, lik)}
                                        aria-pressed={isCurrent}
                                        aria-label={`${SEV[sev].label} severity, ${LIKELIHOOD_LABELS[lik]} likelihood — ${RISK[rating].label} risk`}
                                        className={cls}
                                    >
                                        {RISK[rating].label}
                                    </button>
                                );
                            }

                            return (
                                <div
                                    key={lik}
                                    className={cls}
                                    title={`${SEV[sev].label} × ${LIKELIHOOD_LABELS[lik]} — ${RISK[rating].label} risk`}
                                >
                                    {RISK[rating].label}
                                </div>
                            );
                        })}
                    </div>
                ))}
            </div>
        </div>
    );
}
