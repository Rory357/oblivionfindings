/**
 * Shared pieces for the Risk register pages (register, heatmap, trends,
 * committee views): the view toggle in each header's filter row, plain risk
 * wording (vocabulary.md — risk before/after controls, the board's limit)
 * and the "How risk scores work" explainer.
 */
import { PageHeaderViewToggle } from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDateOnly } from '@/lib/datetime';
import {
    controlEffectivenessLabel,
    riskImpactLabel,
    riskLevelLabel,
    riskLikelihoodLabel,
    riskStrategyLabel,
} from '@/lib/governance-labels';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Grid3x3, HelpCircle, List, TrendingUp } from 'lucide-react';
import { useId } from 'react';

export type RiskView = 'register' | 'heatmap' | 'trends';

const RISK_VIEW_HREFS: Record<RiskView, string> = {
    register: '/governance/risks',
    heatmap: '/governance/risks/heatmap',
    trends: '/governance/risks/trends',
};

/** Register · Heatmap · Trends — switches between the register's views. */
export function RiskViewToggle({ value }: { value: RiskView | null }) {
    return (
        <PageHeaderViewToggle<RiskView | 'none'>
            ariaLabel="Risk register view"
            value={value ?? 'none'}
            onChange={(next) => {
                if (next !== 'none' && next !== value) {
                    router.visit(RISK_VIEW_HREFS[next]);
                }
            }}
            options={[
                { value: 'register', label: 'Register', icon: List },
                { value: 'heatmap', label: 'Heatmap', icon: Grid3x3 },
                { value: 'trends', label: 'Trends', icon: TrendingUp },
            ]}
        />
    );
}

/** The register defaults to "Open and accepted" (every risk still on it). */
export const RISK_STATUS_FILTERS = [
    { value: 'current', label: 'Open and accepted' },
    { value: 'open', label: 'Open' },
    { value: 'accepted', label: 'Accepted by the board' },
    { value: 'closed', label: 'Closed' },
    { value: 'all', label: 'All, including closed' },
];

export type RiskBand = 'critical' | 'high' | 'medium' | 'low';

export const RISK_BANDS: { key: RiskBand; range: string; min: number }[] = [
    { key: 'critical', range: '20–25', min: 20 },
    { key: 'high', range: '15–19', min: 15 },
    { key: 'medium', range: '10–14', min: 10 },
    { key: 'low', range: '1–9', min: 1 },
];

export const RISK_SEVERITY_FILTERS = [
    { value: 'all', label: 'Any level' },
    ...RISK_BANDS.map((band) => ({
        value: band.key,
        label: `${riskLevelLabel(band.key)} (${band.range})`,
    })),
];

/** Score (1–25) → band. */
export function riskBand(score: number | null | undefined): RiskBand {
    const n = Number(score ?? 0);
    if (n >= 20) return 'critical';
    if (n >= 15) return 'high';
    if (n >= 10) return 'medium';
    return 'low';
}

export function riskBandLabel(score: number | null | undefined): string {
    return riskLevelLabel(riskBand(score));
}

/** Score → status token pair (critical · warning for high/medium · success). */
export function riskLevelVariant(score: number | null | undefined): StatusVariant {
    const band = riskBand(score);
    if (band === 'critical') return 'critical';
    if (band === 'high' || band === 'medium') return 'warning';
    return 'success';
}

export interface RiskStatusLike {
    status: string;
    within_appetite: boolean;
    accepted_until?: string | null;
    acceptance_ended?: boolean;
}

/** One header/list chip for a risk's place on the register. */
export function riskStatusChip(risk: RiskStatusLike): {
    label: string;
    variant: StatusVariant;
} {
    if (risk.status === 'accepted') {
        if (risk.acceptance_ended) {
            return {
                label: risk.accepted_until
                    ? `Board acceptance ended ${formatDateOnly(risk.accepted_until)}`
                    : 'Board acceptance ended',
                variant: 'warning',
            };
        }
        return {
            label: risk.accepted_until
                ? `Accepted by the board until ${formatDateOnly(risk.accepted_until)}`
                : 'Accepted by the board',
            variant: 'success',
        };
    }
    if (risk.status === 'closed') return { label: 'Closed', variant: 'neutral' };
    if (!risk.within_appetite) {
        return { label: "Above the board's limit", variant: 'critical' };
    }
    return { label: 'Open', variant: 'info' };
}

/** "Reduce it (treat)" — plain words first, the risk-management term after. */
export function strategyLabel(key: string | null | undefined): string {
    if (!key) return 'Not set';
    const term: Record<string, string> = {
        treat: 'treat',
        transfer: 'transfer',
        terminate: 'avoid',
        avoid: 'avoid',
        tolerate: 'tolerate',
        accept: 'tolerate',
    };
    return term[key]
        ? `${riskStrategyLabel(key)} (${term[key]})`
        : riskStrategyLabel(key);
}

/** "3 – Possible" */
export function likelihoodText(score: number): string {
    return `${score} – ${riskLikelihoodLabel(String(score))}`;
}

/** "4 – Major" */
export function impactText(score: number): string {
    return `${score} – ${riskImpactLabel(String(score))}`;
}

/** Mirrors RiskScoringService::CONTROL_MULTIPLIERS. */
export const CONTROL_MULTIPLIERS: Record<string, number> = {
    none: 1,
    weak: 0.8,
    moderate: 0.5,
    strong: 0.2,
};

export const CONTROL_EFFECT: Record<string, string> = {
    none: 'leaves the score unchanged',
    weak: 'cuts the score to four-fifths',
    moderate: 'cuts the score in half',
    strong: 'cuts the score to a fifth',
};

export function controlText(key: string | null | undefined): string {
    return controlEffectivenessLabel(key);
}

export const CONTROL_HELP =
    'How well current safeguards work. Strong controls cut the score to a fifth; none leave it unchanged.';

/** Mirrors RiskScoringService::calculateResidualScore. */
export function residualFor(inherent: number, control: string): number {
    return Math.max(1, Math.round(inherent * (CONTROL_MULTIPLIERS[control] ?? 1)));
}

/** "How risk scores work" — a short popover explainer. */
export function RiskScoreExplainer({
    className,
    variant = 'outline',
}: {
    className?: string;
    variant?: 'outline' | 'ghost';
}) {
    const titleId = useId();
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant={variant}
                    size="sm"
                    className={cn(className)}
                >
                    <HelpCircle className="size-4" aria-hidden="true" />
                    How risk scores work
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="w-80 space-y-2"
                aria-labelledby={titleId}
            >
                <p id={titleId} className="text-section-title">
                    How risk scores work
                </p>
                <p className="text-subtle">
                    <strong>Risk before controls</strong> is the likelihood (1
                    rare to 5 almost certain) multiplied by the impact (1
                    insignificant to 5 catastrophic), so it runs from 1 to 25.
                </p>
                <p className="text-subtle">
                    <strong>Controls</strong> — the safeguards already in place
                    — then lower it. Strong controls cut the score to a fifth,
                    partly effective ones to a half, weak ones to four-fifths,
                    and no controls leave it unchanged. That gives the{' '}
                    <strong>risk after controls</strong>.
                </p>
                <p className="text-subtle">
                    The risk after controls is compared with{' '}
                    <strong>the board&apos;s limit</strong> for that kind of
                    risk. 20–25 is critical, 15–19 high, 10–14 medium and 1–9
                    low.
                </p>
            </PopoverContent>
        </Popover>
    );
}
