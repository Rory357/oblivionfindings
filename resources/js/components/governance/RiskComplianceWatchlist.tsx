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
import { AlertOctagon, ArrowRight, FileCheck, ShieldAlert } from 'lucide-react';

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

interface RiskComplianceWatchlistProps {
    cardsByKey: Record<string, CockpitCard | undefined>;
    canManageRisks?: boolean;
    canManageCompliance?: boolean;
}

const STATUS_BADGE: Record<string, string> = {
    critical:
        'border-status-critical/30 bg-status-critical-bg text-status-critical',
    warning:
        'border-status-warning/30 bg-status-warning-bg text-status-warning',
    good: 'border-status-success/30 bg-status-success-bg text-status-success',
    done: 'border-status-success/30 bg-status-success-bg text-status-success',
    unknown: 'border-border bg-muted text-muted-foreground',
};

const TONE_VALUE: Record<string, string> = {
    default: 'text-foreground',
    critical: 'text-status-critical',
    warning: 'text-status-warning',
    muted: 'text-muted-foreground',
};

function StatusBadge({ status }: { status: string }) {
    return (
        <Badge
            className={cn(
                'border text-[10px] uppercase',
                STATUS_BADGE[status] ?? STATUS_BADGE.unknown,
            )}
        >
            {status}
        </Badge>
    );
}

function MiniCard({
    card,
    icon: Icon,
    primaryVerb,
}: {
    card: CockpitCard | undefined;
    icon: typeof ShieldAlert;
    primaryVerb: string;
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
                <StatusBadge status={card.status} />
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
                        <li
                            key={h}
                            className="flex items-start gap-1.5 text-xs text-muted-foreground"
                        >
                            <AlertOctagon
                                className="mt-0.5 h-3 w-3 shrink-0 text-status-warning"
                                aria-hidden="true"
                            />
                            <span>{h}</span>
                        </li>
                    ))}
                </ul>
            )}

            <Button asChild size="sm" variant="outline" className="w-full">
                <Link
                    href={card.href}
                    className="inline-flex items-center justify-between"
                >
                    <span>{primaryVerb}</span>
                    <ArrowRight className="h-3.5 w-3.5" aria-hidden="true" />
                </Link>
            </Button>
        </Card>
    );
}

/**
 * Single panel combining critical risks + overdue compliance + notifiable
 * incidents + evidence gaps. Pulls from existing cockpit cards so no extra
 * backend query is needed.
 */
export function RiskComplianceWatchlist({
    cardsByKey,
    canManageRisks = true,
    canManageCompliance = true,
}: RiskComplianceWatchlistProps) {
    return (
        <Card data-dusk="cockpit-risk-watchlist">
            <CardHeader className="pb-3">
                <CardTitle className="text-lg">
                    Risk &amp; Compliance Watchlist
                </CardTitle>
                <CardDescription>
                    Critical risks, overdue obligations and privacy gaps the
                    board needs to be aware of.
                </CardDescription>
            </CardHeader>
            <CardContent className="grid gap-3 md:grid-cols-2">
                <MiniCard
                    card={cardsByKey['top_risks']}
                    icon={ShieldAlert}
                    primaryVerb={
                        canManageRisks ? 'Review risks' : 'Open risk register'
                    }
                />
                <MiniCard
                    card={cardsByKey['compliance_calendar']}
                    icon={FileCheck}
                    primaryVerb={
                        canManageCompliance
                            ? 'Upload evidence'
                            : 'Open obligations'
                    }
                />
                <MiniCard
                    card={cardsByKey['privacy_data']}
                    icon={AlertOctagon}
                    primaryVerb="Open privacy register"
                />
                <MiniCard
                    card={cardsByKey['incidents']}
                    icon={AlertOctagon}
                    primaryVerb="Open incidents"
                />
            </CardContent>
        </Card>
    );
}

export default RiskComplianceWatchlist;
