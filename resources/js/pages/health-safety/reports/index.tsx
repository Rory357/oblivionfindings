import {
    AssuranceReport,
    type SafetyAssuranceReport,
} from '@/components/health-safety/assurance-report';
import {
    filterSafetyDirectory,
    SafetyDirectory,
    SafetyDirectorySearch,
} from '@/components/health-safety/workspace-directory';
import {
    PageHeader,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import {
    SAFETY_HUBS,
    visibleSafetyViews,
} from '@/lib/health-safety/workspace-navigation';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { BarChart3 } from 'lucide-react';
import { useState } from 'react';

export default function SafetyReports({
    view,
    report,
}: {
    view: string | null;
    report: SafetyAssuranceReport | null;
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
                                ? `Your permitted sites${from || to ? ` · ${from ? formatDateOnly(from) : 'All earlier records'} to ${to ? formatDateOnly(to) : 'now'}` : ' · Current records'}`
                                : 'Safety trends, recorded decisions and assurance reports'
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
                                    items={views}
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
                ) : (
                    <SafetyDirectory
                        views={filterSafetyDirectory(views, query)}
                        label="Safety reports and related workspaces"
                    />
                )}
            </PageLayout>
        </AppLayout>
    );
}
