import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CheckCircle2, Target } from 'lucide-react';

export type CarePlanDomain = {
    key?: string | null;
    label?: string | null;
    status?: 'on_track' | 'active' | 'review' | string | null;
    strategies?: Array<{
        text?: string | null;
        owner?: string | null;
    }>;
};

type CarePlanDomainsProps = {
    domains?: CarePlanDomain[];
};

function statusLabel(value?: string | null): string {
    return String(value ?? 'active').replace(/_/g, ' ');
}

function statusClass(value?: string | null): string {
    switch (value) {
        case 'on_track':
            return 'bg-status-success-bg text-status-success';
        case 'review':
            return 'bg-status-warning-bg text-status-warning';
        default:
            return 'bg-primary/10 text-primary';
    }
}

export function CarePlanDomains({ domains = [] }: CarePlanDomainsProps) {
    const visibleDomains = domains.filter((domain) => domain.label);

    if (visibleDomains.length === 0) {
        return null;
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Target className="h-4 w-4 text-primary" />
                    Support domains
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid gap-3 md:grid-cols-2">
                    {visibleDomains.map((domain, index) => (
                        // eslint-disable-next-line no-restricted-syntax -- Domain card is a compact repeated profile panel.
                        <div
                            key={domain.key || `${domain.label}-${index}`}
                            className="rounded-xl border bg-card p-4"
                        >
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <h3 className="text-sm font-semibold">
                                    {domain.label}
                                </h3>
                                <Badge
                                    className={`border-0 capitalize ${statusClass(domain.status)}`}
                                >
                                    {statusLabel(domain.status)}
                                </Badge>
                            </div>
                            <div className="mt-3 space-y-2">
                                {(domain.strategies ?? []).filter(
                                    (strategy) => strategy.text,
                                ).length > 0 ? (
                                    (domain.strategies ?? [])
                                        .filter((strategy) => strategy.text)
                                        .map((strategy, strategyIndex) => (
                                            <div
                                                key={`${strategy.text}-${strategyIndex}`}
                                                className="rounded-lg border bg-muted/30 p-3"
                                            >
                                                <p className="flex gap-2 text-sm">
                                                    <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-success" />
                                                    <span>{strategy.text}</span>
                                                </p>
                                                {strategy.owner ? (
                                                    <p className="mt-1 pl-5 text-xs text-muted-foreground">
                                                        Owner: {strategy.owner}
                                                    </p>
                                                ) : null}
                                            </div>
                                        ))
                                ) : (
                                    <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                                        No strategies recorded for this domain.
                                    </p>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
