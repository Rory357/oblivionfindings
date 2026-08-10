/* Canonical recruitment stage map + presentation helpers. One source of truth
 * for stage labels, the hue-based colour scale (matching the prototype, in the
 * same OKLCH idiom the People hero already uses for avatars), avatar colours and
 * NZD / en-NZ formatting. Replaces the forked status-badge colour maps that used
 * to live across jobs.tsx / scorecard-summary.tsx / recruitment/status-badge. */
import type { CSSProperties } from 'react';

export type StageKey =
    | 'new'
    | 'screening'
    | 'interview_scheduled'
    | 'interview_completed'
    | 'reference_check'
    | 'offer_pending'
    | 'offer_sent'
    | 'offer_accepted'
    | 'onboarding'
    | 'hired'
    | 'rejected'
    | 'withdrawn';

type StageDef = { label: string; hue: number };

export const STAGE_DEFS: Record<string, StageDef> = {
    new: { label: 'New', hue: 255 },
    screening: { label: 'Screening', hue: 277 },
    interview_scheduled: { label: 'Interview', hue: 78 },
    interview_completed: { label: 'Interviewed', hue: 52 },
    reference_check: { label: 'References', hue: 305 },
    offer_pending: { label: 'Offer pending', hue: 220 },
    offer_sent: { label: 'Offer sent', hue: 195 },
    offer_accepted: { label: 'Accepted', hue: 150 },
    onboarding: { label: 'Onboarding', hue: 128 },
    hired: { label: 'Hired', hue: 145 },
    rejected: { label: 'Rejected', hue: 25 },
    withdrawn: { label: 'Withdrawn', hue: 277 },
};

/** Active stages rendered as board columns, in order. Includes offer_pending and
 *  onboarding so a candidate with a drafted-but-unsent offer (or mid-onboarding)
 *  is never unrenderable — every non-terminal stage maps to a visible column. */
export const BOARD_STAGES: StageKey[] = [
    'new',
    'screening',
    'interview_scheduled',
    'interview_completed',
    'reference_check',
    'offer_pending',
    'offer_sent',
    'offer_accepted',
    'onboarding',
];

/** Linear forward flow used to compute "advance to next stage". */
export const STAGE_FLOW: StageKey[] = [
    'new',
    'screening',
    'interview_scheduled',
    'interview_completed',
    'reference_check',
    'offer_pending',
    'offer_sent',
    'offer_accepted',
    'onboarding',
    'hired',
];

export function stageLabel(key: string): string {
    return STAGE_DEFS[key]?.label ?? formatKey(key);
}

export function nextStage(key: string): StageKey | null {
    const idx = STAGE_FLOW.indexOf(key as StageKey);
    if (idx < 0 || idx >= STAGE_FLOW.length - 1) return null;
    return STAGE_FLOW[idx + 1];
}

type StageColors = { dot: string; bg: string; text: string; border: string };

export function stageColors(key: string): StageColors {
    const hue = STAGE_DEFS[key]?.hue ?? 277;
    return {
        dot: `oklch(0.62 0.16 ${hue})`,
        bg: `oklch(0.962 0.032 ${hue})`,
        text: `oklch(0.43 0.13 ${hue})`,
        border: `oklch(0.9 0.05 ${hue})`,
    };
}

/** Inline style for a stage pill (dot + label), token-free hue scale. */
export function stageBadgeStyle(key: string): CSSProperties {
    const c = stageColors(key);
    return {
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        borderRadius: 9999,
        padding: '3px 10px 3px 8px',
        fontSize: 11.5,
        fontWeight: 700,
        background: c.bg,
        color: c.text,
        border: `1px solid ${c.border}`,
        whiteSpace: 'nowrap',
    };
}

export function stageDotStyle(key: string): CSSProperties {
    return {
        height: 7,
        width: 7,
        flex: 'none',
        borderRadius: 9999,
        background: stageColors(key).dot,
    };
}

/* ------------------------------------------------------------------ */
/*  Avatars                                                            */
/* ------------------------------------------------------------------ */

const AVATAR_HUES = [18, 52, 128, 150, 195, 220, 255, 277, 305, 340];

export function avatarHue(seed: string): number {
    let h = 0;
    for (let i = 0; i < seed.length; i++)
        h = (h * 31 + seed.charCodeAt(i)) >>> 0;
    return AVATAR_HUES[h % AVATAR_HUES.length];
}

export function avatarStyle(seed: string, size = 38): CSSProperties {
    const hue = avatarHue(seed);
    return {
        display: 'grid',
        placeItems: 'center',
        height: size,
        width: size,
        flex: 'none',
        borderRadius: 9999,
        background: `oklch(0.62 0.13 ${hue})`,
        color: '#fff',
        fontSize: size <= 32 ? 11 : 13,
        fontWeight: 700,
    };
}

export function initials(name: string): string {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

/* ------------------------------------------------------------------ */
/*  Formatting                                                         */
/* ------------------------------------------------------------------ */

export function formatKey(key: string): string {
    return key.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const NZD = new Intl.NumberFormat('en-NZ', {
    style: 'currency',
    currency: 'NZD',
    maximumFractionDigits: 2,
});

export function formatNZD(value: number): string {
    return NZD.format(value);
}

export function daysLabel(days: number): string {
    return `${days}d`;
}
