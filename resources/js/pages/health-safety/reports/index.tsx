import {
    AssuranceReport,
    type SafetyAssuranceReport,
} from '@/components/health-safety/assurance-report';
import {
    filterSafetyDirectory,
    SafetyDirectory,
    SafetyDirectorySearch,
    type SafetyDirectoryStatus,
} from '@/components/health-safety/workspace-directory';
import {
    PageHeader,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    SAFETY_HUBS,
    visibleSafetyViews,
} from '@/lib/health-safety/workspace-navigation';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import { useState } from 'react';

/** The hub's three groups and the drawing's one-line descriptions (approved H&S drawings, 23 Oct). */
const REPORT_GROUPS: Array<{ label: string; keys: string[] }> = [
    { label: 'For the board and WorkSafe NZ', keys: ['board', 'trends', 'notifications'] },
    { label: 'Following things through', keys: ['investigation-outcomes', 'action-traceability', 'risk-register'] },
    { label: 'In other modules', keys: ['compliance', 'privacy'] },
];
const REPORT_DESCRIPTIONS: Record<string, string> = {
    board: 'The safety summary for the board',
    trends: 'Incident patterns and how we’re doing on safety',
    notifications: 'Decisions and where each notification is at',
    'investigation-outcomes': 'Findings, and which are finished',
    'action-traceability': 'Each action back to its safety review',
    'risk-register': 'Every risk assessment in one place',
    compliance: 'The organisation’s compliance page',
    privacy: 'Privacy requests, breaches and reporting',
};

export default function SafetyReports({
    view,
    report,
    hub_status = {},
}: {
    view: string | null;
    report: SafetyAssuranceReport | null;
    /** Per-report flag and facts on the hub, keyed by view key (optional; cards show descriptions without it). */
    hub_status?: Record<string, SafetyDirectoryStatus>;
}) {
    const { props, url } = usePage<SharedData>();
    const [query, setQuery] = useState('');
    const hub = SAFETY_HUBS.find((hub) => hub.key === 'reports')!;
    const views = visibleSafetyViews(hub, props.auth.can);
    const selected = views.find((item) => item.key === view);
    const period = report?.period;
    const from = period?.start ?? period?.from;
    const to = period?.end ?? period?.to;
    const title = selected?.label ?? hub.label;
    const described = views.map((item) => ({
        ...item,
        description: REPORT_DESCRIPTIONS[item.key] ?? item.description,
    }));
    const select = (key: string) => {
        const item = views.find((candidate) => candidate.key === key);
        if (!item) return;
        setQuery('');
        const next = new URL(item.href, 'https://safety.local');
        if (next.pathname === '/health-safety/reports') {
            const current = new URL(url, 'https://safety.local');
            for (const key of ['site_id', 'from', 'to']) {
                const value = current.searchParams.get(key);
                if (value) next.searchParams.set(key, value);
            }
        }
        router.get(next.pathname + next.search);
    };
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: hub.label, href: '/health-safety/reports' },
                ...(selected ? [{ title, href: selected.href }] : []),
            ]}
        >
            <Head title={title} />
            <PageLayout
                hero={
                    <PageHeader
                        title={title}
                        icon={BarChart3}
                        wrapTitle
                        frontline
                        variant={report ? 'profile' : 'index'}
                        backHref={report ? '/health-safety/reports' : undefined}
                        subline={
                            report
                                ? `Your sites${from || to ? ` · ${from ? formatDateOnly(from) : 'All earlier records'} – ${to ? formatDateOnly(to) : 'now'}` : ' · Current records'}${report.generated_at ? ` · Made ${formatDateTime(report.generated_at)}` : ''}`
                                : 'Safety trends, decisions and assurance reports'
                        }
                        actions={
                            report?.items ? (
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder="Search report records"
                                />
                            ) : !report ? (
                                <SafetyDirectorySearch
                                    value={query}
                                    onChange={setQuery}
                                />
                            ) : undefined
                        }
                        rail={
                            report ? (
                                <PageHeaderRail
                                    items={views.map((item) => ({ key: item.key, label: item.label }))}
                                    value={selected?.key ?? ''}
                                    onSelect={select}
                                    ariaLabel="Safety reports"
                                />
                            ) : undefined
                        }
                    />
                }
            >
                {report ? (
                    <AssuranceReport report={report} query={query} />
                ) : query.trim() ? (
                    <SafetyDirectory
                        views={filterSafetyDirectory(described, query)}
                        status={hub_status}
                        cols={3}
                        label="Matching reports and workspaces"
                    />
                ) : (
                    <div className="flex flex-col gap-5">
                        {REPORT_GROUPS.map((group) => {
                            const items = group.keys
                                .map((key) => described.find((item) => item.key === key))
                                .filter((item): item is (typeof described)[number] => !!item);
                            return items.length ? (
                                <SafetyDirectory
                                    key={group.label}
                                    views={items}
                                    status={hub_status}
                                    cols={3}
                                    label={group.label}
                                />
                            ) : null;
                        })}
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
