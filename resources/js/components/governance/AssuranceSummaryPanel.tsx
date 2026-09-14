import { Link } from '@inertiajs/react';
import {
    ArrowUpRight,
    ClipboardList,
    FileCheck,
    ShieldAlert,
    TrendingUp,
    type LucideIcon,
} from 'lucide-react';

import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    canDoGovernance,
    type GovernancePermissionMap,
} from '@/lib/governance-permissions';

interface CountSignal {
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
        available: boolean;
        variance_percent: number | null;
        material: boolean | null;
        threshold_percent: number;
        href: string;
    };
}

interface Tile {
    key: string;
    icon: LucideIcon;
    label: string;
    value: string;
    caption: string;
    badge: { label: string; variant: StatusVariant };
    href: string;
    ariaLabel: string;
}

const plural = (count: number, one: string, many: string) =>
    count === 1 ? one : many;

function countTile(
    key: string,
    icon: LucideIcon,
    label: string,
    signal: CountSignal,
    copy: { some: (n: number) => string; none: string; unavailable: string; view: string },
    alertVariant: StatusVariant,
): Tile {
    if (!signal.available || signal.count === null) {
        return {
            key,
            icon,
            label,
            value: '—',
            caption: copy.unavailable,
            badge: { label: 'Unavailable', variant: 'neutral' },
            href: signal.href,
            ariaLabel: copy.view,
        };
    }
    const n = signal.count;
    return {
        key,
        icon,
        label,
        value: String(n),
        caption: n > 0 ? copy.some(n) : copy.none,
        badge:
            n > 0
                ? { label: 'Needs attention', variant: alertVariant }
                : { label: 'None', variant: 'success' },
        href: signal.href,
        ariaLabel: copy.view,
    };
}

/**
 * Build the one-sentence headline. It only claims "none" for sources that
 * actually loaded, and says when any source is unavailable — so it can never
 * read "all within appetite" while the same page reports risks above it.
 */
export function assuranceHeadline(
    assurance: AssurancePayload,
    visible: { risks: boolean; compliance: boolean; actions: boolean; finance: boolean },
): string {
    const concerns: string[] = [];
    const clear: string[] = [];
    let unavailable = 0;

    const count = (
        show: boolean,
        signal: CountSignal,
        some: (n: number) => string,
        none: string,
    ) => {
        if (!show) return;
        if (!signal.available || signal.count === null) {
            unavailable += 1;
        } else if (signal.count > 0) {
            concerns.push(some(signal.count));
        } else {
            clear.push(none);
        }
    };

    count(
        visible.risks,
        assurance.risks_above_appetite,
        (n) => `${n} ${plural(n, 'risk', 'risks')} above appetite`,
        'risks above appetite',
    );
    count(
        visible.compliance,
        assurance.obligations_overdue,
        (n) => `${n} overdue compliance ${plural(n, 'obligation', 'obligations')}`,
        'overdue obligations',
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
            unavailable += 1;
        } else if (finance.material) {
            concerns.push(
                `a material budget variance of ${formatVariance(finance.variance_percent)}`,
            );
        } else {
            clear.push('material budget variance');
        }
    }

    const parts: string[] = [];
    if (concerns.length > 0) {
        parts.push(`Needs board attention: ${joinList(concerns)}.`);
    }
    if (clear.length > 0) {
        parts.push(`No ${joinList(clear, 'or')} reported.`);
    }
    if (unavailable > 0) {
        parts.push(
            `${unavailable} ${plural(unavailable, 'source is', 'sources are')} unavailable, so ${plural(unavailable, 'it is', 'they are')} not counted.`,
        );
    }
    return parts.join(' ');
}

function joinList(items: string[], conjunction = 'and'): string {
    if (items.length <= 1) return items.join('');
    return `${items.slice(0, -1).join(', ')} ${conjunction} ${items[items.length - 1]}`;
}

function formatVariance(value: number): string {
    return `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
}

/**
 * Concise, organisation-wide assurance for Governance Home: risks above
 * appetite, overdue compliance obligations, overdue board actions and
 * material budget variance. Each tile links to the register view that lists
 * exactly the counted records; failed sources show "Unavailable", never 0.
 * Tiles only render for registers the viewer may open.
 */
export function AssuranceSummaryPanel({
    assurance,
    permissions,
}: {
    assurance: AssurancePayload | null | undefined;
    permissions: GovernancePermissionMap;
}) {
    if (!assurance) return null;

    const visible = {
        risks: canDoGovernance(permissions, 'risks', 'view'),
        compliance: canDoGovernance(permissions, 'compliance', 'view'),
        actions: canDoGovernance(permissions, 'actions', 'view'),
        finance: canDoGovernance(permissions, 'budgets', 'view'),
    };

    const tiles: Tile[] = [];
    if (visible.risks) {
        const risks = assurance.risks_above_appetite;
        tiles.push(
            countTile(
                'risks',
                ShieldAlert,
                'Risks above appetite',
                risks,
                {
                    some: (n) =>
                        risks.tracked !== null
                            ? `${n} of ${risks.tracked} active ${plural(risks.tracked, 'risk', 'risks')} outside tolerance`
                            : `${n} ${plural(n, 'risk', 'risks')} outside tolerance`,
                    none:
                        risks.tracked !== null
                            ? `None of ${risks.tracked} active ${plural(risks.tracked, 'risk', 'risks')} above appetite`
                            : 'No active risks above appetite',
                    unavailable: 'Risk register data unavailable',
                    view: 'View risks above appetite',
                },
                'critical',
            ),
        );
    }
    if (visible.compliance) {
        tiles.push(
            countTile(
                'obligations',
                FileCheck,
                'Obligations overdue',
                assurance.obligations_overdue,
                {
                    some: (n) =>
                        `${n} statutory or compliance ${plural(n, 'obligation', 'obligations')} past due`,
                    none: 'No compliance obligations overdue',
                    unavailable: 'Compliance data unavailable',
                    view: 'View overdue compliance obligations',
                },
                'critical',
            ),
        );
    }
    if (visible.actions) {
        tiles.push(
            countTile(
                'actions',
                ClipboardList,
                'Overdue board actions',
                assurance.actions_overdue,
                {
                    some: (n) => `${n} board ${plural(n, 'action', 'actions')} past due`,
                    none: 'No board actions overdue',
                    unavailable: 'Action data unavailable',
                    view: 'View overdue board actions',
                },
                'warning',
            ),
        );
    }
    if (visible.finance) {
        const finance = assurance.financial_variance;
        const available =
            finance.available && finance.variance_percent !== null;
        tiles.push({
            key: 'finance',
            icon: TrendingUp,
            label: 'Budget variance',
            value: available ? formatVariance(finance.variance_percent as number) : '—',
            caption: !available
                ? 'Budget variance unavailable for this period'
                : finance.material
                  ? `Material — beyond ±${finance.threshold_percent}% of budget`
                  : `Within ±${finance.threshold_percent}% of budget`,
            badge: !available
                ? { label: 'Unavailable', variant: 'neutral' }
                : finance.material
                  ? { label: 'Material', variant: 'warning' }
                  : { label: 'Within range', variant: 'success' },
            href: finance.href,
            ariaLabel: 'View budget variance',
        });
    }

    if (tiles.length === 0) return null;

    return (
        <Card data-dusk="cockpit-assurance-summary">
            <CardHeader className="pb-3">
                <CardTitle className="text-section-title">
                    Board assurance
                </CardTitle>
                <CardDescription data-testid="assurance-headline">
                    {assuranceHeadline(assurance, visible)}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {tiles.map((tile) => {
                        const Icon = tile.icon;
                        return (
                            <li key={tile.key}>
                                <Link
                                    href={tile.href}
                                    aria-label={`${tile.ariaLabel}: ${tile.value} — ${tile.caption}`}
                                    className="group flex h-full flex-col gap-2 rounded-lg border border-border bg-card p-3 transition-colors hover:border-primary/40 hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    data-dusk={`assurance-${tile.key}`}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="inline-flex items-center gap-1.5 text-caption font-medium">
                                            <Icon
                                                className="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {tile.label}
                                        </span>
                                        <ArrowUpRight
                                            className="size-3.5 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-section-title tabular-nums">
                                            {tile.value}
                                        </span>
                                        <StatusBadge
                                            size="sm"
                                            variant={tile.badge.variant}
                                        >
                                            {tile.badge.label}
                                        </StatusBadge>
                                    </div>
                                    <span className="text-caption">
                                        {tile.caption}
                                    </span>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}

export default AssuranceSummaryPanel;
