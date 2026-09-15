import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    ClipboardList,
    FileCheck,
    ShieldAlert,
    TrendingUp,
    type LucideIcon,
} from 'lucide-react';

import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import type { GovernanceTermKey } from '@/lib/governance-glossary';
import {
    canDoGovernance,
    type GovernancePermissionMap,
} from '@/lib/governance-permissions';

import { CockpitCardStatus, isCardStatusKnown } from './CockpitCardStatus';

interface CountSignal {
    /** False when the viewer may not open this register (nothing is counted). */
    permitted?: boolean;
    available: boolean;
    count: number | null;
    href: string;
}

/** `GovernancePresenter::buildAssurance` — counts match their linked views. */
export interface AssurancePayload {
    risks_above_appetite: CountSignal & { tracked: number | null };
    obligations_overdue: CountSignal;
    actions_overdue: CountSignal;
    financial_variance: {
        permitted?: boolean;
        available: boolean;
        variance_percent: number | null;
        material: boolean | null;
        threshold_percent: number;
        href: string;
    };
}

/** A compact linked row for another area the board watches (managers only). */
export interface AssuranceExtra {
    key: string;
    title: string;
    /** Presenter card status: critical · warning · good · unknown. */
    status: string;
    summary: string;
    href: string;
}

/** The presenter card fields the extra rows need. */
interface PresenterCard {
    key: string;
    title: string;
    status: string;
    metrics: Array<{ label: string; value: string }>;
    href: string;
}

/**
 * Other areas meeting managers watch, shown as compact rows in Board
 * assurance (privacy, incidents, safeguarding, spend requests, sites over
 * budget). They are not repeated anywhere else on Home.
 */
export const ASSURANCE_EXTRA_KEYS = [
    'privacy_data',
    'incidents',
    'safeguarding',
    'spend_approvals',
    'sites_over_budget',
] as const;

export function assuranceExtras(
    cardsByKey: Record<string, PresenterCard | undefined> | null | undefined,
): AssuranceExtra[] {
    return ASSURANCE_EXTRA_KEYS.map((key) => cardsByKey?.[key])
        .filter((card): card is PresenterCard => Boolean(card))
        .map((card) => ({
            key: card.key,
            title: card.title,
            status: card.status,
            summary: card.metrics
                .slice(0, 2)
                .map((metric) => `${metric.label}: ${metric.value}`)
                .join(' · '),
            href: card.href,
        }));
}

export interface AssuranceVisibility {
    risks: boolean;
    compliance: boolean;
    actions: boolean;
    finance: boolean;
}

export interface AssuranceAttention {
    /** How many things need the board's attention. */
    total: number;
    /** "2 risks above the board's limit", … */
    parts: string[];
    /** Areas checked with nothing to report. */
    clear: string[];
    /** Areas that couldn't be loaded (never counted as zero). */
    unavailable: number;
    /** Areas that loaded and were checked. */
    checked: number;
}

const plural = (count: number, one: string, many: string) =>
    count === 1 ? one : many;

function joinList(items: string[], conjunction = 'and'): string {
    if (items.length <= 1) return items.join('');
    return `${items.slice(0, -1).join(', ')} ${conjunction} ${items[items.length - 1]}`;
}

/** "7.2% over budget" / "3.1% under budget" / "on budget". */
export function spendingWords(variancePercent: number): string {
    const amount = Math.abs(variancePercent).toFixed(1);
    if (variancePercent > 0) return `${amount}% over budget`;
    if (variancePercent < 0) return `${amount}% under budget`;
    return 'on budget';
}

/** Which assurance areas this viewer may see — the permission map AND the server's own flag. */
export function assuranceVisibility(
    assurance: AssurancePayload,
    permissions: GovernancePermissionMap,
): AssuranceVisibility {
    return {
        risks:
            canDoGovernance(permissions, 'risks', 'view') &&
            assurance.risks_above_appetite.permitted !== false,
        compliance:
            canDoGovernance(permissions, 'compliance', 'view') &&
            assurance.obligations_overdue.permitted !== false,
        actions:
            canDoGovernance(permissions, 'actions', 'view') &&
            assurance.actions_overdue.permitted !== false,
        finance:
            canDoGovernance(permissions, 'budgets', 'view') &&
            assurance.financial_variance.permitted !== false,
    };
}

/**
 * What needs the board's attention, counted only from the areas the viewer
 * can see and that actually loaded. The header meter and the headline both
 * use this, so they can never disagree — and nothing says "no issues" while
 * another area on the page is flagged.
 */
export function assuranceAttention(
    assurance: AssurancePayload,
    visible: AssuranceVisibility,
    extras: AssuranceExtra[] = [],
): AssuranceAttention {
    const result: AssuranceAttention = {
        total: 0,
        parts: [],
        clear: [],
        unavailable: 0,
        checked: 0,
    };

    const count = (
        show: boolean,
        signal: CountSignal,
        some: (n: number) => string,
        none: string,
    ) => {
        if (!show) return;
        if (!signal.available || signal.count === null) {
            result.unavailable += 1;
            return;
        }
        result.checked += 1;
        if (signal.count > 0) {
            result.total += signal.count;
            result.parts.push(some(signal.count));
        } else {
            result.clear.push(none);
        }
    };

    count(
        visible.risks,
        assurance.risks_above_appetite,
        (n) => `${n} ${plural(n, 'risk', 'risks')} above the board's limit`,
        "risks above the board's limit",
    );
    count(
        visible.compliance,
        assurance.obligations_overdue,
        (n) => `${n} overdue ${plural(n, 'requirement', 'requirements')}`,
        'overdue requirements',
    );
    count(
        visible.actions,
        assurance.actions_overdue,
        (n) => `${n} overdue board ${plural(n, 'action', 'actions')}`,
        'overdue board actions',
    );

    if (visible.finance) {
        const finance = assurance.financial_variance;
        if (!finance.available || finance.variance_percent === null) {
            result.unavailable += 1;
        } else {
            result.checked += 1;
            if (finance.material) {
                result.total += 1;
                result.parts.push(`spending ${spendingWords(finance.variance_percent)}`);
            } else {
                result.clear.push(
                    `spending more than ${finance.threshold_percent}% away from budget`,
                );
            }
        }
    }

    for (const extra of extras) {
        if (!isCardStatusKnown(extra.status)) {
            result.unavailable += 1;
            continue;
        }
        result.checked += 1;
        if (extra.status === 'critical' || extra.status === 'warning') {
            result.total += 1;
            result.parts.push(
                `${extra.title} (${extra.status === 'critical' ? 'needs action' : 'needs watching'})`,
            );
        }
    }

    return result;
}

/** One or two plain sentences summarising the board's assurance. */
export function assuranceHeadline(
    assurance: AssurancePayload,
    visible: AssuranceVisibility,
    extras: AssuranceExtra[] = [],
): string {
    const { parts, clear, unavailable } = assuranceAttention(assurance, visible, extras);
    const sentences: string[] = [];

    if (parts.length > 0) {
        sentences.push(`Needs the board's attention: ${joinList(parts)}.`);
    }
    if (clear.length > 0) {
        sentences.push(`No ${joinList(clear, 'or')} reported.`);
    }
    if (unavailable > 0) {
        sentences.push(
            `${unavailable} ${plural(unavailable, 'area is', 'areas are')} not available, so ${plural(unavailable, "it isn't", "they aren't")} counted.`,
        );
    }
    return sentences.join(' ');
}

interface Tile {
    key: string;
    icon: LucideIcon;
    label: string;
    term: GovernanceTermKey;
    value: string;
    caption: string;
    badge: { label: string; variant: StatusVariant };
    href: string;
    ariaLabel: string;
}

function countTile(
    tile: Omit<Tile, 'value' | 'caption' | 'badge'>,
    signal: CountSignal,
    copy: { some: (n: number) => string; none: string; unavailable: string },
    alertVariant: StatusVariant,
): Tile {
    if (!signal.available || signal.count === null) {
        return {
            ...tile,
            value: '—',
            caption: copy.unavailable,
            badge: { label: 'Not available', variant: 'neutral' },
        };
    }
    const n = signal.count;
    return {
        ...tile,
        value: String(n),
        caption: n > 0 ? copy.some(n) : copy.none,
        badge:
            n > 0
                ? { label: 'Needs attention', variant: alertVariant }
                : { label: 'None', variant: 'success' },
    };
}

/**
 * Board assurance on Governance Home: risks above the board's limit, overdue
 * requirements, overdue board actions and spending against budget — each
 * tile linking to the register view that lists exactly the counted records,
 * with a "What's this?" hint. Failed sources show "Not available", never 0.
 * Meeting managers also see compact rows for the other areas the board
 * watches (privacy, incidents, safeguarding, spend requests).
 */
export function AssuranceSummaryPanel({
    assurance,
    permissions,
    extras = [],
    id,
}: {
    assurance: AssurancePayload | null | undefined;
    permissions: GovernancePermissionMap;
    extras?: AssuranceExtra[];
    id?: string;
}) {
    if (!assurance) return null;

    const visible = assuranceVisibility(assurance, permissions);
    const tiles: Tile[] = [];

    if (visible.risks) {
        const risks = assurance.risks_above_appetite;
        tiles.push(
            countTile(
                {
                    key: 'risks',
                    icon: ShieldAlert,
                    label: "Risks above the board's limit",
                    term: 'board_limit',
                    href: risks.href,
                    ariaLabel: "View risks above the board's limit",
                },
                risks,
                {
                    some: (n) =>
                        risks.tracked !== null
                            ? `${n} of ${risks.tracked} open ${plural(risks.tracked, 'risk', 'risks')}`
                            : `${n} open ${plural(n, 'risk', 'risks')}`,
                    none:
                        risks.tracked !== null
                            ? `None of ${risks.tracked} open ${plural(risks.tracked, 'risk', 'risks')}`
                            : 'No open risks are above it',
                    unavailable: 'Risk register not available',
                },
                'critical',
            ),
        );
    }
    if (visible.compliance) {
        tiles.push(
            countTile(
                {
                    key: 'obligations',
                    icon: FileCheck,
                    label: 'Overdue requirements',
                    term: 'requirement',
                    href: assurance.obligations_overdue.href,
                    ariaLabel: 'View overdue requirements',
                },
                assurance.obligations_overdue,
                {
                    some: (n) => `${n} past ${plural(n, 'its', 'their')} due date`,
                    none: 'None past their due date',
                    unavailable: 'Compliance not available',
                },
                'critical',
            ),
        );
    }
    if (visible.actions) {
        tiles.push(
            countTile(
                {
                    key: 'actions',
                    icon: ClipboardList,
                    label: 'Overdue board actions',
                    term: 'action',
                    href: assurance.actions_overdue.href,
                    ariaLabel: 'View overdue board actions',
                },
                assurance.actions_overdue,
                {
                    some: (n) => `${n} past ${plural(n, 'its', 'their')} due date`,
                    none: 'None past their due date',
                    unavailable: 'Actions not available',
                },
                'warning',
            ),
        );
    }
    if (visible.finance) {
        const finance = assurance.financial_variance;
        const available = finance.available && finance.variance_percent !== null;
        const variance = finance.variance_percent ?? 0;
        tiles.push({
            key: 'finance',
            icon: TrendingUp,
            label: 'Spending against budget',
            term: 'over_under_budget',
            value: !available
                ? '—'
                : variance === 0
                  ? 'On budget'
                  : `${Math.abs(variance).toFixed(1)}% ${variance > 0 ? 'over' : 'under'}`,
            caption: !available
                ? 'Budget figures not available'
                : finance.material
                  ? `More than ${finance.threshold_percent}% away from budget — the board looks at this`
                  : `Within ${finance.threshold_percent}% of budget`,
            badge: !available
                ? { label: 'Not available', variant: 'neutral' }
                : finance.material
                  ? { label: 'Flagged', variant: 'warning' }
                  : { label: 'Within range', variant: 'success' },
            href: finance.href,
            ariaLabel: 'View spending against budget',
        });
    }

    if (tiles.length === 0 && extras.length === 0) return null;

    return (
        <Card id={id} data-dusk="cockpit-assurance-summary">
            <CardHeader className="pb-3">
                <CardTitle className="text-section-title">
                    Board assurance
                </CardTitle>
                <CardDescription data-testid="assurance-headline">
                    {assuranceHeadline(assurance, visible, extras)}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {tiles.length > 0 ? (
                    <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {tiles.map((tile) => {
                            const Icon = tile.icon;
                            return (
                                <li
                                    key={tile.key}
                                    className="flex h-full flex-col gap-2 rounded-lg border border-border bg-card p-3"
                                    data-dusk={`assurance-${tile.key}`}
                                >
                                    <span className="inline-flex items-center gap-1.5 text-caption font-medium">
                                        <Icon
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {tile.label}
                                        <GovernanceTermHint term={tile.term} />
                                    </span>
                                    <Link
                                        href={tile.href}
                                        aria-label={`${tile.ariaLabel}: ${tile.value} — ${tile.caption}`}
                                        className="group flex flex-1 flex-col gap-1 rounded-md transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="text-section-title tabular-nums">
                                                {tile.value}
                                            </span>
                                            <StatusBadge
                                                size="sm"
                                                variant={tile.badge.variant}
                                            >
                                                {tile.badge.label}
                                            </StatusBadge>
                                        </span>
                                        <span className="flex items-center justify-between gap-2">
                                            <span className="text-caption">
                                                {tile.caption}
                                            </span>
                                            <ArrowUpRight
                                                className="size-3.5 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                                                aria-hidden="true"
                                            />
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                ) : null}

                {extras.length > 0 ? (
                    <div
                        className={
                            tiles.length > 0
                                ? 'border-t border-border pt-4'
                                : undefined
                        }
                    >
                        <p className="text-caption font-medium">
                            Other areas the board watches
                        </p>
                        <ul className="mt-2 flex flex-col divide-y divide-border">
                            {extras.map((extra) => (
                                <li key={extra.key}>
                                    <Link
                                        href={extra.href}
                                        className="flex items-center gap-3 rounded-md px-2 py-2 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        data-dusk={`assurance-extra-${extra.key}`}
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-medium text-foreground">
                                                {extra.title}
                                            </span>
                                            <span className="block truncate text-caption">
                                                {extra.summary}
                                            </span>
                                        </span>
                                        <CockpitCardStatus status={extra.status} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

export default AssuranceSummaryPanel;
