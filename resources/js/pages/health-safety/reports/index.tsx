import {
    AssuranceReport,
    type SafetyAssuranceReport,
} from '@/components/health-safety/assurance-report';
import { QuietRows, type QuietRow } from '@/components/health-safety/register-kit';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import {
    PageHeader,
    PageHeaderRail,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    SAFETY_HUBS,
    safetyHubCrumbs,
    visibleSafetyViews,
    type SafetyNavigationPermissions,
} from '@/lib/health-safety/workspace-navigation';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { BarChart3, ClipboardCheck, Flag, ListChecks, Shield, ShieldCheck, Target } from 'lucide-react';
import { useState } from 'react';

/**
 * G4-15 (board approved by Stephan 1 Nov 2026): Reports is a page of links, so it keeps the compact header — the title
 * row and a rail with only Find. One lead block, "For the board and WorkSafe"; the rest as quiet rows. Each row is the
 * link (with its chevron), with no extra "Open" buttons. Only what the person can open is drawn: without board access
 * the page shows Trends and Tell WorkSafe (a live link to the Safety reviews filter), with no "hidden" line. Nothing new
 * is granted (B2.11).
 */
export type WorksafeWaiting = { count: number; first: string | null } | null;

type ReportSection = { key: string; title: string; rows: QuietRow[] };

export function reportSections(can: SafetyNavigationPermissions | null | undefined, worksafe: WorksafeWaiting): ReportSection[] {
    const hub = SAFETY_HUBS.find((item) => item.key === 'reports')!;
    const visible = new Set(visibleSafetyViews(hub, can).map((view) => view.key));
    const concerns = SAFETY_HUBS.find((item) => item.key === 'concerns')!;
    const notifications = visibleSafetyViews(concerns, can).some((view) => view.key === 'notifications');
    const waiting = worksafe && worksafe.count > 0
        ? `${worksafe.count} to tell WorkSafe about${worksafe.first ? `: ${worksafe.first}` : ''}`
        : null;
    const tell = waiting ? <StatusBadge variant="critical" size="sm">Tell WorkSafe</StatusBadge> : undefined;
    const lead: QuietRow[] = [];
    if (notifications) {
        lead.push({ key: 'notifications', icon: Flag, title: 'WorkSafe notifications', elsewhere: true, aside: tell,
            sub: ['In Incidents and safety reviews', waiting].filter(Boolean).join(' · '), href: '/health-safety/reports?view=notifications' });
    }
    if (visible.has('board')) {
        lead.push({ key: 'board', icon: ShieldCheck, title: 'Board reports', sub: 'The pack for the board: incidents, injuries, actions and legal duties', href: '/health-safety/reports?view=board' });
    }
    if (visible.has('trends')) {
        lead.push({ key: 'trends', icon: BarChart3, title: 'Trends', sub: 'Incidents, injuries and actions over time, by site and kind', href: '/health-safety/analytics' });
    }
    // Without board access, telling WorkSafe is the Safety reviews filter (the person's own read), never a "hidden" line.
    if (!notifications && can?.hazards?.view) {
        lead.push({ key: 'tell', icon: Flag, title: 'Tell WorkSafe', aside: tell,
            sub: ['From Safety reviews › WorkSafe: decide or tell', waiting].filter(Boolean).join(' · '), href: '/health-safety/events?tab=decide_or_tell' });
    }
    const following: QuietRow[] = [
        { key: 'investigation-outcomes', icon: ClipboardCheck, title: 'Investigation outcomes', sub: 'What investigations found and what changed', href: '/health-safety/reports?view=investigation-outcomes' },
        { key: 'action-traceability', icon: ListChecks, title: 'Actions and their safety reviews', sub: 'Every action with the review it came from', href: '/health-safety/reports?view=action-traceability' },
        { key: 'risk-register', icon: Target, title: 'Risk assessment report', sub: 'Risks before and after controls, reviews due', href: '/health-safety/reports?view=risk-register' },
    ].filter((row) => visible.has(row.key));
    const elsewhere: QuietRow[] = [
        { key: 'compliance', icon: ShieldCheck, title: 'Compliance', elsewhere: true, sub: 'Opens the Compliance dashboard', href: '/compliance' },
        { key: 'privacy', icon: Shield, title: 'Privacy', elsewhere: true, sub: 'Opens the Privacy dashboard', href: '/privacy/dashboard' },
    ].filter((row) => visible.has(row.key));
    return [
        { key: 'lead', title: visible.has('board') || notifications ? 'For the board and WorkSafe' : '', rows: lead },
        { key: 'following', title: 'Following things through', rows: following },
        { key: 'elsewhere', title: 'In other modules', rows: elsewhere },
    ].filter((section) => section.rows.length > 0);
}

/** The rail's Find: every report row on the page, searchable. */
function ReportsFind({ open, onOpenChange, sections }: { open: boolean; onOpenChange: (open: boolean) => void; sections: ReportSection[] }) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="p-0 sm:max-w-md" showCloseButton={false}>
                <DialogTitle className="sr-only">Find a report</DialogTitle>
                <DialogDescription className="sr-only">Type to find a report, then press Enter to open it.</DialogDescription>
                <Command>
                    <CommandInput placeholder="Find a report…" aria-label="Find a report" />
                    <CommandList>
                        <CommandEmpty>No matching reports.</CommandEmpty>
                        {sections.flatMap((section) => section.rows).map((row) => (
                            <CommandItem
                                key={row.key}
                                value={`${row.title} ${typeof row.sub === 'string' ? row.sub : ''}`}
                                onSelect={() => {
                                    onOpenChange(false);
                                    router.visit(row.href);
                                }}
                            >
                                {row.title}
                                {row.elsewhere ? ' ↗' : ''}
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </DialogContent>
        </Dialog>
    );
}

export default function SafetyReports({
    view,
    report,
    worksafe_waiting: worksafe = null,
}: {
    view: string | null;
    report: SafetyAssuranceReport | null;
    /** G4-15: the one waiting WorkSafe item the person can read (hub page only). */
    worksafe_waiting?: WorksafeWaiting;
}) {
    const { props, url } = usePage<SharedData>();
    const [query, setQuery] = useState('');
    const [finding, setFinding] = useState(false);
    const hub = SAFETY_HUBS.find((hub) => hub.key === 'reports')!;
    const views = visibleSafetyViews(hub, props.auth.can);
    const selected = views.find((item) => item.key === view);
    // B5.3: WorkSafe notifications belongs to "Incidents and safety reviews"; it keeps that hub's crumbs and tabs here.
    const concerns = SAFETY_HUBS.find((item) => item.key === 'concerns')!;
    const notifications =
        view === 'notifications'
            ? visibleSafetyViews(concerns, props.auth.can).find(
                  (item) => item.key === 'notifications',
              )
            : undefined;
    const period = report?.period;
    const from = period?.start ?? period?.from;
    const to = period?.end ?? period?.to;
    const title = selected?.label ?? notifications?.label ?? hub.label;
    const sections = report ? [] : reportSections(props.auth.can, worksafe);
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
            breadcrumbs={
                notifications
                    ? [
                          { title: 'Home', href: '/dashboard' },
                          ...safetyHubCrumbs('concerns', props.auth.can),
                          { title, href: notifications.href },
                      ]
                    : [
                          { title: 'Home', href: '/dashboard' },
                          { title: 'Health and safety', href: '/health-safety' },
                          { title: hub.label, href: '/health-safety/reports' },
                          ...(selected ? [{ title, href: selected.href }] : []),
                      ]
            }
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
                        backHref={report && !notifications ? '/health-safety/reports' : undefined}
                        subline={
                            report
                                ? `Your sites${from || to ? ` · ${from ? formatDateOnly(from) : 'All earlier records'} – ${to ? formatDateOnly(to) : 'now'}` : ' · Current records'}${report.generated_at ? ` · Made ${formatDateTime(report.generated_at)}` : ''}`
                                : 'Safety trends and reports for the board'
                        }
                        actions={
                            report?.items ? (
                                <PageHeaderSearch
                                    value={query}
                                    onChange={setQuery}
                                    placeholder="Search report records"
                                />
                            ) : undefined
                        }
                        rail={
                            notifications ? (
                                <SafetyWorkspaceRail
                                    hub="concerns"
                                    current="notifications"
                                />
                            ) : report ? (
                                <PageHeaderRail
                                    items={views.map((item) => ({ key: item.key, label: item.label }))}
                                    value={selected?.key ?? ''}
                                    onSelect={select}
                                    ariaLabel="Safety reports"
                                />
                            ) : (
                                // G4-15: the compact header's rail carries only Find.
                                <PageHeaderRail
                                    items={[]}
                                    value=""
                                    onSelect={() => undefined}
                                    onFind={() => setFinding(true)}
                                    ariaLabel="Safety reports"
                                />
                            )
                        }
                    />
                }
            >
                {report ? (
                    <AssuranceReport report={report} query={query} />
                ) : (
                    <div className="flex flex-col gap-5">
                        {sections.map((section) => (
                            <QuietRows key={section.key} title={section.title} sub={null} rows={section.rows} />
                        ))}
                        <ReportsFind open={finding} onOpenChange={setFinding} sections={sections} />
                    </div>
                )}
            </PageLayout>
        </AppLayout>
    );
}
