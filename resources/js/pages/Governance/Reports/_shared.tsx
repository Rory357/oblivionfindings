/**
 * Shared pieces for the Governance reports: report card sections, status
 * wording and the "figures worked out" footer.
 */
import { Link } from '@inertiajs/react';
import { ArrowUpRight, BarChart3 } from 'lucide-react';

import type { PageHeaderMeterTone } from '@/components/page';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import { cn } from '@/lib/utils';

export interface ReportMetric {
    label: string;
    value: string;
    tone: string;
    href?: string;
}

export interface ReportCard {
    key: string;
    title: string;
    description: string;
    status: string;
    metrics: ReportMetric[];
    highlights: string[];
    href?: string | null;
}

export interface ReportSection {
    key: string;
    title: string;
    cards: ReportCard[];
}

const REPORT_STATUS: Record<string, { label: string; variant: StatusVariant }> = {
    good: { label: 'On track', variant: 'success' },
    warning: { label: 'Needs attention', variant: 'warning' },
    critical: { label: 'Serious', variant: 'critical' },
    unknown: { label: 'No data', variant: 'neutral' },
};

export function reportStatus(status: string | null | undefined) {
    return REPORT_STATUS[status ?? 'unknown'] ?? REPORT_STATUS.unknown;
}

const METRIC_TONE: Record<string, string> = {
    default: 'text-foreground',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    muted: 'text-muted-foreground',
};

export function metricTone(tone: string | null | undefined): PageHeaderMeterTone {
    if (tone === 'critical') return 'critical';
    if (tone === 'warning') return 'warning';
    return 'brand';
}

export function plural(count: number, one: string, many: string): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** "Figures worked out 15 September 2026, 9:41 am" */
export function GeneratedAt({ at }: { at: string }) {
    return (
        <p className="text-caption text-right">
            Figures worked out {formatDateTimeLong(at)}
        </p>
    );
}

function ReportCardView({ card }: { card: ReportCard }) {
    const status = reportStatus(card.status);
    return (
        <Card className="h-full">
            <CardHeader>
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <CardTitle>{card.title}</CardTitle>
                        <CardDescription>{card.description}</CardDescription>
                    </div>
                    <StatusBadge variant={status.variant} className="shrink-0">
                        {status.label}
                    </StatusBadge>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <dl className="grid grid-cols-2 gap-3">
                    {card.metrics.map((metric) => (
                        <div
                            key={`${card.key}-${metric.label}`}
                            className="rounded-lg bg-muted p-3"
                        >
                            <dt className="text-caption">{metric.label}</dt>
                            <dd
                                className={cn(
                                    'mt-1 text-base font-semibold tabular-nums',
                                    METRIC_TONE[metric.tone] ?? METRIC_TONE.default,
                                )}
                            >
                                {metric.value}
                            </dd>
                        </div>
                    ))}
                </dl>
                {card.highlights.length > 0 ? (
                    <ul className="flex flex-col gap-2">
                        {card.highlights.slice(0, 3).map((highlight) => (
                            <li
                                key={highlight}
                                className="rounded-lg border border-border px-3 py-2 text-sm text-foreground"
                            >
                                {highlight}
                            </li>
                        ))}
                    </ul>
                ) : null}
                {card.href ? (
                    <Link
                        href={card.href}
                        className="inline-flex w-fit items-center gap-1 text-sm font-medium text-primary underline-offset-2 hover:underline"
                    >
                        Open {card.title.toLowerCase()}
                        <ArrowUpRight className="h-3.5 w-3.5" aria-hidden="true" />
                    </Link>
                ) : null}
            </CardContent>
        </Card>
    );
}

export function ReportSections({ sections }: { sections: ReportSection[] }) {
    return (
        <>
            {sections.map((section) => (
                <section
                    key={section.key}
                    className="flex flex-col gap-3"
                    aria-label={section.title}
                >
                    <h2 className="text-section-title">{section.title}</h2>
                    {section.cards.length === 0 ? (
                        <EmptyState
                            variant="compact"
                            icon={BarChart3}
                            title="Nothing to report here yet"
                        />
                    ) : (
                        <div className="grid gap-5 lg:grid-cols-2">
                            {section.cards.map((card) => (
                                <ReportCardView key={card.key} card={card} />
                            ))}
                        </div>
                    )}
                </section>
            ))}
        </>
    );
}

/** Counts of cards by status, for the header chip. */
export function cardStatusCounts(sections: ReportSection[]) {
    const cards = sections.flatMap((section) => section.cards);
    return {
        critical: cards.filter((card) => card.status === 'critical').length,
        warning: cards.filter((card) => card.status === 'warning').length,
        unknown: cards.filter((card) => card.status === 'unknown').length,
        total: cards.length,
    };
}
