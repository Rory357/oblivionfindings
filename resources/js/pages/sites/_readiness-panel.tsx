import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { CheckCircle2, CircleAlert, ExternalLink } from 'lucide-react';

export type ReadinessItem = {
    key: string;
    label: string;
    area: string;
    done: boolean;
    action: string;
};

export type SiteReadiness = {
    critical: ReadinessItem[];
    recommended: ReadinessItem[];
    critical_done: number;
    critical_total: number;
    recommended_done: number;
    recommended_total: number;
    missing_critical: string[];
    score: number;
    is_active: boolean;
    is_active_but_incomplete: boolean;
    recommended_documents?: Array<{ key: string; label: string; hint: string }>;
};

type Props = {
    readiness: SiteReadiness;
    onAction?: (action: string) => void;
};

export function SiteReadinessPanel({ readiness, onAction }: Props) {
    const statusText = readiness.is_active_but_incomplete
        ? 'Active with missing critical setup'
        : readiness.score >= 90
          ? 'Operationally ready'
          : 'Setup in progress';

    return (
        <section className="rounded-lg border bg-card p-4 shadow-sm sm:p-5">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 className="text-base font-semibold">
                        Operational readiness
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {statusText}
                    </p>
                </div>
                <Badge
                    variant="outline"
                    className={cn(
                        'w-fit',
                        readiness.is_active_but_incomplete ||
                            readiness.score < 50
                            ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                            : readiness.score < 90
                              ? 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                              : 'border-status-success/30 bg-status-success-bg text-status-success',
                    )}
                >
                    {readiness.score}%
                </Badge>
            </div>

            <Progress value={readiness.score} className="mt-4" />

            <div className="mt-5 grid gap-4 lg:grid-cols-2">
                <ReadinessList
                    title={`Critical (${readiness.critical_done}/${readiness.critical_total})`}
                    items={readiness.critical}
                    onAction={onAction}
                />
                <ReadinessList
                    title={`Recommended (${readiness.recommended_done}/${readiness.recommended_total})`}
                    items={readiness.recommended}
                    onAction={onAction}
                />
            </div>
        </section>
    );
}

function ReadinessList({
    title,
    items,
    onAction,
}: {
    title: string;
    items: ReadinessItem[];
    onAction?: (action: string) => void;
}) {
    return (
        <div className="space-y-2">
            <h3 className="text-sm font-semibold">{title}</h3>
            <ul className="space-y-2">
                {items.map((item) => (
                    <li
                        key={item.key}
                        className="flex items-center gap-3 rounded-md border bg-background px-3 py-2"
                    >
                        {item.done ? (
                            <CheckCircle2 className="h-4 w-4 shrink-0 text-status-success" />
                        ) : (
                            <CircleAlert className="h-4 w-4 shrink-0 text-status-warning" />
                        )}
                        <span
                            className={cn(
                                'min-w-0 flex-1 text-sm',
                                item.done
                                    ? 'text-foreground'
                                    : 'font-medium text-foreground',
                            )}
                        >
                            {item.label}
                        </span>
                        {!item.done && onAction && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="shrink-0 gap-1"
                                onClick={() => onAction(item.action)}
                            >
                                <ExternalLink className="h-3.5 w-3.5" />
                                Fix
                            </Button>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
