import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CheckCircle2,
    ExternalLink,
} from 'lucide-react';
import { SiteProfileEmptyState } from './site-profile-states';

export type SiteProfileSummaryModule = {
    items?: Array<Record<string, unknown>>;
    summary?: Record<string, unknown> | null;
    href?: string | null;
};

export function SiteProfileModuleSummary({
    label,
    description,
    data,
    actionLabel = `Open ${label}`,
}: {
    label: string;
    description: string;
    data: SiteProfileSummaryModule;
    actionLabel?: string;
}) {
    const items = data.items ?? [];

    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">{label}</h2>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {data.href ? (
                    <Button asChild className="min-h-11">
                        <Link href={data.href}>
                            {actionLabel}
                            <ExternalLink className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                ) : null}
            </div>

            {data.summary ? (
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {Object.entries(data.summary).map(([key, value]) => (
                        <Card key={key}>
                            <CardContent className="p-4">
                                <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    {key.replaceAll('_', ' ')}
                                </p>
                                <p className="mt-1 text-xl font-bold tabular-nums">
                                    {formatValue(value)}
                                </p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            ) : null}

            {items.length ? (
                <Card>
                    <CardContent className="divide-y p-0">
                        {items.map((item, index) => {
                            const status = String(
                                item.status ??
                                    item.condition ??
                                    item.outcome ??
                                    '',
                            );
                            const due =
                                item.due_date ??
                                item.review_due_at ??
                                item.next_due_date ??
                                item.scheduled_at ??
                                item.treatment_date ??
                                item.expiry_date ??
                                item.next_inspection_due;
                            const warning =
                                item.overdue === true ||
                                /critical|high|overdue|failed/i.test(status);

                            return (
                                <div
                                    key={String(item.id ?? index)}
                                    className="flex min-h-16 flex-wrap items-center gap-3 px-4 py-3"
                                >
                                    {warning ? (
                                        <AlertTriangle className="h-5 w-5 shrink-0 text-status-warning" />
                                    ) : due ? (
                                        <CalendarClock className="h-5 w-5 shrink-0 text-muted-foreground" />
                                    ) : (
                                        <CheckCircle2 className="h-5 w-5 shrink-0 text-status-success" />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-semibold">
                                            {itemTitle(item, label)}
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {[
                                                status || null,
                                                due
                                                    ? `Due ${formatValue(due)}`
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') ||
                                                'Details available in the owning module'}
                                        </p>
                                    </div>
                                    {status ? (
                                        <Badge variant="outline">
                                            {status.replaceAll('_', ' ')}
                                        </Badge>
                                    ) : null}
                                    {typeof item.href === 'string' ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={item.href}>
                                                Resolve
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            );
                        })}
                    </CardContent>
                </Card>
            ) : (
                <SiteProfileEmptyState
                    title={`No ${label.toLowerCase()} to show`}
                    description={`There are no current ${label.toLowerCase()} records for this Site.`}
                    action={
                        data.href
                            ? { label: actionLabel, href: data.href }
                            : undefined
                    }
                />
            )}
        </div>
    );
}

function itemTitle(item: Record<string, unknown>, fallback: string): string {
    return String(
        item.title ??
            item.name ??
            item.description ??
            item.reference ??
            item.person ??
            fallback,
    );
}

function formatValue(value: unknown): string {
    if (value === null || value === undefined || value === '')
        return 'Not recorded';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    return String(value);
}
