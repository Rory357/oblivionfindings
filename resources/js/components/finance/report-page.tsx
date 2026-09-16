import { Head, router, usePage } from '@inertiajs/react';
import { CalendarRange, type LucideIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import {
    FinanceSectionRail,
    FinanceTierTwoNav,
} from '@/components/finance/finance-section-rail';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import {
    financeSectionForUrl,
    financeTierTwoForUrl,
} from '@/lib/finance-sections';
import { type BreadcrumbItem } from '@/types';

/**
 * The one composition every Reports & Planning surface renders through:
 * the Event Horizon header (icon, title, period chip, subline, Print +
 * export actions, the report's own totals as meter blocks, the date pills
 * as header filters, the Reports rail) plus the tier-2 strip for the
 * Statements and Ageing groups, plus a body slot.
 *
 * Breadcrumbs are derived from `lib/finance-sections.ts`, so every report
 * gets the same Home-rooted trail — `Home · Finance · Reports · <Group> ·
 * <Report>` — without nine hand-maintained copies drifting apart.
 *
 * Deliberately NOT re-exported from `components/finance/index.ts`: it
 * imports the rail, and pages import it by path (the same call the
 * period filter makes).
 */

/* ------------------------------------------------------------------ */
/*  Dates                                                              */
/* ------------------------------------------------------------------ */

/** "01 Sep 2026" — NZ long-ish date, safe against a datetime string. */
export function formatReportDate(date: string | null | undefined): string {
    if (!date) return '—';
    const parsed = new Date(`${String(date).slice(0, 10)}T00:00:00`);
    if (Number.isNaN(parsed.getTime())) return String(date);

    return parsed.toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

/** The header chip for a report covering a date range. */
export function reportRangeLabel(from: string, to: string): string {
    return `${formatReportDate(from)} – ${formatReportDate(to)}`;
}

/** The header chip for a point-in-time report. */
export function reportAsAtLabel(date: string): string {
    return `As at ${formatReportDate(date)}`;
}

const iso = (date: Date) => {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
};

/** NZ financial year runs 1 April → 31 March. */
const financialYearEnd = (today: Date) => {
    const startYear =
        today.getMonth() >= 3 ? today.getFullYear() : today.getFullYear() - 1;

    return iso(new Date(startYear + 1, 2, 31));
};

/* ------------------------------------------------------------------ */
/*  The as-at pill                                                     */
/* ------------------------------------------------------------------ */

/**
 * The header date pill for point-in-time reports (balance sheet, trial
 * balance) — the sibling of `<FinancePeriodFilter>`, which owns the
 * from/to range. One pill, a popover with the usual as-at presets, and a
 * re-query of the page's own URL on change.
 */
export function ReportAsAtFilter({
    url,
    value,
    param = 'as_of_date',
    label = 'As at',
    idPrefix = 'report-as-at',
}: {
    /** The report's own URL — the date re-queries in place. */
    url: string;
    value: string;
    /** Query parameter the controller reads. */
    param?: string;
    label?: string;
    idPrefix?: string;
}) {
    const [open, setOpen] = useState(false);
    const [date, setDate] = useState(value);

    useEffect(() => setDate(value), [value]);

    const apply = (next: string) => {
        setOpen(false);
        router.get(
            url,
            { [param]: next },
            { preserveScroll: true, preserveState: false },
        );
    };

    const now = new Date();
    const presets = [
        { key: 'today', label: 'Today', date: iso(now) },
        {
            key: 'month-end',
            label: 'End of last month',
            date: iso(new Date(now.getFullYear(), now.getMonth(), 0)),
        },
        {
            key: 'quarter-end',
            label: 'End of last quarter',
            date: iso(
                new Date(
                    now.getFullYear(),
                    Math.floor(now.getMonth() / 3) * 3,
                    0,
                ),
            ),
        },
        {
            key: 'year-end',
            label: 'Financial year end',
            date: financialYearEnd(now),
        },
    ];

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton
                    icon={CalendarRange}
                    active
                    aria-label={`Change the ${label.toLowerCase()} date`}
                >
                    {`${label} ${formatReportDate(value)}`}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64">
                <form
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(date);
                    }}
                >
                    <div className="flex flex-wrap gap-1.5">
                        {presets.map((preset) => (
                            <Button
                                key={preset.key}
                                type="button"
                                variant="outline"
                                size="sm"
                                className="text-xs"
                                onClick={() => apply(preset.date)}
                            >
                                {preset.label}
                            </Button>
                        ))}
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor={`${idPrefix}-date`}>{label}</Label>
                        <Input
                            id={`${idPrefix}-date`}
                            type="date"
                            value={date}
                            onChange={(event) => setDate(event.target.value)}
                        />
                    </div>
                    <Button type="submit" size="sm">
                        Show this date
                    </Button>
                </form>
            </PopoverContent>
        </Popover>
    );
}

/* ------------------------------------------------------------------ */
/*  The report card                                                    */
/* ------------------------------------------------------------------ */

/**
 * A statement surface: a titled card whose body scrolls horizontally on
 * its own. Statement layouts (a P&L, a balance sheet, a cash-flow
 * statement) are NOT entity lists, so `components/ui/table` inside this
 * card is the contract for them; row-per-record tables (the two ageing
 * reports) use `<EntityTable>` instead.
 */
export function ReportCard({
    title,
    caption,
    right,
    scroll = true,
    children,
}: {
    title?: ReactNode;
    caption?: ReactNode;
    right?: ReactNode;
    /** Charts size themselves — turn the horizontal scroller off for them. */
    scroll?: boolean;
    children: ReactNode;
}) {
    return (
        <Card>
            {title || right ? (
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-base">{title}</CardTitle>
                        {caption ? (
                            <p className="mt-1 text-caption">{caption}</p>
                        ) : null}
                    </div>
                    {right ?? null}
                </CardHeader>
            ) : null}
            <CardContent
                className={scroll ? 'scrollbar-pretty overflow-x-auto' : ''}
            >
                {children}
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  The page shell                                                     */
/* ------------------------------------------------------------------ */

export interface FinanceReportPageProps {
    icon?: LucideIcon;
    title: string;
    /** Browser title when it should differ from the header title. */
    headTitle?: string;
    variant?: 'index' | 'profile';
    /** Profile variant only: the glass back chip destination. */
    backHref?: string;
    /** The period this report covers — rendered as the one title chip. */
    periodLabel?: string;
    /** A richer chip (record status) — wins over `periodLabel`. */
    chip?: ReactNode;
    subline?: ReactNode;
    actions?: ReactNode;
    meters?: ReactNode;
    filters?: ReactNode;
    /** A record leaf for the breadcrumb trail (a saved forecast's name). */
    recordCrumb?: string;
    children: ReactNode;
}

export function FinanceReportPage({
    icon,
    title,
    headTitle,
    variant = 'index',
    backHref,
    periodLabel,
    chip,
    subline,
    actions,
    meters,
    filters,
    recordCrumb,
    children,
}: FinanceReportPageProps) {
    const page = usePage();
    const match = financeSectionForUrl(page.url);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Reports', href: '/finance/reports' },
    ];

    if (match) {
        breadcrumbs.push({ title: match.tab.label, href: match.tab.href });
        const sibling = match.tab.tier2
            ? financeTierTwoForUrl(match.tab, page.url)
            : null;
        if (sibling) {
            breadcrumbs.push({ title: sibling.label, href: sibling.href });
        }
    }

    if (recordCrumb) breadcrumbs.push({ title: recordCrumb });

    const header = (
        <PageHeader
            variant={variant}
            backHref={backHref}
            icon={icon}
            title={title}
            titleChip={
                chip ??
                (periodLabel ? (
                    <PageHeaderStatusChip variant="neutral">
                        {periodLabel}
                    </PageHeaderStatusChip>
                ) : undefined)
            }
            subline={subline}
            actions={actions}
            meters={meters}
            filters={filters}
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={headTitle ?? title} />

            <PageLayout hero={header} tabs={<FinanceTierTwoNav />}>
                <div className="flex flex-col gap-5">{children}</div>
            </PageLayout>
        </AppLayout>
    );
}

export default FinanceReportPage;
