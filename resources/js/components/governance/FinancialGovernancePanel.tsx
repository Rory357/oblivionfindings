import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight, Building2, DollarSign, HandCoins } from 'lucide-react';

interface CockpitCardMetric {
    label: string;
    value: string;
    tone: string;
}

interface CockpitCard {
    key: string;
    title: string;
    description: string;
    status: string;
    metrics: CockpitCardMetric[];
    highlights: string[];
    href: string;
}

interface FinancialGovernancePanelProps {
    cardsByKey: Record<string, CockpitCard | undefined>;
    canApproveSpend?: boolean;
    canApproveBudgets?: boolean;
}

const TONE_VALUE: Record<string, string> = {
    default: 'text-foreground',
    critical: 'text-status-critical',
    warning: 'text-status-warning',
    muted: 'text-muted-foreground',
};

const STATUS_BADGE: Record<string, string> = {
    critical:
        'border-status-critical/30 bg-status-critical-bg text-status-critical',
    warning:
        'border-status-warning/30 bg-status-warning-bg text-status-warning',
    good: 'border-status-success/30 bg-status-success-bg text-status-success',
    unknown: 'border-border bg-muted text-muted-foreground',
};

function MiniBlock({
    card,
    icon: Icon,
    verb,
}: {
    card: CockpitCard | undefined;
    icon: typeof DollarSign;
    verb: string;
}) {
    if (!card) return null;

    return (
        <Card
            unstyled
            className="space-y-3 rounded-lg border border-border bg-card p-4"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-2">
                    <div className="rounded-md bg-muted p-2">
                        <Icon
                            className="h-4 w-4 text-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <div>
                        <p className="text-sm font-semibold text-foreground">
                            {card.title}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {card.description}
                        </p>
                    </div>
                </div>
                <Badge
                    className={cn(
                        'border text-[10px] uppercase',
                        STATUS_BADGE[card.status] ?? STATUS_BADGE.unknown,
                    )}
                >
                    {card.status}
                </Badge>
            </div>

            <div className="grid grid-cols-2 gap-2">
                {card.metrics.slice(0, 4).map((m) => (
                    <div key={m.label} className="rounded-md bg-muted/60 p-2">
                        <p className="text-[10px] tracking-wide text-muted-foreground uppercase">
                            {m.label}
                        </p>
                        <p
                            className={cn(
                                'mt-0.5 text-base font-semibold',
                                TONE_VALUE[m.tone] ?? TONE_VALUE.default,
                            )}
                        >
                            {m.value}
                        </p>
                    </div>
                ))}
            </div>

            {card.highlights.length > 0 && (
                <ul className="space-y-1">
                    {card.highlights.slice(0, 2).map((h) => (
                        <li key={h} className="text-xs text-muted-foreground">
                            • {h}
                        </li>
                    ))}
                </ul>
            )}

            <Button asChild size="sm" variant="outline" className="w-full">
                <Link
                    href={card.href}
                    className="inline-flex items-center justify-between"
                >
                    <span>{verb}</span>
                    <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                </Link>
            </Button>
        </Card>
    );
}

/**
 * Financial Governance panel — budget posture, sites over budget, spend
 * approvals queue, donor fund issues. Composes the existing `financial`,
 * `spend_approvals`, and `sites_over_budget` cockpit cards.
 */
export function FinancialGovernancePanel({
    cardsByKey,
    canApproveSpend = true,
    canApproveBudgets = true,
}: FinancialGovernancePanelProps) {
    return (
        <Card data-dusk="cockpit-financial">
            <CardHeader className="pb-3">
                <CardTitle className="text-lg">Financial Governance</CardTitle>
                <CardDescription>
                    Budget posture, spend approvals, and items requiring board
                    sign-off.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 md:grid-cols-2">
                <MiniBlock
                    card={cardsByKey['financial']}
                    icon={DollarSign}
                    verb={canApproveBudgets ? 'Review budget' : 'Open budgets'}
                />
                <MiniBlock
                    card={cardsByKey['spend_approvals']}
                    icon={HandCoins}
                    verb={canApproveSpend ? 'Approve spend' : 'Open requests'}
                />
                <MiniBlock
                    card={cardsByKey['sites_over_budget']}
                    icon={Building2}
                    verb="Open variance"
                />
            </CardContent>
        </Card>
    );
}

export default FinancialGovernancePanel;
